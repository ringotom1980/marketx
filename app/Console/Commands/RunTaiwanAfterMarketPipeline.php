<?php

namespace App\Console\Commands;

use App\Models\SystemJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RunTaiwanAfterMarketPipeline extends Command
{
    protected $signature = 'market:taiwan-aftermarket-pipeline';

    protected $description = 'Refresh Taiwan after-market chip data and preliminary decision scores.';

    public function handle(): int
    {
        foreach ([
            ['taiwan_prices_aftermarket', 'market:import-stocks', []],
            ['taiwan_chips_aftermarket', 'market:import-chips', []],
            ['official_chip_metrics_aftermarket', 'market:import-official-chip-metrics', []],
            ['technical_scores_aftermarket', 'market:calculate-technical-scores', ['--min-days' => 10]],
            ['decision_scores_aftermarket', 'market:calculate-decision-scores', []],
            ['stock_radar_cards_aftermarket', 'market:build-stock-radar-cards', []],
        ] as [$name, $command, $parameters]) {
            $result = $this->runStep($name, $command, $parameters);

            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        $this->info('Taiwan after-market pipeline completed.');

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
