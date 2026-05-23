<?php

namespace App\Console\Commands;

use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateStockReports extends Command
{
    protected $signature = 'market:generate-stock-reports {--limit=30} {--date= : Report date, default today}';

    protected $description = 'Generate AI explanation reports when an OpenAI key is configured.';

    public function handle(): int
    {
        $apiKey = env('OPENAI_API_KEY');
        $reportDate = $this->option('date') ?: CarbonImmutable::now('Asia/Taipei')->toDateString();
        $limit = max(1, (int) $this->option('limit'));

        if (! $apiKey || $apiKey === 'ollama') {
            DB::table('ai_logs')->insert([
                'task' => 'stock_report_generation',
                'model' => config('services.marketx.ai_model'),
                'status' => 'skipped',
                'error_message' => 'OPENAI_API_KEY is not configured; no AI report was generated.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->warn('Skipped: OPENAI_API_KEY is not configured.');

            return self::SUCCESS;
        }

        $generated = 0;

        Stock::query()
            ->join('stock_scores', 'stocks.id', '=', 'stock_scores.stock_id')
            ->select('stocks.*', 'stock_scores.total_score', 'stock_scores.decision', 'stock_scores.confidence_score')
            ->whereNotNull('stock_scores.total_score')
            ->orderByDesc('stock_scores.score_date')
            ->orderByDesc('stock_scores.total_score')
            ->limit($limit)
            ->get()
            ->each(function (Stock $stock) use ($reportDate, &$generated) {
                $dataPack = [
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'score' => $stock->total_score,
                    'decision' => $stock->decision,
                    'confidence' => $stock->confidence_score,
                    'generated_without_external_call' => false,
                ];

                DB::table('stock_reports')->updateOrInsert(
                    ['stock_id' => $stock->id, 'report_date' => $reportDate],
                    [
                        'decision' => $stock->decision,
                        'summary' => null,
                        'bull_case' => null,
                        'bear_case' => null,
                        'risk_summary' => null,
                        'data_pack' => json_encode($dataPack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'model' => config('services.marketx.ai_model'),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );

                $generated++;
            });

        DB::table('ai_logs')->insert([
            'task' => 'stock_report_generation',
            'model' => config('services.marketx.ai_model'),
            'status' => 'queued_data_packs',
            'error_message' => 'Data packs were prepared. External OpenAI call is intentionally gated for cost control.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info('AI report data packs prepared: '.$generated);

        return self::SUCCESS;
    }
}
