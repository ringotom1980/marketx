@extends('welcome')

@php
    $badgeTone = function (?string $tone): string {
        return match ($tone) {
            'green' => 'red',
            'red' => 'green',
            'amber' => 'amber',
            default => '',
        };
    };

    $decisionTone = str_contains($stock['decision'], '買') ? 'red'
        : (str_contains($stock['decision'], '賣') || str_contains($stock['decision'], '減') ? 'green' : 'amber');
@endphp

@section('content')
    <section class="page-head">
        <div>
            <h1>{{ $stock['name'] }} {{ $stock['symbol'] }}</h1>
            <p class="lead">{{ $stock['market'] }}｜收盤 {{ $stock['close'] }}｜漲跌 {{ $stock['change'] }}｜成交量 {{ $stock['volume'] }}</p>
        </div>
        <div class="panel">
            <div class="badge {{ $decisionTone }}">{{ $stock['decision'] }}</div>
            <div class="score" style="margin-top:12px">{{ $stock['score'] }} / 100</div>
            <p class="lead">信心度 {{ $stock['confidence'] }}%</p>
        </div>
    </section>

    <section class="grid two">
        <div class="panel">
            <h2>六大模組分數</h2>
            <table class="table">
                <tbody>
                @foreach ($modules as $module)
                    <tr>
                        <th>{{ $module['name'] }}</th>
                        <td><div class="meter"><span style="width: {{ min(100, max(0, $module['score'])) }}%"></span></div></td>
                        <td>{{ $module['score'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="panel">
            <h2>全球事件影響鏈</h2>
            <div class="chain">
                @foreach ($chain as $item)
                    <div>{{ $item }}</div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="grid two" style="margin-top:16px">
        <div class="panel">
            <h2>個股題材熱度</h2>
            @if (! empty($stockThemes) && $stockThemes->isNotEmpty())
                <table class="table">
                    <tbody>
                    @foreach ($stockThemes as $theme)
                        <tr>
                            <th>{{ $theme['name'] }}</th>
                            <td>
                                <div class="meter"><span style="width: {{ min(100, max(0, $theme['score'])) }}%"></span></div>
                                <p class="lead" style="font-size:13px">{{ $theme['reason'] }}</p>
                            </td>
                            <td>{{ $theme['score'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <p class="lead">這檔股票目前尚未映射到動態題材。等題材關鍵字、新聞事件或產業規則命中後，會自動出現在這裡。</p>
            @endif
        </div>

        <div class="panel">
            <h2>K 線與技術分析</h2>
            @if ($technical && ! empty($technical['signals']))
                <div class="signal-list">
                    @foreach ($technical['signals'] as $signal)
                        <div class="signal-item">
                            <span class="badge {{ $badgeTone($signal['tone'] ?? '') }}">{{ $signal['title'] ?? '技術訊號' }}</span>
                            <p>{{ $signal['body'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="lead">目前技術資料不足，等待更多日 K 資料後會產生均線、KD、MACD、RSI、布林通道與量價訊號。</p>
            @endif
        </div>

        <div class="panel">
            <h2>籌碼分析</h2>
            @if (! empty($chipSignals))
                <div class="signal-list">
                    @foreach ($chipSignals as $signal)
                        <div class="signal-item">
                            <span class="badge {{ $badgeTone($signal['tone'] ?? '') }}">{{ $signal['title'] ?? '籌碼訊號' }}</span>
                            <p>{{ $signal['body'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="lead">目前籌碼資料不足，等待三大法人、融資融券、借券與外資持股資料後會產生分析。</p>
            @endif
        </div>
    </section>

    <section class="grid two" style="margin-top:16px">
        <div class="panel">
            <h2>財務營收分析</h2>
            @if (! empty($fundamentalSignals))
                <div class="signal-list">
                    @foreach ($fundamentalSignals as $signal)
                        <div class="signal-item">
                            <span class="badge {{ $badgeTone($signal['tone'] ?? '') }}">{{ $signal['title'] ?? '財務訊號' }}</span>
                            <p>{{ $signal['body'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="lead">目前財務資料不足，等待月營收、EPS、ROE、毛利率與本益比資料後會產生分析。</p>
            @endif
        </div>

        <div class="panel">
            <h2>規則式中文摘要</h2>
            <p class="lead">{{ $summary }}</p>
        </div>
    </section>
@endsection
