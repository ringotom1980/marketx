<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Support\Ai\AiPipelineService;
use App\Support\Ai\AiReportValidator;
use App\Support\Ai\AiUsageLimiter;
use App\Support\Ai\GeminiProvider;
use App\Support\Ai\StockDataPackBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiGenerateStockReports extends Command
{
    protected $signature = 'market:ai-generate-stock-reports
        {--symbol= : Generate for one stock symbol}
        {--watchlist : Only generate reports for stocks in the watchlist}
        {--limit=5 : Max stocks when symbol is not provided}
        {--live : Actually call Gemini. Without this option, only builds prompts and logs skipped results}';

    protected $description = 'Use Gemini as the AI v3 stock research layer to generate four-part cached stock reports.';

    public function handle(
        GeminiProvider $gemini,
        AiPipelineService $pipeline,
        AiUsageLimiter $limiter,
        StockDataPackBuilder $builder,
        AiReportValidator $validator,
    ): int {
        $task = 'stock_research';
        $live = (bool) $this->option('live');
        $reportDate = CarbonImmutable::now('Asia/Taipei')->toDateString();
        $generated = 0;
        $skipped = 0;

        $stocks = Stock::query()
            ->with(['latestScore', 'latestChip'])
            ->whereHas('latestScore', fn ($query) => $query->whereNotNull('total_score'))
            ->when($this->option('symbol'), fn ($query, $symbol) => $query->where('symbol', $symbol))
            ->when($this->option('watchlist'), function ($query) {
                $query->whereIn('stocks.id', DB::table('watchlist')
                    ->whereNull('user_id')
                    ->select('stock_id'));
            })
            ->orderByDesc(
                DB::raw('(select total_score from stock_scores where stock_scores.stock_id = stocks.id order by score_date desc limit 1)'),
            )
            ->limit($this->option('symbol') ? 1 : max(1, min(50, (int) $this->option('limit'))))
            ->get();

        foreach ($stocks as $stock) {
            if (! $limiter->canRun($task)) {
                $this->warn('Daily AI limit reached for '.$task);
                break;
            }

            $alreadyExists = DB::table('stock_reports')
                ->where('stock_id', $stock->id)
                ->where('report_date', $reportDate)
                ->where('model', 'like', 'gemini:%')
                ->exists();

            if ($alreadyExists) {
                $skipped++;
                continue;
            }

            $dataPack = $builder->build($stock);
            $prompt = $this->prompt($dataPack);
            $result = $gemini->generate($prompt, $live);
            $pipeline->log($task, $result, $prompt);

            if (! $result->ok) {
                $this->warn($stock->symbol.' skipped/failed: '.$result->error);
                $skipped++;
                continue;
            }

            $validation = $validator->validateFourPartReport($result->text);

            if (! $validation['ok']) {
                $this->warn($stock->symbol.' invalid report: '.$validation['error']);
                $skipped++;
                continue;
            }

            DB::table('stock_reports')->updateOrInsert(
                ['stock_id' => $stock->id, 'report_date' => $reportDate],
                [
                    'decision' => data_get($dataPack, 'base_scores.decision'),
                    'summary' => $result->text,
                    'bull_case' => null,
                    'bear_case' => null,
                    'risk_summary' => null,
                    'data_pack' => json_encode($dataPack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'model' => 'gemini:'.$result->model,
                    'token_usage' => json_encode($result->usage, JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $generated++;
            sleep(5);
        }

        $this->info('Gemini stock reports generated: '.$generated);
        $this->line('Skipped: '.$skipped);

        return self::SUCCESS;
    }

    private function prompt(array $dataPack): string
    {
        return implode("\n", [
            '你是《股市在幹嘛》的 Gemini 研究解讀層。',
            '你只能做市場狀態解讀、事件脈絡整理、個股研究報告。',
            '禁止預測價格，禁止明牌，禁止即時交易建議。',
            '請用一般投資人看得懂的白話，但保持專業、精簡、可執行。',
            '請使用繁體中文，固定輸出以下四段標題：',
            '1｜當前階段判定',
            '2｜關鍵依據',
            '3｜觀察重點',
            '4｜失效條件',
            '關鍵依據 3 到 5 條，觀察重點固定 3 條，失效條件 2 到 3 條。',
            '請優先解釋分數、技術訊號、籌碼、財務、題材與事件鏈之間的關係。',
            'Data Pack:',
            json_encode($dataPack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]);
    }
}
