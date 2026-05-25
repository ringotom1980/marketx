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
