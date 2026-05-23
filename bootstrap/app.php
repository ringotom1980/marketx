<?php

use Illuminate\Foundation\Application;
use App\Console\Commands\BackfillTaiwanPrices;
use App\Console\Commands\ImportTaiwanStocks;
use App\Console\Commands\MarketDataStatus;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        BackfillTaiwanPrices::class,
        ImportTaiwanStocks::class,
        MarketDataStatus::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
