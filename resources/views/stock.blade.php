@extends('welcome')

@section('content')
    <section class="page-head">
        <div>
            <h1>{{ $stock['name'] }} {{ $stock['symbol'] }}</h1>
            <p class="lead">{{ $stock['market'] }}｜收盤 {{ $stock['close'] }}｜漲跌 {{ $stock['change'] }}｜成交量 {{ $stock['volume'] }}</p>
        </div>
        <div class="panel">
            <div class="badge green">{{ $stock['decision'] }}</div>
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
            @if ($technical)
                <table class="table">
                    <tbody>
                    <tr><th>SMA 5 / 20 / 60</th><td>{{ $technical['sma5'] ?? '-' }} / {{ $technical['sma20'] ?? '-' }} / {{ $technical['sma60'] ?? '-' }}</td></tr>
                    <tr><th>EMA 12 / 26</th><td>{{ $technical['ema12'] ?? '-' }} / {{ $technical['ema26'] ?? '-' }}</td></tr>
                    <tr><th>RSI 14</th><td>{{ $technical['rsi14'] ?? '-' }}</td></tr>
                    <tr><th>MACD / Signal / Histogram</th><td>{{ $technical['macd'] ?? '-' }} / {{ $technical['macd_signal'] ?? '-' }} / {{ $technical['macd_histogram'] ?? '-' }}</td></tr>
                    <tr><th>KD 9</th><td>K {{ $technical['k9'] ?? '-' }} / D {{ $technical['d9'] ?? '-' }}</td></tr>
                    <tr><th>布林通道 20</th><td>上 {{ $technical['bollinger_upper20'] ?? '-' }} / 中 {{ $technical['bollinger_middle20'] ?? '-' }} / 下 {{ $technical['bollinger_lower20'] ?? '-' }}</td></tr>
                    <tr><th>ATR 14</th><td>{{ $technical['atr14'] ?? '-' }}</td></tr>
                    <tr><th>20 日報酬</th><td>{{ $technical['return20'] ?? '-' }}%</td></tr>
                    <tr><th>20 日量比</th><td>{{ $technical['volume_ratio20'] ?? '-' }}</td></tr>
                    <tr><th>20 日波動</th><td>{{ $technical['volatility20'] ?? '-' }}%</td></tr>
                    <tr><th>20 日突破</th><td>{{ ($technical['breakout20'] ?? false) ? '是' : '否' }}</td></tr>
                    </tbody>
                </table>
            @else
                <p class="lead">尚未產生技術分析。</p>
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
                    <tr><th>融資餘額</th><td>{{ $chip->margin_balance === null ? '無資料' : number_format($chip->margin_balance) }}</td></tr>
                    <tr><th>融券餘額</th><td>{{ $chip->short_balance === null ? '無資料' : number_format($chip->short_balance) }}</td></tr>
                    </tbody>
                </table>
            @else
                <p class="lead">尚未匯入籌碼資料。</p>
            @endif
        </div>
    </section>

    <section class="grid two" style="margin-top:16px">
        <div class="panel">
            <h2>規則式中文解釋</h2>
            <p class="lead">{{ $summary }}</p>
        </div>
    </section>
@endsection
