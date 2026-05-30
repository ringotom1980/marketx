<?php

use App\Models\Stock;
use App\Support\StockResearchReportComposer;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$symbol = $argv[1] ?? '2330';

$stock = Stock::query()
    ->with('latestScore')
    ->where('symbol', $symbol)
    ->firstOrFail();

$report = app(StockResearchReportComposer::class)->compose($stock, $stock->latestScore);

echo json_encode([
    'symbol' => $stock->symbol,
    'name' => $stock->name,
    'engine' => data_get($report, 'data_pack.engine'),
    'summary_preview' => mb_substr($report['summary'], 0, 1200),
    'data_keys' => array_keys($report['data_pack']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
