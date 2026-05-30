<?php

namespace App\Console\Commands;

use App\Support\Ai\AiPipelineService;
use App\Support\Ai\AiUsageLimiter;
use App\Support\Ai\AiResult;
use App\Support\Ai\GeminiProvider;
use App\Support\Ai\GroqProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiGenerateThemePremarketReport extends Command
{
    protected $signature = 'market:ai-generate-theme-premarket
        {--date= : Report date, default today in Asia/Taipei}
        {--force : Regenerate even if today report exists}
        {--live : Actually call AI. Without this option only logs a skipped result}';

    protected $description = 'Generate one cached AI theme premarket report for the theme radar page.';

    public function handle(GeminiProvider $gemini, GroqProvider $groq, AiPipelineService $pipeline, AiUsageLimiter $limiter): int
    {
        $task = 'theme_premarket';
        $reportDate = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), 'Asia/Taipei')->toDateString()
            : CarbonImmutable::now('Asia/Taipei')->toDateString();

        if (! $this->option('force') && DB::table('theme_ai_reports')->where('report_date', $reportDate)->exists()) {
            $this->info('Theme premarket report already exists: '.$reportDate);

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $limiter->canRun($task)) {
            $this->warn('Daily AI limit reached for '.$task);

            return self::SUCCESS;
        }

        $dataPack = $this->dataPack($reportDate);
        $prompt = $this->prompt($dataPack);
        $result = $this->generateWithRetry($gemini, $prompt);

        if (! $result->ok) {
            $pipeline->log($task.'_gemini_fallback', $result, $prompt);
            $this->warn('Gemini theme premarket failed, falling back to Groq: '.$result->error);
            $prompt = $this->prompt($this->compactDataPackForGroq($dataPack));
            $result = $groq->chat($prompt, (bool) $this->option('live'));
        }

        $pipeline->log($task, $result, $prompt);

        if (! $result->ok) {
            $this->warn('AI theme premarket skipped/failed: '.$result->error);

            return self::FAILURE;
        }

        $text = $this->cleanReportText((string) $result->text);

        if (mb_strlen($text) < 120) {
            $this->warn('AI theme premarket report too short.');

            return self::FAILURE;
        }

        DB::table('theme_ai_reports')->updateOrInsert(
            ['report_date' => $reportDate],
            [
                'title' => '《股市在幹嘛》今日題材盤前觀察',
                'summary' => $text,
                'data_pack' => json_encode($dataPack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'model' => $result->provider.':'.$result->model,
                'token_usage' => json_encode($result->usage, JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->info('AI theme premarket report generated: '.$reportDate.' / '.$result->provider.':'.$result->model);

        return self::SUCCESS;
    }

    private function generateWithRetry(GeminiProvider $gemini, string $prompt): AiResult
    {
        $result = $gemini->generate($prompt, (bool) $this->option('live'));

        foreach ([30, 90] as $delaySeconds) {
            if ($result->ok || ! str_contains((string) $result->error, '"code": 503')) {
                break;
            }

            $this->warn('Gemini is busy, retrying after '.$delaySeconds.' seconds...');
            sleep($delaySeconds);
            $result = $gemini->generate($prompt, (bool) $this->option('live'));
        }

        return $result;
    }

    private function compactDataPackForGroq(array $dataPack): array
    {
        $dataPack['global_market'] = collect($dataPack['global_market'] ?? [])
            ->take(12)
            ->values()
            ->all();

        $dataPack['event_clusters'] = collect($dataPack['event_clusters'] ?? [])
            ->take(5)
            ->values()
            ->all();

        $dataPack['recent_news_events'] = collect($dataPack['recent_news_events'] ?? [])
            ->take(6)
            ->values()
            ->all();

        $dataPack['themes'] = collect($dataPack['themes'] ?? [])
            ->take(5)
            ->map(function (array $theme): array {
                return [
                    'name' => $theme['name'] ?? null,
                    'slug' => $theme['slug'] ?? null,
                    'heat_score' => $theme['heat_score'] ?? null,
                    'score_date' => $theme['score_date'] ?? null,
                    'scores' => [
                        'news' => data_get($theme, 'scores.news'),
                        'price' => data_get($theme, 'scores.price'),
                        'volume' => data_get($theme, 'scores.volume'),
                        'chip' => data_get($theme, 'scores.chip'),
                    ],
                    'status' => $theme['status'] ?? null,
                    'warming_reasons' => array_slice((array) ($theme['warming_reasons'] ?? []), 0, 4),
                    'risk_flags' => array_slice((array) ($theme['risk_flags'] ?? []), 0, 4),
                    'representative_stocks' => collect($theme['representative_stocks'] ?? [])
                    ->take(2)
                    ->map(function (array $stock): array {
                        return [
                            'symbol' => $stock['symbol'] ?? null,
                            'name' => $stock['name'] ?? null,
                            'trade_date' => $stock['trade_date'] ?? null,
                            'close' => $stock['close'] ?? null,
                            'change' => $stock['change'] ?? null,
                            'change_pct' => $stock['change_pct'] ?? null,
                            'confidence_score' => $stock['confidence_score'] ?? null,
                            'module_scores' => $stock['module_scores'] ?? [],
                            'technical' => [
                                'rsi14' => data_get($stock, 'technical.rsi14'),
                                'macd' => data_get($stock, 'technical.macd'),
                                'macd_signal' => data_get($stock, 'technical.macd_signal'),
                                'volume_ratio20' => data_get($stock, 'technical.volume_ratio20'),
                                'bais20' => data_get($stock, 'technical.bais20'),
                                'signals' => array_slice((array) data_get($stock, 'technical.signals', []), 0, 4),
                                'risk_flags' => array_slice((array) data_get($stock, 'technical.risk_flags', []), 0, 4),
                            ],
                            'chip' => $stock['chip'] ?? [],
                            'revenue' => $stock['revenue'] ?? [],
                            'financial' => $stock['financial'] ?? [],
                        ];
                    })
                    ->values()
                    ->all(),
                ];
            })
            ->values()
            ->all();

        $dataPack['fallback_note'] = 'Gemini unavailable; this is a compact Groq fallback data pack.';

        return $dataPack;
    }

    private function dataPack(string $reportDate): array
    {
        $themes = DB::table('themes')
            ->leftJoin('theme_scores', function ($join) {
                $join->on('themes.id', '=', 'theme_scores.theme_id')
                    ->whereRaw('theme_scores.score_date = (select max(ts.score_date) from theme_scores ts where ts.theme_id = themes.id)');
            })
            ->where('themes.is_active', true)
            ->whereNotNull('theme_scores.heat_score')
            ->orderByDesc('theme_scores.heat_score')
            ->limit(10)
            ->get([
                'themes.id',
                'themes.name',
                'themes.slug',
                'themes.description',
                'theme_scores.score_date',
                'theme_scores.heat_score',
                'theme_scores.news_score',
                'theme_scores.price_score',
                'theme_scores.volume_score',
                'theme_scores.chip_score',
                'theme_scores.payload',
            ])
            ->map(fn ($theme) => $this->themePack($theme))
            ->values()
            ->all();

        return [
            'report_date' => $reportDate,
            'timezone' => 'Asia/Taipei',
            'generated_for' => 'theme_radar_premarket',
            'global_market' => $this->latestGlobalMarkets(),
            'taifex_night' => $this->latestGlobalIndicator('TAIFEX TX Night'),
            'event_clusters' => $this->eventClusters(),
            'recent_news_events' => $this->recentEvents(),
            'themes' => $themes,
            'rules' => [
                'do_not_predict_prices',
                'do_not_give_buy_sell_recommendations',
                'base_every_claim_on_data_pack',
                'if_data_is_missing_say_it_is_missing',
                'major_news_must_include_who_what_when_where_item',
            ],
        ];
    }

    private function themePack(object $theme): array
    {
        $payload = $this->json($theme->payload);

        $representativeStocks = DB::table('stock_theme_map')
            ->join('stocks', 'stocks.id', '=', 'stock_theme_map.stock_id')
            ->leftJoin('stock_scores', function ($join) {
                $join->on('stocks.id', '=', 'stock_scores.stock_id')
                    ->whereRaw('stock_scores.score_date = (select max(ss.score_date) from stock_scores ss where ss.stock_id = stocks.id)');
            })
            ->leftJoin('stock_prices_1d', function ($join) {
                $join->on('stocks.id', '=', 'stock_prices_1d.stock_id')
                    ->whereRaw('stock_prices_1d.trade_date = (select max(sp.trade_date) from stock_prices_1d sp where sp.stock_id = stocks.id)');
            })
            ->leftJoin('stock_technical_indicators_1d', function ($join) {
                $join->on('stocks.id', '=', 'stock_technical_indicators_1d.stock_id')
                    ->whereRaw('stock_technical_indicators_1d.trade_date = (select max(st.trade_date) from stock_technical_indicators_1d st where st.stock_id = stocks.id)');
            })
            ->leftJoin('stock_chips_1d', function ($join) {
                $join->on('stocks.id', '=', 'stock_chips_1d.stock_id')
                    ->whereRaw('stock_chips_1d.trade_date = (select max(sc.trade_date) from stock_chips_1d sc where sc.stock_id = stocks.id)');
            })
            ->leftJoin('stock_revenues', function ($join) {
                $join->on('stocks.id', '=', 'stock_revenues.stock_id')
                    ->whereRaw('stock_revenues.year_month = (select max(sr.year_month) from stock_revenues sr where sr.stock_id = stocks.id)');
            })
            ->leftJoin('stock_financials', function ($join) {
                $join->on('stocks.id', '=', 'stock_financials.stock_id')
                    ->whereRaw('stock_financials.period = (select max(sf.period) from stock_financials sf where sf.stock_id = stocks.id)');
            })
            ->where('stock_theme_map.theme_id', $theme->id)
            ->orderByDesc('stock_theme_map.weight')
            ->orderByDesc('stock_scores.confidence_score')
            ->limit(5)
            ->get([
                'stocks.symbol',
                'stocks.name',
                'stock_theme_map.weight',
                'stock_prices_1d.trade_date',
                'stock_prices_1d.close',
                'stock_prices_1d.change',
                'stock_prices_1d.change_pct',
                'stock_prices_1d.volume',
                'stock_scores.confidence_score',
                'stock_scores.technical_score',
                'stock_scores.chip_score',
                'stock_scores.fundamental_score',
                'stock_scores.theme_score',
                'stock_technical_indicators_1d.sma5',
                'stock_technical_indicators_1d.sma10',
                'stock_technical_indicators_1d.sma20',
                'stock_technical_indicators_1d.sma60',
                'stock_technical_indicators_1d.rsi14',
                'stock_technical_indicators_1d.macd',
                'stock_technical_indicators_1d.macd_signal',
                'stock_technical_indicators_1d.macd_histogram',
                'stock_technical_indicators_1d.k9',
                'stock_technical_indicators_1d.d9',
                'stock_technical_indicators_1d.volume_ratio20',
                'stock_technical_indicators_1d.bais20',
                'stock_technical_indicators_1d.signals',
                'stock_technical_indicators_1d.risk_flags',
                'stock_chips_1d.foreign_net_buy',
                'stock_chips_1d.investment_trust_net_buy',
                'stock_chips_1d.dealer_net_buy',
                'stock_chips_1d.institutional_net_buy',
                'stock_revenues.revenue',
                'stock_revenues.mom_pct',
                'stock_revenues.yoy_pct',
                'stock_financials.per',
                'stock_financials.eps',
                'stock_financials.roe',
            ])
            ->map(fn ($stock) => [
                'symbol' => $stock->symbol,
                'name' => $stock->name,
                'theme_weight' => (int) ($stock->weight ?? 0),
                'trade_date' => $stock->trade_date,
                'close' => $this->float($stock->close),
                'change' => $this->float($stock->change),
                'change_pct' => $this->float($stock->change_pct),
                'volume' => $stock->volume === null ? null : (int) $stock->volume,
                'confidence_score' => $this->int($stock->confidence_score),
                'module_scores' => [
                    'technical' => $this->int($stock->technical_score),
                    'chip' => $this->int($stock->chip_score),
                    'fundamental' => $this->int($stock->fundamental_score),
                    'theme' => $this->int($stock->theme_score),
                ],
                'technical' => [
                    'sma5' => $this->float($stock->sma5),
                    'sma10' => $this->float($stock->sma10),
                    'sma20' => $this->float($stock->sma20),
                    'sma60' => $this->float($stock->sma60),
                    'rsi14' => $this->float($stock->rsi14),
                    'macd' => $this->float($stock->macd),
                    'macd_signal' => $this->float($stock->macd_signal),
                    'macd_histogram' => $this->float($stock->macd_histogram),
                    'k9' => $this->float($stock->k9),
                    'd9' => $this->float($stock->d9),
                    'volume_ratio20' => $this->float($stock->volume_ratio20),
                    'bais20' => $this->float($stock->bais20),
                    'signals' => $this->json($stock->signals),
                    'risk_flags' => $this->json($stock->risk_flags),
                ],
                'chip' => [
                    'foreign_net_buy' => $this->int($stock->foreign_net_buy),
                    'investment_trust_net_buy' => $this->int($stock->investment_trust_net_buy),
                    'dealer_net_buy' => $this->int($stock->dealer_net_buy),
                    'institutional_net_buy' => $this->int($stock->institutional_net_buy),
                ],
                'fundamental' => [
                    'monthly_revenue_mom_pct' => $this->float($stock->mom_pct),
                    'monthly_revenue_yoy_pct' => $this->float($stock->yoy_pct),
                    'per' => $this->float($stock->per),
                    'eps' => $this->float($stock->eps),
                    'roe' => $this->float($stock->roe),
                ],
            ])
            ->values()
            ->all();

        $events = DB::table('theme_event_matches')
            ->leftJoin('global_events', 'global_events.id', '=', 'theme_event_matches.global_event_id')
            ->where('theme_event_matches.theme_id', $theme->id)
            ->where('theme_event_matches.created_at', '>=', CarbonImmutable::now('Asia/Taipei')->subDays(3)->utc())
            ->orderByDesc('theme_event_matches.match_score')
            ->orderByDesc('theme_event_matches.created_at')
            ->limit(5)
            ->get([
                'theme_event_matches.keyword',
                'theme_event_matches.match_score',
                'global_events.event_date',
                'global_events.source',
                'global_events.title',
                'global_events.summary',
                'global_events.category',
                'global_events.region',
                'global_events.impact_direction',
                'global_events.impact_score',
            ])
            ->map(fn ($event) => [
                'event_date' => $event->event_date,
                'source' => $event->source,
                'title' => $event->title,
                'summary' => $event->summary,
                'category' => $event->category,
                'region' => $event->region,
                'impact_direction' => $event->impact_direction,
                'impact_score' => $event->impact_score,
                'matched_keyword' => $event->keyword,
                'match_score' => $event->match_score,
            ])
            ->values()
            ->all();

        return [
            'name' => $theme->name,
            'slug' => $theme->slug,
            'description' => $theme->description,
            'score_date' => $theme->score_date,
            'heat_score' => $this->int($theme->heat_score),
            'news_score' => $this->int($theme->news_score),
            'price_score' => $this->int($theme->price_score),
            'volume_score' => $this->int($theme->volume_score),
            'chip_score' => $this->int($theme->chip_score),
            'payload' => [
                'source' => data_get($payload, 'source'),
                'components' => data_get($payload, 'components'),
            ],
            'events' => $events,
            'representative_stocks' => $representativeStocks,
        ];
    }

    private function latestGlobalMarkets(): array
    {
        $wanted = [
            'Dow Jones',
            'S&P 500',
            'NASDAQ',
            'SOX',
            'VIX',
            'TSM ADR',
            'UMC ADR',
            'TAIFEX TX Night',
            'Nikkei 225',
            'Hang Seng',
            'KOSPI',
            'DXY',
            'US10Y',
            'Crude Oil',
            'Gold',
        ];

        return DB::table('global_market_data as g')
            ->joinSub(
                DB::table('global_market_data')
                    ->selectRaw('indicator, max(trade_date) as latest_date')
                    ->whereIn('indicator', $wanted)
                    ->groupBy('indicator'),
                'latest',
                function ($join) {
                    $join->on('g.indicator', '=', 'latest.indicator')
                        ->on('g.trade_date', '=', 'latest.latest_date');
                }
            )
            ->whereIn('g.indicator', $wanted)
            ->orderByRaw("array_position(array['".implode("','", $wanted)."'], g.indicator)")
            ->get(['g.indicator', 'g.trade_date', 'g.value', 'g.change_pct', 'g.state', 'g.source', 'g.updated_at'])
            ->map(fn ($row) => [
                'indicator' => $row->indicator,
                'trade_date' => $row->trade_date,
                'value' => $this->float($row->value),
                'change_pct' => $this->float($row->change_pct),
                'state' => $row->state,
                'source' => $row->source,
                'updated_at' => $row->updated_at,
            ])
            ->values()
            ->all();
    }

    private function latestGlobalIndicator(string $indicator): ?array
    {
        $row = DB::table('global_market_data')
            ->where('indicator', $indicator)
            ->orderByDesc('trade_date')
            ->first(['indicator', 'trade_date', 'value', 'change_pct', 'state', 'source', 'updated_at']);

        if (! $row) {
            return null;
        }

        return [
            'indicator' => $row->indicator,
            'trade_date' => $row->trade_date,
            'value' => $this->float($row->value),
            'change_pct' => $this->float($row->change_pct),
            'state' => $row->state,
            'source' => $row->source,
            'updated_at' => $row->updated_at,
        ];
    }

    private function eventClusters(): array
    {
        return DB::table('global_event_clusters')
            ->orderByDesc('cluster_date')
            ->orderByDesc('importance_score')
            ->limit(6)
            ->get()
            ->map(function ($row) {
                $aiPayload = $this->json($row->ai_payload);

                return [
                    'cluster_date' => $row->cluster_date,
                    'title' => data_get($aiPayload, 'title') ?: $row->title,
                    'summary' => data_get($aiPayload, 'summary') ?: $row->summary,
                    'category' => $row->category,
                    'region' => $row->region,
                    'sentiment' => $row->sentiment,
                    'importance_score' => $row->importance_score,
                    'themes' => $this->json($row->themes),
                ];
            })
            ->values()
            ->all();
    }

    private function recentEvents(): array
    {
        return DB::table('global_events')
            ->where('event_date', '>=', CarbonImmutable::now('Asia/Taipei')->subDay()->utc())
            ->orderByDesc('event_date')
            ->limit(10)
            ->get(['event_date', 'source', 'title', 'summary', 'category', 'region', 'impact_direction', 'impact_score'])
            ->map(fn ($row) => [
                'event_date' => $row->event_date,
                'source' => $row->source,
                'title' => $row->title,
                'summary' => $row->summary,
                'category' => $row->category,
                'region' => $row->region,
                'impact_direction' => $row->impact_direction,
                'impact_score' => $row->impact_score,
            ])
            ->values()
            ->all();
    }

    private function prompt(array $dataPack): string
    {
        return implode("\n", [
            '你是《股市在幹嘛》的台股題材盤前研究員，請用繁體中文寫一份「題材盤前觀察」。',
            '固定標題由前端顯示，正文不要再輸出總標題，不要使用 Markdown 粗體符號，不要使用星號項目符號。',
            '',
            '核心原則：',
            '1. 所有判斷都必須來自 Data Pack。沒有資料就明確說「目前資料不足」，不要腦補。',
            '2. 題材熱度要同時參考全球新聞、台灣新聞、事件聚合、台股代表股走勢、美股相關指數或 ADR、台股夜盤。',
            '3. 升溫與降溫必須看代表股股價與量價狀態，不能明明還在上漲卻直接說降溫。',
            '4. 資金輪動可以提出「可能流向」或「今日可觀察」，但必須說明依據，不能保證。',
            '5. 代表股觀察每檔都要寫出事件、股價、技術、籌碼、新聞或國際連動中的具體依據；依據不足就說需觀察，不要寫可望延續攻勢。',
            '6. 重大新聞或事件必須寫出人、事、時、地、物，讓使用者知道真的有這件事。',
            '7. 風險提醒不能只靠單一技術或籌碼，要綜合題材過熱、新聞利多鈍化、夜盤、美股同族群、代表股擴散度與法人延續性。',
            '8. 不要給買進、賣出、目標價，也不要說明系統計分公式。',
            '',
            '請依照下列 7 段輸出，每段內容要詳盡但不要空泛：',
            '1、今日題材總覽：說明今日題材主軸、集中或輪動狀態，並引用全球市場、台股夜盤或新聞依據。',
            '2、升溫題材：列出真正升溫的題材，說明升溫原因與確認條件。',
            '3、降溫題材：列出轉弱或熱度放緩的題材；若只是新聞少但股價仍強，要寫成「熱度放緩但尚未轉弱」。',
            '4、資金輪動：說明昨日台股盤後資料、代表股強弱、美股/ADR/夜盤可能指向的資金流向。',
            '5、代表股觀察：列 3 到 6 檔，格式用「股票：原因」。原因要包含具體事件或資料依據，例如昨晚誰發生什麼事、哪個市場、影響什麼題材。',
            '6、風險提醒：列出今日題材面需要防守的條件，必須有多重依據。',
            '7、今日可關注方向：先說昨晚或盤後發生什麼事，再說可關注哪些題材與代表股，以及為什麼。',
            '',
            'Data Pack:',
            json_encode($dataPack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]);
    }

    private function cleanReportText(string $text): string
    {
        $text = trim(str_replace(['**', '__'], '', $text));

        $lines = preg_split('/\R/u', $text) ?: [];
        $lines = array_values(array_filter($lines, function (string $line, int $index): bool {
            $normalized = trim($line);

            if ($index <= 2 && in_array($normalized, [
                '《股市在幹嘛》今日題材盤前觀察',
                '今日題材盤前觀察',
                '題材盤前觀察',
            ], true)) {
                return false;
            }

            return true;
        }, ARRAY_FILTER_USE_BOTH));

        return trim(implode("\n", $lines));
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function float(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
