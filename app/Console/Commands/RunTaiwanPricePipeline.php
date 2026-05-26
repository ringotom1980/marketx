<?php

namespace App\Console\Commands;

use App\Models\SystemJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RunTaiwanPricePipeline extends Command
{
    protected $signature = 'market:taiwan-price-pipeline';

    protected $description = 'Refresh Taiwan daily prices and technical scores shortly after market close.';

    public function handle(): int
    {
        foreach ([
            ['taiwan_prices_fast', 'market:import-stocks', []],
            ['taiwan_index_fast', 'market:import-taiwan-index', []],
            ['technical_scores_fast', 'market:calculate-technical-scores', ['--min-days' => 10]],
            ['decision_scores_fast', 'market:calculate-decision-scores', []],
            ['stock_radar_cards_fast', 'market:build-stock-radar-cards', []],
        ] as [$name, $command, $parameters]) {
            $result = $this->runStep($name, $command, $parameters);

            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        $this->info('Taiwan price pipeline completed.');

        return self::SUCCESS;
    }

    private function runStep(string $name, string $command, array $parameters): int
    {
        $started = hrtime(true);
        $job = SystemJob::query()->create([
            'job_name' => $name,
            'status' => 'running',
            'started_at' => now(),
            'context' => ['command' => $command, 'parameters' => $parameters],
        ]);

        try {
            $exitCode = Artisan::call($command, $parameters);
            $output = trim(Artisan::output());

            $job->update([
                'status' => $exitCode === self::SUCCESS ? 'success' : 'failed',
                'finished_at' => now(),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'error_message' => $exitCode === self::SUCCESS ? null : $output,
                'context' => ['command' => $command, 'parameters' => $parameters, 'output' => $output],
            ]);

            if ($output !== '') {
                $this->line($output);
            }

            return $exitCode;
        } catch (Throwable $exception) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'error_message' => $exception->getMessage(),
            ]);

            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
