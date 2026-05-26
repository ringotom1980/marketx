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
        $signals = isset($stock->technical_signals)
            ? (json_decode((string) $stock->technical_signals, true) ?: [])
            : ($payloadFor($stock)['signals'] ?? []);

        return collect($signals)
            ->filter(fn ($signal) => empty($tones) || in_array($signal['tone'] ?? null, $tones, true))
            ->pluck('title')
            ->map(fn ($title) => (string) $title)
            ->filter()
            ->values();
    };

    $reason = fn (string $label, string $tone = 'up') => ['label' => $label, 'tone' => $tone];

    $hasSignal = fn (\Illuminate\Support\Collection $signals, string $title): bool => $signals->contains($title);

    $bais20 = function (object $stock) use ($payloadFor): ?float {
        if (isset($stock->bais20) && $stock->bais20 !== null) {
            return (float) $stock->bais20;
        }

        $payload = $payloadFor($stock);
        $close = (float) ($stock->close ?? 0);
        $sma20 = isset($payload['sma20']) ? (float) $payload['sma20'] : 0.0;

        return $close > 0 && $sma20 > 0 ? (($close / $sma20) - 1) * 100 : null;
    };

    $isHighVolumeWeakReversal = function (object $stock) use ($bais20): bool {
        $previousVolume = (float) ($stock->previous_volume ?? 0);
        $volume = (float) ($stock->volume ?? 0);

        if ($previousVolume <= 0 || $volume < ($previousVolume * 2)) {
            return false;
        }

        $open = (float) ($stock->open ?? 0);
        $high = (float) ($stock->high ?? 0);
        $low = (float) ($stock->low ?? 0);
        $close = (float) ($stock->close ?? 0);

        if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0) {
            return false;
        }

        $range = max(0.0001, $high - $low);
        $upperShadowRatio = ($high - max($open, $close)) / $range;
        $previousClose = $stock->previous_close === null ? $open : (float) $stock->previous_close;
        $pulledBackFromHigh = $close <= $open || $close <= ($high * 0.97);
        $intradayLift = $high >= (max($open, $previousClose) * 1.015);
        $bais = $bais20($stock);
        $highPosition = (float) ($stock->return20 ?? 0) >= 8
            || ($bais !== null && $bais >= 8)
            || ((float) ($stock->resistance20 ?? 0) > 0 && $high >= ((float) $stock->resistance20 * 0.97));

        return $highPosition
            && $intradayLift
            && $pulledBackFromHigh
            && $upperShadowRatio >= 0.35;
    };

    $isLowBaseVolumeBreakout = function (object $stock) use ($bais20): bool {
        $close = (float) ($stock->close ?? 0);
        $open = (float) ($stock->open ?? 0);
        $previousClose = (float) ($stock->previous_close ?? 0);
        $volume = (float) ($stock->volume ?? 0);
        $previousVolume = (float) ($stock->previous_volume ?? 0);

        if ($close <= 0 || $volume <= 0 || $previousVolume <= 0) {
            return false;
        }

        $sma20 = $stock->sma20 === null ? null : (float) $stock->sma20;
        $sma60 = $stock->sma60 === null ? null : (float) $stock->sma60;
        $sma120 = $stock->sma120 === null ? null : (float) $stock->sma120;
        $return20 = (float) ($stock->return20 ?? 0);
        $return60 = (float) ($stock->return60 ?? 0);
        $volumeRatio20 = (float) ($stock->volume_ratio20 ?? 0);
        $bais = $bais20($stock);
        $volumeMultiple = $volume / max(1, $previousVolume);

        $belowAverage = ($sma20 !== null && $close <= $sma20)
            || ($sma60 !== null && $close <= $sma60)
            || ($sma120 !== null && $close <= $sma120)
            || $return20 <= -5
            || $return60 <= -5;
        $shortSelloff = $return20 <= -8 || (float) ($stock->return10 ?? 0) <= -6;
        $longDowntrend = $return60 <= -12 || ($sma60 !== null && $sma120 !== null && $sma60 < $sma120);
        $longConsolidation = abs($return60) <= 8
            && abs($return20) <= 6
            && ($bais === null || abs($bais) <= 8);
        $priceRises = (float) ($stock->change_pct ?? 0) > 0 && ($close >= $open || $close > $previousClose);
        $volumeExplodes = $volumeMultiple >= 2.5 || $volumeRatio20 >= 1.8;
        $notOverheated = $return20 <= 10 && ($bais === null || $bais <= 10);

        return ($belowAverage || $shortSelloff || $longDowntrend || $longConsolidation)
            && $priceRises
            && $volumeExplodes
            && $notOverheated;
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

    $reasonLabels = fn (array $reasons): array => collect($reasons)->pluck('label')->all();

    $candidateHas = fn (array $labels, array $needles): bool => collect($needles)
        ->contains(fn ($needle) => in_array($needle, $labels, true));

    $riskReasons = function (object $stock) use ($signalsFor, $reason, $hasSignal, $bais20, $isHighVolumeWeakReversal): array {
        $technicalSignals = $signalsFor($stock, ['red', 'amber'])
            ->reject(fn ($title) => in_array($title, ['MACD 負數縮減'], true)
                || str_contains($title, '放量轉弱')
                || str_contains($title, '爆量轉弱'))
            ->values();
        $bais = $bais20($stock);
        $reasons = collect();

        foreach (['MACD 死亡交叉', 'KD 死亡交叉', '跌破月線', '跌破布林下緣', '高檔放量轉弱', '20 日動能弱', 'RSI 弱勢'] as $preferred) {
            if ($hasSignal($technicalSignals, $preferred)) {
                $reasons->push($reason($preferred, 'down'));
            }
        }

        if ($bais !== null && $bais >= 14) {
            $reasons->push($reason('乖離過大', 'warning'));
        }

        if ($bais !== null && $bais >= 12 && ($hasSignal($technicalSignals, 'RSI 過熱') || $hasSignal($technicalSignals, 'KD 過熱'))) {
            $reasons->push($reason($hasSignal($technicalSignals, 'RSI 過熱') ? 'RSI 過熱' : 'KD 過熱', 'warning'));
        }

        if ($bais !== null && $bais >= 12 && (float) ($stock->return20 ?? 0) >= 10 && $hasSignal($technicalSignals, 'MACD 正數縮小')) {
            $reasons->push($reason('MACD 正數縮小', 'warning'));
        }

        if ((float) ($stock->per ?? 0) > 40 && (float) ($stock->yoy_pct ?? 0) < 10 && (int) ($stock->theme_score ?? 0) >= 65) {
            $reasons->push($reason('評價偏高', 'warning'));
        }

        if ($isHighVolumeWeakReversal($stock)) {
            $reasons->push($reason('爆量轉弱', 'down'));
        }

        if (
            (float) ($stock->margin_balance ?? 0) > 0
            && (float) ($stock->volume ?? 0) > 0
            && ((float) $stock->margin_balance / max(1, (float) $stock->volume)) >= 5
            && ((float) ($stock->return20 ?? 0) >= 15 || ($bais !== null && $bais >= 12))
        ) {
            $reasons->push($reason('融資偏重', 'warning'));
        }

        return $reasons
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

    $lowVolumeReasons = function (object $stock) use ($signalsFor, $reason, $hasSignal, $bais20): array {
        $technicalSignals = $signalsFor($stock);
        $reasons = collect();
        $volume = (float) ($stock->volume ?? 0);
        $previousVolume = (float) ($stock->previous_volume ?? 0);
        $volumeMultiple = $previousVolume > 0 ? $volume / $previousVolume : 0;
        $close = (float) ($stock->close ?? 0);
        $sma20 = $stock->sma20 === null ? null : (float) $stock->sma20;
        $sma60 = $stock->sma60 === null ? null : (float) $stock->sma60;
        $sma120 = $stock->sma120 === null ? null : (float) $stock->sma120;

        if (
            ($sma20 !== null && $close <= $sma20)
            || ($sma60 !== null && $close <= $sma60)
            || ($sma120 !== null && $close <= $sma120)
            || (float) ($stock->return20 ?? 0) <= -5
            || (float) ($stock->return60 ?? 0) <= -5
        ) {
            $reasons->push($reason('低檔區', 'warning'));
        }

        if ((float) ($stock->return20 ?? 0) <= -8 || (float) ($stock->return10 ?? 0) <= -6) {
            $reasons->push($reason('短線急跌後放量', 'warning'));
        }

        if ((float) ($stock->return60 ?? 0) <= -12 || ($sma60 !== null && $sma120 !== null && $sma60 < $sma120)) {
            $reasons->push($reason('長期下跌後轉強', 'warning'));
        }

        if (abs((float) ($stock->return60 ?? 0)) <= 8 && abs((float) ($stock->return20 ?? 0)) <= 6) {
            $reasons->push($reason('長期盤整', 'warning'));
        }

        if ($volumeMultiple >= 2.5) {
            $reasons->push($reason('量增逾2.5倍', 'warning'));
        } elseif ((float) ($stock->volume_ratio20 ?? 0) >= 1.8) {
            $reasons->push($reason('量能高於均量', 'warning'));
        }

        if ((float) ($stock->change_pct ?? 0) > 0) {
            $reasons->push($reason('放量上漲', 'up'));
        }

        if (($bais20($stock) ?? 0) <= 8) {
            $reasons->push($reason('未明顯過熱', 'warning'));
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
        ->leftJoin('stock_technical_indicators_1d', function ($join) {
            $join->on('stocks.id', '=', 'stock_technical_indicators_1d.stock_id')
                ->whereRaw('stock_technical_indicators_1d.trade_date = (select max(sti.trade_date) from stock_technical_indicators_1d sti where sti.stock_id = stocks.id)');
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
            'stock_scores.confidence_payload',
            'stock_scores.theme_score',
            'stock_scores.technical_score',
            'stock_scores.chip_score',
            'stock_scores.fundamental_score',
            'stock_scores.technical_payload',
            'stock_prices_1d.open',
            'stock_prices_1d.high',
            'stock_prices_1d.low',
            'stock_prices_1d.close',
            'stock_prices_1d.change_pct',
            'stock_prices_1d.volume',
            'stock_technical_indicators_1d.sma20',
            'stock_technical_indicators_1d.sma60',
            'stock_technical_indicators_1d.sma120',
            'stock_technical_indicators_1d.bais20',
            'stock_technical_indicators_1d.return20',
            'stock_technical_indicators_1d.return10',
            'stock_technical_indicators_1d.return60',
            'stock_technical_indicators_1d.volume_ratio20',
            'stock_technical_indicators_1d.rsi14',
            'stock_technical_indicators_1d.macd',
            'stock_technical_indicators_1d.macd_signal',
            'stock_technical_indicators_1d.macd_histogram',
            'stock_technical_indicators_1d.k9',
            'stock_technical_indicators_1d.d9',
            'stock_technical_indicators_1d.bollinger_upper20',
            'stock_technical_indicators_1d.bollinger_middle20',
            'stock_technical_indicators_1d.bollinger_lower20',
            'stock_technical_indicators_1d.resistance20',
            'stock_technical_indicators_1d.signals as technical_signals',
            'stock_chips_1d.margin_balance',
            'stock_chips_1d.short_balance',
            'stock_financials.per',
            'stock_revenues.mom_pct',
            'stock_revenues.yoy_pct',
            DB::raw('(select sp_prev.close from stock_prices_1d sp_prev where sp_prev.stock_id = stocks.id and sp_prev.trade_date < stock_prices_1d.trade_date order by sp_prev.trade_date desc limit 1) as previous_close'),
            DB::raw('(select sp_prev.volume from stock_prices_1d sp_prev where sp_prev.stock_id = stocks.id and sp_prev.trade_date < stock_prices_1d.trade_date order by sp_prev.trade_date desc limit 1) as previous_volume')
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
            $stock->return20 = (float) ($stock->return20 ?? ($payload['return20'] ?? 0));
            $stock->volume_ratio20 = (float) ($stock->volume_ratio20 ?? ($payload['volume_ratio20'] ?? 0));

            return $stock;
        });

    $confidencePayloadFor = function (object $stock): array {
        $payload = $stock->confidence_payload ?? null;

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        return is_array($payload) ? $payload : [];
    };

    $payloadReasons = function (object $stock, string $type) use ($confidencePayloadFor, $reason): array {
        $payload = $confidencePayloadFor($stock);
        $groups = $payload['reasons'] ?? [];
        $items = collect();
        $push = function (array $labels, string $tone) use ($items, $reason): void {
            foreach ($labels as $label) {
                $label = trim((string) $label);

                if ($label !== '') {
                    $items->push($reason($label, $tone));
                }
            }
        };

        if (in_array($type, ['priority', 'potential'], true)) {
            $push($groups['bull'] ?? [], 'up');
            $push($groups['risk'] ?? [], 'warning');
        } elseif ($type === 'risk') {
            $push($groups['risk'] ?? [], 'warning');
            $push($groups['bear'] ?? [], 'down');
        } elseif ($type === 'weak') {
            $push($groups['bear'] ?? [], 'down');
            $push($groups['risk'] ?? [], 'warning');
        } else {
            $push($groups['bull'] ?? [], 'warning');
            $push($groups['risk'] ?? [], 'warning');
            $push($groups['bear'] ?? [], 'down');
        }

        return $items
            ->unique('label')
            ->take(4)
            ->values()
            ->all();
    };

    $cardConfidence = function (object $stock, string $type) use ($confidencePayloadFor): int {
        $payload = $confidencePayloadFor($stock);
        return (int) ($payload['opportunity_confidence'] ?? $stock->confidence_score ?? 0);
    };

    $stockCardItem = fn (object $stock, array $fallbackReasons, string $type): array => [
        'symbol' => $stock->symbol,
        'name' => $stock->name,
        'confidence' => $cardConfidence($stock, $type),
        'reasons' => $type === 'low_volume' ? $fallbackReasons : ($payloadReasons($stock, $type) ?: $fallbackReasons),
    ];

    $topStocks = $stockCandidates
        ->map(function ($stock) use ($priorityReasons) {
            $stock->radar_reasons = $priorityReasons($stock);
            return $stock;
        })
        ->filter(function ($stock) use ($bais20, $reasonLabels, $candidateHas) {
            $labels = $reasonLabels($stock->radar_reasons);

            return (int) ($stock->confidence_score ?? 0) >= 65
                && (int) ($stock->total_score ?? 0) >= 58
                && ($bais20($stock) === null || abs($bais20($stock)) <= 12)
                && $candidateHas($labels, ['MACD 黃金交叉', 'KD 黃金交叉', '20 日突破', '價漲量增', '均線多頭排列', '題材升溫', '營收轉強'])
                && count($labels) >= 2;
        })
        ->sortByDesc(fn ($stock) => sprintf(
            '%03d-%03d-%03d',
            (int) ($stock->confidence_score ?? 0),
            (int) ($stock->theme_score ?? 0),
            (int) ($stock->technical_score ?? 0),
        ))
        ->map(fn ($stock) => $stockCardItem($stock, $stock->radar_reasons, 'priority'))
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
        ->map(function ($stock) use ($riskReasons) {
            $stock->radar_reasons = $riskReasons($stock);
            return $stock;
        })
        ->filter(function ($stock) use ($reasonLabels, $candidateHas, $cardConfidence) {
            $labels = $reasonLabels($stock->radar_reasons);

            return $cardConfidence($stock, 'risk') >= 35
                && $cardConfidence($stock, 'risk') <= 78
                && $candidateHas($labels, ['乖離過大', 'RSI 過熱', 'KD 過熱', '爆量轉弱', '高檔放量轉弱', '評價偏高', '融資偏重', '跌破月線', '跌破布林下緣'])
                && count($labels) >= 2;
        })
        ->sortByDesc(fn ($stock) => sprintf(
            '%03d-%06.2f-%06.2f',
            $cardConfidence($stock, 'risk'),
            abs((float) ($stock->bais20 ?? 0)),
            max(0, (float) ($stock->return20 ?? 0)),
        ))
        ->map(fn ($stock) => $stockCardItem($stock, $stock->radar_reasons, 'risk'))
        ->take(6)
        ->values();
    $usedSymbols = array_merge($usedSymbols, $riskStocks->pluck('symbol')->all());

    $potentialStocks = $stockCandidates
        ->reject(fn ($stock) => in_array($stock->symbol, $usedSymbols, true))
        ->map(function ($stock) use ($potentialReasons) {
            $stock->radar_reasons = $potentialReasons($stock);
            return $stock;
        })
        ->filter(function ($stock) use ($reasonLabels) {
            $labels = $reasonLabels($stock->radar_reasons);

            return (int) ($stock->confidence_score ?? 0) >= 50
                && (int) ($stock->total_score ?? 0) >= 48
                && (float) ($stock->return20 ?? 0) < 8
                && count($labels) >= 2;
        })
        ->sortByDesc(fn ($stock) => sprintf(
            '%03d-%03d-%03d',
            (int) ($stock->confidence_score ?? 0),
            (int) ($stock->theme_score ?? 0),
            (int) ($stock->technical_score ?? 0),
        ))
        ->map(fn ($stock) => $stockCardItem($stock, $stock->radar_reasons, 'potential'))
        ->take(6)
        ->values();
    $usedSymbols = array_merge($usedSymbols, $potentialStocks->pluck('symbol')->all());

    $lowVolumeStocks = $stockCandidates
        ->reject(fn ($stock) => in_array($stock->symbol, $usedSymbols, true))
        ->map(function ($stock) use ($lowVolumeReasons) {
            $stock->radar_reasons = $lowVolumeReasons($stock);
            return $stock;
        })
        ->filter(fn ($stock) => $isLowBaseVolumeBreakout($stock)
            && count($stock->radar_reasons) >= 2)
        ->sortByDesc(fn ($stock) => sprintf(
            '%03d-%06.2f-%06.2f',
            $cardConfidence($stock, 'low_volume'),
            (float) ($stock->volume ?? 0) / max(1, (float) ($stock->previous_volume ?? 0)),
            (float) ($stock->volume_ratio20 ?? 0),
        ))
        ->map(fn ($stock) => $stockCardItem($stock, $stock->radar_reasons, 'low_volume'))
        ->take(6)
        ->values();
    $usedSymbols = array_merge($usedSymbols, $lowVolumeStocks->pluck('symbol')->all());

    $weakStocks = $stockCandidates
        ->reject(fn ($stock) => in_array($stock->symbol, $usedSymbols, true))
        ->map(function ($stock) use ($weakReasons) {
            $stock->radar_reasons = $weakReasons($stock);
            return $stock;
        })
        ->filter(fn ($stock) => ((int) ($stock->technical_score ?? 0) < 48 || (float) ($stock->return20 ?? 0) <= -8)
            && count($stock->radar_reasons) >= 2)
        ->sortByDesc(fn ($stock) => sprintf(
            '%03d-%03d',
            $cardConfidence($stock, 'weak'),
            100 - (int) ($stock->technical_score ?? 0),
        ))
        ->map(fn ($stock) => $stockCardItem($stock, $stock->radar_reasons, 'weak'))
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

    $confidencePayload = $latestScore?->confidence_payload ?? [];
    if (is_string($confidencePayload)) {
        $confidencePayload = json_decode($confidencePayload, true) ?: [];
    }
    $confidencePayload = is_array($confidencePayload) ? $confidencePayload : [];
    $confidence = (int) ($confidencePayload['opportunity_confidence'] ?? $latestScore?->confidence_score ?? 0);
    $bullReasons = array_values(array_filter((array) data_get($confidencePayload, 'reasons.bull', [])));
    $bearReasons = array_values(array_filter((array) data_get($confidencePayload, 'reasons.bear', [])));
    $riskReasons = array_values(array_filter((array) data_get($confidencePayload, 'reasons.risk', [])));
    $hasRisk = count($riskReasons) > 0;

    $evaluation = match (true) {
        $confidence >= 78 && ! $hasRisk => ['label' => '高度觀察', 'tone' => 'red'],
        $confidence >= 68 && $hasRisk => ['label' => '偏多但風險升高', 'tone' => 'amber'],
        $confidence >= 68 => ['label' => '偏多觀察', 'tone' => 'red'],
        $confidence >= 55 => ['label' => '中性觀察', 'tone' => 'amber'],
        $confidence >= 40 => ['label' => '保守觀察', 'tone' => 'amber'],
        default => ['label' => '弱勢觀察', 'tone' => 'green'],
    };

    $supportText = $bullReasons === []
        ? '目前多方支撐條件不足'
        : implode('、', array_slice($bullReasons, 0, 4));
    $riskText = array_merge($riskReasons, $bearReasons) === []
        ? '目前沒有明顯風險旗標，但仍需留意盤勢變化'
        : implode('、', array_slice(array_values(array_unique(array_merge($riskReasons, $bearReasons))), 0, 4));
    $interpretation = match ($evaluation['label']) {
        '高度觀察' => '多數核心條件偏正向，但仍不代表保證上漲，適合列入優先觀察。',
        '偏多觀察' => '整體條件偏正向，但仍要確認量價與籌碼是否延續。',
        '偏多但風險升高' => '雖然仍有多方條件，但風險旗標已出現，追價要更保守。',
        '中性觀察' => '多空條件尚未明顯傾斜，適合等待更清楚的量價或籌碼訊號。',
        '保守觀察' => '看多信心偏低，除非後續出現明確轉強訊號，否則不宜積極追價。',
        default => '目前弱勢或扣分條件較多，應優先控管風險。',
    };
    $stockEvaluationSummary = "目前看多信心為 {$confidence}%，狀態為「{$evaluation['label']}」。\n"
        .'系統以技術 35%、籌碼 25%、財務 25%、題材 15% 加權計算。'
        ."\n主要支撐：{$supportText}。"
        ."\n主要風險：{$riskText}。"
        ."\n解讀：{$interpretation}";

    return view('stock', [
        'stock' => [
            'symbol' => $stockRecord->symbol,
            'name' => $stockRecord->name,
            'market' => $stockRecord->market,
            'close' => $latestPrice?->close ?? '無資料',
            'change' => $latestPrice?->change ?? '無資料',
            'volume' => $latestPrice?->volume ? number_format($latestPrice->volume) : '無資料',
            'decision' => $evaluation['label'],
            'decisionTone' => $evaluation['tone'],
            'score' => $latestScore?->total_score ?? $latestScore?->technical_score ?? 0,
            'confidence' => $confidence,
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
        'summary' => $stockEvaluationSummary,
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
