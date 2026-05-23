<?php

use Illuminate\Foundation\Application;
use App\Console\Commands\BackfillTaiwanPrices;
use App\Console\Commands\CalculateDecisionScores;
use App\Console\Commands\CalculateFundamentalScores;
use App\Console\Commands\CalculateTechnicalScores;
use App\Console\Commands\CalculateThemeScores;
use App\Console\Commands\GenerateStockReports;
use App\Console\Commands\ImportGlobalEvents;
use App\Console\Commands\ImportGlobalMarketData;
use App\Console\Commands\ImportTaiwanChips;
use App\Console\Commands\ImportTaiwanRevenues;
use App\Console\Commands\ImportTaiwanStocks;
use App\Console\Commands\MarketDataStatus;
use App\Console\Commands\RunDailyPipeline;
use App\Console\Commands\SeedThemes;
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
        CalculateDecisionScores::class,
        CalculateFundamentalScores::class,
        CalculateTechnicalScores::class,
        CalculateThemeScores::class,
        GenerateStockReports::class,
        ImportGlobalEvents::class,
        ImportGlobalMarketData::class,
        ImportTaiwanChips::class,
        ImportTaiwanRevenues::class,
        ImportTaiwanStocks::class,
        MarketDataStatus::class,
        RunDailyPipeline::class,
        SeedThemes::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
