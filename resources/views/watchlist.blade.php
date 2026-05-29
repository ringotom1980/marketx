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

        .watch-card {
            cursor: pointer;
            position: relative;
            transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
        }

        .watch-card:hover {
            border-color: #f7b5b8;
            box-shadow: 0 12px 34px rgba(15, 23, 42, .08);
            transform: translateY(-1px);
        }

        .watch-remove {
            position: absolute;
            right: 12px;
            top: 12px;
            z-index: 2;
        }

        .watch-remove button {
            align-items: center;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 999px;
            color: var(--muted);
            cursor: pointer;
            display: inline-flex;
            font-size: 18px;
            font-weight: 900;
            height: 34px;
            justify-content: center;
            line-height: 1;
            padding: 0;
            width: 34px;
        }

        .watch-remove button:hover {
            border-color: #fecaca;
            color: var(--button);
        }

        .watch-card-head {
            display: flex;
            gap: 12px;
            justify-content: space-between;
            padding-right: 42px;
        }

        .watch-card-title {
            margin: 0 0 4px;
        }

        .watch-card-symbol {
            color: var(--muted);
            font-size: 15px;
            font-weight: 900;
        }

        .watch-card-price {
            align-items: baseline;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 16px;
        }

        .watch-card-price strong {
            color: var(--ink);
            font-size: 30px;
            line-height: 1;
        }

        .watch-card-change {
            font-size: 18px;
            font-weight: 900;
            white-space: nowrap;
        }

        .watch-card-change.red {
            color: var(--red);
        }

        .watch-card-change.green {
            color: var(--green);
        }

        .watch-card-change.amber {
            color: var(--muted);
        }

        .watch-confidence {
            margin-top: 14px;
            color: var(--muted);
            font-weight: 900;
        }

        .watch-confidence strong {
            color: var(--ink);
            font-size: 22px;
        }

        .watch-card .ai-report-form {
            position: relative;
            z-index: 2;
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

            .watch-card-price strong {
                font-size: 26px;
            }

            .watch-card-change {
                font-size: 16px;
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
                @php
                    $closeText = $item['close'] === null ? '無資料' : rtrim(rtrim(number_format($item['close'], 2), '0'), '.');
                    $change = $item['change'];
                    $changePercent = $item['change_percent'];
                    $arrow = $change === null ? '' : ($change > 0 ? '▲' : ($change < 0 ? '▼' : ''));
                    $changeClass = $change === null ? 'amber' : ($change > 0 ? 'red' : ($change < 0 ? 'green' : 'amber'));
                    $changeText = $change === null
                        ? '無資料'
                        : $arrow.rtrim(rtrim(number_format(abs($change), 2), '0'), '.').'（'.rtrim(rtrim(number_format(abs($changePercent ?? 0), 2), '0'), '.').'%）';
                @endphp
                <article class="panel watch-card" data-href="/s/{{ $item['symbol'] }}">
                    <form class="watch-remove" method="post" action="/watchlist/{{ $item['symbol'] }}" aria-label="移除 {{ $item['name'] }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" title="移除">×</button>
                    </form>

                    <div class="watch-card-head">
                        <div style="min-width:0">
                            <h2 class="watch-card-title">{{ $item['name'] }}</h2>
                            <div class="watch-card-symbol">{{ $item['symbol'] }}</div>
                        </div>
                    </div>

                    <div class="watch-card-price">
                        <strong>{{ $closeText }}</strong>
                        <span class="watch-card-change {{ $changeClass }}">{{ $changeText }}</span>
                    </div>

                    <div class="watch-confidence">
                        信心指數 <strong>{{ $item['confidence'] === null ? '無' : $item['confidence'].'%' }}</strong>
                    </div>

                    @if ($isAdmin && ! $item['report_is_ai'])
                        <form class="ai-report-form" method="post" action="/watchlist/{{ $item['symbol'] }}/ai-report" style="margin-top:14px">
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

            document.querySelectorAll('.watch-card[data-href]').forEach((card) => {
                card.addEventListener('click', (event) => {
                    if (event.target.closest('a, button, form, input, select, textarea')) {
                        return;
                    }

                    window.location.href = card.dataset.href;
                });
            });

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
