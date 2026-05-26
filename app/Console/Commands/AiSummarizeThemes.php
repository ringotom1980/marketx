<?php

namespace App\Console\Commands;

use App\Support\Ai\AiPipelineService;
use App\Support\Ai\AiUsageLimiter;
use App\Support\Ai\GroqProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiSummarizeThemes extends Command
{
    protected $signature = 'market:ai-summarize-themes
        {--date= : Theme score date, default today}
        {--batch=2 : Themes per Groq call}
        {--live : Actually call Groq. Without this option, only logs skipped results}';

    protected $description = 'Use Groq to generate cached plain-language theme status summaries.';

    public function handle(GroqProvider $groq, AiPipelineService $pipeline, AiUsageLimiter $limiter): int
    {
        $task = 'theme_summary';
        $scoreDate = $this->option('date') ?: CarbonImmutable::now('Asia/Taipei')->toDateString();
        $batchSize = max(1, min(2, (int) $this->option('batch')));
        $live = (bool) $this->option('live');

        if (! $limiter->canRun($task)) {
            $this->warn('Daily AI limit reached for '.$task);
            return self::SUCCESS;
        }

        $themes = DB::table('theme_scores')
            ->join('themes', 'themes.id', '=', 'theme_scores.theme_id')
            ->where('themes.is_active', true)
            ->where('theme_scores.score_date', $scoreDate)
            ->whereNotNull('theme_scores.heat_score')
            ->orderByDesc('theme_scores.heat_score')
            ->get([
                'theme_scores.id as score_id',
                'theme_scores.theme_id',
                'themes.slug',
                'themes.name',
                'theme_scores.heat_score',
                'theme_scores.news_score',
                'theme_scores.price_score',
                'theme_scores.volume_score',
                'theme_scores.chip_score',
                'theme_scores.payload',
            ])
            ->filter(function ($theme) {
                $payload = $this->payload($theme->payload);

                return blank(data_get($payload, 'ai_summary.status_zh'));
            })
            ->values();

        if ($themes->isEmpty()) {
            $this->info('Theme AI summaries already cached or no theme scores available.');
            return self::SUCCESS;
        }

        $updated = 0;

        foreach ($themes->chunk($batchSize) as $batch) {
            if (! $limiter->canRun($task)) {
                $this->warn('Daily AI limit reached during batching.');
                break;
            }

            $dataPack = $batch->map(fn ($theme) => $this->themePack($theme))->values()->all();
            $prompt = $this->prompt($scoreDate, $dataPack);
            $result = $groq->chat($prompt, $live);
            $pipeline->log($task, $result, $prompt);

            if (! $result->ok) {
                $this->warn('Groq theme summary skipped/failed: '.$result->error);
                continue;
            }

            $rows = $this->decodeJsonArray((string) $result->text);

            foreach ($rows as $row) {
                $slug = (string) ($row['slug'] ?? '');
                $theme = $batch->firstWhere('slug', $slug);

                if (! $theme || blank($row['status_zh'] ?? null)) {
                    continue;
                }

                $payload = $this->payload($theme->payload);
                $payload['ai_summary'] = [
                    'provider' => 'groq',
                    'model' => $result->model,
                    'status_zh' => trim((string) $row['status_zh']),
                    'price_reason_zh' => trim((string) ($row['price_reason_zh'] ?? '')),
                    'generated_at' => now()->toIso8601String(),
                ];

                DB::table('theme_scores')
                    ->where('id', $theme->score_id)
                    ->update([
                        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);

                $updated++;
            }

            sleep(12);
        }

        $this->info('Theme AI summaries updated: '.$updated);

        return self::SUCCESS;
    }

    private function themePack(object $theme): array
    {
        $stocks = DB::table('stock_theme_map')
            ->join('stocks', 'stocks.id', '=', 'stock_theme_map.stock_id')
            ->leftJoin('stock_scores', function ($join) {
                $join->on('stocks.id', '=', 'stock_scores.stock_id')
                    ->whereRaw('stock_scores.score_date = (select max(ss.score_date) from stock_scores ss where ss.stock_id = stocks.id)');
            })
            ->leftJoin('stock_prices_1d', function ($join) {
                $join->on('stocks.id', '=', 'stock_prices_1d.stock_id')
                    ->whereRaw('stock_prices_1d.trade_date = (select max(sp.trade_date) from stock_prices_1d sp where sp.stock_id = stocks.id)');
            })
            ->where('stock_theme_map.theme_id', $theme->theme_id)
            ->orderByDesc('stock_scores.confidence_score')
            ->limit(4)
            ->get([
                'stocks.symbol',
                'stocks.name',
                'stock_scores.confidence_score',
                'stock_prices_1d.close',
                'stock_prices_1d.change',
                'stock_prices_1d.change_pct',
            ])
            ->map(fn ($stock) => [
                'symbol' => $stock->symbol,
                'name' => $stock->name,
                'confidence' => $stock->confidence_score,
                'close' => $stock->close,
                'change' => $stock->change,
                'change_pct' => $stock->change_pct,
            ])
            ->values()
            ->all();

        $events = DB::table('theme_event_matches')
            ->leftJoin('global_events', 'global_events.id', '=', 'theme_event_matches.global_event_id')
            ->where('theme_event_matches.theme_id', $theme->theme_id)
            ->orderByDesc('theme_event_matches.created_at')
            ->limit(2)
            ->get(['global_events.title', 'global_events.summary', 'global_events.region', 'theme_event_matches.match_score'])
            ->map(fn ($event) => [
                'title' => $event->title,
                'summary' => $event->summary,
                'region' => $event->region,
                'match_score' => $event->match_score,
            ])
            ->values()
            ->all();

        return [
            'slug' => $theme->slug,
            'name' => $theme->name,
            'heat_score' => $theme->heat_score,
            'news_score' => $theme->news_score,
            'price_score' => $theme->price_score,
            'volume_score' => $theme->volume_score,
            'chip_score' => $theme->chip_score,
            'top_stocks' => $stocks,
            'recent_events' => $events,
        ];
    }

    private function prompt(string $scoreDate, array $themes): string
    {
        return implode("\n", [
            '你是《股市在幹嘛》的 Groq 題材摘要層。',
            '任務：根據題材分數、代表股票今日漲跌與新聞事件，寫出白話的「目前狀態」與「今天股價漲跌原因」。',
            '限制：不要預測價格，不要給買賣建議，不要說明計分公式，不要使用簡體中文。',
            '每個題材 status_zh 只寫 1 句，50 字以內。',
            'price_reason_zh 只寫 1 句，40 字以內。',
            '只能根據輸入資料，不要捏造沒有出現的公司、事件或數字。',
            '請輸出 JSON array，每筆包含 slug, status_zh, price_reason_zh。',
            '只輸出 JSON，不要 markdown，不要補充說明。',
            '日期：'.$scoreDate,
            '題材資料：',
            json_encode($themes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function decodeJsonArray(string $text): array
    {
        $clean = trim($text);
        $clean = preg_replace('/^```json\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/^```\s*/', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
        $clean = trim($clean);

        $decoded = json_decode($clean, true);

        if (is_array($decoded)) {
            return array_is_list($decoded) ? $decoded : [$decoded];
        }

        if (preg_match('/(\[[\s\S]*\])/', $text, $matches) === 1) {
            $decoded = json_decode($matches[1], true);

            if (is_array($decoded)) {
                return array_is_list($decoded) ? $decoded : [$decoded];
            }
        }

        return [];
    }

    private function payload(mixed $payload): array
    {
        if (is_string($payload)) {
            return json_decode($payload, true) ?: [];
        }

        return (array) ($payload ?? []);
    }
}
