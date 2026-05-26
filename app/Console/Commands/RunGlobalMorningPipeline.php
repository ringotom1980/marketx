<?php

namespace App\Console\Commands;

use App\Models\SystemJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RunGlobalMorningPipeline extends Command
{
    protected $signature = 'market:global-morning-pipeline {--skip-ai : Skip AI preprocessing}';

    protected $description = 'Run the post-US-close global radar refresh.';

    public function handle(): int
    {
        $steps = [
            ['global_market', 'market:import-global-market', []],
            ['taiwan_index', 'market:import-taiwan-index', []],
            ['taifex_night', 'market:backfill-taifex-night', ['--days' => 7]],
            ['global_events', 'market:import-global-events', []],
            ['event_clusters', 'market:cluster-global-events', []],
            ['global_influence', 'market:calculate-global-influence', []],
            ['dynamic_themes', 'market:detect-dynamic-themes', []],
            ['theme_scores', 'market:calculate-theme-scores', []],
            ['decision_scores_global', 'market:calculate-decision-scores', []],
            ['stock_radar_cards_global', 'market:build-stock-radar-cards', []],
        ];

        if (! $this->option('skip-ai')) {
            $steps[] = ['ai_event_preprocess', 'market:ai-preprocess-events', ['--limit' => 5]];
        }

        foreach ($steps as [$name, $command, $parameters]) {
            $result = $this->runStep($name, $command, $parameters);

            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        $this->info('Global morning pipeline completed.');

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
