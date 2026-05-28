<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('market:global-morning-pipeline')
    ->dailyAt('06:10')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

foreach ([
    // US close first pass and delayed Yahoo Finance confirmations.
    '04:10', '04:40', '05:10', '06:30', '07:30',
    // Japan / Korea close first pass and backfill checks.
    '14:15', '14:45', '15:30',
    // Hong Kong / China close first pass and backfill checks.
    '16:15', '16:45', '17:30',
    // Commodity / ADR late checks while the US session is active.
    '22:20', '23:20',
] as $time) {
    Schedule::command('market:global-market-refresh')
        ->dailyAt($time)
        ->timezone('Asia/Taipei')
        ->withoutOverlapping();
}

foreach (['08:00', '08:30'] as $time) {
    Schedule::command('market:ai-generate-global-premarket --live')
        ->dailyAt($time)
        ->timezone('Asia/Taipei')
        ->withoutOverlapping();
}

foreach (['08:10', '08:40'] as $time) {
    Schedule::command('market:ai-generate-theme-premarket --live')
        ->dailyAt($time)
        ->timezone('Asia/Taipei')
        ->withoutOverlapping();
}

Schedule::command('market:taiwan-price-pipeline')
    ->dailyAt('14:05')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:taiwan-price-pipeline')
    ->dailyAt('15:10')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:taiwan-aftermarket-pipeline')
    ->dailyAt('16:40')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:daily-pipeline')
    ->dailyAt('21:30')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:taiwan-price-pipeline')
    ->dailyAt('22:45')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:taiwan-price-pipeline')
    ->dailyAt('23:45')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:taifex-night-pipeline')
    ->dailyAt('22:20')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:taifex-night-pipeline')
    ->dailyAt('05:20')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

foreach (['17:10', '22:10', '03:10'] as $time) {
    Schedule::command('market:agents-run')
        ->dailyAt($time)
        ->timezone('Asia/Taipei')
        ->withoutOverlapping();
}
