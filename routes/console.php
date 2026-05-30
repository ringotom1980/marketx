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

foreach (['08:00', '08:20'] as $time) {
    Schedule::command('market:ai-generate-global-premarket --live')
        ->dailyAt($time)
        ->timezone('Asia/Taipei')
        ->withoutOverlapping();
}

foreach (['08:10', '08:25'] as $time) {
    Schedule::command('market:ai-generate-theme-premarket --live')
        ->dailyAt($time)
        ->timezone('Asia/Taipei')
        ->withoutOverlapping();
}

Schedule::command('market:build-stock-radar-cards')
    ->dailyAt('08:30')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:update-stock-radar-observations')
    ->dailyAt('08:32')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:build-daily-context --session=premarket')
    ->dailyAt('08:35')
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

Schedule::command('market:build-daily-context --session=aftermarket')
    ->dailyAt('17:25')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:daily-pipeline')
    ->dailyAt('21:30')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:build-daily-context --session=night')
    ->dailyAt('22:35')
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

Schedule::command('market:build-daily-context --session=daily')
    ->dailyAt('00:50')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:export-agent-knowledge-pack')
    ->dailyAt('00:55')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:agents-run')
    ->dailyAt('01:00')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:agents-run-ollama --model=qwen2.5:1.5b --limit=5 --timeout=600')
    ->dailyAt('01:20')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:agents-review-cases')
    ->dailyAt('01:40')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:agents-suggest-report-phrases --limit=12')
    ->dailyAt('01:55')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:agents-learning-pipeline --phase=collect --limit=120')
    ->cron('10 */6 * * *')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:agents-build-knowledge-bases --fetch-news --limit=80')
    ->cron('25 */6 * * *')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:agents-learning-pipeline --phase=classify --limit=160')
    ->dailyAt('01:12')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:agents-learning-pipeline --phase=language --limit=80')
    ->dailyAt('01:52')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:agents-build-knowledge-bases --seed-language --limit=160')
    ->dailyAt('01:58')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:agents-learning-pipeline --phase=rules --limit=80')
    ->dailyAt('02:08')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:agents-learning-pipeline --phase=review --limit=80')
    ->dailyAt('02:18')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:agents-publish-language-suggestions --limit=40 --min-priority=62')
    ->dailyAt('02:28')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:audit-stock-report-quality --limit=180')
    ->dailyAt('02:40')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:agents-quality-to-language-suggestions --limit=120')
    ->dailyAt('02:48')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();

Schedule::command('market:agents-publish-language-suggestions --limit=30 --min-priority=70')
    ->dailyAt('02:56')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping();
