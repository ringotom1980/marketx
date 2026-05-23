<?php

use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $featuredStocks = Stock::query()
        ->whereIn('symbol', ['2382', '3017'])
        ->get()
        ->keyBy('symbol');

    return view('home', [
        'markets' => [
            ['name' => '美股', 'state' => '偏強', 'tone' => 'green'],
            ['name' => '費半', 'state' => '強勢', 'tone' => 'green'],
            ['name' => 'VIX', 'state' => '低風險', 'tone' => 'green'],
            ['name' => '美債', 'state' => '壓力下降', 'tone' => 'blue'],
            ['name' => '美元', 'state' => '偏弱', 'tone' => 'amber'],
        ],
        'events' => [
            ['title' => 'NVIDIA 財報優於預期', 'impact' => 'AI Server、散熱與 CoWoS 題材維持高熱度'],
            ['title' => 'Fed 官員偏鴿', 'impact' => '科技股估值壓力下降，風險偏好回升'],
            ['title' => 'AI 晶片出口限制', 'impact' => '供應鏈需追蹤政策不確定性'],
        ],
        'themes' => [
            ['name' => 'AI Server', 'score' => 92],
            ['name' => '散熱', 'score' => 88],
            ['name' => 'CoWoS', 'score' => 86],
            ['name' => '光通訊', 'score' => 81],
        ],
        'topStocks' => [
            [
                'symbol' => '2382',
                'name' => $featuredStocks->get('2382')?->name ?? '廣達',
                'decision' => '買進',
                'score' => 82,
            ],
            [
                'symbol' => '3017',
                'name' => $featuredStocks->get('3017')?->name ?? '奇鋐',
                'decision' => '強力買進',
                'score' => 88,
            ],
        ],
        'riskStocks' => [
            ['name' => '高檔爆量股', 'risk' => '高檔爆量'],
            ['name' => '題材退潮股', 'risk' => '題材退潮'],
            ['name' => '法人轉賣股', 'risk' => '法人轉賣'],
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
            'latestScore',
        ])
        ->where('symbol', $symbol)
        ->firstOrFail();

    $latestPrice = $stockRecord->dailyPrices->first();
    $latestScore = $stockRecord->latestScore;
    $technicalScore = $latestScore?->technical_score ?? 0;
    $technicalPayload = $latestScore?->technical_payload;

    $decisionPack = match (true) {
        $technicalScore >= 85 => ['decision' => '強力買進', 'score' => $technicalScore, 'confidence' => 65],
        $technicalScore >= 70 => ['decision' => '買進', 'score' => $technicalScore, 'confidence' => 62],
        $technicalScore >= 55 => ['decision' => '續抱', 'score' => $technicalScore, 'confidence' => 58],
        $technicalScore >= 40 => ['decision' => '減碼', 'score' => $technicalScore, 'confidence' => 55],
        default => ['decision' => '賣出', 'score' => $technicalScore, 'confidence' => 50],
    };

    return view('stock', [
        'stock' => [
            'symbol' => $stockRecord->symbol,
            'name' => $stockRecord->name,
            'market' => $stockRecord->market,
            'close' => $latestPrice?->close ?? '待匯入',
            'change' => $latestPrice?->change ?? '待匯入',
            'volume' => $latestPrice?->volume ? number_format($latestPrice->volume) : '待匯入',
            'decision' => $decisionPack['decision'],
            'score' => $decisionPack['score'],
            'confidence' => $decisionPack['confidence'],
        ],
        'modules' => [
            ['name' => '全球宏觀', 'score' => 0],
            ['name' => '全球事件', 'score' => 0],
            ['name' => '題材熱度', 'score' => 0],
            ['name' => '技術結構', 'score' => $technicalScore],
            ['name' => '籌碼', 'score' => 0],
            ['name' => '財務營收', 'score' => 0],
        ],
        'technical' => $technicalPayload,
        'chain' => [
            '全球事件引擎尚未啟用',
            '-> Phase 8 將建立事件分類、產業分類與個股映射',
        ],
        'summary' => '目前決策卡先以 Technical Score 顯示技術結構分數；Macro、Event、Theme、Chip、Fundamental 與 AI Explain 將依規劃分階段接上。',
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
    return view('simple', [
        'heading' => '系統後台',
        'description' => 'Job 狀態、爬蟲狀態、AI 生成狀態、錯誤紀錄與手動重跑。',
        'items' => [
            ['title' => 'Job 狀態', 'body' => 'system_jobs 將記錄每個 pipeline 任務的開始、結束、耗時與錯誤。'],
            ['title' => 'AI 成本治理', 'body' => 'ai_logs 會追蹤模型、token、任務狀態與估算成本。'],
        ],
    ]);
});

