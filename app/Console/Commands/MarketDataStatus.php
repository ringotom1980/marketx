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

        $this->line('Stocks total: '.$stocks);
        $this->line('Stocks active: '.$activeStocks);
        $this->line('Daily price rows: '.$priceRows);
        $this->line('Stocks with prices: '.$pricedStocks);
        $this->line('Price date range: '.($dateRange->min_date ?? '-').' -> '.($dateRange->max_date ?? '-'));

        return self::SUCCESS;
    }
}

