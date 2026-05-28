@props([
    'placeholder' => '搜尋股票代號、名稱、產業',
    'value' => '',
])

@once
    <style>
        .stock-live-search {
            position: relative;
            min-width: 0;
        }

        .stock-live-search .search {
            width: 100%;
        }

        .stock-live-search-results {
            position: absolute;
            z-index: 30;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            display: none;
            max-height: 320px;
            overflow-y: auto;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 18px 40px rgba(15, 23, 42, .12);
        }

        .stock-live-search-results.open {
            display: block;
        }

        .stock-live-search-item {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 8px;
            width: 100%;
            border: 0;
            border-bottom: 1px solid #edf0f3;
            background: #fff;
            color: var(--ink);
            padding: 10px 12px;
            text-align: left;
            cursor: pointer;
        }

        .stock-live-search-item:last-child {
            border-bottom: 0;
        }

        .stock-live-search-item:hover,
        .stock-live-search-item.active {
            background: #fff7f7;
        }

        .stock-live-symbol {
            font-weight: 900;
            color: var(--button);
        }

        .stock-live-name {
            font-weight: 800;
        }

        .stock-live-meta {
            grid-column: 2;
            color: var(--muted);
            font-size: 12px;
        }

        .stock-live-empty {
            padding: 12px;
            color: var(--muted);
            font-size: 14px;
        }

        @media (max-width: 640px) {
            .stock-live-search {
                width: 100%;
            }

            .stock-live-search .search {
                grid-template-columns: minmax(0, 1fr) auto;
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-stock-live-search]').forEach((root) => {
                const input = root.querySelector('[data-stock-search-input]');
                const results = root.querySelector('[data-stock-search-results]');
                const form = root.querySelector('form');
                let timer = null;
                let items = [];
                let activeIndex = -1;

                const close = () => {
                    results.classList.remove('open');
                    results.innerHTML = '';
                    items = [];
                    activeIndex = -1;
                };

                const render = (stocks, query) => {
                    items = stocks;
                    activeIndex = -1;

                    if (query.trim().length === 0) {
                        close();
                        return;
                    }

                    if (stocks.length === 0) {
                        results.innerHTML = '<div class="stock-live-empty">找不到符合的股票</div>';
                        results.classList.add('open');
                        return;
                    }

                    results.innerHTML = stocks.map((stock, index) => `
                        <button class="stock-live-search-item" type="button" data-index="${index}">
                            <span class="stock-live-symbol">${stock.symbol}</span>
                            <span class="stock-live-name">${stock.name}</span>
                            <span class="stock-live-meta">${stock.market || ''}${stock.industry ? '｜' + stock.industry : ''}</span>
                        </button>
                    `).join('');
                    results.classList.add('open');
                };

                const search = () => {
                    const query = input.value.trim();
                    if (query.length < 1) {
                        close();
                        return;
                    }

                    fetch(`/api/stocks/search?q=${encodeURIComponent(query)}`, {
                        headers: { 'Accept': 'application/json' },
                    })
                        .then((response) => response.ok ? response.json() : [])
                        .then((stocks) => render(Array.isArray(stocks) ? stocks : [], query))
                        .catch(close);
                };

                input.addEventListener('input', () => {
                    window.clearTimeout(timer);
                    timer = window.setTimeout(search, 120);
                });

                input.addEventListener('focus', () => {
                    if (input.value.trim().length > 0) {
                        search();
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

                        results.querySelectorAll('.stock-live-search-item').forEach((item, index) => {
                            item.classList.toggle('active', index === activeIndex);
                        });
                    }

                    if (event.key === 'Enter' && activeIndex >= 0 && items[activeIndex]) {
                        event.preventDefault();
                        window.location.href = `/s/${items[activeIndex].symbol}`;
                    }

                    if (event.key === 'Escape') {
                        close();
                    }
                });

                results.addEventListener('click', (event) => {
                    const button = event.target.closest('[data-index]');
                    if (!button) {
                        return;
                    }

                    const stock = items[Number(button.dataset.index)];
                    if (stock) {
                        window.location.href = `/s/${stock.symbol}`;
                    }
                });

                form.addEventListener('submit', (event) => {
                    if (items.length === 1) {
                        event.preventDefault();
                        window.location.href = `/s/${items[0].symbol}`;
                    }
                });

                document.addEventListener('click', (event) => {
                    if (!root.contains(event.target)) {
                        close();
                    }
                });
            });
        });
    </script>
@endonce

<div class="stock-live-search" data-stock-live-search>
    <form class="search" action="/search" method="get" autocomplete="off">
        <input
            name="q"
            value="{{ $value }}"
            placeholder="{{ $placeholder }}"
            data-stock-search-input
            aria-label="搜尋股票"
        >
        <button type="submit">搜尋</button>
    </form>
    <div class="stock-live-search-results" data-stock-search-results></div>
</div>
