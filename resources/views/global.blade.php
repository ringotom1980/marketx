@extends('welcome')

@section('content')
    <style>
        .global-head { display: grid; gap: 12px; }
        .market-section { margin-top: 16px; }
        .market-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }
        .market-section-head h2 {
            margin: 0;
            font-size: 19px;
        }
        .market-card {
            display: grid;
            gap: 10px;
        }
        .market-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }
        .market-name {
            display: grid;
            gap: 2px;
            min-width: 0;
        }
        .market-name strong {
            font-size: 17px;
            line-height: 1.25;
        }
        .market-name span,
        .market-date {
            color: var(--muted);
            font-size: 12px;
        }
        .market-value {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            border-top: 1px solid #edf0f3;
            padding-top: 10px;
        }
        .market-value strong {
            font-size: 22px;
            line-height: 1;
        }
        .market-change {
            font-weight: 900;
            white-space: nowrap;
        }
        .market-change.red { color: var(--red); }
        .market-change.green { color: var(--green); }
        .market-change.amber { color: var(--amber); }
        .global-ai-report {
            position: relative;
        }
        .global-ai-body {
            color: var(--text);
            font-size: 16px;
            line-height: 1.9;
            max-height: 156px;
            overflow: hidden;
            position: relative;
            white-space: pre-line;
        }
        .global-ai-report.expanded .global-ai-body {
            max-height: none;
        }
        .global-ai-report:not(.expanded)::after {
            background: linear-gradient(to bottom, rgba(255, 255, 255, 0), var(--panel));
            bottom: 42px;
            content: "";
            height: 58px;
            left: 0;
            pointer-events: none;
            position: absolute;
            right: 0;
        }
        .global-ai-toggle {
            margin-top: 14px;
        }
        .global-ai-title-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }
        .global-ai-title-row h2 {
            margin: 0;
        }
        .global-ai-updated {
            color: var(--muted);
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
            padding-top: 4px;
        }
        @media (max-width: 640px) {
            .global-ai-title-row {
                display: block;
            }
            .global-ai-updated {
                margin-top: 4px;
                white-space: normal;
            }
            .global-ai-body {
                font-size: 15px;
                line-height: 1.85;
                max-height: 148px;
            }
        }
    </style>

    <section class="page-head global-head">
        <div>
            <h1>全球雷達</h1>
            @if ($radar['aiReport'])
                <div class="panel">
                    <div class="global-ai-title-row">
                        <h2>《股市在幹嘛》今日全球盤前觀察</h2>
                        <span class="global-ai-updated">
                            AI 更新：{{ \Carbon\CarbonImmutable::parse($radar['aiReport']['updatedAt'])->timezone('Asia/Taipei')->format('m/d H:i') }}
                        </span>
                    </div>
                    <div class="global-ai-report" data-global-ai-report>
                        <div class="global-ai-body">{{ $radar['aiReport']['summary'] }}</div>
                        <button class="button global-ai-toggle" type="button" data-global-ai-toggle>點我展開</button>
                    </div>
                </div>
            @else
                <div class="panel">
                    <h2>《股市在幹嘛》今日全球盤前觀察</h2>
                    <p class="lead">今日 Gemini 全球盤前觀察產生中。</p>
                </div>
            @endif
            <p class="market-date">資料更新：{{ $radar['asOf'] ? \Carbon\CarbonImmutable::parse($radar['asOf'])->timezone('Asia/Taipei')->format('m/d H:i') : '待更新' }}</p>
        </div>
    </section>

    @foreach ($radar['groups'] as $group)
        <section class="market-section">
            <div class="market-section-head">
                <h2>{{ $group['title'] }}</h2>
            </div>

            <div class="grid three">
                @forelse ($group['cards'] as $card)
                    <article class="panel market-card">
                        <div class="market-card-top">
                            <div class="market-name">
                                <strong>{{ $card['name'] }}</strong>
                                <span>{{ $card['region'] }}</span>
                            </div>
                            <span class="badge {{ $card['tone'] }}">{{ $card['state'] }}</span>
                        </div>

                        <div class="market-value">
                            <strong>{{ $card['value'] }}</strong>
                            <span class="market-change {{ $card['tone'] }}">{{ $card['changePct'] }}</span>
                        </div>

                        <div class="market-date">交易日：{{ $card['tradeDate'] }}</div>
                    </article>
                @empty
                    <div class="panel">
                        <p class="lead">目前尚未匯入此區市場資料。</p>
                    </div>
                @endforelse
            </div>
        </section>
    @endforeach

    <script>
        document.querySelectorAll('[data-global-ai-report]').forEach((report) => {
            const button = report.querySelector('[data-global-ai-toggle]');
            const body = report.querySelector('.global-ai-body');

            if (!button || !body) return;

            if (body.scrollHeight <= body.clientHeight + 4) {
                button.hidden = true;
                report.classList.add('expanded');
                return;
            }

            button.addEventListener('click', () => {
                const expanded = report.classList.toggle('expanded');
                button.textContent = expanded ? '收合內容' : '點我展開';
            });
        });
    </script>
@endsection
