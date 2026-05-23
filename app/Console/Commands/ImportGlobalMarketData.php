<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportGlobalMarketData extends Command
{
    protected $signature = 'market:import-global-market';

    protected $description = 'Import global market indicators from configured providers.';

    public function handle(): int
    {
        $sourceUrl = config('services.marketx.global_market_feed');

        if (! $sourceUrl) {
            DB::table('system_logs')->insert([
                'level' => 'info',
                'source' => 'Global Engine',
                'message' => 'Global market import skipped because MARKETX_GLOBAL_MARKET_FEED is not configured.',
                'context' => json_encode(['required_env' => 'MARKETX_GLOBAL_MARKET_FEED']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->warn('Skipped: MARKETX_GLOBAL_MARKET_FEED is not configured.');

            return self::SUCCESS;
        }

        $this->error('Configured global market feed importer is not implemented for this source yet: '.$sourceUrl);

        return self::FAILURE;
    }
}
