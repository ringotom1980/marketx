<?php

namespace App\Console\Commands;

use App\Models\SystemJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RunGlobalMarketRefresh extends Command
{
    protected $signature = 'market:global-market-refresh';

    protected $description = 'Refresh global market indices and recompute global influence scores.';

    public function handle(): int
    {
        foreach ([
            ['global_market_refresh', 'market:import-global-market', []],
            ['global_influence_refresh', 'market:calculate-global-influence', []],
        ] as [$name, $command, $parameters]) {
            $result = $this->runStep($name, $command, $parameters);

            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        $this->info('Global market refresh completed.');

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
