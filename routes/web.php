<?php

use App\Models\Stock;
use App\Models\User;
use App\Support\ChipSignalAnalyzer;
use App\Support\EventClusterDisplay;
use App\Support\FundamentalSignalAnalyzer;
use App\Support\GlobalRadarBuilder;
use App\Support\MarketxAuth;
use App\Support\MarketDisplay;
use App\Support\ModuleStateDisplay;
use App\Support\Ai\AiPipelineService;
use App\Support\Ai\AiUsageLimiter;
use App\Support\StockEventChainBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

Route::get('/login', function () {
    if (session()->get('marketx_admin') === true || session()->has('marketx_user_id')) {
        return redirect('/');
    }

    return view('login', ['mode' => 'login']);
});

Route::post('/login', function (Request $request) {
    $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
    ]);

    $email = $request->string('email')->lower()->toString();
    $password = $request->string('password')->toString();
    $adminEmail = strtolower((string) config('services.marketx.admin_email', 'admin@marketx.local'));
    $hash = config('services.marketx.admin_password_hash');

    if ($email === $adminEmail && $hash && Hash::check($password, $hash)) {
        $user = User::query()->firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => '管理者',
                'password' => $password,
                'is_admin' => true,
            ]
        );

        if (! $user->is_admin || ! Hash::check($password, $user->password)) {
            $user->forceFill([
                'password' => $password,
                'is_admin' => true,
            ])->save();
        }
    } else {
        $user = User::query()->where('email', $email)->first();
    }

    if (! $user || ! Hash::check($password, $user->password)) {
        DB::table('system_logs')->insert([
            'level' => 'warning',
            'source' => 'auth',
            'message' => '登入失敗',
            'context' => json_encode([
                'email' => $email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()
            ->withErrors(['email' => '帳號或密碼錯誤'])
            ->onlyInput('email');
    }

    $request->session()->regenerate();
    $request->session()->put('marketx_user_id', $user->id);
    $request->session()->put('marketx_user_name', $user->name);
    $request->session()->put('marketx_is_admin', (bool) $user->is_admin);
    DB::table('sessions')
        ->where('id', $request->session()->getId())
        ->update(['user_id' => $user->id]);
    DB::table('system_logs')->insert([
        'level' => 'info',
        'source' => 'auth',
        'message' => $user->is_admin ? '管理者登入成功' : '會員登入成功',
        'context' => json_encode([
            'user_id' => $user->id,
            'email' => $user->email,
            'is_admin' => (bool) $user->is_admin,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ], JSON_UNESCAPED_UNICODE),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return redirect()->intended('/');
});

Route::get('/register', function () {
    if (session()->get('marketx_admin') === true || session()->has('marketx_user_id')) {
        return redirect('/');
    }

    return view('login', ['mode' => 'register']);
});

Route::post('/register', function (Request $request) {
    $adminEmail = strtolower((string) config('services.marketx.admin_email', 'admin@marketx.local'));
    $validated = $request->validate([
        'name' => ['required', 'string', 'max:50'],
        'email' => ['required', 'email', 'max:255', 'unique:users,email', 'not_in:'.$adminEmail],
        'password' => ['required', 'string', 'min:8', 'confirmed'],
    ]);

    $user = User::query()->create([
        'name' => $validated['name'],
        'email' => strtolower($validated['email']),
        'password' => $validated['password'],
        'is_admin' => false,
    ]);

    $request->session()->regenerate();
    $request->session()->put('marketx_user_id', $user->id);
    $request->session()->put('marketx_user_name', $user->name);
    $request->session()->put('marketx_is_admin', false);
    DB::table('sessions')
        ->where('id', $request->session()->getId())
        ->update(['user_id' => $user->id]);

    return redirect('/');
});

Route::match(['get', 'post'], '/logout', function (Request $request) {
    DB::table('sessions')
        ->where('id', $request->session()->getId())
        ->update(['user_id' => null]);

    $request->session()->forget('marketx_admin');
    $request->session()->forget('marketx_user_id');
    $request->session()->forget('marketx_user_name');
    $request->session()->forget('marketx_is_admin');
    $request->session()->regenerateToken();

    return redirect('/login');
});

Route::get('/', function () {
    $payloadNumber = function (array $payload, array $keys): ?float {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = str_replace([',', '%'], '', trim((string) $payload[$key]));

            if ($value !== '' && is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    };

    $aggregateK = function ($rows, callable $keyResolver): array {
        return $rows
            ->groupBy($keyResolver)
            ->map(function ($group, $key) {
                $first = $group->first();
                $last = $group->last();

                return [
                    'time' => (string) $key,
                    'open' => (float) $first['open'],
                    'high' => (float) $group->max('high'),
                    'low' => (float) $group->min('low'),
                    'close' => (float) $last['close'],
                    'volume' => (int) $group->sum('volume'),
                ];
            })
            ->values()
            ->all();
    };

    $buildIndicatorK = function (string $indicator) use ($payloadNumber, $aggregateK): array {
        $previousClose = null;
        $rows = DB::table('global_market_data')
            ->where('indicator', $indicator)
            ->whereNotNull('value')
            ->orderBy('trade_date')
            ->limit(720)
            ->get(['trade_date', 'value', 'raw_payload'])
            ->map(function ($row) use (&$previousClose, $payloadNumber) {
                $payload = json_decode((string) $row->raw_payload, true) ?: [];
                $close = $payloadNumber($payload, ['close', 'Close', 'ClosingPrice', 'Last']) ?? (float) $row->value;
                $open = $payloadNumber($payload, ['open', 'Open', 'OpeningPrice']) ?? $previousClose ?? $close;
                $high = $payloadNumber($payload, ['high', 'High', 'HighestPrice', 'Highest']) ?? max($open, $close);
                $low = $payloadNumber($payload, ['low', 'Low', 'LowestPrice', 'Lowest']) ?? min($open, $close);
                $volume = $payloadNumber($payload, ['volume', 'Volume', 'TradingVolume', 'TotalVolume']) ?? 0;
                $date = \Carbon\CarbonImmutable::parse($row->trade_date, 'Asia/Taipei');
                $previousClose = $close;

                return [
                    'date' => $date,
                    'time' => $date->toDateString(),
                    'open' => (float) $open,
                    'high' => (float) max($high, $open, $close),
                    'low' => (float) min($low, $open, $close),
                    'close' => (float) $close,
                    'volume' => (int) $volume,
                ];
            })
            ->filter(fn ($row) => $row['close'] > 0)
            ->values();

        $daily = $rows->slice(-260)
            ->map(fn ($row) => [
                'time' => $row['time'],
                'open' => $row['open'],
                'high' => $row['high'],
                'low' => $row['low'],
                'close' => $row['close'],
                'volume' => $row['volume'],
            ])
            ->values()
            ->all();

        return [
            'daily' => $daily,
            'weekly' => array_slice($aggregateK($rows, fn ($row) => $row['date']->format('o-\WW')), -160),
            'monthly' => array_slice($aggregateK($rows, fn ($row) => $row['date']->format('Y-m')), -120),
        ];
    };

    $marketCharts = [
        [
            'id' => 'taiex',
            'title' => '台股大盤 K 線',
            'subtitle' => '加權指數，日 K / 周 K / 月 K',
            'source' => 'Yahoo Finance ^TWII',
            'ranges' => $buildIndicatorK('TAIEX'),
        ],
        [
            'id' => 'tx-night',
            'title' => '台股夜盤 K 線',
            'subtitle' => 'TAIFEX TX 夜盤，日 K / 周 K / 月 K',
            'source' => '臺灣期貨交易所：期貨每日交易行情下載',
            'ranges' => $buildIndicatorK('TAIFEX TX Night'),
        ],
    ];

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

    $payloadFor = function (object $stock): array {
        $payload = is_array($stock->technical_payload)
            ? $stock->technical_payload
            : (json_decode((string) $stock->technical_payload, true) ?: []);

        return is_array($payload) ? $payload : [];
    };

    $signalsFor = function (object $stock, array $tones = [] ) use ($payloadFor): \Illuminate\Support\Collection {
        return collect($payloadFor($stock)['signals'] ?? [])
            ->filter(fn ($signal) => empty($tones) || in_array($signal['tone'] ?? null, $tones, true))
            ->pluck('title')
            ->map(fn ($title) => (string) $title)
            ->filter()
            ->values();
    };

    $reason = fn (string $label, string $tone = 'up') => ['label' => $label, 'tone' => $tone];

    $hasSignal = fn (\Illuminate\Support\Collection $signals, string $title): bool => $signals->contains($title);

    $bais20 = function (object $stock) use ($payloadFor): ?float {
        $payload = $payloadFor($stock);
        $close = (float) ($stock->close ?? 0);
        $sma20 = isset($payload['sma20']) ? (float) $payload['sma20'] : 0.0;

        return $close > 0 && $sma20 > 0 ? (($close / $sma20) - 1) * 100 : null;
    };

    $priorityReasons = function (object $stock) use ($signalsFor, $reason, $hasSignal, $bais20): array {
        $technicalSignals = $signalsFor($stock, ['green']);

        $bais = $bais20($stock);

        $reasons = collect();

        foreach (['MACD 黃金交叉', 'KD 黃金交叉', '20 日突破', '價漲量增', '均線多頭排列', 'MACD 翻正', '突破布林上緣', 'RSI 強勢區'] as $preferred) {
            if ($hasSignal($technicalSignals, $preferred)) {
                $reasons->push($reason($preferred));
            }
        }

        if ($bais !== null && abs($bais) <= 10) {
            $reasons->push($reason('乖離正常'));
        }

        if ((int) ($stock->chip_score ?? 0) >= 78) {
            $reasons->push($reason('籌碼集中'));
        }

        if ((int) ($stock->theme_score ?? 0) >= 76) {
            $reasons->push($reason('題材升溫'));
        }

        if ((float) ($stock->yoy_pct ?? 0) > 10 || (float) ($stock->mom_pct ?? 0) > 5) {
            $reasons->push($reason('營收轉強'));
        }

        if ((float) ($stock->per ?? 0) > 0 && (float) $stock->per <= 25) {
            $reasons->push($reason('評價合理'));
        }

        return $reasons
            ->unique('label')
            ->take(3)
            ->values()
            ->all();
    };

    $riskReasons = function (object $stock) use ($signalsFor, $reason, $hasSignal, $bais20): array {
        $technicalSignals = $signalsFor($stock, ['red', 'amber'])
            ->reject(fn ($title) => in_array($title, ['MACD 負數縮減'], true))
            ->values();
        $bais = $bais20($stock);
        $reasons = collect();

        foreach (['MACD 死亡交叉', 'KD 死亡交叉', 'MACD 正數縮小', '跌破月線', '跌破布林下緣', '高檔放量轉弱', '20 日動能弱', 'RSI 弱勢'] as $preferred) {
            if ($hasSignal($technicalSignals, $preferred)) {
                $reasons->push($reason($preferred, str_contains($preferred, '過熱') || str_contains($preferred, '量能') ? 'warning' : 'down'));
            }
        }

        if ($bais !== null && $bais >= 14) {
            $reasons->push($reason('乖離過大', 'warning'));
        }

        if ((float) ($stock->per ?? 0) > 40 && (float) ($stock->yoy_pct ?? 0) < 10) {
            $reasons->push($reason('評價偏高', 'warning'));
        }

        if ((float) ($stock->volume_ratio20 ?? 0) >= 1.5 && (float) ($stock->change_pct ?? 0) <= 0) {
            $reasons->push($reason('爆量轉弱', 'down'));
        }

        if ((float) ($stock->margin_balance ?? 0) > 0 && (float) ($stock->volume ?? 0) > 0 && ((float) $stock->margin_balance / max(1, (float) $stock->volume)) >= 5) {
            $reasons->push($reason('融資偏重', 'warning'));
        }

        return $reasons
            ->merge($technicalSignals->map(fn ($signal) => $reason($signal, str_contains($signal, '過熱') || str_contains($signal, '量能') ? 'warning' : 'down')))
            ->unique('label')
            ->take(3)
            ->values()
            ->all();
    };

    $potentialReasons = function (object $stock) use ($signalsFor, $reason, $hasSignal, $bais20): array {
        $technicalSignals = $signalsFor($stock, ['green', 'amber']);
        $bais = $bais20($stock);
        $reasons = collect();

        if ((int) ($stock->theme_score ?? 0) >= 50 && (int) ($stock->theme_score ?? 0) < 76) {
            $reasons->push($reason('題材初升溫', 'warning'));
        }

        if ($bais !== null && $bais >= -6 && $bais <= 8) {
            $reasons->push($reason('低乖離', 'warning'));
        }

        if ((float) ($stock->volume_ratio20 ?? 0) >= 1.15 && (float) ($stock->volume_ratio20 ?? 0) < 1.8) {
            $reasons->push($reason('量能轉強', 'warning'));
        }

        if ((float) ($stock->per ?? 0) > 0 && (float) $stock->per <= 25) {
            $reasons->push($reason('評價合理', 'warning'));
        }

        if ((float) ($stock->yoy_pct ?? 0) > 0 || (float) ($stock->mom_pct ?? 0) > 0) {
            $reasons->push($reason('營收改善', 'warning'));
        }

        foreach (['MACD 負數縮減', '量能不足'] as $watch) {
            if ($hasSignal($technicalSignals, $watch)) {
                $reasons->push($reason($watch, 'warning'));
            }
        }

        return $reasons->unique('label')->take(3)->values()->all();
    };

    $lowVolumeReasons = function (object $stock) use ($signalsFor, $reason, $hasSignal): array {
        $technicalSignals = $signalsFor($stock);
        $reasons = collect();

        if ((float) ($stock->return20 ?? 0) <= -5) {
            $reasons->push($reason('低檔區', 'warning'));
        }

        if ((float) ($stock->volume_ratio20 ?? 0) >= 1.5) {
            $reasons->push($reason('低檔爆量', 'warning'));
        }

        if ((float) ($stock->change_pct ?? 0) >= 0) {
            $reasons->push($reason('跌深止穩', 'warning'));
        }

        if ((int) ($stock->chip_score ?? 0) >= 60) {
            $reasons->push($reason('籌碼承接', 'warning'));
        }

        foreach (['MACD 負數縮減', 'KD 黃金交叉'] as $watch) {
            if ($hasSignal($technicalSignals, $watch)) {
                $reasons->push($reason($watch, $watch === 'KD 黃金交叉' ? 'up' : 'warning'));
            }
        }

        return $reasons->unique('label')->take(3)->values()->all();
    };

    $weakReasons = function (object $stock) use ($signalsFor, $reason, $hasSignal): array {
        $technicalSignals = $signalsFor($stock, ['red', 'amber']);
        $reasons = collect();

        foreach (['跌破月線', 'MACD 死亡交叉', 'KD 死亡交叉', '20 日動能弱', 'RSI 弱勢', 'EMA 動能偏弱', '月線低於季線'] as $preferred) {
            if ($hasSignal($technicalSignals, $preferred)) {
                $reasons->push($reason($preferred, 'down'));
            }
        }

        if ((int) ($stock->theme_score ?? 0) < 45) {
            $reasons->push($reason('題材退燒', 'down'));
        }

        if ((float) ($stock->yoy_pct ?? 0) < -10 || (float) ($stock->mom_pct ?? 0) < -10) {
            $reasons->push($reason('營收衰退', 'down'));
        }

        if ((int) ($stock->chip_score ?? 100) < 45) {
            $reasons->push($reason('籌碼偏弱', 'down'));
        }

        return $reasons->unique('label')->take(3)->values()->all();
    };

    $stockCandidates = Stock::query()
        ->join('stock_scores', function ($join) {
            $join->on('stocks.id', '=', 'stock_scores.stock_id')
                ->whereRaw('stock_scores.score_date = (select max(ss.score_date) from stock_scores ss where ss.stock_id = stocks.id)');
        })
        ->leftJoin('stock_prices_1d', function ($join) {
            $join->on('stocks.id', '=', 'stock_prices_1d.stock_id')
                ->whereRaw('stock_prices_1d.trade_date = (select max(sp.trade_date) from stock_prices_1d sp where sp.stock_id = stocks.id)');
        })
        ->leftJoin('stock_chips_1d', function ($join) {
            $join->on('stocks.id', '=', 'stock_chips_1d.stock_id')
                ->whereRaw('stock_chips_1d.trade_date = (select max(sc.trade_date) from stock_chips_1d sc where sc.stock_id = stocks.id)');
        })
        ->leftJoin('stock_financials', function ($join) {
            $join->on('stocks.id', '=', 'stock_financials.stock_id')
                ->whereRaw('stock_financials.period = (select max(sf.period) from stock_financials sf where sf.stock_id = stocks.id)');
        })
        ->leftJoin('stock_revenues', function ($join) {
            $join->on('stocks.id', '=', 'stock_revenues.stock_id')
                ->whereRaw('stock_revenues.year_month = (select max(sr.year_month) from stock_revenues sr where sr.stock_id = stocks.id)');
        })
        ->select(
            'stocks.symbol',
            'stocks.name',
            'stock_scores.total_score',
            'stock_scores.confidence_score',
            'stock_scores.theme_score',
            'stock_scores.technical_score',
            'stock_scores.chip_score',
            'stock_scores.fundamental_score',
            'stock_scores.technical_payload',
            'stock_prices_1d.close',
            'stock_prices_1d.change_pct',
            'stock_prices_1d.volume',
            'stock_chips_1d.margin_balance',
            'stock_chips_1d.short_balance',
            'stock_financials.per',
            'stock_revenues.mom_pct',
            'stock_revenues.yoy_pct'
        )
        ->whereNotNull('stock_scores.total_score')
        ->where('stock_scores.technical_score', '>', 0)
        ->orderByDesc('stock_scores.confidence_score')
        ->orderByDesc('stock_scores.total_score')
        ->orderBy('stocks.symbol')
        ->limit(180)
        ->get()
        ->map(function ($stock) use ($payloadFor) {
            $payload = $payloadFor($stock);
            $stock->return20 = (float) ($payload['return20'] ?? 0);
            $stock->volume_ratio20 = (float) ($payload['volume_ratio20'] ?? 0);

            return $stock;
        });

    $stockCardItem = fn (object $stock, array $reasons): array => [
        'symbol' => $stock->symbol,
        'name' => $stock->name,
        'confidence' => (int) ($stock->confidence_score ?? 0),
        'reasons' => $reasons,
    ];

    $topStocks = $stockCandidates
        ->filter(fn ($stock) => (int) ($stock->confidence_score ?? 0) >= 65
            && (int) ($stock->total_score ?? 0) >= 58
            && ($bais20($stock) === null || abs($bais20($stock)) <= 12)
            && $priorityReasons($stock) !== [])
        ->map(fn ($stock) => $stockCardItem($stock, $priorityReasons($stock)))
        ->take(6)
        ->values();
    $usedSymbols = $topStocks->pluck('symbol')->all();

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

    $riskStocks = $stockCandidates
        ->reject(fn ($stock) => in_array($stock->symbol, $usedSymbols, true))
        ->filter(fn ($stock) => (int) ($stock->confidence_score ?? 0) >= 55
            && ((int) ($stock->theme_score ?? 0) >= 55 || (int) ($stock->technical_score ?? 0) >= 45)
            && $riskReasons($stock) !== [])
        ->map(fn ($stock) => $stockCardItem($stock, $riskReasons($stock)))
        ->take(6)
        ->values();
    $usedSymbols = array_merge($usedSymbols, $riskStocks->pluck('symbol')->all());

    $potentialStocks = $stockCandidates
        ->reject(fn ($stock) => in_array($stock->symbol, $usedSymbols, true))
        ->filter(fn ($stock) => (int) ($stock->confidence_score ?? 0) >= 50
            && (int) ($stock->total_score ?? 0) >= 48
            && $potentialReasons($stock) !== [])
        ->map(fn ($stock) => $stockCardItem($stock, $potentialReasons($stock)))
        ->take(6)
        ->values();
    $usedSymbols = array_merge($usedSymbols, $potentialStocks->pluck('symbol')->all());

    $lowVolumeStocks = $stockCandidates
        ->reject(fn ($stock) => in_array($stock->symbol, $usedSymbols, true))
        ->filter(fn ($stock) => (float) ($stock->return20 ?? 0) <= -5
            && (float) ($stock->volume_ratio20 ?? 0) >= 1.35
            && $lowVolumeReasons($stock) !== [])
        ->sortByDesc(fn ($stock) => (float) ($stock->volume_ratio20 ?? 0))
        ->map(fn ($stock) => $stockCardItem($stock, $lowVolumeReasons($stock)))
        ->take(6)
        ->values();
    $usedSymbols = array_merge($usedSymbols, $lowVolumeStocks->pluck('symbol')->all());

    $weakStocks = $stockCandidates
        ->reject(fn ($stock) => in_array($stock->symbol, $usedSymbols, true))
        ->filter(fn ($stock) => ((int) ($stock->technical_score ?? 0) < 48 || (float) ($stock->return20 ?? 0) <= -8)
            && $weakReasons($stock) !== [])
        ->sortBy(fn ($stock) => (int) ($stock->confidence_score ?? 0))
        ->map(fn ($stock) => $stockCardItem($stock, $weakReasons($stock)))
        ->take(6)
        ->values();

    $stockRadarCards = collect([
        ['title' => '優先觀察', 'empty' => '尚未產生觀察名單', 'items' => $topStocks],
        ['title' => '風險升高', 'empty' => '目前沒有明顯風險升高名單', 'items' => $riskStocks],
        ['title' => '潛力觀察', 'empty' => '尚未找到潛力觀察名單', 'items' => $potentialStocks],
        ['title' => '低檔爆量', 'empty' => '目前沒有明顯低檔爆量名單', 'items' => $lowVolumeStocks],
        ['title' => '持續弱勢', 'empty' => '目前沒有明顯持續弱勢名單', 'items' => $weakStocks],
    ]);

    return view('home', [
        'markets' => $markets,
        'events' => $events,
        'themes' => $themes,
        'topStocks' => $topStocks,
        'riskStocks' => $riskStocks,
        'stockRadarCards' => $stockRadarCards,
        'marketCharts' => $marketCharts,
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

Route::get('/s/{symbol}', function (string $symbol, StockEventChainBuilder $eventChainBuilder) {
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
    $isWatched = DB::table('watchlist')
        ->when(MarketxAuth::userId() === null, fn ($query) => $query->whereNull('user_id'), fn ($query) => $query->where('user_id', MarketxAuth::userId()))
        ->where('stock_id', $stockRecord->id)
        ->exists();
    $technicalPayload = $latestScore?->technical_payload;
    $recentChips = $stockRecord->chips()->latest('trade_date')->limit(5)->get();
    $recentPrices = $stockRecord->dailyPrices()->latest('trade_date')->limit(20)->get();
    $chipSignals = app(ChipSignalAnalyzer::class)->analyze($stockRecord, $recentChips, $recentPrices);
    $latestFinancial = DB::table('stock_financials')->where('stock_id', $stockRecord->id)->orderByDesc('period')->first();
    $latestRevenue = DB::table('stock_revenues')->where('stock_id', $stockRecord->id)->orderByDesc('year_month')->first();
    $fundamentalSignals = app(FundamentalSignalAnalyzer::class)->analyze($stockRecord, $latestFinancial, $latestRevenue);
    $priceRows = $stockRecord->dailyPrices()
        ->whereNotNull('open')
        ->whereNotNull('high')
        ->whereNotNull('low')
        ->whereNotNull('close')
        ->orderBy('trade_date')
        ->limit(1400)
        ->get(['trade_date', 'open', 'high', 'low', 'close', 'volume']);
    $dailyK = $priceRows
        ->slice(-260)
        ->values()
        ->map(fn ($row) => [
            'time' => $row->trade_date->toDateString(),
            'open' => (float) $row->open,
            'high' => (float) $row->high,
            'low' => (float) $row->low,
            'close' => (float) $row->close,
            'volume' => (int) ($row->volume ?? 0),
        ])
        ->all();
    $aggregateK = function ($rows, callable $keyResolver): array {
        return $rows
            ->groupBy($keyResolver)
            ->map(function ($group, $key) {
                $first = $group->first();
                $last = $group->last();

                return [
                    'time' => (string) $key,
                    'open' => (float) $first->open,
                    'high' => (float) $group->max('high'),
                    'low' => (float) $group->min('low'),
                    'close' => (float) $last->close,
                    'volume' => (int) $group->sum('volume'),
                ];
            })
            ->values()
            ->all();
    };
    $weeklyK = array_slice($aggregateK(
        $priceRows,
        fn ($row) => $row->trade_date->format('o-\WW')
    ), -160);
    $yearlyK = $aggregateK(
        $priceRows,
        fn ($row) => $row->trade_date->format('Y')
    );
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
    $eventChains = $eventChainBuilder->build($stockRecord, $latestScore);

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
            'isWatched' => $isWatched,
        ],
        'modules' => collect([
            ['name' => '全球宏觀', 'score' => $latestScore?->macro_score ?? 0],
            ['name' => '全球事件', 'score' => $latestScore?->event_score ?? 0],
            ['name' => '題材熱度', 'score' => $themeModuleScore],
            ['name' => '技術結構', 'score' => $latestScore?->technical_score ?? 0],
            ['name' => '籌碼', 'score' => $latestScore?->chip_score ?? 0],
            ['name' => '財務營收', 'score' => $latestScore?->fundamental_score ?? 0],
        ])->map(function ($module) {
            return $module + ModuleStateDisplay::fromScore($module['score'], $module['name']);
        })->all(),
        'technical' => $technicalPayload,
        'chartData' => [
            'intraday' => [],
            'daily' => $dailyK,
            'weekly' => $weeklyK,
            'yearly' => $yearlyK,
        ],
        'chip' => $latestChip,
        'chipSignals' => $chipSignals,
        'stockThemes' => $stockThemes,
        'fundamentalSignals' => $fundamentalSignals,
        'eventChains' => $eventChains,
        'summary' => $latestReport?->summary
            ?: '目前先使用免費規則式中文解釋引擎，依技術、籌碼、財務與題材分數整理風險摘要。AI 介面已預留，之後可接 OpenAI 或其他模型。',
    ]);
});

Route::get('/global', function (GlobalRadarBuilder $builder) {
    return view('global', ['radar' => $builder->build()]);
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
        ->map(function ($theme) use ($themePhase, $themeTone) {
            $mappedCount = DB::table('stock_theme_map')->where('theme_id', $theme->id)->count();
            $eventCount = DB::table('theme_event_matches')->where('theme_id', $theme->id)->count();
            $eventRegions = DB::table('theme_event_matches')
                ->leftJoin('global_events', 'global_events.id', '=', 'theme_event_matches.global_event_id')
                ->where('theme_event_matches.theme_id', $theme->id)
                ->selectRaw("sum(case when lower(coalesce(global_events.region, '')) in ('tw', 'taiwan', '台灣') or lower(coalesce(global_events.source, '')) like '%taiwan%' then 1 else 0 end) as taiwan_count")
                ->selectRaw("sum(case when lower(coalesce(global_events.region, '')) in ('tw', 'taiwan', '台灣') or lower(coalesce(global_events.source, '')) like '%taiwan%' then 0 else 1 end) as global_count")
                ->first();
            $score = (int) ($theme->heat_score ?? 0);
            $priceState = ModuleStateDisplay::fromScore($theme->price_score, '技術結構');
            $chipState = ModuleStateDisplay::fromScore($theme->chip_score, '籌碼');
            $relatedStocks = DB::table('stock_theme_map')
                ->join('stocks', 'stocks.id', '=', 'stock_theme_map.stock_id')
                ->leftJoin('stock_scores', function ($join) {
                    $join->on('stocks.id', '=', 'stock_scores.stock_id')
                        ->whereRaw('stock_scores.score_date = (select max(ss.score_date) from stock_scores ss where ss.stock_id = stocks.id)');
                })
                ->where('stock_theme_map.theme_id', $theme->id)
                ->whereNotNull('stock_scores.total_score')
                ->orderByDesc('stock_scores.total_score')
                ->limit(20)
                ->get(['stocks.symbol', 'stocks.name', 'stock_scores.total_score', 'stock_scores.confidence_score', 'stock_scores.decision'])
                ->map(fn ($stock) => [
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'score' => $stock->total_score,
                    'confidence' => $stock->confidence_score,
                    'decision' => $stock->decision,
                ])
                ->all();

            return [
                'name' => $theme->name,
                'score' => $score,
                'phase' => $themePhase($score, (int) ($theme->news_score ?? 0), (int) ($theme->price_score ?? 0)),
                'tone' => $themeTone($score),
                'event_count' => $eventCount,
                'taiwan_event_count' => (int) ($eventRegions->taiwan_count ?? 0),
                'global_event_count' => (int) ($eventRegions->global_count ?? 0),
                'stock_count' => $mappedCount,
                'confidence' => $score,
                'price_state' => $priceState['label'],
                'price_tone' => $priceState['tone'],
                'chip_state' => $chipState['label'],
                'chip_tone' => $chipState['tone'],
                'top_stocks' => array_slice($relatedStocks, 0, 4),
                'related_stocks' => $relatedStocks,
            ];
        });

    return view('themes', ['themes' => $themes]);
});

Route::get('/watchlist', function (AiUsageLimiter $aiLimiter) {
    $items = MarketxAuth::watchlistQuery()
        ->join('stocks', 'stocks.id', '=', 'watchlist.stock_id')
        ->leftJoin('stock_prices_1d', function ($join) {
            $join->on('stocks.id', '=', 'stock_prices_1d.stock_id')
                ->whereRaw('stock_prices_1d.trade_date = (select max(sp.trade_date) from stock_prices_1d sp where sp.stock_id = stocks.id)');
        })
        ->leftJoin('stock_scores', function ($join) {
            $join->on('stocks.id', '=', 'stock_scores.stock_id')
                ->whereRaw('stock_scores.score_date = (select max(ss.score_date) from stock_scores ss where ss.stock_id = stocks.id)');
        })
        ->leftJoin('stock_reports', function ($join) {
            $join->on('stocks.id', '=', 'stock_reports.stock_id')
                ->whereRaw('stock_reports.report_date = (select max(sr.report_date) from stock_reports sr where sr.stock_id = stocks.id)');
        })
        ->orderByDesc('watchlist.created_at')
        ->get([
            'stocks.symbol',
            'stocks.name',
            'stocks.market',
            'stocks.industry',
            'stock_prices_1d.close',
            'stock_prices_1d.change',
            'stock_prices_1d.trade_date',
            'stock_scores.decision',
            'stock_scores.total_score',
            'stock_scores.confidence_score',
            'stock_scores.macro_score',
            'stock_scores.event_score',
            'stock_scores.theme_score',
            'stock_scores.technical_score',
            'stock_scores.chip_score',
            'stock_scores.fundamental_score',
            'stock_reports.report_date',
            'stock_reports.model',
            'stock_reports.summary',
        ])
        ->map(function ($item) {
            $moduleScores = collect([
                $item->macro_score,
                $item->event_score,
                $item->theme_score,
                $item->technical_score,
                $item->chip_score,
                $item->fundamental_score,
            ])->filter(fn ($score) => $score !== null && (int) $score > 0);

            $weakModules = [];
            if ((int) ($item->theme_score ?? 0) <= 0) {
                $weakModules[] = '題材未接上';
            }
            if ((int) ($item->fundamental_score ?? 0) <= 0) {
                $weakModules[] = '財務不足';
            }
            if ((int) ($item->chip_score ?? 0) <= 0) {
                $weakModules[] = '籌碼不足';
            }

            return [
                'symbol' => $item->symbol,
                'name' => $item->name,
                'market' => $item->market,
                'industry' => $item->industry ?: '未分類',
                'close' => $item->close,
                'change' => $item->change,
                'trade_date' => $item->trade_date,
                'decision' => $item->decision ?: '尚未評分',
                'score' => $item->total_score,
                'confidence' => $item->confidence_score,
                'complete_modules' => $moduleScores->count(),
                'weak_modules' => $weakModules,
                'report_date' => $item->report_date,
                'report_model' => $item->model,
                'report_is_ai' => str_starts_with((string) $item->model, 'gemini:'),
                'report_summary' => $item->summary,
            ];
        });

    return view('watchlist', [
        'items' => $items,
        'isAdmin' => MarketxAuth::isAdmin(),
        'aiUsage' => [
            'used' => $aiLimiter->usedToday('stock_research'),
            'limit' => $aiLimiter->limit('stock_research'),
            'remaining' => $aiLimiter->remaining('stock_research'),
        ],
    ]);
});

Route::post('/watchlist', function (Request $request) {
    $validated = $request->validate([
        'symbol' => ['required', 'string', 'max:16'],
    ]);

    $keyword = trim($validated['symbol']);
    $stock = Stock::query()
        ->where('symbol', strtoupper($keyword))
        ->orWhere('name', $keyword)
        ->first();

    if (! $stock) {
        return back()
            ->withErrors(['symbol' => '找不到這檔股票，請輸入正確股票代號。'])
            ->withInput();
    }

    DB::table('watchlist')->updateOrInsert(
        ['user_id' => MarketxAuth::userId(), 'stock_id' => $stock->id],
        ['created_at' => now(), 'updated_at' => now()]
    );

    return back()->with('status', $stock->name.' 已加入追蹤清單。');
});

Route::delete('/watchlist/{symbol}', function (string $symbol) {
    $stock = Stock::query()->where('symbol', $symbol)->firstOrFail();

    MarketxAuth::watchlistQuery()
        ->where('stock_id', $stock->id)
        ->delete();

    return back()->with('status', $stock->name.' 已取消追蹤。');
});

Route::post('/watchlist/{symbol}/ai-report', function (string $symbol, AiUsageLimiter $aiLimiter) {
    MarketxAuth::requireAdmin();

    $stock = Stock::query()->where('symbol', $symbol)->firstOrFail();
    $isWatched = MarketxAuth::watchlistQuery()
        ->where('stock_id', $stock->id)
        ->exists();

    if (! $isWatched) {
        return back()->with('aiModal', [
            'title' => '無法產生 AI 報告',
            'body' => '這檔股票不在追蹤清單內。',
        ]);
    }

    if (! $aiLimiter->canRun('stock_research')) {
        return back()->with('aiModal', [
            'title' => '今日額度已用完',
            'body' => '今日個股 AI 報告額度已用完。',
        ]);
    }

    config(['services.marketx.ai_pipeline_enabled' => true]);

    $exitCode = Artisan::call('market:ai-generate-stock-reports', [
        '--symbol' => $stock->symbol,
        '--live' => true,
    ]);

    $output = trim(Artisan::output());
    $generated = str_contains($output, 'Gemini stock reports generated: 1');
    $alreadySkipped = str_contains($output, 'Skipped: 1');
    $used = $aiLimiter->usedToday('stock_research');
    $limit = $aiLimiter->limit('stock_research');
    $remaining = $aiLimiter->remaining('stock_research');

    $body = match (true) {
        $exitCode !== 0 => 'AI 報告產生失敗，請稍後再試。',
        $generated => $stock->name.' AI 報告已產生完成。',
        $alreadySkipped => $stock->name.' 今日已經有 AI 報告，不需要重複產生。',
        default => $stock->name.' AI 報告任務已完成。',
    };

    if ($exitCode === 0) {
        $body .= "\n今日已產生報告".$used.'檔，剩餘'.$remaining.'檔。';
    }

    return back()->with('aiModal', [
        'title' => $exitCode === 0 ? 'AI 報告完成' : 'AI 報告失敗',
        'body' => $body,
        'used' => $used,
        'limit' => $limit,
        'remaining' => $remaining,
    ]);
});

Route::get('/admin', function (AiPipelineService $aiPipeline) {
    MarketxAuth::requireAdmin();

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

    $today = now('Asia/Taipei')->toDateString();
    $aiStatus = $aiPipeline->status();

    $watchlistCount = DB::table('watchlist')->count();
    $todayAiReports = DB::table('stock_reports')
        ->whereDate('report_date', $today)
        ->where('model', 'like', 'gemini:%')
        ->count();
    $latestAiLogs = DB::table('ai_logs')
        ->orderByDesc('created_at')
        ->limit(8)
        ->get(['task', 'model', 'status', 'error_message', 'created_at']);
    $authLogs = DB::table('system_logs')
        ->where('source', 'auth')
        ->orderByDesc('created_at')
        ->limit(12)
        ->get(['level', 'message', 'context', 'created_at']);
    $sessionUserId = function (?string $payload): ?int {
        $decoded = base64_decode((string) $payload, true);
        if ($decoded === false) {
            return null;
        }

        $data = @unserialize($decoded, ['allowed_classes' => false]);
        if (! is_array($data) || ! isset($data['marketx_user_id'])) {
            return null;
        }

        return (int) $data['marketx_user_id'];
    };
    $sessionRows = DB::table('sessions')
        ->get(['id', 'payload', 'last_activity']);
    $lastSeenByUser = $sessionRows
        ->map(fn ($session) => [
            'user_id' => $sessionUserId($session->payload),
            'last_activity' => (int) $session->last_activity,
        ])
        ->filter(fn ($session) => $session['user_id'] !== null)
        ->groupBy('user_id')
        ->map(fn ($sessions) => $sessions->max('last_activity'));
    $members = User::query()
        ->orderByDesc('created_at')
        ->limit(100)
        ->get(['id', 'name', 'email', 'is_admin', 'created_at'])
        ->map(function (User $user) use ($lastSeenByUser) {
            $lastActivity = $lastSeenByUser->get($user->id);
            $user->last_seen_at = $lastActivity
                ? \Carbon\CarbonImmutable::createFromTimestamp((int) $lastActivity, 'Asia/Taipei')
                : null;

            return $user;
        });
    $onlineSessions = DB::table('sessions')
        ->where('last_activity', '>=', now()->subMinutes(5)->timestamp)
        ->orderByDesc('last_activity')
        ->limit(50)
        ->get(['id', 'payload', 'ip_address', 'user_agent', 'last_activity']);
    $onlineUsers = User::query()
        ->whereIn('id', $onlineSessions->map(fn ($session) => $sessionUserId($session->payload))->filter()->unique()->values())
        ->get(['id', 'name', 'email', 'is_admin'])
        ->keyBy('id');
    $onlineMembers = $onlineSessions
        ->map(function ($session) use ($sessionUserId, $onlineUsers) {
            $userId = $sessionUserId($session->payload);
            $user = $userId ? $onlineUsers->get($userId) : null;
            $session->user_id = $userId;
            $session->name = $user?->name;
            $session->email = $user?->email;
            $session->is_admin = (bool) ($user?->is_admin ?? false);
            $session->last_seen_at = \Carbon\CarbonImmutable::createFromTimestamp((int) $session->last_activity, 'Asia/Taipei');
            $session->device = match (true) {
                str_contains((string) $session->user_agent, 'iPhone') => 'iPhone',
                str_contains((string) $session->user_agent, 'Android') => 'Android',
                str_contains((string) $session->user_agent, 'Mobile') => '手機',
                str_contains((string) $session->user_agent, 'Windows') => 'Windows',
                str_contains((string) $session->user_agent, 'Macintosh') => 'Mac',
                default => '未知裝置',
            };

            return $session;
        });

    return view('admin', [
        'stats' => $stats,
        'aiStatus' => $aiStatus,
        'watchlistCount' => $watchlistCount,
        'todayAiReports' => $todayAiReports,
        'latestAiLogs' => $latestAiLogs,
        'authLogs' => $authLogs,
        'members' => $members,
        'onlineMembers' => $onlineMembers,
    ]);
});

Route::post('/admin/ai/watchlist-reports', function (Request $request) {
    MarketxAuth::requireAdmin();

    $validated = $request->validate([
        'limit' => ['nullable', 'integer', 'min:1', 'max:5'],
    ]);

    $limit = (int) ($validated['limit'] ?? 3);

    config(['services.marketx.ai_pipeline_enabled' => true]);

    $exitCode = Artisan::call('market:ai-generate-stock-reports', [
        '--watchlist' => true,
        '--limit' => $limit,
        '--live' => true,
    ]);

    $output = trim(Artisan::output());
    preg_match('/Gemini stock reports generated:\s*(\d+)/', $output, $generatedMatch);
    preg_match('/Skipped:\s*(\d+)/', $output, $skippedMatch);
    $generated = (int) ($generatedMatch[1] ?? 0);
    $skipped = (int) ($skippedMatch[1] ?? 0);

    return back()->with(
        $exitCode === 0 ? 'status' : 'error',
        $exitCode === 0
            ? '追蹤清單 AI 任務完成：產生 '.$generated.' 檔，略過 '.$skipped.' 檔。'
            : 'AI 任務執行失敗，請查看最近 AI 紀錄。'
    );
});
