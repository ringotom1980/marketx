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
        {--batch=1 : Themes per Groq call}
        {--live : Actually call Groq. Without this option, only logs skipped results}
        {--force : Regenerate summaries even when a cached summary exists}
        {--slug=* : Only summarize specific theme slug(s)}';

    protected $description = 'Use Groq to generate cached plain-language theme status summaries.';

    public function handle(GroqProvider $groq, AiPipelineService $pipeline, AiUsageLimiter $limiter): int
    {
        $task = 'theme_summary';
        $scoreDate = $this->option('date') ?: CarbonImmutable::now('Asia/Taipei')->toDateString();
        $batchSize = max(1, min(1, (int) $this->option('batch')));
        $live = (bool) $this->option('live');
        $force = (bool) $this->option('force');

        if (! $limiter->canRun($task)) {
            $this->warn('Daily AI limit reached for '.$task);
            return self::SUCCESS;
        }

        $themes = DB::table('theme_scores')
            ->join('themes', 'themes.id', '=', 'theme_scores.theme_id')
            ->where('themes.is_active', true)
            ->where('theme_scores.score_date', $scoreDate)
            ->whereNotNull('theme_scores.heat_score')
            ->when($this->option('slug') !== [], fn ($query) => $query->whereIn('themes.slug', $this->option('slug')))
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
            ->filter(function ($theme) use ($force) {
                if ($force) {
                    return true;
                }

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

                $status = trim((string) ($row['status_zh'] ?? ''));

                if (! $theme || blank($status) || ! str_contains($status, $theme->name)) {
                    continue;
                }

                $payload = $this->payload($theme->payload);
                $payload['ai_summary'] = [
                    'provider' => 'groq',
                    'model' => $result->model,
                    'status_zh' => $status,
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

            sleep(8);
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
                'stock_scores.technical_score',
                'stock_scores.chip_score',
                'stock_prices_1d.close',
                'stock_prices_1d.change',
                'stock_prices_1d.change_pct',
            ])
            ->map(fn ($stock) => [
                'symbol' => $stock->symbol,
                'name' => $stock->name,
                'confidence' => $stock->confidence_score,
                'technical_score' => $stock->technical_score,
                'chip_score' => $stock->chip_score,
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

        $upCount = collect($stocks)->filter(fn ($stock) => (float) ($stock['change'] ?? 0) > 0)->count();
        $downCount = collect($stocks)->filter(fn ($stock) => (float) ($stock['change'] ?? 0) < 0)->count();
        $flatCount = max(0, count($stocks) - $upCount - $downCount);
        $avgChangePct = collect($stocks)
            ->pluck('change_pct')
            ->filter(fn ($value) => $value !== null)
            ->avg();

        return [
            'slug' => $theme->slug,
            'name' => $theme->name,
            'heat_score' => $theme->heat_score,
            'news_score' => $theme->news_score,
            'price_score' => $theme->price_score,
            'volume_score' => $theme->volume_score,
            'chip_score' => $theme->chip_score,
            'stock_breadth' => [
                'up' => $upCount,
                'down' => $downCount,
                'flat' => $flatCount,
                'avg_change_pct' => $avgChangePct === null ? null : round((float) $avgChangePct, 2),
            ],
            'top_stocks' => $stocks,
            'recent_events' => $events,
        ];
    }

    private function prompt(string $scoreDate, array $themes): string
    {
        return implode("\n", [
            '你是《股市在幹嘛》的台股題材研究助理，請用繁體中文寫給一般投資人看的題材狀態。',
            '任務：根據題材熱度、代表股票今日漲跌、技術/籌碼分數與新聞事件，寫出真正有資訊量的「目前狀態」。',
            '限制：不要預測價格，不要給買賣建議，不要說明計分公式，不要使用簡體中文。',
            'status_zh 固定 3 句，總長 110 到 180 字。',
            '第 1 句：說這個題材今天偏強、偏弱或觀察，並指出主要來源。',
            '第 2 句：說代表股票今天上漲或下跌可能跟什麼資料有關，必須提到 1 到 3 檔輸入中的股票名稱。',
            '第 3 句：說一個具體風險或觀察條件，例如法人是否延續、族群是否擴散、是否只有少數股票撐場。',
            'price_reason_zh 可以留空字串，因為股價原因要寫進 status_zh。',
            '禁止使用這些空話或怪詞：行業熱度高漲、全球需求增加、新技術發展、技術指標下滑、籌碼調整、可能與昨日有關、高企、分數表現出色、技術和籌碼分數。',
            '如果要描述分數，只能翻成自然台股語言，例如：量價轉強、代表股偏強、法人偏正向、族群漲跌不一致。',
            '如果 recent_events 是空的，不要提新聞；如果 recent_events 有資料，也只能提輸入裡的事件重點。',
            '不要每個題材都寫成同一個格式；必須依照該題材名稱、代表股、新聞或漲跌廣度調整內容。',
            '語氣要求：像研究員簡短說明，不要像罐頭模板。必須使用輸入中的題材名稱與股票名稱，不能複製其他題材的內容。',
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
