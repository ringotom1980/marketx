@extends('welcome')

@section('content')
    <section class="page-head">
        <div>
            <h1>今日全球 × 台股狀態中心</h1>
            <p class="lead">整合全球市場、重大事件、題材熱度、台股籌碼與個股分數，作為買進、續抱、減碼、賣出的決策雷達。</p>
        </div>
        <form class="search" action="/search" method="get">
            <input name="q" value="{{ request('q') }}" placeholder="搜尋股票代號、名稱或產業">
            <button type="submit">搜尋</button>
        </form>
    </section>

    <section class="grid two">
        <div class="panel">
            <h2>全球市場摘要</h2>
            <table class="table">
                <tbody>
                @foreach ($markets as $market)
                    <tr>
                        <th>{{ $market['name'] }}</th>
                        <td><span class="badge {{ $market['tone'] }}">{{ $market['state'] }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="panel">
            <h2>今日全球重大事件</h2>
            <div class="chain">
                @foreach ($events as $event)
                    <div><strong>{{ $event['title'] }}</strong><br>{{ $event['impact'] }}</div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="grid three" style="margin-top:16px">
        <div class="panel">
            <h2>今日題材熱度</h2>
            <table class="table">
                <tbody>
                @foreach ($themes as $theme)
                    <tr>
                        <th>{{ $theme['name'] }}</th>
                        <td>
                            <div class="meter"><span style="width: {{ $theme['score'] }}%"></span></div>
                        </td>
                        <td>{{ $theme['score'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="panel">
            <h2>今日高分股票</h2>
            <table class="table">
                <tbody>
                @forelse ($topStocks as $stock)
                    <tr>
                        <th><a href="/s/{{ $stock['symbol'] }}">{{ $stock['name'] }}</a></th>
                        <td><span class="badge green">{{ $stock['decision'] }}</span></td>
                        <td>{{ $stock['score'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">尚未產生分數。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="panel">
            <h2>今日風險升高股票</h2>
            <table class="table">
                <tbody>
                @foreach ($riskStocks as $stock)
                    <tr>
                        <th>{{ $stock['name'] }}</th>
                        <td><span class="badge amber">{{ $stock['risk'] }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
