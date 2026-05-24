<?php

namespace App\Console\Commands;

use App\Models\SystemJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RunDailyPipeline extends Command
{
    protected $signature = 'market:daily-pipeline {--fast : Skip external imports and only recompute local scores} {--skip-ai : Skip AI report generation}';

    protected $description = 'Run the daily MarketX data, scoring, and report pipeline.';

    public function handle(): int
    {
        $steps = $this->option('fast')
            ? [
                ['technical_scores', 'market:calculate-technical-scores', ['--min-days' => 10]],
                ['fundamental_scores', 'market:calculate-fundamental-scores', []],
                ['global_influence', 'market:calculate-global-influence', []],
                ['theme_mappings', 'market:seed-theme-mappings', []],
                ['theme_scores', 'market:calculate-theme-scores', []],
                ['decision_scores', 'market:calculate-decision-scores', []],
            ]
            : [
                ['global_market', 'market:import-global-market', []],
                ['global_events', 'market:import-global-events', []],
                ['taiwan_stocks', 'market:import-stocks', ['--deactivate-missing' => true]],
                ['taiwan_chips', 'market:import-chips', []],
                ['taiwan_margins', 'market:import-margins', []],
                ['taiwan_revenues', 'market:import-revenues', []],
                ['taiwan_valuations', 'market:import-valuations', []],
                ['technical_scores', 'market:calculate-technical-scores', ['--min-days' => 10]],
                ['fundamental_scores', 'market:calculate-fundamental-scores', []],
                ['global_influence', 'market:calculate-global-influence', []],
                ['theme_mappings', 'market:seed-theme-mappings', []],
                ['theme_scores', 'market:calculate-theme-scores', []],
                ['decision_scores', 'market:calculate-decision-scores', []],
            ];

        if (! $this->option('skip-ai')) {
            $steps[] = ['rule_based_reports', 'market:generate-stock-reports', ['--limit' => 0]];
        }

        foreach ($steps as [$name, $command, $parameters]) {
            $result = $this->runStep($name, $command, $parameters);

            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        $this->info('Daily pipeline completed.');

        return self::SUCCESS;
    }

    private function runStep(string $name, string $command, array $parameters): int
    {
        $startedAt = now();
        $started = hrtime(true);

        $job = SystemJob::query()->create([
            'job_name' => $name,
            'status' => 'running',
            'started_at' => $startedAt,
            'context' => ['command' => $command, 'parameters' => $parameters],
        ]);

        $this->line('Running '.$command.'...');

        try {
            $exitCode = Artisan::call($command, $parameters);
            $output = trim(Artisan::output());

            $job->update([
                'status' => $exitCode === self::SUCCESS ? 'success' : 'failed',
                'finished_at' => now(),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'error_message' => $exitCode === self::SUCCESS ? null : $output,
                'context' => [
                    'command' => $command,
                    'parameters' => $parameters,
                    'output' => $output,
                ],
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
