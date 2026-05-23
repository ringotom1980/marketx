<?php

use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $markets = [
        ['name' => '美股', 'state' => '待接 Global Engine', 'tone' => 'amber'],
        ['name' => '費半', 'state' => '待接 Global Engine', 'tone' => 'amber'],
        ['name' => 'VIX', 'state' => '待接 Global Engine', 'tone' => 'amber'],
        ['name' => '美債', 'state' => '待接 Global Engine', 'tone' => 'amber'],
        ['name' => '美元', 'state' => '待接 Global Engine', 'tone' => 'amber'],
    ];

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
            'decision' => $stock->decision ?? '待評分',
            'score' => $stock->total_score ?? 0,
        ]);

    return view('home', [
        'markets' => $markets,
        'events' => [
            ['title' => '全球事件引擎尚未啟用', 'impact' => 'Phase 8 將接新聞抓取、事件分類、產業分類與個股映射。'],
        ],
        'themes' => [
            ['name' => 'AI Server', 'score' => 0],
            ['name' => '散熱', 'score' => 0],
            ['name' => 'CoWoS', 'score' => 0],
            ['name' => '光通訊', 'score' => 0],
        ],
        'topStocks' => $topStocks,
        'riskStocks' => [
            ['name' => '法人賣超', 'risk' => '待接風險排序'],
            ['name' => '技術轉弱', 'risk' => '待接風險排序'],
            ['name' => '波動升高', 'risk' => '待接風險排序'],
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

    return view('stock', [
        'stock' => [
            'symbol' => $stockRecord->symbol,
            'name' => $stockRecord->name,
            'market' => $stockRecord->market,
            'close' => $latestPrice?->close ?? '待匯入',
            'change' => $latestPrice?->change ?? '待匯入',
            'volume' => $latestPrice?->volume ? number_format($latestPrice->volume) : '待匯入',
            'decision' => $latestScore?->decision ?? '待評分',
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
            '全球事件引擎尚未啟用',
            '-> Phase 8 將建立事件分類、產業分類與個股映射',
        ],
        'summary' => '目前 Decision Engine 已整合 Technical Score 與 Chip Score；Macro、Event、Theme、Fundamental 與 AI Explain 將依規劃分階段接上。',
    ]);
});

Route::get('/global', function () {
    return view('simple', [
        'heading' => '全球雷達',
        'description' => '追蹤美股、費半、VIX、DXY、美債、原油、黃金與台積電 ADR，並建立全球事件時間線。',
        'items' => [
            ['title' => '全球市場資料', 'body' => 'Global Engine 將負責抓取與標準化主要市場指標。'],
            ['title' => 'AI 全球推理', 'body' => 'Event Engine 與 AI Explain Engine 會把 Fed、AI、地緣政治等事件轉成產業影響鏈。'],
        ],
    ]);
});

Route::get('/themes', function () {
    return view('simple', [
        'heading' => '題材雷達',
        'description' => '題材熱度、資金流向、情緒變化與個股映射會集中在這裡。',
        'items' => [
            ['title' => '題材熱度排行', 'body' => 'AI、CoWoS、散熱、光通訊等題材會有每日 heat score。'],
            ['title' => '題材到個股', 'body' => 'Theme Engine 會維護題材與個股的權重與受惠理由。'],
        ],
    ]);
});

Route::get('/watchlist', function () {
    return view('simple', [
        'heading' => '追蹤清單',
        'description' => '自選股、每日更新與每日 AI 報告的入口。',
        'items' => [
            ['title' => '自選股', 'body' => '後續會接使用者資料與 watchlist table。'],
            ['title' => '每日報告', 'body' => '盤後 pipeline 完成後，會為追蹤清單產生重點摘要。'],
        ],
    ]);
});

Route::get('/admin', function () {
    $stats = [
        ['title' => '股票總數', 'body' => (string) DB::table('stocks')->count()],
        ['title' => '日 K 筆數', 'body' => (string) DB::table('stock_prices_1d')->count()],
        ['title' => '籌碼筆數', 'body' => (string) DB::table('stock_chips_1d')->count()],
        ['title' => '分數筆數', 'body' => (string) DB::table('stock_scores')->count()],
    ];

    return view('simple', [
        'heading' => '系統後台',
        'description' => 'Job 狀態、爬蟲狀態、AI 生成狀態、錯誤紀錄與手動重跑。',
        'items' => $stats,
    ]);
});

