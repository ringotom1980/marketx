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

        .watchlist-add-search {
            min-width: 0;
            position: relative;
        }

        .watchlist-add-search .search {
            width: 100%;
        }

        .watchlist-add-results {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, .12);
            display: none;
            left: 0;
            max-height: 320px;
            overflow-y: auto;
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            z-index: 35;
        }

        .watchlist-add-results.open {
            display: block;
        }

        .watchlist-add-item {
            background: #fff;
            border: 0;
            border-bottom: 1px solid #edf0f3;
            color: var(--ink);
            cursor: pointer;
            display: grid;
            gap: 4px 8px;
            grid-template-columns: auto 1fr;
            padding: 10px 12px;
            text-align: left;
            width: 100%;
        }

        .watchlist-add-item:last-child {
            border-bottom: 0;
        }

        .watchlist-add-item:hover,
        .watchlist-add-item.active {
            background: #fff7f7;
        }

        .watchlist-add-symbol {
            color: var(--button);
            font-weight: 900;
        }

        .watchlist-add-name {
            font-weight: 800;
        }

        .watchlist-add-meta {
            color: var(--muted);
            font-size: 12px;
            grid-column: 2;
        }

        .watchlist-add-empty {
            color: var(--muted);
            font-size: 14px;
            padding: 12px;
        }

        @keyframes ai-spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 640px) {
            .watchlist-add-search {
                width: 100%;
            }

            .watchlist-add-search .search {
                grid-template-columns: minmax(0, 1fr) auto;
            }
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
        </div>
        <div class="watchlist-add-search" data-watchlist-add-search>
            <form class="search" action="/watchlist" method="post" autocomplete="off">
                @csrf
                <input name="symbol" value="{{ old('symbol') }}" placeholder="輸入代號或名稱，例如 2330、台積電" data-watchlist-add-input aria-label="加入追蹤股票">
                <button type="submit">加入</button>
            </form>
            <div class="watchlist-add-results" data-watchlist-add-results></div>
        </div>
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
            const addSearch = document.querySelector('[data-watchlist-add-search]');

            if (addSearch) {
                const input = addSearch.querySelector('[data-watchlist-add-input]');
                const form = addSearch.querySelector('form');
                const results = addSearch.querySelector('[data-watchlist-add-results]');
                let timer = null;
                let items = [];
                let activeIndex = -1;

                const closeResults = () => {
                    results.classList.remove('open');
                    results.innerHTML = '';
                    items = [];
                    activeIndex = -1;
                };

                const escapeHtml = (value) => String(value ?? '')
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');

                const renderResults = (stocks, query) => {
                    items = stocks;
                    activeIndex = -1;

                    if (query.trim() === '') {
                        closeResults();
                        return;
                    }

                    if (stocks.length === 0) {
                        results.innerHTML = '<div class="watchlist-add-empty">找不到符合的股票</div>';
                        results.classList.add('open');
                        return;
                    }

                    results.innerHTML = stocks.map((stock, index) => `
                        <button class="watchlist-add-item" type="button" data-index="${index}">
                            <span class="watchlist-add-symbol">${escapeHtml(stock.symbol)}</span>
                            <span class="watchlist-add-name">${escapeHtml(stock.name)}</span>
                            <span class="watchlist-add-meta">${escapeHtml(stock.market || '')}${stock.industry ? '｜' + escapeHtml(stock.industry) : ''}</span>
                        </button>
                    `).join('');
                    results.classList.add('open');
                };

                const searchStocks = () => {
                    const query = input.value.trim();
                    if (query.length < 1) {
                        closeResults();
                        return;
                    }

                    fetch(`/api/stocks/search?q=${encodeURIComponent(query)}`, {
                        headers: { 'Accept': 'application/json' },
                    })
                        .then((response) => response.ok ? response.json() : [])
                        .then((stocks) => renderResults(Array.isArray(stocks) ? stocks : [], query))
                        .catch(closeResults);
                };

                const chooseStock = (stock) => {
                    if (!stock) {
                        return;
                    }

                    input.value = stock.symbol;
                    closeResults();
                    form.requestSubmit();
                };

                input.addEventListener('input', () => {
                    window.clearTimeout(timer);
                    timer = window.setTimeout(searchStocks, 120);
                });

                input.addEventListener('focus', () => {
                    if (input.value.trim() !== '') {
                        searchStocks();
                    }
                });

                input.addEventListener('keydown', (event) => {
                    if (!results.classList.contains('open') || items.length === 0) {
                        return;
                    }

                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        activeIndex = event.key === 'ArrowDown'
                            ? Math.min(activeIndex + 1, items.length - 1)
                            : Math.max(activeIndex - 1, 0);

                        results.querySelectorAll('.watchlist-add-item').forEach((item, index) => {
                            item.classList.toggle('active', index === activeIndex);
                        });
                    }

                    if (event.key === 'Enter' && activeIndex >= 0 && items[activeIndex]) {
                        event.preventDefault();
                        chooseStock(items[activeIndex]);
                    }

                    if (event.key === 'Escape') {
                        closeResults();
                    }
                });

                results.addEventListener('click', (event) => {
                    const button = event.target.closest('[data-index]');
                    if (!button) {
                        return;
                    }

                    chooseStock(items[Number(button.dataset.index)]);
                });

                form.addEventListener('submit', (event) => {
                    if (items.length === 1 && input.value.trim() !== items[0].symbol) {
                        event.preventDefault();
                        chooseStock(items[0]);
                    }
                });

                document.addEventListener('click', (event) => {
                    if (!addSearch.contains(event.target)) {
                        closeResults();
                    }
                });
            }

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
