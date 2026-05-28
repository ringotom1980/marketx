<?php

namespace App\Console\Commands;

use App\Models\MarketDailyContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BuildMarketDailyContext extends Command
{
    protected $signature = 'market:build-daily-context
        {--date= : Context date, default today in Asia/Taipei}
        {--session=daily : Context session, for example premarket/aftermarket/night/manual}';

    protected $description = 'Build the shared daily market context pack used by agents and reports.';

    private const GLOBAL_INDICATORS = [
        'Dow Jones',
        'S&P 500',
        'NASDAQ',
        'Russell 2000',
        'SOX',
        'VIX',
        'TSM ADR',
        'UMC ADR',
        'Nikkei 225',
        'Hang Seng',
        'Hang Seng China Enterprises',
        'KOSPI',
        'KOSDAQ',
        'Shanghai Composite',
        'DXY',
        'US10Y',
        'Crude Oil',
        'Gold',
        'TAIEX',
        'TAIFEX TX Night',
    ];

    public function handle(): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), 'Asia/Taipei')->toDateString()
            : CarbonImmutable::now('Asia/Taipei')->toDateString();
        $session = (string) $this->option('session');

        $globalMarkets = $this->latestGlobalMarkets();
        $taiwanMarket = $this->taiwanMarket($globalMarkets);
        $themes = $this->themeSnapshot();
        $radar = $this->radarSnapshot();
        $events = $this->eventSnapshot();
        $aiReports = $this->aiReports();
        $freshness = $this->freshness();

        $scores = $this->scoreContext($globalMarkets, $themes, $radar);
        $summary = $this->summary($scores, $globalMarkets, $themes, $radar);

        MarketDailyContext::updateOrCreate(
            ['context_date' => $date, 'session' => $session],
            [
                'market_phase' => $this->marketPhase($session),
                'risk_score' => $scores['risk_score'],
                'opportunity_score' => $scores['opportunity_score'],
                'summary' => $summary,
                'global_markets' => $globalMarkets->values()->all(),
                'taiwan_market' => $taiwanMarket,
                'theme_snapshot' => $themes,
                'radar_snapshot' => $radar,
                'event_snapshot' => $events,
                'ai_reports' => $aiReports,
                'freshness' => $freshness,
                'payload' => [
                    'score_inputs' => $scores['inputs'],
                    'built_at' => CarbonImmutable::now('Asia/Taipei')->toDateTimeString(),
                ],
            ]
        );

        $this->info("Market daily context built: {$date}/{$session}");

        return self::SUCCESS;
    }

    private function latestGlobalMarkets(): Collection
    {
        if (! Schema::hasTable('global_market_data')) {
            return collect();
        }

        return DB::table('global_market_data as g')
            ->joinSub(
                DB::table('global_market_data')
                    ->selectRaw('indicator, max(trade_date) as latest_date')
                    ->whereIn('indicator', self::GLOBAL_INDICATORS)
                    ->groupBy('indicator'),
                'latest',
                function ($join) {
                    $join->on('g.indicator', '=', 'latest.indicator')
                        ->on('g.trade_date', '=', 'latest.latest_date');
                }
            )
            ->select('g.indicator', 'g.trade_date', 'g.value', 'g.change', 'g.change_pct', 'g.state', 'g.source', 'g.updated_at')
            ->get()
            ->mapWithKeys(fn (object $row) => [
                $row->indicator => [
                    'indicator' => $row->indicator,
                    'trade_date' => (string) $row->trade_date,
                    'value' => $this->number($row->value),
                    'change' => $this->number($row->change),
                    'change_pct' => $this->number($row->change_pct),
                    'state' => $row->state,
                    'source' => $row->source,
                    'updated_at' => $row->updated_at,
                ],
            ]);
    }

    private function taiwanMarket(Collection $globalMarkets): array
    {
        $latestMargin = null;

        if (Schema::hasTable('market_margins_1d')) {
            $latestMargin = DB::table('market_margins_1d')
                ->orderByDesc('trade_date')
                ->orderByDesc('updated_at')
                ->first();
        }

        return [
            'taiex' => $globalMarkets->get('TAIEX'),
            'taifex_night' => $globalMarkets->get('TAIFEX TX Night'),
            'margin' => $latestMargin ? [
                'market' => $latestMargin->market,
                'trade_date' => (string) $latestMargin->trade_date,
                'margin_balance' => $latestMargin->margin_balance,
                'short_balance' => $latestMargin->short_balance,
                'margin_buy' => $latestMargin->margin_buy,
                'margin_sell' => $latestMargin->margin_sell,
                'short_sell' => $latestMargin->short_sell,
                'short_buy' => $latestMargin->short_buy,
                'updated_at' => $latestMargin->updated_at,
            ] : null,
        ];
    }

    private function themeSnapshot(): array
    {
        if (! Schema::hasTable('theme_scores')) {
            return [];
        }

        $latestDate = DB::table('theme_scores')->max('score_date');

        if (! $latestDate) {
            return [];
        }

        return DB::table('theme_scores as ts')
            ->join('themes as t', 't.id', '=', 'ts.theme_id')
            ->where('ts.score_date', $latestDate)
            ->where('t.is_active', true)
            ->where('ts.heat_score', '>', 0)
            ->orderByDesc('ts.heat_score')
            ->limit(15)
            ->get([
                't.name',
                't.slug',
                'ts.score_date',
                'ts.heat_score',
                'ts.news_score',
                'ts.price_score',
                'ts.volume_score',
                'ts.chip_score',
                'ts.ai_event_score',
                'ts.payload',
                'ts.updated_at',
            ])
            ->map(fn (object $row) => [
                'name' => $row->name,
                'slug' => $row->slug,
                'score_date' => (string) $row->score_date,
                'heat_score' => (int) $row->heat_score,
                'trend' => $this->themeTrend($this->json($row->payload)),
                'scores' => [
                    'news' => $row->news_score === null ? null : (int) $row->news_score,
                    'price' => $row->price_score === null ? null : (int) $row->price_score,
                    'volume' => $row->volume_score === null ? null : (int) $row->volume_score,
                    'chip' => $row->chip_score === null ? null : (int) $row->chip_score,
                    'ai_event' => $row->ai_event_score === null ? null : (int) $row->ai_event_score,
                ],
                'updated_at' => $row->updated_at,
            ])
            ->values()
            ->all();
    }

    private function radarSnapshot(): array
    {
        if (! Schema::hasTable('stock_radar_cards')) {
            return [];
        }

        $latestDate = DB::table('stock_radar_cards')->max('card_date');

        if (! $latestDate) {
            return [];
        }

        return DB::table('stock_radar_cards as c')
            ->join('stocks as s', 's.id', '=', 'c.stock_id')
            ->where('c.card_date', $latestDate)
            ->orderBy('c.card_type')
            ->orderBy('c.rank')
            ->get([
                'c.card_date',
                'c.card_type',
                'c.rank',
                'c.confidence_score',
                'c.reasons',
                'c.metrics_payload',
                's.symbol',
                's.name',
                's.market',
                's.industry',
            ])
            ->groupBy('card_type')
            ->map(fn (Collection $rows) => $rows->take(8)->map(fn (object $row) => [
                'symbol' => $row->symbol,
                'name' => $row->name,
                'market' => $row->market,
                'industry' => $row->industry,
                'rank' => (int) $row->rank,
                'confidence_score' => (int) $row->confidence_score,
                'reasons' => $this->json($row->reasons),
                'metrics' => $this->json($row->metrics_payload),
            ])->values()->all())
            ->all();
    }

    private function eventSnapshot(): array
    {
        $clusters = [];
        $events = [];

        if (Schema::hasTable('global_event_clusters')) {
            $clusters = DB::table('global_event_clusters')
                ->orderByDesc('cluster_date')
                ->orderByDesc('importance_score')
                ->limit(8)
                ->get(['cluster_date', 'title', 'summary', 'category', 'region', 'importance_score', 'sentiment', 'themes', 'related_symbols'])
                ->map(fn (object $row) => [
                    'date' => (string) $row->cluster_date,
                    'title' => $row->title,
                    'summary' => $row->summary,
                    'category' => $row->category,
                    'region' => $row->region,
                    'importance_score' => (int) $row->importance_score,
                    'sentiment' => $row->sentiment,
                    'themes' => $this->json($row->themes),
                    'related_symbols' => $this->json($row->related_symbols),
                ])
                ->values()
                ->all();
        }

        if (Schema::hasTable('global_events')) {
            $events = DB::table('global_events')
                ->orderByDesc('event_date')
                ->orderByDesc('impact_score')
                ->limit(8)
                ->get(['event_date', 'title', 'summary', 'category', 'region', 'impact_direction', 'impact_score', 'source'])
                ->map(fn (object $row) => [
                    'event_date' => $row->event_date,
                    'title' => $row->title,
                    'summary' => $row->summary,
                    'category' => $row->category,
                    'region' => $row->region,
                    'impact_direction' => $row->impact_direction,
                    'impact_score' => $row->impact_score === null ? null : (int) $row->impact_score,
                    'source' => $row->source,
                ])
                ->values()
                ->all();
        }

        return [
            'clusters' => $clusters,
            'recent_events' => $events,
        ];
    }

    private function aiReports(): array
    {
        return [
            'global' => $this->latestAiReport('global_ai_reports'),
            'theme' => $this->latestAiReport('theme_ai_reports'),
        ];
    }

    private function latestAiReport(string $table): ?array
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $row = DB::table($table)
            ->orderByDesc('report_date')
            ->first(['report_date', 'title', 'summary', 'model', 'updated_at']);

        if (! $row) {
            return null;
        }

        return [
            'report_date' => (string) $row->report_date,
            'title' => $row->title,
            'summary_excerpt' => mb_substr((string) $row->summary, 0, 500),
            'model' => $row->model,
            'updated_at' => $row->updated_at,
        ];
    }

    private function freshness(): array
    {
        $tables = [
            'stock_prices_1d' => ['date' => 'trade_date'],
            'stock_technical_indicators_1d' => ['date' => 'trade_date'],
            'stock_chips_1d' => ['date' => 'trade_date'],
            'stock_scores' => ['date' => 'score_date'],
            'stock_financials' => ['date' => 'period'],
            'stock_revenues' => ['date' => 'year_month'],
            'global_market_data' => ['date' => 'trade_date'],
            'theme_scores' => ['date' => 'score_date'],
            'stock_radar_cards' => ['date' => 'card_date'],
            'global_event_clusters' => ['date' => 'cluster_date'],
        ];

        return collect($tables)
            ->filter(fn (array $config, string $table) => Schema::hasTable($table))
            ->mapWithKeys(function (array $config, string $table) {
                return [$table => [
                    'latest' => DB::table($table)->max($config['date']),
                    'updated_at' => DB::table($table)->max('updated_at'),
                    'count' => DB::table($table)->count(),
                ]];
            })
            ->all();
    }

    private function scoreContext(Collection $globalMarkets, array $themes, array $radar): array
    {
        $bullInputs = [
            $this->change($globalMarkets, 'NASDAQ'),
            $this->change($globalMarkets, 'SOX'),
            $this->change($globalMarkets, 'TSM ADR'),
            $this->change($globalMarkets, 'TAIFEX TX Night'),
        ];
        $bearInputs = [
            $this->change($globalMarkets, 'VIX'),
            $this->change($globalMarkets, 'US10Y'),
            $this->change($globalMarkets, 'DXY'),
            $this->change($globalMarkets, 'Crude Oil'),
        ];

        $bull = collect($bullInputs)->filter(fn (?float $v) => $v !== null)->avg() ?? 0.0;
        $pressure = collect($bearInputs)->filter(fn (?float $v) => $v !== null)->avg() ?? 0.0;
        $themeHeat = collect($themes)->take(5)->avg('heat_score') ?: 50;
        $priorityConfidence = collect($radar['priority'] ?? [])->avg('confidence_score') ?: 50;
        $riskConfidence = collect($radar['risk'] ?? [])->avg('confidence_score') ?: 50;

        $opportunity = 50 + ($bull * 6) + (($themeHeat - 50) * 0.25) + (($priorityConfidence - 50) * 0.2);
        $risk = 40 + ($pressure * 7) + (($riskConfidence - 50) * 0.35);

        return [
            'opportunity_score' => $this->clampScore($opportunity),
            'risk_score' => $this->clampScore($risk),
            'inputs' => [
                'bullish_market_avg_change' => round($bull, 4),
                'pressure_market_avg_change' => round($pressure, 4),
                'top_theme_heat_avg' => round((float) $themeHeat, 2),
                'priority_confidence_avg' => round((float) $priorityConfidence, 2),
                'risk_confidence_avg' => round((float) $riskConfidence, 2),
            ],
        ];
    }

    private function summary(array $scores, Collection $globalMarkets, array $themes, array $radar): string
    {
        $topThemes = collect($themes)->take(3)->pluck('name')->implode('、') ?: '尚無明確題材';
        $night = $this->change($globalMarkets, 'TAIFEX TX Night');
        $sox = $this->change($globalMarkets, 'SOX');
        $nasdaq = $this->change($globalMarkets, 'NASDAQ');
        $priorityCount = count($radar['priority'] ?? []);
        $riskCount = count($radar['risk'] ?? []);

        return sprintf(
            '市場背景包已建立：機會分 %d，風險分 %d。夜盤變動 %s，SOX %s，NASDAQ %s。今日題材焦點為 %s；優先觀察候選 %d 檔，風險升高候選 %d 檔。',
            $scores['opportunity_score'],
            $scores['risk_score'],
            $this->formatPct($night),
            $this->formatPct($sox),
            $this->formatPct($nasdaq),
            $topThemes,
            $priorityCount,
            $riskCount
        );
    }

    private function marketPhase(string $session): string
    {
        return match ($session) {
            'premarket' => '盤前',
            'aftermarket' => '台股盤後',
            'night' => '夜盤與美股時段',
            default => '每日整合',
        };
    }

    private function themeTrend(array $payload): string
    {
        $trend = $payload['trend'] ?? $payload['temperature_trend'] ?? null;

        if (is_string($trend) && $trend !== '') {
            return $trend;
        }

        $previous = $payload['previous_heat_score'] ?? $payload['previousScore'] ?? null;
        $current = $payload['heat_score'] ?? null;

        if (is_numeric($previous) && is_numeric($current)) {
            return (float) $current >= (float) $previous ? 'warming' : 'cooling';
        }

        return 'watching';
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function change(Collection $markets, string $indicator): ?float
    {
        $row = $markets->get($indicator);

        return isset($row['change_pct']) ? $this->number($row['change_pct']) : null;
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function formatPct(?float $value): string
    {
        return $value === null ? '無資料' : number_format($value, 2).'%';
    }

    private function clampScore(float $score): int
    {
        return max(0, min(100, (int) round($score)));
    }
}
