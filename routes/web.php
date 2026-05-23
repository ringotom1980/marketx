<?php

use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $markets = DB::table('global_market_data')
        ->orderByDesc('trade_date')
        ->limit(5)
        ->get()
        ->map(fn ($row) => [
            'name' => $row->indicator,
            'state' => $row->state ?: 'waiting source',
            'tone' => $row->change_pct !== null && (float) $row->change_pct >= 0 ? 'green' : 'amber',
        ]);

    if ($markets->isEmpty()) {
        $markets = collect([
            ['name' => 'US Market', 'state' => 'Global Engine ready', 'tone' => 'amber'],
            ['name' => 'SOX', 'state' => 'Global Engine ready', 'tone' => 'amber'],
            ['name' => 'VIX', 'state' => 'Global Engine ready', 'tone' => 'amber'],
            ['name' => 'DXY', 'state' => 'Global Engine ready', 'tone' => 'amber'],
            ['name' => 'US 10Y', 'state' => 'Global Engine ready', 'tone' => 'amber'],
        ]);
    }

    $topStocks = Stock::query()
        ->join('stock_scores', 'stocks.id', '=', 'stock_scores.stock_id')
        ->select('stocks.symbol', 'stocks.name', 'stock_scores.decision', 'stock_scores.total_score')
        ->whereNotNull('stock_scores.total_score')
        ->orderByDesc('stock_scores.score_date')
        ->orderByDesc('stock_scores.total_score')
        ->limit(5)
        ->get()
        ->map(fn ($stock) => [
            'symbol' => $stock->symbol,
            'name' => $stock->name,
            'decision' => $stock->decision ?? '等待決策',
            'score' => $stock->total_score ?? 0,
        ]);

    $events = DB::table('global_events')
        ->orderByDesc('event_date')
        ->limit(4)
        ->get()
        ->map(fn ($event) => [
            'title' => $event->title,
            'impact' => $event->summary ?: ($event->impact_direction ?: 'pending'),
        ]);

    if ($events->isEmpty()) {
        $events = collect([
            ['title' => 'Event Engine ready', 'impact' => 'No global event source configured; importer logs skipped status and writes no fake data.'],
        ]);
    }

    $themes = DB::table('themes')
        ->leftJoin('theme_scores', function ($join) {
            $join->on('themes.id', '=', 'theme_scores.theme_id')
                ->whereRaw('theme_scores.score_date = (select max(ts.score_date) from theme_scores ts where ts.theme_id = themes.id)');
        })
        ->select('themes.name', 'theme_scores.heat_score')
        ->where('themes.is_active', true)
        ->orderByDesc('theme_scores.heat_score')
        ->limit(4)
        ->get()
        ->map(fn ($theme) => ['name' => $theme->name, 'score' => $theme->heat_score ?? 0]);

    if ($themes->isEmpty()) {
        $themes = collect([
            ['name' => 'AI Server', 'score' => 0],
            ['name' => 'Thermal', 'score' => 0],
            ['name' => 'CoWoS', 'score' => 0],
            ['name' => 'Optical Communication', 'score' => 0],
        ]);
    }

    return view('home', [
        'markets' => $markets,
        'events' => $events,
        'themes' => $themes,
        'topStocks' => $topStocks,
        'riskStocks' => [
            ['name' => 'Risk Engine', 'risk' => 'Risk list will use real score deterioration and chip reversal signals after enough score history is collected.'],
        ],
    ]);
});

Route::get('/search', function (Request $request) {
    $query = trim((string) $request->query('q', ''));

    if (preg_match('/^\d{4}$/', $query) === 1) {
        $exactStock = Stock::query()->where('symbol', $query)->first();

        if ($exactStock) {
            return redirect('/s/'.$exactStock->symbol);
        }
    }

    $stocks = collect();

    if ($query !== '') {
        $stocks = Stock::query()
            ->where(function ($builder) use ($query) {
                $builder
                    ->where('symbol', 'like', $query.'%')
                    ->orWhere('name', 'like', '%'.$query.'%')
                    ->orWhere('industry', 'like', '%'.$query.'%');
            })
            ->orderByRaw("CASE WHEN symbol LIKE ? THEN 0 ELSE 1 END", [$query.'%'])
            ->orderBy('symbol')
            ->limit(50)
            ->get();
    }

    return view('search', [
        'query' => $query,
        'stocks' => $stocks,
    ]);
});

Route::get('/s/{symbol}', function (string $symbol) {
    $stockRecord = Stock::query()
        ->with([
            'dailyPrices' => fn ($query) => $query->latest('trade_date')->limit(1),
            'latestChip',
            'latestScore',
        ])
        ->where('symbol', $symbol)
        ->firstOrFail();

    $latestPrice = $stockRecord->dailyPrices->first();
    $latestChip = $stockRecord->latestChip;
    $latestScore = $stockRecord->latestScore;
    $technicalPayload = $latestScore?->technical_payload;

    $latestReport = DB::table('stock_reports')
        ->where('stock_id', $stockRecord->id)
        ->orderByDesc('report_date')
        ->first();

    return view('stock', [
        'stock' => [
            'symbol' => $stockRecord->symbol,
            'name' => $stockRecord->name,
            'market' => $stockRecord->market,
            'close' => $latestPrice?->close ?? '無資料',
            'change' => $latestPrice?->change ?? '無資料',
            'volume' => $latestPrice?->volume ? number_format($latestPrice->volume) : '無資料',
            'decision' => $latestScore?->decision ?? '等待決策',
            'score' => $latestScore?->total_score ?? $latestScore?->technical_score ?? 0,
            'confidence' => $latestScore?->confidence_score ?? 0,
        ],
        'modules' => [
            ['name' => '全球宏觀', 'score' => $latestScore?->macro_score ?? 0],
            ['name' => '全球事件', 'score' => $latestScore?->event_score ?? 0],
            ['name' => '題材熱度', 'score' => $latestScore?->theme_score ?? 0],
            ['name' => '技術結構', 'score' => $latestScore?->technical_score ?? 0],
            ['name' => '籌碼', 'score' => $latestScore?->chip_score ?? 0],
            ['name' => '財務營收', 'score' => $latestScore?->fundamental_score ?? 0],
        ],
        'technical' => $technicalPayload,
        'chip' => $latestChip,
        'chain' => [
            'Global events',
            '-> industry impact',
            '-> theme mapping',
            '-> stock score',
        ],
        'summary' => $latestReport?->summary
            ?: 'Decision Engine is running on real Taiwan price/chip data. AI summary stays empty until an OpenAI key and cost gate are configured.',
    ]);
});

Route::get('/global', function () {
    $marketRows = DB::table('global_market_data')
        ->orderByDesc('trade_date')
        ->orderBy('indicator')
        ->limit(12)
        ->get();

    $eventRows = DB::table('global_events')
        ->orderByDesc('event_date')
        ->orderByDesc('id')
        ->limit(8)
        ->get();

    $items = $marketRows->map(fn ($row) => [
        'title' => $row->indicator.' '.$row->trade_date,
        'body' => trim(($row->state ?: 'no state').' value='.($row->value ?? 'n/a').' change='.($row->change_pct ?? 'n/a')),
    ])->merge($eventRows->map(fn ($row) => [
        'title' => $row->title,
        'body' => trim(($row->category ?: 'event').' / '.($row->impact_direction ?: 'pending').' / '.($row->summary ?: 'no summary')),
    ]));

    if ($items->isEmpty()) {
        $items = collect([
            ['title' => 'Global Engine ready', 'body' => 'No global market/event source is configured yet. Import jobs will record skipped status instead of writing fake data.'],
        ]);
    }

    return view('simple', [
        'heading' => '全球雷達',
        'description' => '全球市場、全球事件與產業影響鏈入口。沒有設定外部來源時，只顯示系統狀態，不寫入假資料。',
        'items' => $items,
    ]);
});

Route::get('/themes', function () {
    $themes = DB::table('themes')
        ->leftJoin('theme_scores', function ($join) {
            $join->on('themes.id', '=', 'theme_scores.theme_id')
                ->whereRaw('theme_scores.score_date = (select max(ts.score_date) from theme_scores ts where ts.theme_id = themes.id)');
        })
        ->select('themes.name', 'themes.description', 'theme_scores.heat_score', 'theme_scores.price_score', 'theme_scores.chip_score', 'theme_scores.score_date')
        ->where('themes.is_active', true)
        ->orderByDesc('theme_scores.heat_score')
        ->orderBy('themes.name')
        ->get()
        ->map(fn ($theme) => [
            'title' => $theme->name.($theme->heat_score === null ? ' - pending' : ' - '.$theme->heat_score),
            'body' => trim(($theme->description ?: '').' price='.($theme->price_score ?? 'n/a').' chip='.($theme->chip_score ?? 'n/a').' date='.($theme->score_date ?? 'n/a')),
        ]);

    if ($themes->isEmpty()) {
        $themes = collect([
            ['title' => 'Theme Engine ready', 'body' => 'Run market:seed-themes to create initial theme definitions. Scores require real stock-theme mappings.'],
        ]);
    }

    return view('simple', [
        'heading' => '題材雷達',
        'description' => '題材熱度、資金流向與個股映射入口。分數只從真實映射與已計算個股分數產生。',
        'items' => $themes,
    ]);
});

Route::get('/watchlist', function () {
    return view('simple', [
        'heading' => '追蹤清單',
        'description' => '自選股入口已保留 watchlist 資料表，下一輪可接登入使用者與每日報告。',
        'items' => [
            ['title' => 'Watchlist table', 'body' => 'Ready for user-stock subscriptions.'],
            ['title' => 'Daily report hook', 'body' => 'Daily pipeline can generate reports for watched stocks after AI source is configured.'],
        ],
    ]);
});

Route::get('/admin', function () {
    $stats = [
        ['title' => 'Stocks', 'body' => (string) DB::table('stocks')->count()],
        ['title' => 'Daily K rows', 'body' => (string) DB::table('stock_prices_1d')->count()],
        ['title' => 'Chip rows', 'body' => (string) DB::table('stock_chips_1d')->count()],
        ['title' => 'Score rows', 'body' => (string) DB::table('stock_scores')->count()],
        ['title' => 'Financial rows', 'body' => (string) DB::table('stock_financials')->count()],
        ['title' => 'Revenue rows', 'body' => (string) DB::table('stock_revenues')->count()],
        ['title' => 'Themes', 'body' => (string) DB::table('themes')->count()],
        ['title' => 'Global events', 'body' => (string) DB::table('global_events')->count()],
        ['title' => 'System jobs', 'body' => (string) DB::table('system_jobs')->count()],
        ['title' => 'AI logs', 'body' => (string) DB::table('ai_logs')->count()],
    ];

    return view('simple', [
        'heading' => '後台狀態',
        'description' => 'Job 狀態、資料覆蓋、AI 生成紀錄與系統治理入口。',
        'items' => $stats,
    ]);
});
