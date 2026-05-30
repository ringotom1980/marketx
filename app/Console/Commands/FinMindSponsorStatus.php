<?php

namespace App\Console\Commands;

use App\Support\SponsorDataHealth;
use Illuminate\Console\Command;

class FinMindSponsorStatus extends Command
{
    protected $signature = 'market:finmind-sponsor-status';

    protected $description = 'Show FinMind Sponsor dataset coverage and freshness.';

    public function handle(SponsorDataHealth $health): int
    {
        $items = collect($health->items());

        $this->table(
            ['狀態', '資料', '最新日期', '筆數', '股票數', '來源', '備註'],
            $items->map(fn (array $item) => [
                $item['status_label'],
                $item['label'],
                $item['latest_at'] ?? '-',
                number_format((int) $item['count']),
                $item['symbol_count'] === null ? '-' : number_format((int) $item['symbol_count']),
                $item['source'],
                $item['note'],
            ])->all()
        );

        $summary = $health->summary();
        $this->line(sprintf(
            'Summary: 正常 %d / 需觀察 %d / 過舊 %d / 無資料 %d / 總計 %d',
            $summary['ok'],
            $summary['partial'],
            $summary['stale'],
            $summary['missing'],
            $summary['total']
        ));

        return self::SUCCESS;
    }
}
