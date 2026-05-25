<?php

use Illuminate\Foundation\Application;
use App\Console\Commands\BackfillTaiwanPrices;
use App\Console\Commands\CalculateBrokerDayTradePatterns;
use App\Console\Commands\CalculateDecisionScores;
use App\Console\Commands\CalculateFundamentalScores;
use App\Console\Commands\CalculateGlobalInfluenceScores;
use App\Console\Commands\CalculateTechnicalScores;
use App\Console\Commands\CalculateThemeScores;
use App\Console\Commands\DetectDynamicThemes;
use App\Console\Commands\GenerateStockReports;
use App\Console\Commands\ImportGlobalEvents;
use App\Console\Commands\ImportGlobalMarketData;
use App\Console\Commands\ImportBrokerTradesFromCsv;
use App\Console\Commands\ImportOfficialFinancialStatements;
use App\Console\Commands\ImportOfficialFreeChipMetrics;
use App\Console\Commands\ImportTaiwanChips;
use App\Console\Commands\ImportTaiwanMargins;
use App\Console\Commands\ImportTaiwanRevenues;
use App\Console\Commands\ImportTaiwanStocks;
use App\Console\Commands\ImportTaiwanValuations;
use App\Console\Commands\ImportTwseBrokerTrades;
use App\Console\Commands\AiGenerateStockReports;
use App\Console\Commands\AiPreprocessEvents;
use App\Console\Commands\AiStatus;
use App\Console\Commands\MapDynamicThemes;
use App\Console\Commands\MarketDataStatus;
use App\Console\Commands\RunDailyPipeline;
use App\Console\Commands\SeedThemeMappings;
use App\Console\Commands\SeedThemeKeywords;
use App\Console\Commands\SeedThemes;
use App\Http\Middleware\EnsureMarketxAdmin;
use App\Http\Middleware\SyncMarketxSessionUser;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        AiGenerateStockReports::class,
        AiPreprocessEvents::class,
        AiStatus::class,
        BackfillTaiwanPrices::class,
        CalculateBrokerDayTradePatterns::class,
        CalculateDecisionScores::class,
        CalculateFundamentalScores::class,
        CalculateGlobalInfluenceScores::class,
        CalculateTechnicalScores::class,
        CalculateThemeScores::class,
        DetectDynamicThemes::class,
        GenerateStockReports::class,
        ImportGlobalEvents::class,
        ImportGlobalMarketData::class,
        ImportBrokerTradesFromCsv::class,
        ImportOfficialFinancialStatements::class,
        ImportOfficialFreeChipMetrics::class,
        ImportTaiwanChips::class,
        ImportTaiwanMargins::class,
        ImportTaiwanRevenues::class,
        ImportTaiwanStocks::class,
        ImportTaiwanValuations::class,
        ImportTwseBrokerTrades::class,
        MapDynamicThemes::class,
        MarketDataStatus::class,
        RunDailyPipeline::class,
        SeedThemeMappings::class,
        SeedThemeKeywords::class,
        SeedThemes::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            EnsureMarketxAdmin::class,
            SyncMarketxSessionUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
