<?php

use App\Models\Stock;
use App\Support\ChipSignalAnalyzer;
use App\Support\FundamentalSignalAnalyzer;
use App\Support\MarketDisplay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

Route::get('/login', function () {
    if (session()->get('marketx_admin') === true) {
        return redirect('/');
    }

    return view('login');
});

Route::post('/login', function (Request $request) {
    $request->validate([
        'password' => ['required', 'string'],
    ]);

    $hash = config('services.marketx.admin_password_hash');

    if (! $hash || ! Hash::check($request->string('password')->toString(), $hash)) {
        return back()
            ->withErrors(['password' => '密碼錯誤'])
            ->onlyInput();
    }

    $request->session()->regenerate();
    $request->session()->put('marketx_admin', true);

    return redirect()->intended('/');
});

Route::match(['get', 'post'], '/logout', function (Request $request) {
    $request->session()->forget('marketx_admin');
    $request->session()->regenerateToken();

    return redirect('/login');
});

Route::get('/', function () {
    $markets = DB::table('global_market_data')
        ->orderByDesc('trade_date')
        ->limit(5)
        ->get()
        ->map(fn ($row) => [
            'name' => MarketDisplay::indicatorName($row->indicator),
            'state' => MarketDisplay::stateName($row->state),
            'tone' => MarketDisplay::tone($row->state, $row->change_pct === null ? null : (float) $row->change_pct),
        ]);

    if ($markets->isEmpty()) {
        $markets = collect([
            ['name' => '美股', 'state' => '等待資料匯入', 'tone' => 'amber'],
            ['name' => '費半', 'state' => '等待資料匯入', 'tone' => 'amber'],
            ['name' => 'VIX', 'state' => '等待資料匯入', 'tone' => 'amber'],
            ['name' => '美債', 'state' => '等待資料匯入', 'tone' => 'amber'],
            ['name' => '美元', 'state' => '等待資料匯入', 'tone' => 'amber'],
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
            'decision' => $stock->decision ?? '等待計算',
            'score' => $stock->total_score ?? 0,
        ]);

    $events = DB::table('global_events')
        ->orderByDesc('event_date')
        ->limit(4)
        ->get()
        ->map(fn ($event) => [
            'title' => MarketDisplay::eventTitle($event),
            'impact' => MarketDisplay::eventBody($event),
        ]);

    if ($events->isEmpty()) {
        $events = collect([
            ['title' => '全球事件資料準備中', 'impact' => '尚未匯入全球新聞與事件。'],
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
        ->orderBy('themes.name')
        ->limit(12)
        ->get()
        ->map(fn ($theme) => [
            'name' => $theme->name,
            'score' => (int) ($theme->heat_score ?? 0),
        ]);

    return view('home', [
        'markets' => $markets,
        'events' => $events,
        'themes' => $themes,
        'topStocks' => $topStocks,
        'riskStocks' => [
            ['name' => '高檔震盪觀察', 'risk' => '題材熱度轉弱、量能放大或法人轉賣時列入警示。'],
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
            ->orderByRaw('CASE WHEN symbol LIKE ? THEN 0 ELSE 1 END', [$query.'%'])
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
    $recentChips = $stockRecord->chips()->latest('trade_date')->limit(5)->get();
    $recentPrices = $stockRecord->dailyPrices()->latest('trade_date')->limit(20)->get();
    $chipSignals = app(ChipSignalAnalyzer::class)->analyze($stockRecord, $recentChips, $recentPrices);
    $latestFinancial = DB::table('stock_financials')->where('stock_id', $stockRecord->id)->orderByDesc('period')->first();
    $latestRevenue = DB::table('stock_revenues')->where('stock_id', $stockRecord->id)->orderByDesc('year_month')->first();
    $fundamentalSignals = app(FundamentalSignalAnalyzer::class)->analyze($stockRecord, $latestFinancial, $latestRevenue);

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
            'decision' => $latestScore?->decision ?? '等待計算',
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
        'chipSignals' => $chipSignals,
        'fundamentalSignals' => $fundamentalSignals,
        'chain' => [
            '全球市場與事件',
            '→ 產業與題材影響',
            '→ 台股族群資金流',
            '→ 個股分數與風險',
        ],
        'summary' => $latestReport?->summary
            ?: '目前先使用免費規則式中文解釋引擎，依技術、籌碼、財務與題材分數整理風險摘要。AI 介面已預留，之後可接 OpenAI 或其他模型。',
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
        'title' => MarketDisplay::indicatorName($row->indicator).'｜'.$row->trade_date,
        'body' => '狀態：'.MarketDisplay::stateName($row->state)
            .'｜數值：'.number_format((float) $row->value, 2)
            .'｜漲跌：'.($row->change_pct === null ? '無資料' : number_format((float) $row->change_pct, 2).'%'),
    ])->merge($eventRows->map(fn ($row) => [
        'title' => MarketDisplay::eventTitle($row),
        'body' => MarketDisplay::eventBody($row),
    ]));

    if ($items->isEmpty()) {
        $items = collect([
            ['title' => '全球雷達資料準備中', 'body' => '尚未匯入全球市場與事件資料。'],
        ]);
    }

    return view('simple', [
        'heading' => '全球雷達',
        'description' => '追蹤美股、費半、VIX、美債、美元、原油、黃金與台積電 ADR，並整理全球事件對台股題材的可能影響。',
        'items' => $items,
    ]);
});

Route::get('/themes', function () {
    $themes = DB::table('themes')
        ->leftJoin('theme_scores', function ($join) {
            $join->on('themes.id', '=', 'theme_scores.theme_id')
                ->whereRaw('theme_scores.score_date = (select max(ts.score_date) from theme_scores ts where ts.theme_id = themes.id)');
        })
        ->select('themes.id', 'themes.name', 'themes.description', 'themes.ai_status', 'theme_scores.heat_score', 'theme_scores.news_score', 'theme_scores.price_score', 'theme_scores.chip_score', 'theme_scores.score_date')
        ->where('themes.is_active', true)
        ->orderByDesc('theme_scores.heat_score')
        ->orderBy('themes.name')
        ->get()
        ->map(function ($theme) {
            $mappedCount = DB::table('stock_theme_map')->where('theme_id', $theme->id)->count();
            $eventCount = DB::table('theme_event_matches')->where('theme_id', $theme->id)->count();
            $score = $theme->heat_score === null ? '尚未計分' : ((int) $theme->heat_score).' / 100';

            return [
                'title' => $theme->name.'｜熱度 '.$score,
                'body' => ($theme->description ?: '由事件、關鍵字與市場資料動態維護的題材。')
                    .'｜映射股票：'.$mappedCount.' 檔'
                    .'｜新聞事件：'.$eventCount.' 筆'
                    .'｜新聞分數：'.($theme->news_score ?? '無')
                    .'｜技術分數：'.($theme->price_score ?? '無')
                    .'｜籌碼分數：'.($theme->chip_score ?? '無')
                    .'｜AI 狀態：'.($theme->ai_status ?? '預留')
                    .'｜更新日：'.($theme->score_date ?? '尚未更新'),
            ];
        });

    if ($themes->isEmpty()) {
        $themes = collect([
            ['title' => '題材資料準備中', 'body' => '尚未產生題材熱度資料。'],
        ]);
    }

    return view('simple', [
        'heading' => '題材雷達',
        'description' => '依新聞事件、題材關鍵字、股票映射、技術分數與籌碼分數計算題材熱度。AI 欄位已預留，目前先使用免費規則式版本。',
        'items' => $themes,
    ]);
});

Route::get('/watchlist', function () {
    return view('simple', [
        'heading' => '追蹤清單',
        'description' => '自選股功能將用來集中查看每日分數、風險變化與規則式摘要。',
        'items' => [
            ['title' => '追蹤清單資料表已預留', 'body' => '下一階段可加入新增、刪除與每日報告功能。'],
            ['title' => '每日摘要可串接', 'body' => '之後可依自選股產生盤後重點整理。'],
        ],
    ]);
});

Route::get('/admin', function () {
    $stats = [
        ['title' => '股票檔數', 'body' => (string) DB::table('stocks')->count()],
        ['title' => '日 K 筆數', 'body' => (string) DB::table('stock_prices_1d')->count()],
        ['title' => '籌碼筆數', 'body' => (string) DB::table('stock_chips_1d')->count()],
        ['title' => '個股融資融券筆數', 'body' => (string) DB::table('stock_chips_1d')->whereNotNull('margin_balance')->count()],
        ['title' => '大盤融資融券筆數', 'body' => (string) DB::table('market_margins_1d')->count()],
        ['title' => '分數筆數', 'body' => (string) DB::table('stock_scores')->count()],
        ['title' => '財報筆數', 'body' => (string) DB::table('stock_financials')->count()],
        ['title' => '月營收筆數', 'body' => (string) DB::table('stock_revenues')->count()],
        ['title' => '題材數量', 'body' => (string) DB::table('themes')->count()],
        ['title' => '題材關鍵字', 'body' => (string) DB::table('theme_keywords')->count()],
        ['title' => '題材事件命中', 'body' => (string) DB::table('theme_event_matches')->count()],
        ['title' => '全球事件筆數', 'body' => (string) DB::table('global_events')->count()],
        ['title' => '系統工作紀錄', 'body' => (string) DB::table('system_jobs')->count()],
        ['title' => 'AI 紀錄', 'body' => (string) DB::table('ai_logs')->count()],
    ];

    return view('simple', [
        'heading' => '後台',
        'description' => '查看資料匯入、Job 狀態、AI 預留紀錄與目前資料庫累積狀態。',
        'items' => $stats,
    ]);
});
