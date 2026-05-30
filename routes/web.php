<?php

use App\Models\Stock;
use App\Models\User;
use App\Models\AgentFinding;
use App\Models\AgentMemory;
use App\Models\AgentRole;
use App\Models\AgentRun;
use App\Support\ChipSignalAnalyzer;
use App\Support\EventClusterDisplay;
use App\Support\FundamentalSignalAnalyzer;
use App\Support\GlobalRadarBuilder;
use App\Support\MarketxAuth;
use App\Support\MarketDisplay;
use App\Support\ModuleStateDisplay;
use App\Support\StockReportPhraseComposer;
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

    return redirect()->intended('/')->with('show_home_screen_tip', true);
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

    return redirect('/')->with('show_home_screen_tip', true);
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
            'source' => '臺灣證券交易所：發行量加權股價指數歷史資料 / 市場成交資訊',
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

    $latestRadarDate = DB::table('stock_radar_cards')->max('card_date');
    $stockRadarConfig = collect([
        'priority' => ['title' => '優先觀察', 'empty' => '目前沒有符合條件的優先觀察股'],
        'risk' => ['title' => '風險升高', 'empty' => '目前沒有符合條件的風險升高股'],
        'potential' => ['title' => '潛力觀察', 'empty' => '目前沒有符合條件的潛力觀察股'],
        'low_volume' => ['title' => '低檔爆量', 'empty' => '目前沒有符合條件的低檔爆量股'],
        'weak' => ['title' => '持續弱勢', 'empty' => '目前沒有符合條件的持續弱勢股'],
    ]);

    $stockRadarRows = $latestRadarDate
        ? DB::table('stock_radar_cards')
            ->join('stocks', 'stocks.id', '=', 'stock_radar_cards.stock_id')
            ->where('stock_radar_cards.card_date', $latestRadarDate)
            ->orderBy('stock_radar_cards.card_type')
            ->orderBy('stock_radar_cards.rank')
            ->get([
                'stock_radar_cards.card_type',
                'stock_radar_cards.rank',
                'stock_radar_cards.confidence_score',
                'stock_radar_cards.reasons',
                'stock_radar_cards.metrics_payload',
                'stocks.symbol',
                'stocks.name',
            ])
        : collect();

    $stockRadarCards = $stockRadarConfig->map(function (array $config, string $type) use ($stockRadarRows) {
        $items = $stockRadarRows
            ->where('card_type', $type)
            ->sortBy('rank')
            ->map(function ($row) {
                $metrics = json_decode((string) $row->metrics_payload, true) ?: [];

                return [
                    'symbol' => $row->symbol,
                    'name' => $row->name,
                    'confidence' => (int) $row->confidence_score,
                    'reasons' => json_decode((string) $row->reasons, true) ?: [],
                    'themes' => is_array($metrics['themes'] ?? null) ? $metrics['themes'] : [],
                ];
            })
            ->values();

        return ['title' => $config['title'], 'empty' => $config['empty'], 'items' => $items];
    })->values();

    $topStocks = $stockRadarCards->get(0)['items'] ?? collect();
    $riskStocks = $stockRadarCards->get(1)['items'] ?? collect();

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
        ->select(
            'themes.id',
            'themes.slug',
            'themes.name',
            'theme_scores.heat_score',
            DB::raw('(select ts_prev.heat_score from theme_scores ts_prev where ts_prev.theme_id = themes.id and ts_prev.score_date < theme_scores.score_date order by ts_prev.score_date desc limit 1) as previous_heat_score')
        )
        ->where('themes.is_active', true)
        ->whereNotNull('theme_scores.heat_score')
        ->where('theme_scores.heat_score', '>', 0)
        ->orderByDesc('theme_scores.heat_score')
        ->orderBy('themes.name')
        ->limit(10)
        ->get()
        ->map(function ($theme) {
            $score = (int) ($theme->heat_score ?? 0);
            $previous = $theme->previous_heat_score === null ? null : (int) $theme->previous_heat_score;
            $change = $previous === null ? 0 : $score - $previous;

            return [
                'name' => $theme->name,
                'slug' => $theme->slug,
                'score' => $score,
                'trend' => match (true) {
                    $previous === null => 'watch',
                    $change >= 3 => 'up',
                    $change <= -3 => 'down',
                    default => 'watch',
                },
                'trend_label' => match (true) {
                    $previous === null => '觀察中',
                    $change >= 3 => '升溫中',
                    $change <= -3 => '降溫中',
                    default => '觀察中',
                },
            ];
        });

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

Route::get('/api/stocks/search', function (Request $request) {
    $query = trim((string) $request->query('q', ''));

    if ($query === '') {
        return response()->json([]);
    }

    $stocks = Stock::query()
        ->where(function ($builder) use ($query) {
            $builder
                ->where('symbol', 'like', $query.'%')
                ->orWhere('name', 'like', '%'.$query.'%')
                ->orWhere('industry', 'like', '%'.$query.'%');
        })
        ->orderByRaw('CASE WHEN symbol = ? THEN 0 WHEN symbol LIKE ? THEN 1 WHEN name LIKE ? THEN 2 ELSE 3 END', [
            $query,
            $query.'%',
            $query.'%',
        ])
        ->orderBy('symbol')
        ->limit(12)
        ->get(['symbol', 'name', 'market', 'industry']);

    return response()->json($stocks);
});

Route::get('/s/{symbol}', function (string $symbol, StockEventChainBuilder $eventChainBuilder, StockReportPhraseComposer $phraseComposer) {
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
        ->orderByDesc('trade_date')
        ->limit(1800)
        ->get(['trade_date', 'open', 'high', 'low', 'close', 'volume'])
        ->sortBy('trade_date')
        ->values();
    $dailyK = $priceRows
        ->slice(-520)
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
    $monthlySupportRows = array_slice($aggregateK(
        $priceRows,
        fn ($row) => $row->trade_date->format('Y-m')
    ), -36);
    $quarterlySupportRows = array_slice($aggregateK(
        $priceRows,
        fn ($row) => $row->trade_date->format('Y').'-Q'.(int) ceil(((int) $row->trade_date->format('n')) / 3)
    ), -20);
    $supportLatestClose = $latestPrice?->close === null ? null : (float) $latestPrice->close;
    $buildSupportChart = function (array $sourceRows, string $label) use ($supportLatestClose): array {
        $value = fn ($row, string $key) => is_array($row) ? data_get($row, $key) : $row->{$key};
        $supportRows = collect($sourceRows)
            ->filter(fn ($row) => (float) $value($row, 'high') > 0 && (float) $value($row, 'low') > 0 && (float) $value($row, 'close') > 0)
            ->values();

        if ($supportRows->count() < 4 || $supportLatestClose === null) {
            return ['rows' => [], 'current' => $supportLatestClose, 'support' => null, 'pressure' => null, 'note' => '資料不足', 'period' => $label];
        }

        $low = (float) $supportRows->min(fn ($row) => $value($row, 'low'));
        $high = (float) $supportRows->max(fn ($row) => $value($row, 'high'));
        $step = max(0.01, ($high - $low) / 12);
        $bins = collect(range(0, 11))->map(function (int $index) use ($low, $step) {
            $from = $low + ($step * $index);
            $to = $from + $step;

            return [
                'label' => number_format($from, 2).'~'.number_format($to, 2),
                'from' => $from,
                'to' => $to,
                'mid' => ($from + $to) / 2,
                'volume' => 0,
                'type' => 'neutral',
                'role' => null,
            ];
        })->all();

        foreach ($supportRows as $row) {
            $close = (float) $value($row, 'close');
            $index = (int) floor(($close - $low) / $step);
            $index = max(0, min(count($bins) - 1, $index));
            $bins[$index]['volume'] += max(0, (int) ($value($row, 'volume') ?? 0));
        }

        $activeBins = collect($bins)->filter(fn (array $bin) => (int) $bin['volume'] > 0)->values();
        $supportBin = $activeBins
            ->filter(fn (array $bin) => $bin['to'] < $supportLatestClose)
            ->sortByDesc('volume')
            ->first();
        $pressureBin = $activeBins
            ->filter(fn (array $bin) => $bin['from'] > $supportLatestClose)
            ->sortByDesc('volume')
            ->first();
        $isNewHigh = $pressureBin === null && $supportLatestClose >= (float) $supportRows->max(fn ($row) => $value($row, 'high'));

        $rows = collect($bins)
            ->reverse()
            ->map(function (array $bin) use ($supportLatestClose, $supportBin, $pressureBin) {
                if ($supportLatestClose >= $bin['from'] && $supportLatestClose <= $bin['to']) {
                    $bin['type'] = 'current';
                    $bin['role'] = '目前價';
                } elseif ($supportBin && abs($bin['mid'] - $supportBin['mid']) < 0.00001) {
                    $bin['type'] = 'support';
                    $bin['role'] = '支撐';
                } elseif ($pressureBin && abs($bin['mid'] - $pressureBin['mid']) < 0.00001) {
                    $bin['type'] = 'pressure';
                    $bin['role'] = '壓力';
                }

                unset($bin['from'], $bin['to'], $bin['mid']);

                return $bin;
            })
            ->filter(fn (array $bin) => (int) $bin['volume'] > 0 || $bin['role'] !== null)
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'current' => $supportLatestClose,
            'support' => $supportBin ? number_format($supportBin['from'], 2).'~'.number_format($supportBin['to'], 2) : null,
            'pressure' => $pressureBin ? number_format($pressureBin['from'], 2).'~'.number_format($pressureBin['to'], 2) : null,
            'note' => $isNewHigh ? '創新高，暫無明確上方壓力' : null,
            'period' => $label,
        ];
    };
    $supportChart = [
        'week' => $buildSupportChart(array_slice($weeklyK, -52), '周統計'),
        'month' => $buildSupportChart($monthlySupportRows, '月統計'),
        'quarter' => $buildSupportChart($quarterlySupportRows, '季統計'),
    ];

    $chipRowsForChart = $stockRecord->chips()
        ->latest('trade_date')
        ->limit(130)
        ->get(['trade_date', 'foreign_net_buy', 'investment_trust_net_buy', 'dealer_net_buy', 'institutional_net_buy', 'margin_balance', 'short_balance', 'lending_available_volume'])
        ->sortBy('trade_date')
        ->values()
        ->map(fn ($row) => [
            'date' => $row->trade_date->toDateString(),
            'foreign' => (int) ($row->foreign_net_buy ?? 0),
            'trust' => (int) ($row->investment_trust_net_buy ?? 0),
            'dealer' => (int) ($row->dealer_net_buy ?? 0),
            'institutional' => (int) ($row->institutional_net_buy ?? 0),
            'margin' => $row->margin_balance === null ? null : (int) $row->margin_balance,
            'short' => $row->short_balance === null ? null : (int) $row->short_balance,
            'lendingAvailable' => $row->lending_available_volume === null ? null : (int) $row->lending_available_volume,
        ])
        ->all();

    $revenueRowsForChart = DB::table('stock_revenues')
        ->where('stock_id', $stockRecord->id)
        ->orderByDesc('year_month')
        ->limit(120)
        ->get(['year_month', 'revenue', 'mom_pct', 'yoy_pct'])
        ->sortBy('year_month')
        ->values()
        ->map(fn ($row) => [
            'date' => (string) $row->year_month,
            'revenue' => $row->revenue === null ? null : round(((float) $row->revenue) / 1000, 2),
            'mom' => $row->mom_pct === null ? null : (float) $row->mom_pct,
            'yoy' => $row->yoy_pct === null ? null : (float) $row->yoy_pct,
        ])
        ->all();

    $financialRowsForChart = DB::table('stock_financials')
        ->where('stock_id', $stockRecord->id)
        ->where(function ($query) {
            $query->whereNotNull('eps')
                ->orWhereNotNull('roe')
                ->orWhereNotNull('gross_margin')
                ->orWhereNotNull('operating_margin');
        })
        ->where('period', 'like', '%Q%')
        ->orderByDesc('period')
        ->limit(80)
        ->get(['period', 'eps', 'roe', 'gross_margin', 'operating_margin', 'per', 'pb_ratio'])
        ->sortBy('period')
        ->values()
        ->map(fn ($row) => [
            'date' => (string) $row->period,
            'eps' => $row->eps === null ? null : (float) $row->eps,
            'roe' => $row->roe === null ? null : (float) $row->roe,
            'grossMargin' => $row->gross_margin === null ? null : (float) $row->gross_margin,
            'operatingMargin' => $row->operating_margin === null ? null : (float) $row->operating_margin,
            'per' => $row->per === null ? null : (float) $row->per,
            'pb' => $row->pb_ratio === null ? null : (float) $row->pb_ratio,
        ])
        ->all();

    $latestTechnical = DB::table('stock_technical_indicators_1d')
        ->where('stock_id', $stockRecord->id)
        ->orderByDesc('trade_date')
        ->first();
    $technicalArray = is_array($technicalPayload)
        ? $technicalPayload
        : (is_string($technicalPayload) ? (json_decode($technicalPayload, true) ?: []) : []);
    $num = fn ($value): ?float => $value === null || $value === '' ? null : (float) $value;
    $closeAt = function ($rows, int $daysAgo) use ($num): ?float {
        $index = $rows->count() - 1 - $daysAgo;

        return $index >= 0 ? $num($rows->get($index)?->close) : null;
    };
    $pctFrom = function (?float $current, ?float $base): ?float {
        return $current !== null && $base !== null && $base > 0 ? (($current / $base) - 1) * 100 : null;
    };
    $latestPriceRow = $priceRows->last();
    $latestClose = $num($latestPriceRow?->close);
    $latestOpen = $num($latestPriceRow?->open);
    $latestHigh = $num($latestPriceRow?->high);
    $latestLow = $num($latestPriceRow?->low);
    $recentPriceStats = [
        'change' => $num($latestPrice?->change),
        'return5' => $pctFrom($latestClose, $closeAt($priceRows, 5)),
        'return20' => $pctFrom($latestClose, $closeAt($priceRows, 20)),
        'return60' => $pctFrom($latestClose, $closeAt($priceRows, 60)),
        'bais20' => $num($latestTechnical?->bais20 ?? data_get($technicalArray, 'bais20')),
        'rsi14' => $num($latestTechnical?->rsi14 ?? data_get($technicalArray, 'rsi14')),
        'volumeRatio20' => $num($latestTechnical?->volume_ratio20 ?? data_get($technicalArray, 'volume_ratio20')),
        'closeVsSma20' => $pctFrom($latestClose, $num($latestTechnical?->sma20 ?? data_get($technicalArray, 'sma20'))),
        'upperShadowRatio' => null,
    ];
    if ($latestOpen !== null && $latestClose !== null && $latestHigh !== null && $latestLow !== null && $latestHigh > $latestLow) {
        $recentPriceStats['upperShadowRatio'] = (($latestHigh - max($latestOpen, $latestClose)) / ($latestHigh - $latestLow)) * 100;
    }
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
    $latestRadarCard = DB::table('stock_radar_cards')
        ->where('stock_id', $stockRecord->id)
        ->where('card_date', DB::table('stock_radar_cards')->max('card_date'))
        ->orderByRaw("case card_type when 'risk' then 1 when 'priority' then 2 when 'low_volume' then 3 when 'potential' then 4 when 'weak' then 5 else 99 end")
        ->first(['card_type', 'reasons']);

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
    $radarReasons = $latestRadarCard ? (json_decode((string) $latestRadarCard->reasons, true) ?: []) : [];
    $radarReasonLabels = collect($radarReasons)
        ->pluck('label')
        ->filter()
        ->values()
        ->all();

    if ($latestRadarCard) {
        $evaluation = match ($latestRadarCard->card_type) {
            'priority' => ['label' => '優先觀察', 'tone' => 'red'],
            'risk' => ['label' => '風險升高', 'tone' => 'amber'],
            'potential' => ['label' => '潛力觀察', 'tone' => 'amber'],
            'low_volume' => ['label' => '低檔爆量觀察', 'tone' => 'amber'],
            'weak' => ['label' => '持續弱勢', 'tone' => 'green'],
            default => $evaluation,
        };
    }

    $supportText = $bullReasons === []
        ? '目前多方支撐條件不足'
        : implode('、', array_slice($bullReasons, 0, 4));
    $riskText = array_merge($riskReasons, $bearReasons) === []
        ? '目前沒有明顯風險旗標，但仍需留意盤勢變化'
        : implode('、', array_slice(array_values(array_unique(array_merge($riskReasons, $bearReasons))), 0, 4));
    $interpretation = (function () use ($evaluation, $latestRadarCard, $recentPriceStats, $confidence, $supportText, $riskText) {
        $cardType = $latestRadarCard?->card_type;
        $return5 = $recentPriceStats['return5'];
        $return20 = $recentPriceStats['return20'];
        $return60 = $recentPriceStats['return60'];
        $bais20 = $recentPriceStats['bais20'];
        $volumeRatio20 = $recentPriceStats['volumeRatio20'];
        $closeVsSma20 = $recentPriceStats['closeVsSma20'];
        $upperShadowRatio = $recentPriceStats['upperShadowRatio'];

        $isRecentWeak = ($return20 !== null && $return20 <= -6)
            || ($return60 !== null && $return60 <= -12)
            || ($closeVsSma20 !== null && $closeVsSma20 < -3);
        $isExtended = ($return20 !== null && $return20 >= 14)
            || ($bais20 !== null && $bais20 >= 10);
        $isRebound = ($return5 !== null && $return5 > 3)
            && ($return20 !== null && $return20 <= 8)
            && ($volumeRatio20 !== null && $volumeRatio20 >= 1.4);
        $hasUpperSellPressure = ($upperShadowRatio !== null && $upperShadowRatio >= 35)
            && ($volumeRatio20 !== null && $volumeRatio20 >= 1.3);

        if ($cardType === 'risk') {
            if ($isRecentWeak) {
                return "這檔被列入風險升高，不是因為漲多過熱，而是近期股價已經轉弱或跌破均線，風險重點在於「{$riskText}」。接下來要先看能不能止跌並站回月線，否則反彈容易只是短線修正。";
            }

            if ($hasUpperSellPressure) {
                return "這檔風險來自放量後高檔賣壓變明顯，K 線上影線偏重，代表追價買盤開始遇到壓力。即使題材或部分指標仍有支撐，也要先觀察隔日能否重新站回高點附近。";
            }

            if ($isExtended) {
                return "這檔近期漲幅或乖離已偏大，列入風險升高是提醒強勢後安全邊際變小。若量能降溫、籌碼轉弱或營收跟不上評價，短線拉回壓力會提高。";
            }

            return "這檔目前不是單純看空，而是多方條件與風險條件同時存在。主要支撐是「{$supportText}」，但「{$riskText}」也需要一起看，操作上不適合只看信心指數追價。";
        }

        if ($cardType === 'weak') {
            return "這檔目前屬於持續弱勢，重點不是短線有沒有反彈，而是股價結構仍未轉強。除非後續出現放量站回月線、MACD/KD 修復與籌碼回補，否則仍應先以風險控管為主。";
        }

        if ($cardType === 'low_volume') {
            if ($isRebound) {
                return "這檔屬於低檔爆量觀察，股價先前位階不高，近期開始出現放量上攻。這種訊號有機會代表資金重新注意，但要看量能能否延續，且不能隔天快速跌回整理區。";
            }

            return "這檔被歸在低檔爆量，代表系統看到低位階或整理後的量能變化。現在重點不是急著判斷已經轉強，而是觀察爆量後能否守住關鍵均線與前一波整理區上緣。";
        }

        if ($cardType === 'priority') {
            if ($isExtended) {
                return "這檔雖列入優先觀察，但近期漲幅已不小，優勢在「{$supportText}」，風險則是追價成本偏高。比較健康的走法是量能延續、回檔不破短均線，而不是再用急漲拉開乖離。";
            }

            return "這檔目前列入優先觀察，代表股價、題材或籌碼有較多正向條件配合。後續重點是成交量能否維持、籌碼是否延續買盤，若只剩題材但股價不跟，信心就要下修。";
        }

        if ($cardType === 'potential') {
            return "這檔屬於潛力觀察，代表條件正在累積但還沒有到全面轉強。若後續能補上量價突破、題材升溫或籌碼轉買，才比較有機會從觀察轉成更明確的偏多結構。";
        }

        return match ($evaluation['label']) {
            '高度觀察', '偏多觀察' => "目前看多信心約 {$confidence}%，但仍要回到股價走勢確認；若量能、均線與籌碼不能同步，分數再高也只能當觀察名單。",
            '偏多但風險升高' => "目前仍有多方條件，但風險旗標也出現，必須同時看支撐與扣分原因，避免只看單一分數追價。",
            '中性觀察' => '多空條件尚未明顯傾斜，現階段比較適合等待量價、籌碼或題材出現更一致的方向。',
            '保守觀察' => '看多信心偏低，除非後續股價先站回關鍵均線並出現量能配合，否則不宜把短線反彈直接視為轉強。',
            default => '目前弱勢或扣分條件較多，應先確認股價是否止跌與籌碼是否改善，再評估後續機會。',
        };
    })();
    $cardText = $latestRadarCard
        ? "\n首頁分類：{$evaluation['label']}".($radarReasonLabels === [] ? '' : '，原因：'.implode('、', array_slice($radarReasonLabels, 0, 4)).'。')
        : '';
    $phraseEvaluation = $phraseComposer->composeQuickEvaluation(
        $stockRecord,
        $latestScore,
        $latestChip,
        $latestPrice,
        $latestRevenue,
        $latestRadarCard?->card_type
    );
    if (($phraseEvaluation['one_liner'] ?? '') !== '') {
        $interpretation = $phraseEvaluation['one_liner'];
    }

    $stockEvaluationSummary = "目前看多信心為 {$confidence}%，狀態為「{$evaluation['label']}」。\n"
        .$cardText
        ."\n主要支撐：{$supportText}。"
        ."\n主要風險：{$riskText}。"
        ."\n解讀：{$interpretation}";
    $supportPills = collect($radarReasons)
        ->filter(fn ($reason) => ($reason['tone'] ?? '') !== 'down')
        ->map(fn ($reason) => [
            'label' => $reason['label'] ?? '',
            'tone' => $reason['tone'] ?? 'up',
        ])
        ->filter(fn ($reason) => $reason['label'] !== '')
        ->values()
        ->all();
    if ($supportPills === []) {
        $supportPills = collect($bullReasons)
            ->take(4)
            ->map(fn ($label) => ['label' => $label, 'tone' => 'up'])
            ->values()
            ->all();
    }
    $riskPills = collect(array_merge($riskReasons, $bearReasons))
        ->unique()
        ->take(4)
        ->map(fn ($label) => ['label' => $label, 'tone' => in_array($label, $bearReasons, true) ? 'down' : 'warning'])
        ->values()
        ->all();
    if ($riskPills === []) {
        $riskPills = [['label' => '尚無明顯風險旗標', 'tone' => 'warning']];
    }
    $radarCardLabels = [
        'priority' => '今日優先觀察',
        'risk' => '今日風險升高',
        'potential' => '潛力觀察',
        'low_volume' => '低檔爆量',
        'weak' => '持續弱勢',
    ];
    $evaluationQuick = [
        'state' => $evaluation['label'],
        'tone' => $evaluation['tone'],
        'confidence' => $confidence,
        'radar_card' => $latestRadarCard ? ($radarCardLabels[$latestRadarCard->card_type] ?? $latestRadarCard->card_type) : '未列入今日五張卡',
        'radar_tone' => $latestRadarCard ? $evaluation['tone'] : 'amber',
        'support_pills' => array_slice($supportPills, 0, 4),
        'risk_pills' => $riskPills,
        'one_liner' => $interpretation,
        'phrase_engine' => $phraseEvaluation['engine'] ?? null,
    ];

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
        'stockCharts' => [
            'support' => $supportChart,
            'chips' => $chipRowsForChart,
            'revenues' => $revenueRowsForChart,
            'financials' => $financialRowsForChart,
        ],
        'chip' => $latestChip,
        'chipSignals' => $chipSignals,
        'stockThemes' => $stockThemes,
        'fundamentalSignals' => $fundamentalSignals,
        'eventChains' => $eventChains,
        'latestReport' => $latestReport,
        'summary' => $stockEvaluationSummary,
        'evaluationQuick' => $evaluationQuick,
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
    $themeStatus = function (string $name, int $score, int $newsScore, int $priceScore, int $volumeScore, int $chipScore): string {
        $drivers = [];

        if ($newsScore >= 55) {
            $drivers[] = '新聞熱度';
        }

        if ($priceScore >= 60) {
            $drivers[] = '代表股轉強';
        }

        if ($volumeScore >= 55) {
            $drivers[] = '量價同步';
        }

        if ($chipScore >= 60) {
            $drivers[] = '籌碼偏正向';
        }

        $driverText = $drivers === [] ? '相關股票與事件資料' : implode('、', array_slice($drivers, 0, 3));
        $level = match (true) {
            $score >= 80 => '高熱度',
            $score >= 65 => '中高熱度',
            $score >= 45 => '觀察熱度',
            default => '低熱度觀察',
        };

        return $name.'題材由'.$driverText.'帶動，目前屬於'.$level.'。';
    };
    $themeReasons = function (int $score, int $newsScore, int $priceScore, int $volumeScore, int $chipScore, int $stockCount): array {
        $reasons = [];

        if ($newsScore >= 55) {
            $reasons[] = ['label' => '新聞熱度增加', 'tone' => 'red'];
        }

        if ($priceScore >= 60) {
            $reasons[] = ['label' => '代表股轉強', 'tone' => 'red'];
        }

        if ($volumeScore >= 55) {
            $reasons[] = ['label' => '量價同步', 'tone' => 'red'];
        }

        if ($chipScore >= 60) {
            $reasons[] = ['label' => '法人籌碼偏正向', 'tone' => 'red'];
        }

        if ($stockCount >= 10) {
            $reasons[] = ['label' => '族群擴散', 'tone' => 'red'];
        }

        if ($score >= 70) {
            $reasons[] = ['label' => '熱度維持高檔', 'tone' => 'red'];
        }

        return array_slice($reasons === [] ? [['label' => '題材觀察中', 'tone' => 'amber']] : $reasons, 0, 4);
    };
    $themeRisks = function (int $score, int $eventCount, int $priceScore, int $chipScore, int $stockCount): array {
        $risks = [];

        if ($stockCount < 5) {
            $risks[] = ['label' => '題材尚未全面擴散', 'tone' => 'amber'];
        }

        if ($chipScore < 55) {
            $risks[] = ['label' => '需觀察法人延續', 'tone' => 'amber'];
        }

        if ($priceScore < 55) {
            $risks[] = ['label' => '代表股尚未同步轉強', 'tone' => 'amber'];
        }

        if ($eventCount === 0) {
            $risks[] = ['label' => '新聞催化不足', 'tone' => 'amber'];
        }

        if ($score >= 80) {
            $risks[] = ['label' => '熱度偏高留意拉回', 'tone' => 'amber'];
        }

        return array_slice($risks === [] ? [['label' => '觀察後續量價延續', 'tone' => 'amber']] : $risks, 0, 3);
    };
    $themes = DB::table('themes')
        ->leftJoin('theme_scores', function ($join) {
            $join->on('themes.id', '=', 'theme_scores.theme_id')
                ->whereRaw('theme_scores.score_date = (select max(ts.score_date) from theme_scores ts where ts.theme_id = themes.id)');
        })
        ->select('themes.id', 'themes.slug', 'themes.name', 'themes.description', 'themes.ai_status', 'theme_scores.heat_score', 'theme_scores.news_score', 'theme_scores.price_score', 'theme_scores.volume_score', 'theme_scores.chip_score', 'theme_scores.score_date', 'theme_scores.payload')
        ->where('themes.is_active', true)
        ->orderByRaw('coalesce(theme_scores.heat_score, 0) desc')
        ->orderBy('themes.name')
        ->get()
        ->map(function ($theme) use ($themePhase, $themeTone, $themeStatus, $themeReasons, $themeRisks) {
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
                ->leftJoin('stock_prices_1d', function ($join) {
                    $join->on('stocks.id', '=', 'stock_prices_1d.stock_id')
                        ->whereRaw('stock_prices_1d.trade_date = (select max(sp.trade_date) from stock_prices_1d sp where sp.stock_id = stocks.id)');
                })
                ->where('stock_theme_map.theme_id', $theme->id)
                ->whereNotNull('stock_scores.total_score')
                ->orderByDesc('stock_scores.confidence_score')
                ->limit(20)
                ->get(['stocks.symbol', 'stocks.name', 'stock_scores.total_score', 'stock_scores.confidence_score', 'stock_scores.confidence_payload', 'stock_prices_1d.close', 'stock_prices_1d.change'])
                ->map(function ($stock) {
                    $payload = is_string($stock->confidence_payload)
                        ? (json_decode($stock->confidence_payload, true) ?: [])
                        : ((array) ($stock->confidence_payload ?? []));
                    $confidence = (int) ($payload['opportunity_confidence'] ?? $stock->confidence_score ?? 0);

                    return [
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                        'score' => $stock->total_score,
                        'confidence' => $confidence,
                        'close' => $stock->close === null ? null : (float) $stock->close,
                        'change' => $stock->change === null ? null : (float) $stock->change,
                        'state' => match (true) {
                            $confidence >= 78 => '高度觀察',
                            $confidence >= 68 => '偏多觀察',
                            $confidence >= 55 => '中性觀察',
                            $confidence >= 40 => '保守觀察',
                            default => '弱勢觀察',
                        },
                    ];
                })
                ->all();

            $newsScore = (int) ($theme->news_score ?? 0);
            $priceScore = (int) ($theme->price_score ?? 0);
            $volumeScore = (int) ($theme->volume_score ?? 0);
            $chipScore = (int) ($theme->chip_score ?? 0);
            $payload = is_string($theme->payload ?? null)
                ? (json_decode($theme->payload, true) ?: [])
                : ((array) ($theme->payload ?? []));
            $aiSummary = data_get($payload, 'ai_summary.status_zh');
            $aiPriceReason = data_get($payload, 'ai_summary.price_reason_zh');
            $fallbackStatus = $themeStatus($theme->name, $score, $newsScore, $priceScore, $volumeScore, $chipScore);

            return [
                'name' => $theme->name,
                'slug' => $theme->slug,
                'score' => $score,
                'phase' => $themePhase($score, $newsScore, $priceScore),
                'tone' => $themeTone($score),
                'status' => filled($aiSummary) ? $aiSummary : $fallbackStatus,
                'price_reason' => filled($aiPriceReason) ? $aiPriceReason : null,
                'reasons' => $themeReasons($score, $newsScore, $priceScore, $volumeScore, $chipScore, $mappedCount),
                'risks' => $themeRisks($score, $eventCount, $priceScore, $chipScore, $mappedCount),
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

    $aiReport = DB::table('theme_ai_reports')
        ->orderByDesc('report_date')
        ->orderByDesc('updated_at')
        ->first(['report_date', 'title', 'summary', 'model', 'updated_at']);

    return view('themes', ['themes' => $themes, 'aiReport' => $aiReport]);
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
            'stock_prices_1d.volume',
            'stock_prices_1d.open',
            'stock_prices_1d.high',
            'stock_prices_1d.low',
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

            $close = $item->close === null ? null : (float) $item->close;
            $change = $item->change === null ? null : (float) $item->change;
            $previousClose = $close !== null && $change !== null ? $close - $change : null;
            $changePercent = $previousClose !== null && $previousClose > 0
                ? ($change / $previousClose) * 100
                : null;

            return [
                'symbol' => $item->symbol,
                'name' => $item->name,
                'market' => $item->market,
                'industry' => $item->industry ?: '未分類',
                'close' => $close,
                'change' => $change,
                'change_percent' => $changePercent,
                'volume_lots' => $item->volume === null ? null : (int) round(((int) $item->volume) / 1000),
                'open' => $item->open === null ? null : (float) $item->open,
                'high' => $item->high === null ? null : (float) $item->high,
                'low' => $item->low === null ? null : (float) $item->low,
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
    $authLastSeenLogs = DB::table('system_logs')
        ->where('source', 'auth')
        ->where('level', 'info')
        ->orderByDesc('created_at')
        ->limit(1000)
        ->get(['context', 'created_at']);
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
    DB::table('sessions')
        ->whereNull('user_id')
        ->orderByDesc('last_activity')
        ->limit(200)
        ->get(['id', 'payload'])
        ->each(function ($session) use ($sessionUserId) {
            $userId = $sessionUserId($session->payload);

            if ($userId) {
                DB::table('sessions')
                    ->where('id', $session->id)
                    ->update(['user_id' => $userId]);
            }
        });
    $sessionRows = DB::table('sessions')
        ->get(['id', 'user_id', 'payload', 'last_activity']);
    $lastSeenByUser = $sessionRows
        ->map(fn ($session) => [
            'user_id' => $session->user_id ?: $sessionUserId($session->payload),
            'last_activity' => (int) $session->last_activity,
        ])
        ->filter(fn ($session) => $session['user_id'] !== null)
        ->groupBy('user_id')
        ->map(fn ($sessions) => $sessions->max('last_activity'));
    $lastLoginByUser = $authLastSeenLogs
        ->map(function ($log) {
            $context = is_string($log->context) ? json_decode($log->context, true) : (array) $log->context;

            return [
                'user_id' => isset($context['user_id']) ? (int) $context['user_id'] : null,
                'created_at' => $log->created_at,
            ];
        })
        ->filter(fn ($log) => $log['user_id'] !== null)
        ->groupBy('user_id')
        ->map(fn ($logs) => $logs->max('created_at'));
    $members = User::query()
        ->orderByDesc('created_at')
        ->limit(100)
        ->get(['id', 'name', 'email', 'is_admin', 'created_at'])
        ->map(function (User $user) use ($lastSeenByUser, $lastLoginByUser) {
            $lastActivity = $lastSeenByUser->get($user->id);
            $user->last_seen_at = $lastActivity
                ? \Carbon\CarbonImmutable::createFromTimestamp((int) $lastActivity, 'Asia/Taipei')
                : ($lastLoginByUser->get($user->id)
                    ? \Carbon\CarbonImmutable::parse($lastLoginByUser->get($user->id))->timezone('Asia/Taipei')
                    : null);

            return $user;
        });
    $onlineSessions = DB::table('sessions')
        ->where('last_activity', '>=', now()->subMinutes(5)->timestamp)
        ->orderByDesc('last_activity')
        ->limit(50)
        ->get(['id', 'user_id', 'payload', 'ip_address', 'user_agent', 'last_activity']);
    $onlineUsers = User::query()
        ->whereIn('id', $onlineSessions->map(fn ($session) => $session->user_id ?: $sessionUserId($session->payload))->filter()->unique()->values())
        ->get(['id', 'name', 'email', 'is_admin'])
        ->keyBy('id');
    $onlineMembers = $onlineSessions
        ->map(function ($session) use ($sessionUserId, $onlineUsers) {
            $userId = $session->user_id ?: $sessionUserId($session->payload);
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

Route::get('/admin/agents', function () {
    MarketxAuth::requireAdmin();

    $roles = AgentRole::query()
        ->withCount([
            'runs',
            'findings',
            'findings as pending_findings_count' => fn ($query) => $query->where('status', 'pending'),
            'memories',
        ])
        ->orderBy('id')
        ->get()
        ->map(function (AgentRole $role) {
            $role->latest_run = AgentRun::query()
                ->where('agent_role_id', $role->id)
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->first();

            return $role;
        });

    $latestRuns = AgentRun::query()
        ->with('role:id,name,slug')
        ->orderByDesc('started_at')
        ->orderByDesc('id')
        ->limit(12)
        ->get();

    $pendingFindings = AgentFinding::query()
        ->with('role:id,name,slug')
        ->where('status', 'pending')
        ->orderByRaw("case severity when 'critical' then 1 when 'high' then 2 when 'medium' then 3 when 'low' then 4 else 5 end")
        ->orderByDesc('created_at')
        ->limit(20)
        ->get();

    $reviewedFindings = AgentFinding::query()
        ->with('role:id,name,slug')
        ->whereIn('status', ['accepted', 'rejected', 'resolved', 'observing'])
        ->orderByDesc('reviewed_at')
        ->orderByDesc('updated_at')
        ->limit(20)
        ->get();

    $latestMemories = AgentMemory::query()
        ->with('role:id,name,slug')
        ->where('status', 'active')
        ->orderByDesc('updated_at')
        ->limit(20)
        ->get();

    $pendingLearningSuggestions = DB::table('agent_learning_suggestions')
        ->leftJoin('agent_roles', 'agent_roles.id', '=', 'agent_learning_suggestions.agent_role_id')
        ->where('agent_learning_suggestions.status', 'pending')
        ->orderByDesc('agent_learning_suggestions.priority')
        ->orderByDesc('agent_learning_suggestions.id')
        ->limit(30)
        ->get([
            'agent_learning_suggestions.id',
            'agent_learning_suggestions.suggestion_type',
            'agent_learning_suggestions.target_table',
            'agent_learning_suggestions.priority',
            'agent_learning_suggestions.title',
            'agent_learning_suggestions.rationale',
            'agent_learning_suggestions.proposed_payload',
            'agent_learning_suggestions.created_at',
            'agent_roles.name as agent_name',
        ]);

    $summary = [
        'roles' => $roles->count(),
        'active_roles' => $roles->where('is_active', true)->count(),
        'pending_findings' => AgentFinding::query()->where('status', 'pending')->count(),
        'reviewed_findings' => AgentFinding::query()->whereIn('status', ['accepted', 'rejected', 'resolved', 'observing'])->count(),
        'active_memories' => AgentMemory::query()->where('status', 'active')->count(),
        'today_runs' => AgentRun::query()->whereDate('created_at', now('Asia/Taipei')->toDateString())->count(),
        'pending_learning_suggestions' => DB::table('agent_learning_suggestions')->where('status', 'pending')->count(),
        'knowledge_items' => DB::table('market_knowledge_items')->where('status', 'active')->count(),
    ];

    return view('admin_agents', [
        'roles' => $roles,
        'latestRuns' => $latestRuns,
        'pendingFindings' => $pendingFindings,
        'reviewedFindings' => $reviewedFindings,
        'latestMemories' => $latestMemories,
        'pendingLearningSuggestions' => $pendingLearningSuggestions,
        'summary' => $summary,
    ]);
});

Route::get('/admin/radar-performance', function () {
    MarketxAuth::requireAdmin();

    $cardLabels = [
        'priority' => '優先觀察',
        'risk' => '風險升高',
        'potential' => '潛力觀察',
        'low_volume' => '低檔爆量',
        'weak' => '持續弱勢',
    ];

    $horizonRows = DB::table('stock_radar_observation_checks as c')
        ->join('stock_radar_observations as o', 'o.id', '=', 'c.stock_radar_observation_id')
        ->whereIn('c.days_since_selected', [1, 3, 5])
        ->groupBy('o.card_type', 'c.days_since_selected')
        ->orderBy('o.card_type')
        ->orderBy('c.days_since_selected')
        ->get([
            'o.card_type',
            'c.days_since_selected',
            DB::raw('count(*) as total'),
            DB::raw('count(c.change_pct) as valid_count'),
            DB::raw('round(avg(c.change_pct), 2) as avg_change_pct'),
            DB::raw('sum(case when c.change_pct > 0 then 1 else 0 end) as up_count'),
            DB::raw('sum(case when c.change_pct < 0 then 1 else 0 end) as down_count'),
            DB::raw('sum(case when c.change_pct = 0 then 1 else 0 end) as flat_count'),
            DB::raw('round(max(c.change_pct), 2) as max_change_pct'),
            DB::raw('round(min(c.change_pct), 2) as min_change_pct'),
        ]);

    $horizonStats = collect($cardLabels)->mapWithKeys(function (string $label, string $type) use ($horizonRows) {
        $rows = $horizonRows->where('card_type', $type)->keyBy('days_since_selected');

        return [$type => [
            'label' => $label,
            'horizons' => collect([1, 3, 5])->mapWithKeys(fn (int $day) => [$day => $rows->get($day)]),
        ]];
    });

    $conditionStats = DB::table('stock_radar_observations')
        ->groupBy('card_type')
        ->get([
            'card_type',
            DB::raw('count(*) as total'),
            DB::raw("sum(case when status = 'active' then 1 else 0 end) as active_count"),
            DB::raw('round(avg((performance_payload->>\'avg_change_pct\')::numeric), 2) as tracked_avg_change_pct'),
        ])
        ->keyBy('card_type');

    $observationsForReasons = DB::table('stock_radar_observations as o')
        ->leftJoin('stock_radar_observation_checks as c', function ($join) {
            $join->on('c.stock_radar_observation_id', '=', 'o.id')
                ->where('c.days_since_selected', 1);
        })
        ->where('o.selected_date', '>=', now('Asia/Taipei')->subDays(45)->toDateString())
        ->get(['o.card_type', 'o.entry_reasons', 'c.change_pct']);

    $reasonStats = [];
    foreach ($observationsForReasons as $row) {
        $reasons = is_string($row->entry_reasons) ? json_decode($row->entry_reasons, true) : [];
        $labels = collect(is_array($reasons) ? $reasons : [])
            ->map(fn ($reason) => is_array($reason) ? ($reason['label'] ?? null) : null)
            ->filter()
            ->unique()
            ->values();

        foreach ($labels as $label) {
            $key = $row->card_type.'|'.$label;
            $reasonStats[$key] ??= [
                'card_type' => $row->card_type,
                'label' => $label,
                'total' => 0,
                'valid' => 0,
                'up' => 0,
                'down' => 0,
                'sum' => 0.0,
            ];
            $reasonStats[$key]['total']++;

            if ($row->change_pct !== null) {
                $change = (float) $row->change_pct;
                $reasonStats[$key]['valid']++;
                $reasonStats[$key]['sum'] += $change;
                $reasonStats[$key][$change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat')] =
                    ($reasonStats[$key][$change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat')] ?? 0) + 1;
            }
        }
    }

    $reasonStats = collect($reasonStats)
        ->map(function (array $row) {
            $row['avg_change_pct'] = $row['valid'] > 0 ? round($row['sum'] / $row['valid'], 2) : null;
            $row['win_rate'] = $row['valid'] > 0 ? round(($row['up'] / $row['valid']) * 100, 1) : null;

            return $row;
        })
        ->sortByDesc(fn (array $row) => sprintf('%04d-%08.2f', $row['valid'], $row['avg_change_pct'] ?? -999))
        ->take(30)
        ->values();

    $observations = DB::table('stock_radar_observations as o')
        ->join('stocks as s', 's.id', '=', 'o.stock_id')
        ->orderByDesc('o.selected_date')
        ->orderBy('o.card_type')
        ->orderBy('o.entry_rank')
        ->limit(120)
        ->get([
            'o.id',
            'o.selected_date',
            'o.card_type',
            'o.entry_rank',
            'o.entry_confidence',
            'o.entry_reasons',
            'o.status',
            'o.last_checked_date',
            'o.performance_payload',
            's.symbol',
            's.name',
            's.market',
        ]);

    $latestChecks = DB::table('stock_radar_observation_checks as c')
        ->joinSub(
            DB::table('stock_radar_observation_checks')
                ->selectRaw('stock_radar_observation_id, max(check_date) as latest_date')
                ->groupBy('stock_radar_observation_id'),
            'latest',
            function ($join) {
                $join->on('c.stock_radar_observation_id', '=', 'latest.stock_radar_observation_id')
                    ->on('c.check_date', '=', 'latest.latest_date');
            }
        )
        ->whereIn('c.stock_radar_observation_id', $observations->pluck('id'))
        ->get(['c.stock_radar_observation_id', 'c.check_date', 'c.days_since_selected', 'c.close', 'c.change', 'c.change_pct', 'c.condition_still_present'])
        ->keyBy('stock_radar_observation_id');

    $observations = $observations->map(function ($row) use ($latestChecks) {
        $row->latest_check = $latestChecks->get($row->id);
        $reasons = is_string($row->entry_reasons) ? json_decode($row->entry_reasons, true) : [];
        $row->reason_labels = collect(is_array($reasons) ? $reasons : [])
            ->map(fn ($reason) => is_array($reason) ? ($reason['label'] ?? null) : null)
            ->filter()
            ->take(4)
            ->values();
        $row->performance = is_string($row->performance_payload) ? json_decode($row->performance_payload, true) : [];

        return $row;
    });

    return view('admin_radar_performance', [
        'cardLabels' => $cardLabels,
        'horizonStats' => $horizonStats,
        'conditionStats' => $conditionStats,
        'reasonStats' => $reasonStats,
        'observations' => $observations,
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
