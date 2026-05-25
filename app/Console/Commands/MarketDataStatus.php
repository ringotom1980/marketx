<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarketDataStatus extends Command
{
    protected $signature = 'market:data-status';

    protected $description = 'Show current market data coverage.';

    public function handle(): int
    {
        $stocks = DB::table('stocks')->count();
        $activeStocks = DB::table('stocks')->where('is_active', true)->count();
        $priceRows = DB::table('stock_prices_1d')->count();
        $pricedStocks = DB::table('stock_prices_1d')->distinct('stock_id')->count('stock_id');
        $dateRange = DB::table('stock_prices_1d')
            ->selectRaw('MIN(trade_date) as min_date, MAX(trade_date) as max_date')
            ->first();
        $latestDate = $dateRange->max_date ?? null;
        $latestPriceRows = $latestDate
            ? DB::table('stock_prices_1d')->where('trade_date', $latestDate)->count()
            : 0;
        $recentJobs = DB::table('system_jobs')
            ->orderByDesc('started_at')
            ->limit(20)
            ->get(['job_name', 'status', 'started_at', 'finished_at']);
        $recentFeedLogs = DB::table('system_logs')
            ->where('source', 'market_data_feed')
            ->orderByDesc('id')
            ->limit(4)
            ->get(['message', 'context', 'created_at']);
        $latestRowsByMarket = $latestDate
            ? DB::table('stock_prices_1d')
                ->join('stocks', 'stocks.id', '=', 'stock_prices_1d.stock_id')
                ->where('stock_prices_1d.trade_date', $latestDate)
                ->groupBy('stocks.market')
                ->orderBy('stocks.market')
                ->selectRaw('stocks.market, count(*) as row_count')
                ->pluck('row_count', 'stocks.market')
            : collect();

        $this->line('Stocks total: '.$stocks);
        $this->line('Stocks active: '.$activeStocks);
        $this->line('Daily price rows: '.$priceRows);
        $this->line('Stocks with prices: '.$pricedStocks);
        $this->line('Price date range: '.($dateRange->min_date ?? '-').' -> '.($dateRange->max_date ?? '-'));
        $this->line('Latest price date rows: '.$latestDate.' / '.$latestPriceRows);
        $this->line('Latest rows by market: '.$latestRowsByMarket->map(fn ($count, $market) => $market.'='.$count)->implode(', '));

        if ($recentFeedLogs->isNotEmpty()) {
            $this->newLine();
            $this->line('Recent feed coverage:');
            foreach ($recentFeedLogs as $log) {
                $context = json_decode((string) $log->context, true) ?: [];
                $this->line(sprintf(
                    '- %s at %s | missing=%d invalid_ohlc=%d sample=%s',
                    $log->message,
                    $log->created_at,
                    (int) ($context['missing_active_count'] ?? 0),
                    (int) ($context['skipped_invalid_ohlc'] ?? 0),
                    implode(',', array_slice(array_merge($context['missing_active_symbols'] ?? [], $context['invalid_ohlc_symbols'] ?? []), 0, 12)),
                ));
            }
        }

        if ($recentJobs->isNotEmpty()) {
            $this->newLine();
            $this->line('Recent jobs:');
            foreach ($recentJobs as $job) {
                $this->line(sprintf(
                    '- %s [%s] %s -> %s',
                    $job->job_name,
                    $job->status,
                    $job->started_at ?? '-',
                    $job->finished_at ?? '-',
                ));
            }
        }

        return self::SUCCESS;
    }
}
