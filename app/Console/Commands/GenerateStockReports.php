<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Support\StockResearchReportComposer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateStockReports extends Command
{
    protected $signature = 'market:generate-stock-reports
        {--limit=0 : Max reports to generate. Use 0 for all scored stocks}
        {--date= : Report date, default today}';

    protected $description = 'Generate data-driven Chinese stock research reports from existing MarketX data.';

    public function handle(StockResearchReportComposer $composer): int
    {
        $reportDate = $this->option('date') ?: CarbonImmutable::now('Asia/Taipei')->toDateString();
        $limit = max(0, (int) $this->option('limit'));
        $generated = 0;

        $query = Stock::query()
            ->with(['latestScore', 'latestChip', 'dailyPrices' => fn ($query) => $query->latest('trade_date')->limit(1)])
            ->whereHas('latestScore', fn ($query) => $query->whereNotNull('total_score'))
            ->orderBy('symbol');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->get()->each(function (Stock $stock) use ($reportDate, &$generated, $composer) {
            $score = $stock->latestScore;
            $chip = $stock->latestChip;
            $price = $stock->dailyPrices->first();
            $revenue = DB::table('stock_revenues')
                ->where('stock_id', $stock->id)
                ->orderByDesc('year_month')
                ->first();

            $report = $composer->compose($stock, $score, $chip, $price, $revenue);

            DB::table('stock_reports')->updateOrInsert(
                ['stock_id' => $stock->id, 'report_date' => $reportDate],
                [
                    'decision' => $score->decision,
                    'summary' => $report['summary'],
                    'bull_case' => $report['bull_case'],
                    'bear_case' => $report['bear_case'],
                    'risk_summary' => $report['risk_summary'],
                    'data_pack' => json_encode($report['data_pack'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'model' => 'research-report-v1',
                    'token_usage' => json_encode(['prompt_tokens' => 0, 'completion_tokens' => 0]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $generated++;
        });

        DB::table('ai_logs')->insert([
            'task' => 'stock_report_generation',
            'model' => 'research-report-v1',
            'input_hash' => null,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cost_estimate' => 0,
            'status' => 'success_research_report',
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info('Data-driven stock research reports generated: '.$generated);

        return self::SUCCESS;
    }
}
