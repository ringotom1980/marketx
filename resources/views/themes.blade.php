@extends('welcome')

@section('content')
    <section class="page-head">
        <div>
            <h1>題材雷達</h1>
            <p class="lead">依今日事件、題材熱度、資金分數與相關股票狀態，判斷題材是否升溫、延續或退潮。</p>
        </div>
    </section>

    <section class="grid two">
        @forelse ($themes as $theme)
            <article class="panel">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
                    <div>
                        <h2 style="margin-bottom:6px">{{ $theme['name'] }}</h2>
                        <span class="badge {{ $theme['tone'] }}">{{ $theme['phase'] }}</span>
                    </div>
                    <div class="score" style="font-size:32px">{{ $theme['score'] }}</div>
                </div>

                <div class="meter" style="margin:14px 0 12px">
                    <span style="width: {{ min(100, max(0, $theme['score'])) }}%"></span>
                </div>

                <table class="table" style="margin-top:12px">
                    <tbody>
                    <tr>
                        <th>新聞事件</th>
                        <td>台灣 {{ $theme['taiwan_event_count'] }} / 國際 {{ $theme['global_event_count'] }}</td>
                    </tr>
                    <tr>
                        <th>相關股票</th>
                        <td>{{ $theme['stock_count'] }} 檔</td>
                    </tr>
                    <tr>
                        <th>技術 / 籌碼</th>
                        <td>{{ $theme['price_score'] }} / {{ $theme['chip_score'] }}</td>
                    </tr>
                    @if ($theme['top_stocks'] !== [])
                        <tr>
                            <th>代表股票</th>
                            <td>
                                @foreach ($theme['top_stocks'] as $stock)
                                    <a href="/s/{{ $stock['symbol'] }}">{{ $stock['name'] }}</a>{{ ! $loop->last ? '、' : '' }}
                                @endforeach
                            </td>
                        </tr>
                    @endif
                    </tbody>
                </table>

                @if ($theme['related_stocks'] !== [])
                    <details style="margin-top:12px">
                        <summary class="button" style="display:inline-flex">查看相關股票 {{ $theme['stock_count'] }} 檔</summary>
                        <table class="table" style="margin-top:10px">
                            <tbody>
                            @foreach ($theme['related_stocks'] as $stock)
                                <tr>
                                    <th><a href="/s/{{ $stock['symbol'] }}">{{ $stock['name'] }}</a></th>
                                    <td>{{ $stock['decision'] ?? '等待計算' }}</td>
                                    <td>{{ $stock['score'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </details>
                @endif
            </article>
        @empty
            <div class="panel">
                <h2>題材資料準備中</h2>
                <p class="lead">尚未產生題材熱度資料。</p>
            </div>
        @endforelse
    </section>
@endsection
