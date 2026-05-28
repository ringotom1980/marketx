<?php

namespace App\Console\Commands;

use App\Support\Ai\AiPipelineService;
use App\Support\Ai\AiUsageLimiter;
use App\Support\Ai\AiResult;
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

        if (! $this->option('force') && ! $limiter->canRun($task)) {
            $this->warn('Daily AI limit reached for '.$task);
            return self::SUCCESS;
        }

        $dataPack = $this->dataPack($reportDate);
        $prompt = $this->prompt($dataPack);
        $result = $this->generateWithRetry($gemini, $prompt);

        $pipeline->log($task, $result, $prompt);

        if (! $result->ok) {
            $this->warn('Gemini global premarket skipped/failed: '.$result->error);
            return self::FAILURE;
        }

        $text = $this->cleanReportText((string) $result->text);

        if (mb_strlen($text) < 80) {
            $this->warn('Gemini global premarket report too short.');
            return self::FAILURE;
        }

        DB::table('global_ai_reports')->updateOrInsert(
            ['report_date' => $reportDate],
            [
                'title' => '《股市在幹嘛》今日全球盤前觀察',
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
            '輸出格式，請固定使用以下 8 個段落標題，不要更名：',
            '不要另外輸出總標題，因為網站卡片已經有固定標題。',
            '不要使用 Markdown 粗體符號，例如 **文字**。請使用純文字。',
            '1、全球股市重點：整理美股、半導體、ADR、亞洲主要股市的實際漲跌與市場含意，至少 4 句。',
            '2、匯率利率：整理美元指數、美國 10 年債等資金壓力訊號，至少 3 句。',
            '3、原物料及貴金屬：整理原油、黃金等商品訊號及可能影響，至少 3 句。',
            '4、重大事件：只寫真正重要且可能影響股市的事件。每一件事都要交代人、事、時、地、物；如果 Data Pack 沒有足夠重要事件，請寫「目前資料中未見足以主導股市方向的重大事件」。',
            '5、新聞時事：整理可能影響市場心理、產業鏈或資金流向的新聞。每則新聞要交代人、事、時、地、物；不要寫娛樂、產品推廣、與金融市場關聯薄弱的消息。',
            '6、縱觀全球：綜合股市、匯率、利率、原物料、ADR 與事件，說明今天台股盤前的整體外部環境，至少 4 句。',
            '7、注意類股：列出台股今日較需要留意風險或波動的類股，並說明依據。',
            '8、可關注的族群：列出今日可觀察的族群或題材，不要說買進，只說觀察理由與確認條件。',
            '',
            '內容要求：',
            '- 每一段都要詳盡、有依據，不要只寫一句話。',
            '- 重大事件與新聞時事寧缺勿濫，只能引用 Data Pack 內的重要事件或新聞。',
            '- 若要提到特定市場或指標，必須附上方向或數據，例如上漲/下跌、漲跌幅或壓力升降。',
            '- 「注意類股」與「可關注的族群」要明確說出為什麼，不可只列名稱。',
            '',
            'Data Pack:',
            json_encode($dataPack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]);
    }

    private function cleanReportText(string $text): string
    {
        $text = trim($text);
        $text = str_replace(['**', '__'], '', $text);

        $lines = preg_split('/\R/u', $text) ?: [];
        $lines = array_values(array_filter($lines, function (string $line, int $index): bool {
            $normalized = trim($line);

            if ($index <= 2 && in_array($normalized, [
                '今日全球盤前觀察',
                '股市在幹嘛今日全球盤前觀察',
                '《股市在幹嘛》今日全球盤前觀察',
                '一、今日全球盤前觀察',
            ], true)) {
                return false;
            }

            return true;
        }, ARRAY_FILTER_USE_BOTH));

        return trim(implode("\n", $lines));
    }
}
