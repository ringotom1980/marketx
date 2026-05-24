<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalculateBrokerDayTradePatterns extends Command
{
    protected $signature = 'market:calculate-broker-daytrade-patterns
        {--days=60 : Lookback window}
        {--min-buy=100000 : Minimum branch net buy shares}
        {--sellback-ratio=0.6 : Minimum next-record sellback ratio}';

    protected $description = 'Detect suspected next-day broker branch flipping patterns from imported branch trades.';

    public function handle(): int
    {
        $from = CarbonImmutable::now('Asia/Taipei')->subDays(max(2, (int) $this->option('days')))->toDateString();
        $minBuy = max(1, (int) $this->option('min-buy'));
        $threshold = max(0.1, min(1.0, (float) $this->option('sellback-ratio')));
        $inserted = 0;
        $checked = 0;

        DB::table('stock_broker_trades_1d')
            ->where('trade_date', '>=', $from)
            ->where('net_volume', '>=', $minBuy)
            ->orderBy('stock_id')
            ->orderBy('broker_branch_id')
            ->orderBy('trade_date')
            ->chunkById(500, function ($trades) use ($threshold, &$inserted, &$checked) {
                foreach ($trades as $buyTrade) {
                    $checked++;
                    $sellTrade = DB::table('stock_broker_trades_1d')
                        ->where('stock_id', $buyTrade->stock_id)
                        ->where('broker_branch_id', $buyTrade->broker_branch_id)
                        ->where('trade_date', '>', $buyTrade->trade_date)
                        ->orderBy('trade_date')
                        ->first();

                    if (! $sellTrade || $sellTrade->net_volume >= 0) {
                        continue;
                    }

                    $buyVolume = (int) $buyTrade->net_volume;
                    $sellVolume = abs((int) $sellTrade->net_volume);
                    $sellbackRatio = $sellVolume / max(1, $buyVolume);

                    if ($sellbackRatio < $threshold) {
                        continue;
                    }

                    DB::table('broker_daytrade_patterns')->updateOrInsert(
                        [
                            'stock_id' => $buyTrade->stock_id,
                            'broker_branch_id' => $buyTrade->broker_branch_id,
                            'buy_date' => $buyTrade->trade_date,
                            'sell_date' => $sellTrade->trade_date,
                        ],
                        [
                            'buy_volume' => $buyVolume,
                            'sell_volume' => $sellVolume,
                            'sellback_ratio' => round($sellbackRatio, 4),
                            'confidence_score' => $this->confidence($sellbackRatio, $buyVolume),
                            'raw_payload' => json_encode([
                                'source' => 'stock_broker_trades_1d',
                                'rule' => 'positive_net_buy_followed_by_negative_net_sell',
                            ], JSON_UNESCAPED_UNICODE),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ],
                    );

                    $inserted++;
                }
            });

        $this->info('Broker day-trade candidates checked: '.$checked);
        $this->line('Suspected patterns upserted: '.$inserted);

        return self::SUCCESS;
    }

    private function confidence(float $sellbackRatio, int $buyVolume): int
    {
        $score = 45;
        $score += min(35, (int) round($sellbackRatio * 35));

        if ($buyVolume >= 1_000_000) {
            $score += 15;
        } elseif ($buyVolume >= 300_000) {
            $score += 8;
        }

        return max(0, min(100, $score));
    }
}
