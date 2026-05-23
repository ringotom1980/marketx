@extends('welcome')

@section('content')
    <section class="page-head">
        <div>
            <h1>{{ $stock['name'] }} {{ $stock['symbol'] }}</h1>
            <p class="lead">{{ $stock['market'] }} · 收盤價 {{ $stock['close'] }} · 漲跌 {{ $stock['change'] }} · 成交量 {{ $stock['volume'] }}</p>
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
            <div class="placeholder-chart">TradingView Lightweight Charts 接入區</div>
        </div>
        <div class="panel">
            <h2>AI 分析摘要</h2>
            <p class="lead">{{ $summary }}</p>
        </div>
    </section>
@endsection

