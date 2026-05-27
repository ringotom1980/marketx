<?php

namespace App\Console\Commands;

use App\Support\Ai\AiPipelineService;
use App\Support\Ai\AiUsageLimiter;
use App\Support\Ai\GeminiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiGenerateGlobalPremarketReport extends Command
{
    protected $signature = 'market:ai-generate-global-premarket
        {--date= : Report date, default today in Asia/Taipei}
        {--force : Regenerate even if today report exists}
        {--live : Actually call Gemini. Without this option only logs a skipped result}';

    protected $description = 'Generate one cached Gemini global premarket report for the global radar page.';

    public function handle(GeminiProvider $gemini, AiPipelineService $pipeline, AiUsageLimiter $limiter): int
    {
        $task = 'global_premarket';
        $reportDate = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), 'Asia/Taipei')->toDateString()
            : CarbonImmutable::now('Asia/Taipei')->toDateString();

        if (! $this->option('force') && DB::table('global_ai_reports')->where('report_date', $reportDate)->exists()) {
            $this->info('Global premarket report already exists: '.$reportDate);
            return self::SUCCESS;
        }

        if (! $limiter->canRun($task)) {
            $this->warn('Daily AI limit reached for '.$task);
            return self::SUCCESS;
        }

        $dataPack = $this->dataPack($reportDate);
        $prompt = $this->prompt($dataPack);
        $result = $gemini->generate($prompt, (bool) $this->option('live'));

        $pipeline->log($task, $result, $prompt);

        if (! $result->ok) {
            $this->warn('Gemini global premarket skipped/failed: '.$result->error);
            return self::FAILURE;
        }

        $text = trim((string) $result->text);

        if (mb_strlen($text) < 80) {
            $this->warn('Gemini global premarket report too short.');
            return self::FAILURE;
        }

        DB::table('global_ai_reports')->updateOrInsert(
            ['report_date' => $reportDate],
            [
                'title' => '今日全球盤前觀察',
                'summary' => $text,
                'data_pack' => json_encode($dataPack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'model' => 'gemini:'.$result->model,
                'token_usage' => json_encode($result->usage, JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->info('Gemini global premarket report generated: '.$reportDate);

        return self::SUCCESS;
    }

    private function dataPack(string $reportDate): array
    {
        $markets = DB::table('global_market_data as g')
            ->joinSub(
                DB::table('global_market_data')
                    ->selectRaw('indicator, max(trade_date) as latest_date')
                    ->groupBy('indicator'),
                'latest',
                function ($join) {
                    $join->on('g.indicator', '=', 'latest.indicator')
                        ->on('g.trade_date', '=', 'latest.latest_date');
                }
            )
            ->select('g.indicator', 'g.trade_date', 'g.value', 'g.change_pct', 'g.state', 'g.source', 'g.updated_at')
            ->orderBy('g.indicator')
            ->get()
            ->map(fn ($row) => [
                'indicator' => $row->indicator,
                'trade_date' => $row->trade_date,
                'value' => $row->value === null ? null : (float) $row->value,
                'change_pct' => $row->change_pct === null ? null : (float) $row->change_pct,
                'state' => $row->state,
                'source' => $row->source,
                'updated_at' => $row->updated_at,
            ])
            ->values()
            ->all();

        $clusters = DB::table('global_event_clusters')
            ->orderByDesc('cluster_date')
            ->orderByDesc('importance_score')
            ->limit(8)
            ->get()
            ->map(function ($row) {
                $aiPayload = $row->ai_payload ? json_decode($row->ai_payload, true) : [];

                return [
                    'cluster_date' => $row->cluster_date,
                    'title' => data_get($aiPayload, 'title') ?: $row->title,
                    'summary' => data_get($aiPayload, 'summary') ?: $row->summary,
                    'category' => $row->category,
                    'region' => $row->region,
                    'sentiment' => $row->sentiment,
                    'importance_score' => $row->importance_score,
                    'themes' => $row->themes ? json_decode($row->themes, true) : [],
                ];
            })
            ->values()
            ->all();

        $rawEvents = DB::table('global_events')
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

        return [
            'report_date' => $reportDate,
            'timezone' => 'Asia/Taipei',
            'generated_for' => '台股盤前全球雷達',
            'market_data' => $markets,
            'event_clusters' => $clusters,
            'recent_events' => $rawEvents,
            'rules' => [
                'do_not_predict_prices',
                'do_not_give_buy_sell_recommendations',
                'base_every_claim_on_market_data_or_events',
                'if_data_is_missing_say_it_is_missing',
            ],
        ];
    }

    private function prompt(array $dataPack): string
    {
        return implode("\n", [
            '你是《股市在幹嘛》的全球盤前研究員，請用繁體中文撰寫「今日全球盤前觀察」。',
            '目標讀者是台股投資人。你的任務不是預測價格，也不是提供買賣建議，而是根據資料說明全球市場對今日台股的可能影響與注意事項。',
            '',
            '硬性規則：',
            '1. 每個判斷都必須能從 Data Pack 的指數、原物料、匯率、利率、ADR、台指夜盤或事件資料找到依據。',
            '2. 不要使用空泛句，例如「市場仍需觀察」、「投資人宜謹慎」除非後面有明確依據。',
            '3. 不要編造 Data Pack 沒有的新聞、數字、公司或事件。',
            '4. 如果某些資料不足，請直接說「目前資料不足」，不要硬分析。',
            '5. 不要說買進、賣出、強烈買進、目標價或預測點位。',
            '',
            '輸出格式：',
            '一、全球股市重點：3 到 5 句，需提到美股、半導體或亞洲市場中的實際變化。',
            '二、匯率利率與原物料：3 到 5 句，需提到美元、美債、原油、黃金至少兩項。',
            '三、重大事件脈絡：2 到 4 句，根據事件資料整理，不要重複新聞標題。',
            '四、對台股的可能影響：3 到 5 點，每點要有明確理由。',
            '五、今日注意事項：3 點，寫成具體觀察條件。',
            '',
            'Data Pack:',
            json_encode($dataPack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]);
    }
}
