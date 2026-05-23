<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportGlobalEvents extends Command
{
    protected $signature = 'market:import-global-events';

    protected $description = 'Import global event/news records from configured providers.';

    public function handle(): int
    {
        $feed = config('services.marketx.global_event_feed');

        if (! $feed) {
            DB::table('system_logs')->insert([
                'level' => 'info',
                'source' => 'Event Engine',
                'message' => 'Global event import skipped because MARKETX_GLOBAL_EVENT_FEED is not configured.',
                'context' => json_encode(['required_env' => 'MARKETX_GLOBAL_EVENT_FEED']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->warn('Skipped: MARKETX_GLOBAL_EVENT_FEED is not configured.');

            return self::SUCCESS;
        }

        $this->error('Configured global event feed importer is not implemented for this source yet: '.$feed);

        return self::FAILURE;
    }
}
