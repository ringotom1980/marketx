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
            @if ($stock['isWatched'])
                <form method="post" action="/watchlist/{{ $stock['symbol'] }}" style="margin-top:12px">
                    @csrf
                    @method('DELETE')
                    <button class="button" type="submit" style="width:100%">取消追蹤</button>
                </form>
            @else
                <form method="post" action="/watchlist" style="margin-top:12px">
                    @csrf
                    <input type="hidden" name="symbol" value="{{ $stock['symbol'] }}">
                    <button class="button" type="submit" style="width:100%">加入追蹤</button>
                </form>
            @endif
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
            @if (! empty($eventChains))
                <div class="signal-list">
                    @foreach ($eventChains as $chain)
                        <div class="signal-item">
                            <span class="badge amber">{{ $chain['event'] }}</span>
                            <p>{{ $chain['path'] }}</p>
                            <p>{{ $chain['judgement'] }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="lead">目前沒有明確全球事件直接連到此股票，先以技術、籌碼與財務分數觀察。</p>
            @endif
        </div>
    </section>

    <section class="grid two" style="margin-top:16px">
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
            <h2>評價</h2>
            <p class="lead">{{ $summary }}</p>
        </div>
    </section>
@endsection
