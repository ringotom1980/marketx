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
                        <td><div class="meter"><span style="width: {{ $module['score'] }}%"></span></div></td>
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
                <p class="lead">目前技術資料不足，等待更多日 K 資料回補後產生分析。</p>
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
                <p class="lead">目前籌碼資料不足，等待法人與融資融券資料更新後產生分析。</p>
            @endif
        </div>
    </section>

    <section class="grid two" style="margin-top:16px">
        <div class="panel">
            <h2>財報分析</h2>
            @if (! empty($fundamentalSignals))
                <div class="signal-list">
                    @foreach ($fundamentalSignals as $signal)
                        <div class="signal-item">
                            <span class="badge {{ $badgeTone($signal['tone'] ?? '') }}">{{ $signal['title'] ?? '財報訊號' }}</span>
                            <p>{{ $signal['body'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="lead">目前財報資料不足，等待官方財報與月營收資料更新後產生分析。</p>
            @endif
        </div>

        <div class="panel">
            <h2>AI 分析摘要</h2>
            <p class="lead">{{ $summary }}</p>
        </div>
    </section>
@endsection
