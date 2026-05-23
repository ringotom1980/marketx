<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('market:daily-pipeline')
    ->dailyAt('18:30')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();
