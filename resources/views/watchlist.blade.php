@extends('welcome')

@php
    $decisionTone = function (string $decision): string {
        if (str_contains($decision, '買')) {
            return 'red';
        }

        if (str_contains($decision, '賣') || str_contains($decision, '減')) {
            return 'green';
        }

        return 'amber';
    };

    $changeTone = function ($change): string {
        if ($change === null) {
            return 'amber';
        }

        return (float) $change >= 0 ? 'red' : 'green';
    };
@endphp

@section('content')
    <style>
        .ai-loading-overlay,
        .ai-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 100;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(22, 32, 42, .46);
            backdrop-filter: blur(4px);
        }

        .ai-loading-overlay.active,
        .ai-modal-overlay.active {
            display: flex;
        }

        .ai-loading-box,
        .ai-modal {
            width: min(92vw, 360px);
            border-radius: 8px;
            border: 1px solid var(--line);
            background: #fff;
            padding: 22px;
            text-align: center;
            box-shadow: 0 18px 50px rgba(22, 32, 42, .18);
        }

        .ai-spinner {
            width: 52px;
            height: 52px;
            border: 5px solid #fee4e2;
            border-top-color: var(--button);
            border-radius: 999px;
            margin: 0 auto 14px;
            animation: ai-spin .85s linear infinite;
        }

        .ai-modal h2 {
            margin: 0 0 12px;
            font-size: 22px;
        }

        .ai-modal p {
            margin: 0;
            white-space: pre-line;
            color: var(--muted);
            line-height: 1.7;
        }

        .ai-modal .button {
            width: 100%;
            margin-top: 18px;
        }

        @keyframes ai-spin {
            to { transform: rotate(360deg); }
        }
    </style>

    <div class="ai-loading-overlay" id="ai-loading-overlay" aria-live="polite" aria-busy="true">
        <div class="ai-loading-box">
            <div class="ai-spinner"></div>
            <p class="lead">AI 報告產生中，請稍候...</p>
        </div>
    </div>

    @if (session('aiModal'))
        <div class="ai-modal-overlay active" id="ai-result-modal">
            <div class="ai-modal" role="dialog" aria-modal="true" aria-labelledby="ai-result-title">
                <h2 id="ai-result-title">{{ session('aiModal.title') }}</h2>
                <p>{{ session('aiModal.body') }}</p>
                <button class="button" type="button" data-close-ai-modal>知道了</button>
            </div>
        </div>
    @endif

    <section class="page-head">
        <div>
            <h1>追蹤清單</h1>
            <p class="lead">
                自選股集中看決策、信心指數、收盤價與資料完整度。
                @if ($isAdmin)
                    今日個股 AI 報告已用 {{ $aiUsage['used'] }} / {{ $aiUsage['limit'] }}，剩餘 {{ $aiUsage['remaining'] }} 檔。
                @else
                    已產生的 AI 報告可查看，產生新報告僅限管理者使用。
                @endif
            </p>
        </div>
        <form class="search" action="/watchlist" method="post">
            @csrf
            <input name="symbol" value="{{ old('symbol') }}" placeholder="輸入股票代號，例如 2330">
            <button type="submit">加入</button>
        </form>
    </section>

    @if (session('status') || session('error'))
        <section class="panel" style="margin-bottom:16px;border-color:#fecaca;background:#fff7f7">
            <p class="lead" style="white-space:pre-line;color:{{ session('error') ? 'var(--amber)' : 'var(--red)' }}">{{ session('status') ?? session('error') }}</p>
        </section>
    @endif

    @if ($errors->any())
        <section class="panel" style="margin-bottom:16px;border-color:#fcd34d;background:#fffbeb">
            <p class="lead" style="color:var(--amber)">{{ $errors->first() }}</p>
        </section>
    @endif

    @if ($items->isEmpty())
        <section class="panel">
            <h2>尚未加入股票</h2>
            <p class="lead">先輸入股票代號加入追蹤。建議從你每天會看的股票開始，例如台積電、鴻海、廣達這類核心觀察標的。</p>
        </section>
    @else
        <section class="grid two">
            @foreach ($items as $item)
                <article class="panel">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
                        <div style="min-width:0">
                            <h2 style="margin-bottom:4px">
                                <a href="/s/{{ $item['symbol'] }}">{{ $item['name'] }} {{ $item['symbol'] }}</a>
                            </h2>
                            <p class="lead">{{ $item['market'] }}｜{{ $item['industry'] }}</p>
                        </div>
                        <span class="badge {{ $decisionTone($item['decision']) }}">{{ $item['decision'] }}</span>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:14px 0">
                        <div>
                            <p class="lead" style="font-size:12px">信心指數</p>
                            <strong style="font-size:24px">{{ $item['confidence'] === null ? '無' : $item['confidence'].'%' }}</strong>
                        </div>
                        <div>
                            <p class="lead" style="font-size:12px">收盤</p>
                            <strong style="font-size:24px">{{ $item['close'] ?? '無' }}</strong>
                        </div>
                    </div>

                    <div class="chain">
                        <div>
                            <span class="badge {{ $changeTone($item['change']) }}">
                                漲跌 {{ $item['change'] === null ? '無資料' : $item['change'] }}
                            </span>
                            <span class="badge amber">模組 {{ $item['complete_modules'] }} / 6</span>
                        </div>
                        @if (! empty($item['weak_modules']))
                            <div>
                                @foreach ($item['weak_modules'] as $weakModule)
                                    <span class="badge amber">{{ $weakModule }}</span>
                                @endforeach
                            </div>
                        @endif
                        <p class="lead">資料日期：{{ $item['trade_date'] ?? '無資料' }}</p>
                    </div>

                    @if (! empty($item['report_summary']))
                        <div class="signal-item" style="margin-top:12px">
                            <span class="badge {{ $item['report_is_ai'] ? 'red' : 'amber' }}">
                                {{ $item['report_is_ai'] ? 'AI 報告' : '規則式評價' }} {{ $item['report_date'] }}
                            </span>
                            <p>{!! nl2br(e(\Illuminate\Support\Str::limit($item['report_summary'], 180))) !!}</p>
                        </div>
                    @else
                        <div class="signal-item" style="margin-top:12px">
                            <span class="badge amber">尚未產生 AI 報告</span>
                            <p>之後執行追蹤清單 AI 任務後，這裡會顯示 Gemini 四段式研究摘要。</p>
                        </div>
                    @endif

                    <div style="display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:14px">
                        <a class="button" href="/s/{{ $item['symbol'] }}" style="text-align:center">查看</a>
                        <form method="post" action="/watchlist/{{ $item['symbol'] }}">
                            @csrf
                            @method('DELETE')
                            <button class="button" type="submit">移除</button>
                        </form>
                    </div>
                    @if ($isAdmin && ! $item['report_is_ai'])
                        <form class="ai-report-form" method="post" action="/watchlist/{{ $item['symbol'] }}/ai-report" style="margin-top:8px">
                            @csrf
                            <button class="button" type="submit" style="width:100%" {{ $aiUsage['remaining'] <= 0 ? 'disabled' : '' }}>
                                產生AI報告
                            </button>
                        </form>
                    @endif
                </article>
            @endforeach
        </section>
    @endif

    <script>
        (() => {
            const loadingOverlay = document.getElementById('ai-loading-overlay');

            document.querySelectorAll('.ai-report-form').forEach((form) => {
                form.addEventListener('submit', () => {
                    loadingOverlay?.classList.add('active');
                    form.querySelector('button[type="submit"]')?.setAttribute('disabled', 'disabled');
                });
            });

            document.querySelectorAll('[data-close-ai-modal]').forEach((button) => {
                button.addEventListener('click', () => {
                    document.getElementById('ai-result-modal')?.classList.remove('active');
                });
            });
        })();
    </script>
@endsection
