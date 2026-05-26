@extends('welcome')

@section('content')
    <section class="page-head">
        <div>
            <h1>題材雷達</h1>
            <p class="lead">依今日事件、題材熱度、資金狀態與相關股票表現，判斷題材是否升溫、延續或退潮。</p>
        </div>
    </section>

    <section class="grid two">
        @forelse ($themes as $theme)
            <article class="panel" id="theme-{{ $theme['slug'] }}" style="scroll-margin-top:96px">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
                    <h2 style="margin:0">{{ $theme['name'] }}</h2>
                    <div style="display:flex;align-items:center;gap:8px;white-space:nowrap">
                        <span class="badge {{ $theme['tone'] }}">{{ $theme['phase'] }}</span>
                        <strong>{{ $theme['confidence'] }}%</strong>
                    </div>
                </div>

                @if ($theme['top_stocks'] !== [])
                    <div style="margin-top:18px">
                        <h3 style="font-size:16px;margin:0 0 8px">代表股票</h3>
                        <div style="display:flex;flex-wrap:wrap;gap:10px 14px">
                            @foreach ($theme['top_stocks'] as $stock)
                                @php
                                    $change = $stock['change'];
                                    $tone = $change === null ? 'var(--muted)' : ($change > 0 ? 'var(--red)' : ($change < 0 ? 'var(--green)' : 'var(--muted)'));
                                    $arrow = $change === null ? '' : ($change > 0 ? '▲' : ($change < 0 ? '▼' : ''));
                                    $changeText = $change === null ? '' : ' '.$arrow.rtrim(rtrim(number_format(abs($change), 2), '0'), '.');
                                    $closeText = $stock['close'] === null ? '待更新' : rtrim(rtrim(number_format($stock['close'], 2), '0'), '.');
                                @endphp
                                <a href="/s/{{ $stock['symbol'] }}" style="color:inherit;text-decoration:none">
                                    <strong>{{ $stock['name'] }}</strong>
                                    <span>{{ $closeText }}</span>
                                    @if ($changeText !== '')
                                        <span style="color:{{ $tone }};font-weight:800">{{ $changeText }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($theme['related_stocks'] !== [])
                    <details style="margin-top:12px">
                        <summary class="button" style="display:inline-flex">查看相關股票 {{ $theme['stock_count'] }} 檔</summary>
                        <table class="table" style="margin-top:10px">
                            <tbody>
                            @foreach ($theme['related_stocks'] as $stock)
                                <tr>
                                    <th><a href="/s/{{ $stock['symbol'] }}">{{ $stock['name'] }}</a></th>
                                    <td>{{ $stock['state'] ?? '等待計算' }}</td>
                                    <td>信心 {{ $stock['confidence'] ?? 0 }}%</td>
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
