<?php

use App\Models\Stock;
use App\Support\ChipSignalAnalyzer;
use App\Support\EventClusterDisplay;
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
        ->where('stock_scores.macro_score', '>', 0)
        ->where('stock_scores.event_score', '>', 0)
        ->where('stock_scores.theme_score', '>', 0)
        ->where('stock_scores.technical_score', '>', 0)
        ->where('stock_scores.chip_score', '>', 0)
        ->where('stock_scores.fundamental_score', '>', 0)
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

    $events = DB::table('global_event_clusters')
        ->orderByDesc('cluster_date')
        ->orderByDesc('importance_score')
        ->limit(5)
        ->get(['title', 'summary', 'category', 'region', 'importance_score', 'sentiment', 'themes'])
        ->map(fn ($cluster) => [
            'title' => EventClusterDisplay::title($cluster),
            'impact' => EventClusterDisplay::body($cluster),
        ]);

    if ($events->isEmpty()) {
        $events = DB::table('global_events')
            ->orderByDesc('event_date')
            ->limit(4)
            ->get()
            ->map(fn ($event) => [
                'title' => MarketDisplay::eventTitle($event),
                'impact' => MarketDisplay::eventBody($event),
            ]);
    }

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
    $stockThemes = DB::table('stock_theme_map')
        ->join('themes', 'themes.id', '=', 'stock_theme_map.theme_id')
        ->leftJoin('theme_scores', function ($join) {
            $join->on('themes.id', '=', 'theme_scores.theme_id')
                ->whereRaw('theme_scores.score_date = (select max(ts.score_date) from theme_scores ts where ts.theme_id = themes.id)');
        })
        ->select(
            'themes.name',
            'stock_theme_map.weight',
            'stock_theme_map.reason',
            'theme_scores.heat_score',
            'theme_scores.news_score',
            'theme_scores.price_score',
            'theme_scores.chip_score',
            'theme_scores.score_date',
        )
        ->where('stock_theme_map.stock_id', $stockRecord->id)
        ->where('themes.is_active', true)
        ->orderByDesc('theme_scores.heat_score')
        ->orderByDesc('stock_theme_map.weight')
        ->limit(8)
        ->get()
        ->map(fn ($theme) => [
            'name' => $theme->name,
            'score' => (int) ($theme->heat_score ?? 0),
            'weight' => (int) ($theme->weight ?? 0),
            'reason' => $theme->reason ?: '由產業、關鍵字或規則式映射連到此題材。',
            'newsScore' => $theme->news_score,
            'priceScore' => $theme->price_score,
            'chipScore' => $theme->chip_score,
            'date' => $theme->score_date,
        ]);
    $themeModuleScore = (int) ($latestScore?->theme_score ?? 0);

    if ($themeModuleScore <= 0 && $stockThemes->isNotEmpty()) {
        $themeWeightSum = $stockThemes->sum(fn ($theme) => max(1, (int) $theme['weight']));
        $themeWeightedScore = $stockThemes->sum(fn ($theme) => (int) $theme['score'] * max(1, (int) $theme['weight']));
        $themeModuleScore = $themeWeightSum > 0 ? (int) round($themeWeightedScore / $themeWeightSum) : 0;
    }

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
            ['name' => '題材熱度', 'score' => $themeModuleScore],
            ['name' => '技術結構', 'score' => $latestScore?->technical_score ?? 0],
            ['name' => '籌碼', 'score' => $latestScore?->chip_score ?? 0],
            ['name' => '財務營收', 'score' => $latestScore?->fundamental_score ?? 0],
        ],
        'technical' => $technicalPayload,
        'chip' => $latestChip,
        'chipSignals' => $chipSignals,
        'stockThemes' => $stockThemes,
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
    $themePhase = function (int $score, int $newsScore, int $priceScore): string {
        if ($score >= 85 && ($newsScore >= 75 || $priceScore >= 75)) {
            return '高檔延續';
        }

        if ($score >= 70) {
            return '升溫中';
        }

        if ($score >= 55) {
            return '觀察延續';
        }

        if ($score >= 40) {
            return '熱度降溫';
        }

        return '題材退潮';
    };
    $themeTone = fn (int $score): string => match (true) {
        $score >= 70 => 'red',
        $score >= 45 => 'amber',
        default => 'green',
    };
    $themeReason = function (string $name, int $eventCount, int $mappedCount, mixed $newsScore, mixed $priceScore, mixed $chipScore): string {
        $parts = [];

        if ($eventCount > 0 || (int) ($newsScore ?? 0) > 0) {
            $parts[] = '近期新聞與事件有命中';
        }

        if ((int) ($priceScore ?? 0) >= 65) {
            $parts[] = '題材內股票技術面偏強';
        }

        if ((int) ($chipScore ?? 0) >= 65) {
            $parts[] = '題材內籌碼偏正向';
        }

        if ($mappedCount > 0) {
            $parts[] = '目前已連到 '.$mappedCount.' 檔相關股票';
        }

        if ($parts === []) {
            return $name.'目前資料仍在累積，先列入觀察。';
        }

        return $name.'主要因為'.implode('，', $parts).'。';
    };

    $themes = DB::table('themes')
        ->leftJoin('theme_scores', function ($join) {
            $join->on('themes.id', '=', 'theme_scores.theme_id')
                ->whereRaw('theme_scores.score_date = (select max(ts.score_date) from theme_scores ts where ts.theme_id = themes.id)');
        })
        ->select('themes.id', 'themes.name', 'themes.description', 'themes.ai_status', 'theme_scores.heat_score', 'theme_scores.news_score', 'theme_scores.price_score', 'theme_scores.chip_score', 'theme_scores.score_date')
        ->where('themes.is_active', true)
        ->orderByDesc('theme_scores.heat_score')
        ->orderBy('themes.name')
        ->limit(20)
        ->get()
        ->map(function ($theme) use ($themePhase, $themeTone, $themeReason) {
            $mappedCount = DB::table('stock_theme_map')->where('theme_id', $theme->id)->count();
            $eventCount = DB::table('theme_event_matches')->where('theme_id', $theme->id)->count();
            $score = (int) ($theme->heat_score ?? 0);
            $priceScore = $theme->price_score === null ? '無' : (string) $theme->price_score;
            $chipScore = $theme->chip_score === null ? '無' : (string) $theme->chip_score;
            $topStocks = DB::table('stock_theme_map')
                ->join('stocks', 'stocks.id', '=', 'stock_theme_map.stock_id')
                ->leftJoin('stock_scores', function ($join) {
                    $join->on('stocks.id', '=', 'stock_scores.stock_id')
                        ->whereRaw('stock_scores.score_date = (select max(ss.score_date) from stock_scores ss where ss.stock_id = stocks.id)');
                })
                ->where('stock_theme_map.theme_id', $theme->id)
                ->whereNotNull('stock_scores.total_score')
                ->orderByDesc('stock_scores.total_score')
                ->limit(4)
                ->get(['stocks.symbol', 'stocks.name', 'stock_scores.total_score'])
                ->map(fn ($stock) => [
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'score' => $stock->total_score,
                ])
                ->all();

            return [
                'name' => $theme->name,
                'score' => $score,
                'phase' => $themePhase($score, (int) ($theme->news_score ?? 0), (int) ($theme->price_score ?? 0)),
                'tone' => $themeTone($score),
                'reason' => $themeReason($theme->name, $eventCount, $mappedCount, $theme->news_score, $theme->price_score, $theme->chip_score),
                'event_count' => $eventCount,
                'stock_count' => $mappedCount,
                'price_score' => $priceScore,
                'chip_score' => $chipScore,
                'top_stocks' => $topStocks,
            ];
        });

    return view('themes', ['themes' => $themes]);
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
