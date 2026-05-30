<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneStockSnapshots extends Command
{
    protected $signature = 'market:prune-stock-snapshots
        {--days=10 : Number of days to keep}
        {--dry-run : Show rows that would be deleted without deleting}';

    protected $description = 'Prune old intraday stock snapshot rows used by realtime quote charts.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = CarbonImmutable::now('Asia/Taipei')
            ->subDays($days)
            ->startOfDay()
            ->toDateTimeString();

        $query = DB::table('stock_snapshots')->where('snapshot_at', '<', $cutoff);
        $count = (int) $query->count();

        if ($this->option('dry-run')) {
            $this->info("Stock snapshot rows older than {$cutoff}: {$count}");

            return self::SUCCESS;
        }

        $deleted = 0;
        DB::table('stock_snapshots')
            ->where('snapshot_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(5000, function ($rows) use (&$deleted) {
                $ids = $rows->pluck('id')->all();
                $deleted += DB::table('stock_snapshots')->whereIn('id', $ids)->delete();
            });

        $this->info("Stock snapshot rows pruned: {$deleted}; cutoff={$cutoff}");

        return self::SUCCESS;
    }
}
