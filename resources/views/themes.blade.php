@extends('welcome')

@section('content')
    <style>
        .theme-ai-card {
            position: relative;
        }

        .theme-ai-title-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .theme-ai-updated {
            color: var(--muted);
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
            padding-top: 4px;
        }

        .theme-ai-body {
            color: var(--text);
            font-size: 16px;
            line-height: 1.9;
            max-height: 156px;
            overflow: hidden;
            position: relative;
            white-space: pre-line;
        }

        .theme-ai-body.collapsed::after {
            background: linear-gradient(to bottom, rgba(255, 255, 255, 0), var(--panel));
            bottom: 0;
            content: "";
            height: 58px;
            left: 0;
            pointer-events: none;
            position: absolute;
            right: 0;
        }

        .theme-ai-body.expanded {
            max-height: none;
        }

        .theme-ai-toggle {
            margin-top: 14px;
        }

        .theme-stock-row {
            align-items: center;
            color: inherit;
            display: inline-flex;
            gap: 6px;
            text-decoration: none;
        }

        @media (max-width: 640px) {
            .theme-ai-title-row {
                display: block;
            }

            .theme-ai-updated {
                margin-top: 4px;
                white-space: normal;
            }

            .theme-ai-body {
                font-size: 15px;
                line-height: 1.85;
                max-height: 148px;
            }
        }
    </style>

    <section class="page-head">
        <div>
            <h1>題材雷達</h1>
            <p class="lead">追蹤題材熱度、代表股票與資金輪動，早上 08:00 產生題材盤前觀察。</p>
        </div>
    </section>

    @if ($aiReport)
        <section class="panel theme-ai-card">
            <div class="theme-ai-title-row">
                <h2 style="margin:0">{{ $aiReport->title ?: '《股市在幹嘛》今日題材盤前觀察' }}</h2>
                <div class="theme-ai-updated">
                    AI 更新：{{ \Carbon\CarbonImmutable::parse($aiReport->updated_at)->timezone('Asia/Taipei')->format('m/d H:i') }}
                </div>
            </div>
            <div id="theme-ai-body" class="theme-ai-body collapsed">{{ $aiReport->summary }}</div>
            <button id="theme-ai-toggle" class="button theme-ai-toggle" type="button">點我展開</button>
        </section>
    @else
        <section class="panel">
            <h2>《股市在幹嘛》今日題材盤前觀察</h2>
            <p class="lead">今日題材 AI 盤前觀察尚未產生。系統預計每日 08:00 依據新聞、全球市場、台股夜盤、題材熱度與代表股資料產生一次。</p>
        </section>
    @endif

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
                                    $closeText = $stock['close'] === null ? '尚無收盤價' : rtrim(rtrim(number_format($stock['close'], 2), '0'), '.');
                                @endphp
                                <a class="theme-stock-row" href="/s/{{ $stock['symbol'] }}">
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
                                @php
                                    $relatedChange = $stock['change'];
                                    $relatedTone = $relatedChange === null ? 'var(--muted)' : ($relatedChange > 0 ? 'var(--red)' : ($relatedChange < 0 ? 'var(--green)' : 'var(--muted)'));
                                    $relatedArrow = $relatedChange === null ? '' : ($relatedChange > 0 ? '▲' : ($relatedChange < 0 ? '▼' : ''));
                                    $relatedChangeText = $relatedChange === null ? '待更新' : $relatedArrow.rtrim(rtrim(number_format(abs($relatedChange), 2), '0'), '.');
                                    $relatedCloseText = $stock['close'] === null ? '尚無收盤價' : rtrim(rtrim(number_format($stock['close'], 2), '0'), '.');
                                @endphp
                                <tr>
                                    <th><a href="/s/{{ $stock['symbol'] }}">{{ $stock['name'] }}</a></th>
                                    <td>
                                        {{ $relatedCloseText }}
                                        <span style="color:{{ $relatedTone }};font-weight:900">{{ $relatedChangeText }}</span>
                                    </td>
                                    <td>{{ $stock['state'] ?? '觀察中' }}</td>
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
                <h2>題材資料尚未建立</h2>
                <p class="lead">請先執行題材資料與熱度計算流程。</p>
            </div>
        @endforelse
    </section>

    @if ($aiReport)
        <script>
            (() => {
                const body = document.getElementById('theme-ai-body');
                const toggle = document.getElementById('theme-ai-toggle');

                if (!body || !toggle) {
                    return;
                }

                toggle.addEventListener('click', () => {
                    const expanded = body.classList.toggle('expanded');
                    body.classList.toggle('collapsed', !expanded);
                    toggle.textContent = expanded ? '收合內容' : '點我展開';
                });
            })();
        </script>
    @endif
@endsection
