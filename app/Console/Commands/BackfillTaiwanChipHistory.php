<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillTaiwanChipHistory extends Command
{
    protected $signature = 'market:backfill-chip-history
        {--months=6 : Number of recent months to backfill}
        {--from= : Start date YYYY-MM-DD. Overrides --months}
        {--to= : End date YYYY-MM-DD. Defaults to latest stock price date or today}
        {--sleep=1 : Seconds to sleep between dates}
        {--with-official-metrics : Also refresh official chip add-ons that are mostly current-day datasets}';

    protected $description = 'Backfill recent institutional and margin history using existing official daily importers.';

    public function handle(): int
    {
        $sleep = max(0, (int) $this->option('sleep'));
        $dates = $this->dates();

        if ($dates->isEmpty()) {
            $this->warn('No stock price dates found. Import prices before chip history.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Backfilling chip history for %d trade date(s): %s -> %s', $dates->count(), $dates->first(), $dates->last()));

        $failed = 0;

        foreach ($dates as $date) {
            $this->line('Importing '.$date);

            foreach ([
                'market:import-chips',
                'market:import-margins',
            ] as $command) {
                try {
                    $exitCode = $this->call($command, ['--date' => $date]);

                    if ($exitCode !== self::SUCCESS) {
                        $failed++;
                        $this->warn($command.' failed for '.$date.' with exit code '.$exitCode);
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    $this->warn($command.' failed for '.$date.': '.$exception->getMessage());
                }
            }

            if ($this->option('with-official-metrics')) {
                try {
                    $this->call('market:import-official-chip-metrics', ['--date' => $date]);
                } catch (Throwable $exception) {
                    $this->warn('market:import-official-chip-metrics failed for '.$date.': '.$exception->getMessage());
                }
            }

            if ($sleep > 0) {
                sleep($sleep);
            }
        }

        $failed === 0
            ? $this->info('Chip history backfill completed.')
            : $this->warn('Chip history backfill completed with '.$failed.' failed importer run(s).');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function dates(): \Illuminate\Support\Collection
    {
        $latestPriceDate = DB::table('stock_prices_1d')->max('trade_date');
        $to = $this->option('to')
            ? CarbonImmutable::parse((string) $this->option('to'), 'Asia/Taipei')->startOfDay()
            : ($latestPriceDate
                ? CarbonImmutable::parse((string) $latestPriceDate, 'Asia/Taipei')->startOfDay()
                : now('Asia/Taipei')->toImmutable()->startOfDay());

        $from = $this->option('from')
            ? CarbonImmutable::parse((string) $this->option('from'), 'Asia/Taipei')->startOfDay()
            : $to->subMonthsNoOverflow(max(1, (int) $this->option('months')));

        return collect(CarbonPeriod::create($from, '1 day', $to))
            ->filter(fn ($date) => ! $date->isWeekend())
            ->map(fn ($date) => $date->format('Y-m-d'))
            ->values();
    }
}
