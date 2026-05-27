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
            white-space: pre-line;
            line-height: 1.75;
            color: var(--ink);
            max-height: 156px;
            overflow: hidden;
            transition: max-height .2s ease;
        }
        .global-ai-report.expanded .global-ai-body {
            max-height: none;
        }
        .global-ai-report:not(.expanded)::after {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            bottom: 42px;
            height: 72px;
            pointer-events: none;
            background: linear-gradient(to bottom, rgba(255, 255, 255, 0), var(--panel));
        }
        .global-ai-toggle {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(193, 18, 31, .22);
            border-radius: 999px;
            background: #fff5f5;
            color: var(--button);
            padding: 7px 12px;
            margin-top: 10px;
            font-weight: 900;
            cursor: pointer;
            font-size: 14px;
        }
    </style>

    <section class="page-head global-head">
        <div>
            <h1>全球雷達</h1>
            @if ($radar['aiReport'])
                <div class="panel">
                    <h2>{{ $radar['aiReport']['title'] }}</h2>
                    <div class="global-ai-report" data-global-ai-report>
                        <div class="global-ai-body">{{ $radar['aiReport']['summary'] }}</div>
                        <button class="global-ai-toggle" type="button" data-global-ai-toggle>點我展開</button>
                    </div>
                    <p class="market-date" style="margin-top:10px">
                        AI 更新：{{ \Carbon\CarbonImmutable::parse($radar['aiReport']['updatedAt'])->timezone('Asia/Taipei')->format('m/d H:i') }}
                    </p>
                </div>
            @else
                <div class="panel">
                    <h2>今日全球盤前觀察</h2>
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
