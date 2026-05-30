<?php

use App\Models\Stock;
use App\Support\StockReportPhraseComposer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$symbol = $argv[1] ?? '2330';

$stock = Stock::query()
    ->with([
        'latestScore',
        'latestChip',
        'dailyPrices' => fn ($query) => $query->latest('trade_date')->limit(1),
    ])
    ->where('symbol', $symbol)
    ->first();

if (! $stock) {
    fwrite(STDERR, "Stock not found: {$symbol}\n");
    exit(1);
}

$revenue = DB::table('stock_revenues')
    ->where('stock_id', $stock->id)
    ->orderByDesc('year_month')
    ->first();

$report = app(StockReportPhraseComposer::class)->compose(
    $stock,
    $stock->latestScore,
    $stock->latestChip,
    $stock->dailyPrices->first(),
    $revenue,
);

$recentAssets = DB::table('language_assets')
    ->whereNotNull('last_used_at')
    ->orderByDesc('last_used_at')
    ->limit(8)
    ->get(['id', 'section', 'tone', 'condition_key', 'usage_count', 'last_used_at'])
    ->map(fn ($asset) => (array) $asset)
    ->all();

echo json_encode([
    'symbol' => $stock->symbol,
    'name' => $stock->name,
    'engine' => data_get($report, 'data_pack.engine'),
    'article_template_id' => data_get($report, 'data_pack.article_template_id'),
    'paragraph_template_ids' => data_get($report, 'data_pack.paragraph_template_ids'),
    'summary_preview' => mb_substr($report['summary'], 0, 500),
    'recent_language_assets' => $recentAssets,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
