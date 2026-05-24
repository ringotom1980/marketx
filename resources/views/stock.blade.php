@extends('welcome')

@section('content')
    <section class="page-head">
        <div>
            <h1>{{ $stock['name'] }} {{ $stock['symbol'] }}</h1>
            <p class="lead">{{ $stock['market'] }}｜收盤 {{ $stock['close'] }}｜漲跌 {{ $stock['change'] }}｜成交量 {{ $stock['volume'] }}</p>
        </div>
        <div class="panel">
            @php
                $decisionTone = str_contains($stock['decision'], '買') ? 'red'
                    : (str_contains($stock['decision'], '賣') || str_contains($stock['decision'], '減') ? 'green' : 'amber');
            @endphp
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
                        @php
                            $toneClass = match ($signal['tone'] ?? '') {
                                'green' => 'red',
                                'red' => 'green',
                                'amber' => 'amber',
                                default => '',
                            };
                        @endphp
                        <div class="signal-item">
                            <span class="badge {{ $toneClass }}">{{ $signal['title'] ?? '技術訊號' }}</span>
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
            @if ($chip)
                <table class="table">
                    <tbody>
                    <tr><th>交易日</th><td>{{ $chip->trade_date->toDateString() }}</td></tr>
                    <tr><th>外資買賣超</th><td>{{ number_format($chip->foreign_net_buy) }}</td></tr>
                    <tr><th>投信買賣超</th><td>{{ number_format($chip->investment_trust_net_buy) }}</td></tr>
                    <tr><th>自營商買賣超</th><td>{{ number_format($chip->dealer_net_buy) }}</td></tr>
                    <tr><th>三大法人合計</th><td>{{ number_format($chip->institutional_net_buy) }}</td></tr>
                    <tr><th>融資餘額</th><td>{{ $chip->margin_balance === null ? '未取得' : number_format($chip->margin_balance) }}</td></tr>
                    <tr><th>融券餘額</th><td>{{ $chip->short_balance === null ? '未取得' : number_format($chip->short_balance) }}</td></tr>
                    </tbody>
                </table>
            @else
                <p class="lead">目前尚未匯入籌碼資料。</p>
            @endif
        </div>
    </section>

    <section class="grid two" style="margin-top:16px">
        <div class="panel">
            <h2>AI 分析摘要</h2>
            <p class="lead">{{ $summary }}</p>
        </div>
    </section>
@endsection
