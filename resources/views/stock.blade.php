@extends('welcome')

@php
    $badgeTone = function (?string $tone): string {
        return match ($tone) {
            'green' => 'red',
            'red' => 'green',
            'amber' => 'amber',
            default => '',
        };
    };

    $decisionTone = $stock['decisionTone'] ?? (
        str_contains($stock['decision'], '買') ? 'red'
        : (str_contains($stock['decision'], '賣') || str_contains($stock['decision'], '減') ? 'green' : 'amber')
    );
@endphp

@section('content')
    <style>
        .stock-head {
            grid-template-columns: 1fr !important;
        }

        .stock-title-row {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .stock-header-row {
            align-items: flex-start;
            display: flex;
            justify-content: space-between;
            gap: 16px;
        }

        .stock-header-main {
            min-width: 0;
        }

        .stock-title-row h1 {
            margin: 0;
        }

        .stock-watch-form {
            margin: 0;
        }

        .stock-watch-button {
            padding: 9px 12px;
        }

        .stock-quote-line {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 10px;
        }

        .stock-price-change {
            font-weight: 900;
        }

        .stock-price-change.red {
            color: var(--red);
        }

        .stock-price-change.green {
            color: var(--green);
        }

        .chart-tabs {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 12px;
        }

        .chart-tab {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            color: var(--muted);
            padding: 10px 8px;
            font-weight: 800;
            cursor: pointer;
        }

        .chart-tab.active {
            border-color: var(--button);
            background: var(--button);
            color: #fff;
        }

        .k-chart-wrap {
            position: relative;
            width: 100%;
            height: 340px;
            touch-action: none;
            user-select: none;
            -webkit-user-select: none;
            -webkit-touch-callout: none;
        }

        .k-chart-wrap #stock-k-chart {
            width: 100%;
            height: 100%;
            display: block;
            user-select: none;
            -webkit-user-select: none;
            -webkit-touch-callout: none;
        }

        .chart-empty {
            display: none;
            align-items: center;
            min-height: 160px;
            color: var(--muted);
            line-height: 1.6;
        }

        .chart-tip {
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .stock-quote-meta {
            color: var(--muted);
            font-size: clamp(13px, 3.2vw, 15px);
            font-weight: 700;
        }

        .stock-info-tabs {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 14px;
        }

        .stock-info-tab {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            color: var(--muted);
            padding: 10px 8px;
            font-weight: 900;
            cursor: pointer;
            white-space: nowrap;
        }

        .stock-info-tab.active {
            border-color: var(--button);
            background: var(--button);
            color: #fff;
        }

        .stock-info-panel {
            display: none;
        }

        .stock-info-panel.active {
            display: block;
        }

        .evaluation-quick {
            display: grid;
            gap: 14px;
        }

        .evaluation-hero {
            align-items: center;
            display: flex;
            justify-content: space-between;
            gap: 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--line);
        }

        .evaluation-state {
            display: grid;
            gap: 6px;
        }

        .evaluation-state strong {
            font-size: 24px;
            line-height: 1.2;
        }

        .evaluation-confidence {
            color: var(--ink);
            font-size: 22px;
            font-weight: 900;
            white-space: nowrap;
        }

        .evaluation-row {
            display: grid;
            gap: 8px;
        }

        .evaluation-row-title {
            color: var(--muted);
            font-size: 13px;
            font-weight: 900;
        }

        .quick-pill-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .quick-pill {
            align-items: center;
            border-radius: 999px;
            display: inline-flex;
            min-height: 28px;
            padding: 4px 10px;
            background: #fff1f1;
            border: 1px solid #fecaca;
            color: var(--button);
            font-size: 13px;
            font-weight: 900;
            line-height: 1.25;
        }

        .quick-pill.warning {
            background: #fffbeb;
            border-color: #fde68a;
            color: #a16207;
        }

        .quick-pill.down,
        .quick-pill.green {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #047857;
        }

        .quick-pill.red {
            background: #fff1f1;
            border-color: #fecaca;
            color: var(--button);
        }

        .evaluation-note {
            margin: 0;
            color: var(--muted);
            line-height: 1.7;
        }

        .stock-chart-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .mini-chart-card {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 12px;
            background: #fff;
            min-width: 0;
        }

        .mini-chart-head {
            align-items: center;
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }

        .mini-chart-head h3 {
            font-size: 16px;
            margin: 0;
        }

        .mini-chart-note {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .mini-period-tabs {
            display: inline-flex;
            gap: 4px;
        }

        .mini-period-tab {
            background: #f8fafc;
            border: 1px solid #d8e1ec;
            border-radius: 999px;
            color: var(--muted);
            cursor: pointer;
            font-size: 12px;
            font-weight: 900;
            line-height: 1;
            padding: 6px 9px;
        }

        .mini-period-tab.active {
            background: var(--button);
            border-color: var(--button);
            color: #fff;
        }

        .mini-chart-wrap {
            height: 220px;
            width: 100%;
        }

        .mini-chart-wrap.tall {
            height: 280px;
        }

        .mini-chart-wrap > div {
            display: block;
            height: 100%;
            width: 100%;
        }

        .chart-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
        }

        .legend-dot {
            border-radius: 999px;
            display: inline-block;
            height: 8px;
            margin-right: 4px;
            width: 8px;
        }

        @media (max-width: 520px) {
            .k-chart-wrap { height: 300px; }
            .chart-tab { padding: 9px 4px; font-size: 13px; }
            .stock-watch-button { padding: 8px 10px; }
            .stock-info-tabs {
                grid-template-columns: repeat(4, max-content);
                overflow-x: auto;
                padding-bottom: 2px;
            }
            .stock-info-tab {
                padding: 9px 12px;
                font-size: 13px;
            }
            .stock-header-row {
                align-items: flex-start;
            }
            .evaluation-hero {
                align-items: flex-start;
                display: grid;
            }
            .evaluation-confidence {
                font-size: 20px;
            }
            .stock-chart-grid {
                grid-template-columns: 1fr;
            }
            .mini-chart-wrap {
                height: 210px;
            }
            .mini-chart-wrap.tall {
                height: 260px;
            }
        }
    </style>

    @php
        $closeValue = is_numeric($stock['close']) ? (float) $stock['close'] : null;
        $changeValue = is_numeric($stock['change']) ? (float) $stock['change'] : null;
        $changePctValue = is_numeric($stock['changePct'] ?? null) ? (float) $stock['changePct'] : null;
        $closeText = $closeValue === null ? '無資料' : rtrim(rtrim(number_format($closeValue, 2), '0'), '.');
        $changeArrow = $changeValue === null ? '' : ($changeValue > 0 ? '▲' : ($changeValue < 0 ? '▼' : ''));
        $changeText = $changeValue === null ? '' : $changeArrow.rtrim(rtrim(number_format(abs($changeValue), 2), '0'), '.');
        $changePctText = $changePctValue === null ? '' : '（'.($changePctValue > 0 ? '+' : '').rtrim(rtrim(number_format($changePctValue, 2), '0'), '.').'%）';
        $changeTone = $changeValue === null ? '' : ($changeValue > 0 ? 'red' : ($changeValue < 0 ? 'green' : ''));
        $quoteSource = $stock['quoteSource'] ?? '收盤';
        $quoteTime = $stock['quoteTime'] ?? null;
    @endphp

    <section class="page-head stock-head">
        <div class="stock-header-row">
            <div class="stock-header-main">
                <div class="stock-title-row">
                    <h1>{{ $stock['name'] }} {{ $stock['symbol'] }}</h1>
                </div>
                <p class="lead stock-quote-line">
                    <span class="stock-price-change {{ $changeTone }}">{{ $closeText }}{{ $changeText }}</span>
                    @if ($changePctText !== '')
                        <span class="stock-price-change {{ $changeTone }}">{{ $changePctText }}</span>
                    @endif
                    <span>成交量{{ $stock['volume'] }}</span>
                    <span class="stock-quote-meta">{{ $quoteSource }}{{ $quoteTime ? ' '.$quoteTime : '' }}</span>
                </p>
            </div>
            <div>
                @if ($stock['isWatched'])
                <form class="stock-watch-form" method="post" action="/watchlist/{{ $stock['symbol'] }}">
                    @csrf
                    @method('DELETE')
                    <button class="button stock-watch-button" type="submit">取消追蹤</button>
                </form>
                @else
                <form class="stock-watch-form" method="post" action="/watchlist">
                    @csrf
                    <input type="hidden" name="symbol" value="{{ $stock['symbol'] }}">
                    <button class="button stock-watch-button" type="submit">加入追蹤</button>
                </form>
                @endif
            </div>
        </div>
    </section>

    <section class="panel" style="margin-top:16px">
        <h2>K 線圖</h2>
        <div class="chart-tabs" data-chart-tabs>
            <button class="chart-tab" type="button" data-range="intraday">當日</button>
            <button class="chart-tab active" type="button" data-range="daily">日K</button>
            <button class="chart-tab" type="button" data-range="weekly">周K</button>
            <button class="chart-tab" type="button" data-range="yearly">年K</button>
        </div>
        <div class="k-chart-wrap">
            <div id="stock-k-chart"></div>
        </div>
        <p class="chart-tip">拖曳可平移、滑鼠滾輪或雙指可縮放；點擊或滑動圖表可查看十字線與 OHLC。</p>
        <p class="chart-empty" id="stock-chart-empty">目前這個週期還沒有足夠 K 線資料。</p>
    </section>

    <section class="panel" style="margin-top:16px">
        <div class="stock-info-tabs" data-stock-info-tabs>
            <button class="stock-info-tab active" type="button" data-stock-tab="technical">技術圖表</button>
            <button class="stock-info-tab" type="button" data-stock-tab="chip">籌碼</button>
            <button class="stock-info-tab" type="button" data-stock-tab="fundamental">財務</button>
            <button class="stock-info-tab" type="button" data-stock-tab="ai">AI 報告</button>
        </div>

        <div class="stock-info-panel active" data-stock-panel="technical">
            <h2>技術圖表</h2>
            <div class="stock-chart-grid">
                <div class="mini-chart-card">
                    <div class="mini-chart-head">
                        <h3>壓力支撐</h3>
                        <div class="mini-period-tabs" data-support-tabs>
                            <button class="mini-period-tab" type="button" data-support-period="week">周</button>
                            <button class="mini-period-tab active" type="button" data-support-period="month">月</button>
                            <button class="mini-period-tab" type="button" data-support-period="quarter">季</button>
                        </div>
                    </div>
                    <div class="mini-chart-wrap tall">
                        <div data-stock-echart="support"></div>
                    </div>
                    <div class="chart-legend">
                        <span><i class="legend-dot" style="background:#ef4444"></i>壓力</span>
                        <span><i class="legend-dot" style="background:#f6c766"></i>目前價</span>
                        <span><i class="legend-dot" style="background:#8b5cf6"></i>支撐</span>
                    </div>
                </div>
                <div class="mini-chart-card">
                    <div class="mini-chart-head">
                        <h3>價量走勢</h3>
                        <span class="mini-chart-note">近兩年</span>
                    </div>
                    <div class="mini-chart-wrap tall">
                        <div data-stock-echart="priceVolume"></div>
                    </div>
                    <div class="chart-legend">
                        <span><i class="legend-dot" style="background:#ef4444"></i>收盤價</span>
                        <span><i class="legend-dot" style="background:#93c5fd"></i>成交量</span>
                    </div>
                </div>
            </div>
            @if ($technical && ! empty($technical['signals']))
                <div class="signal-list">
                    @foreach ($technical['signals'] as $signal)
                        <div class="signal-item">
                            <span class="badge {{ $badgeTone($signal['tone'] ?? '') }}">{{ $signal['title'] ?? '技術訊號' }}</span>
                            <p>{{ $signal['body'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="lead">目前技術資料不足，等待更多日 K 資料後會產生均線、KD、MACD、RSI、布林通道與量價訊號。</p>
            @endif
        </div>

        <div class="stock-info-panel" data-stock-panel="chip">
            <h2>籌碼圖表</h2>
            <div class="stock-chart-grid">
                <div class="mini-chart-card">
                    <div class="mini-chart-head">
                        <h3>三大法人買賣超</h3>
                        <div class="mini-period-tabs" data-institutional-tabs>
                            <button class="mini-period-tab active" type="button" data-institutional-period="day">日</button>
                            <button class="mini-period-tab" type="button" data-institutional-period="week">周</button>
                            <button class="mini-period-tab" type="button" data-institutional-period="month">月</button>
                            <button class="mini-period-tab" type="button" data-institutional-period="quarter">季</button>
                        </div>
                    </div>
                    <div class="mini-chart-wrap">
                        <div data-stock-echart="institutional"></div>
                    </div>
                    <div class="chart-legend">
                        <span><i class="legend-dot" style="background:#14b8a6"></i>外資</span>
                        <span><i class="legend-dot" style="background:#f59e0b"></i>投信</span>
                        <span><i class="legend-dot" style="background:#ec4899"></i>自營商</span>
                    </div>
                </div>
                <div class="mini-chart-card">
                    <div class="mini-chart-head">
                        <h3>融資融券變化</h3>
                        <div class="mini-period-tabs" data-margin-tabs>
                            <button class="mini-period-tab active" type="button" data-margin-period="week">周</button>
                            <button class="mini-period-tab" type="button" data-margin-period="month">月</button>
                            <button class="mini-period-tab" type="button" data-margin-period="quarter">季</button>
                        </div>
                    </div>
                    <div class="mini-chart-wrap">
                        <div data-stock-echart="margin"></div>
                    </div>
                    <div class="chart-legend">
                        <span><i class="legend-dot" style="background:#38bdf8"></i>融資</span>
                        <span><i class="legend-dot" style="background:#f43f5e"></i>融券</span>
                        <span><i class="legend-dot" style="background:#8b5cf6"></i>借券可用額度</span>
                    </div>
                </div>
            </div>
            @if (! empty($chipSignals))
                <div class="signal-list">
                    @foreach ($chipSignals as $signal)
                        <div class="signal-item">
                            <span class="badge {{ $badgeTone($signal['tone'] ?? '') }}">{{ $signal['title'] ?? '籌碼訊號' }}</span>
                            <p>{{ $signal['body'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="lead">目前籌碼資料不足，等待三大法人、融資融券、借券與外資持股資料後會產生分析。</p>
            @endif
        </div>

        <div class="stock-info-panel" data-stock-panel="fundamental">
            <h2>財務圖表</h2>
            <div class="stock-chart-grid">
                <div class="mini-chart-card">
                    <div class="mini-chart-head">
                        <h3>每月營收</h3>
                        <span class="mini-chart-note">近十年</span>
                    </div>
                    <div class="mini-chart-wrap">
                        <div data-stock-echart="revenue"></div>
                    </div>
                    <div class="chart-legend">
                        <span><i class="legend-dot" style="background:#38bdf8"></i>月營收</span>
                        <span><i class="legend-dot" style="background:#f97316"></i>年增率</span>
                    </div>
                </div>
                <div class="mini-chart-card">
                    <div class="mini-chart-head">
                        <h3>財報三率與 EPS</h3>
                        <span class="mini-chart-note">長期趨勢</span>
                    </div>
                    <div class="mini-chart-wrap">
                        <div data-stock-echart="financial"></div>
                    </div>
                    <div class="chart-legend">
                        <span><i class="legend-dot" style="background:#2563eb"></i>毛利率</span>
                        <span><i class="legend-dot" style="background:#ef4444"></i>營益率</span>
                        <span><i class="legend-dot" style="background:#f59e0b"></i>ROE</span>
                        <span><i class="legend-dot" style="background:#14b8a6"></i>EPS</span>
                    </div>
                </div>
            </div>
            @if (! empty($fundamentalSignals))
                <div class="signal-list">
                    @foreach ($fundamentalSignals as $signal)
                        <div class="signal-item">
                            <span class="badge {{ $badgeTone($signal['tone'] ?? '') }}">{{ $signal['title'] ?? '財務訊號' }}</span>
                            <p>{{ $signal['body'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="lead">目前財務資料不足，等待月營收、EPS、ROE、毛利率與本益比資料後會產生分析。</p>
            @endif
        </div>

        <div class="stock-info-panel" data-stock-panel="ai">
            <h2>AI 報告</h2>
            @if ($latestReport)
                <p class="lead" style="white-space:pre-line">{{ $latestReport->summary }}</p>
            @else
                <p class="lead">目前尚未產生此股票的 AI 報告。若已加入追蹤清單，管理者可在追蹤清單產生報告。</p>
            @endif
        </div>
    </section>

    <script defer src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/lightweight-charts@4.2.3/dist/lightweight-charts.standalone.production.js"></script>
    <script>
        (() => {
            const tabs = Array.from(document.querySelectorAll('[data-stock-info-tabs] .stock-info-tab'));
            const panels = Array.from(document.querySelectorAll('[data-stock-panel]'));

            tabs.forEach((tab) => {
                tab.addEventListener('click', () => {
                    const target = tab.dataset.stockTab;
                    tabs.forEach((item) => item.classList.toggle('active', item === tab));
                    panels.forEach((panel) => panel.classList.toggle('active', panel.dataset.stockPanel === target));
                    window.dispatchEvent(new Event('stock-tab-change'));
                });
            });
        })();

        (() => {
            return;
            const stockCharts = @json($stockCharts);
            const kData = @json($chartData);
            const canvases = Array.from(document.querySelectorAll('[data-stock-mini-chart]'));

            const colors = {
                red: '#ef4444',
                green: '#10b981',
                blue: '#60a5fa',
                cyan: '#38bdf8',
                amber: '#f6c766',
                orange: '#f97316',
                purple: '#8b5cf6',
                teal: '#14b8a6',
                pink: '#ec4899',
                grid: '#e5e7eb',
                text: '#64748b',
                ink: '#0f172a',
            };

            const resizeCanvas = (canvas) => {
                const rect = canvas.getBoundingClientRect();
                if (rect.width <= 0 || rect.height <= 0) return null;
                const ratio = window.devicePixelRatio || 1;
                canvas.width = Math.max(1, Math.floor(rect.width * ratio));
                canvas.height = Math.max(1, Math.floor(rect.height * ratio));
                const ctx = canvas.getContext('2d');
                ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                return { ctx, width: rect.width, height: rect.height };
            };

            const empty = (ctx, width, height, text = '資料不足') => {
                ctx.clearRect(0, 0, width, height);
                ctx.fillStyle = colors.text;
                ctx.font = '13px system-ui, -apple-system, BlinkMacSystemFont, sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText(text, width / 2, height / 2);
            };

            const number = (value) => Number.isFinite(Number(value)) ? Number(value) : null;
            const compact = (value) => {
                const abs = Math.abs(value);
                if (abs >= 1000000) return `${Math.round(value / 1000000)}M`;
                if (abs >= 1000) return `${Math.round(value / 1000)}k`;
                return `${Math.round(value)}`;
            };

            const range = (values) => {
                const valid = values.map(number).filter((value) => value !== null);
                if (!valid.length) return { min: 0, max: 1 };
                let min = Math.min(...valid);
                let max = Math.max(...valid);
                if (min === max) {
                    min -= 1;
                    max += 1;
                }
                const pad = (max - min) * 0.12;
                return { min: min - pad, max: max + pad };
            };

            const yScale = (value, min, max, top, bottom) => bottom - ((value - min) / (max - min)) * (bottom - top);

            const drawGrid = (ctx, width, height, left = 34, right = 10, top = 14, bottom = 28) => {
                ctx.strokeStyle = colors.grid;
                ctx.lineWidth = 1;
                ctx.setLineDash([3, 4]);
                for (let i = 0; i < 4; i++) {
                    const y = top + ((bottom - top) * i / 3);
                    ctx.beginPath();
                    ctx.moveTo(left, y);
                    ctx.lineTo(width - right, y);
                    ctx.stroke();
                }
                ctx.setLineDash([]);
            };

            const drawSupport = (canvas) => {
                const size = resizeCanvas(canvas);
                if (!size) return;
                const { ctx, width, height } = size;
                const data = stockCharts.support || [];
                if (!data.length) return empty(ctx, width, height);
                ctx.clearRect(0, 0, width, height);
                const left = 76;
                const right = 46;
                const top = 10;
                const rowH = (height - top - 16) / data.length;
                const max = Math.max(...data.map((row) => Number(row.volume || 0)), 1);
                data.forEach((row, index) => {
                    const y = top + index * rowH + 4;
                    const barW = (width - left - right) * (Number(row.volume || 0) / max);
                    ctx.fillStyle = row.type === 'pressure' ? 'rgba(239,68,68,.28)' : 'rgba(139,92,246,.72)';
                    ctx.fillRect(left, y, barW, Math.max(6, rowH - 8));
                    ctx.fillStyle = colors.ink;
                    ctx.font = '11px system-ui, -apple-system, BlinkMacSystemFont, sans-serif';
                    ctx.textAlign = 'right';
                    ctx.fillText(row.label, left - 8, y + rowH * .55);
                    ctx.textAlign = 'left';
                    ctx.fillText(compact(Number(row.volume || 0)), left + barW + 4, y + rowH * .55);
                });
            };

            const drawBarLine = (canvas, rows, barKey, lineKeys = [], options = {}) => {
                const size = resizeCanvas(canvas);
                if (!size) return;
                const { ctx, width, height } = size;
                if (!rows.length) return empty(ctx, width, height);
                ctx.clearRect(0, 0, width, height);
                const left = 38;
                const right = 14;
                const top = 14;
                const bottom = height - 30;
                drawGrid(ctx, width, height, left, right, top, bottom);
                const barValues = rows.map((row) => number(row[barKey])).filter((value) => value !== null);
                const lineValues = rows.flatMap((row) => lineKeys.map((key) => number(row[key]))).filter((value) => value !== null);
                const barRange = range([0, ...barValues]);
                const lineRange = lineValues.length ? range(lineValues) : barRange;
                const zero = yScale(0, barRange.min, barRange.max, top, bottom);
                const step = (width - left - right) / Math.max(1, rows.length);
                const barW = Math.max(3, Math.min(14, step * 0.52));

                rows.forEach((row, index) => {
                    const value = number(row[barKey]);
                    if (value === null) return;
                    const x = left + index * step + step / 2 - barW / 2;
                    const y = yScale(value, barRange.min, barRange.max, top, bottom);
                    ctx.fillStyle = options.barColor || colors.blue;
                    ctx.fillRect(x, Math.min(y, zero), barW, Math.max(2, Math.abs(zero - y)));
                });

                lineKeys.forEach((key, keyIndex) => {
                    const lineColor = options.lineColors?.[key] || [colors.red, colors.orange, colors.teal][keyIndex % 3];
                    ctx.strokeStyle = lineColor;
                    ctx.lineWidth = 2;
                    ctx.beginPath();
                    let started = false;
                    rows.forEach((row, index) => {
                        const value = number(row[key]);
                        if (value === null) return;
                        const x = left + index * step + step / 2;
                        const y = yScale(value, lineRange.min, lineRange.max, top, bottom);
                        if (!started) {
                            ctx.moveTo(x, y);
                            started = true;
                        } else {
                            ctx.lineTo(x, y);
                        }
                    });
                    if (started) ctx.stroke();
                });

                ctx.fillStyle = colors.text;
                ctx.font = '11px system-ui, -apple-system, BlinkMacSystemFont, sans-serif';
                ctx.textAlign = 'center';
                rows.forEach((row, index) => {
                    if (index % Math.ceil(rows.length / 4) === 0 || index === rows.length - 1) {
                        ctx.fillText(row.date || '', left + index * step + step / 2, height - 8);
                    }
                });
            };

            const drawGroupedBars = (canvas, rows, keys, keyColors) => {
                const size = resizeCanvas(canvas);
                if (!size) return;
                const { ctx, width, height } = size;
                if (!rows.length) return empty(ctx, width, height);
                ctx.clearRect(0, 0, width, height);
                const left = 38;
                const right = 12;
                const top = 14;
                const bottom = height - 30;
                drawGrid(ctx, width, height, left, right, top, bottom);
                const values = rows.flatMap((row) => keys.map((key) => number(row[key]))).filter((value) => value !== null);
                const { min, max } = range(values);
                const zero = yScale(0, min, max, top, bottom);
                const step = (width - left - right) / Math.max(1, rows.length);
                const barW = Math.max(2, Math.min(7, (step * 0.68) / keys.length));
                rows.forEach((row, index) => {
                    keys.forEach((key, keyIndex) => {
                        const value = number(row[key]);
                        if (value === null) return;
                        const groupW = barW * keys.length;
                        const x = left + index * step + step / 2 - groupW / 2 + keyIndex * barW;
                        const y = yScale(value, min, max, top, bottom);
                        ctx.fillStyle = keyColors[key] || colors.blue;
                        ctx.fillRect(x, Math.min(y, zero), barW, Math.max(2, Math.abs(zero - y)));
                    });
                });
            };

            const drawLines = (canvas, rows, keys, keyColors) => {
                const size = resizeCanvas(canvas);
                if (!size) return;
                const { ctx, width, height } = size;
                if (!rows.length) return empty(ctx, width, height);
                ctx.clearRect(0, 0, width, height);
                const left = 38;
                const right = 12;
                const top = 14;
                const bottom = height - 30;
                drawGrid(ctx, width, height, left, right, top, bottom);
                const values = rows.flatMap((row) => keys.map((key) => number(row[key]))).filter((value) => value !== null);
                const { min, max } = range(values);
                const step = (width - left - right) / Math.max(1, rows.length - 1);
                keys.forEach((key) => {
                    ctx.strokeStyle = keyColors[key] || colors.blue;
                    ctx.lineWidth = 2;
                    ctx.beginPath();
                    let started = false;
                    rows.forEach((row, index) => {
                        const value = number(row[key]);
                        if (value === null) return;
                        const x = left + index * step;
                        const y = yScale(value, min, max, top, bottom);
                        if (!started) {
                            ctx.moveTo(x, y);
                            started = true;
                        } else {
                            ctx.lineTo(x, y);
                        }
                    });
                    if (started) ctx.stroke();
                });
            };

            const renderMiniCharts = () => {
                canvases.forEach((canvas) => {
                    const type = canvas.dataset.stockMiniChart;
                    if (type === 'support') drawSupport(canvas);
                    if (type === 'priceVolume') drawBarLine(canvas, (kData.daily || []).slice(-60).map((row) => ({ date: row.time?.slice(5), volume: row.volume, close: row.close })), 'volume', ['close'], { barColor: 'rgba(147,197,253,.75)', lineColors: { close: colors.red } });
                    if (type === 'institutional') drawGroupedBars(canvas, stockCharts.chips || [], ['foreign', 'trust', 'dealer'], { foreign: colors.teal, trust: '#f59e0b', dealer: colors.pink });
                    if (type === 'margin') drawLines(canvas, stockCharts.chips || [], ['margin', 'short'], { margin: colors.cyan, short: '#f43f5e' });
                    if (type === 'revenue') drawBarLine(canvas, stockCharts.revenues || [], 'revenue', ['yoy'], { barColor: colors.cyan, lineColors: { yoy: colors.orange } });
                    if (type === 'financial') drawLines(canvas, stockCharts.financials || [], ['grossMargin', 'operatingMargin', 'roe', 'eps'], { grossMargin: '#2563eb', operatingMargin: colors.red, roe: '#f59e0b', eps: colors.teal });
                });
            };

            window.addEventListener('resize', renderMiniCharts);
            window.addEventListener('stock-tab-change', () => setTimeout(renderMiniCharts, 30));
            renderMiniCharts();
        })();

        (() => {
            const stockCharts = @json($stockCharts);
            const kData = @json($chartData);
            const nodes = Array.from(document.querySelectorAll('[data-stock-echart]'));
            const charts = new Map();

            const palette = {
                red: '#cf1428',
                redSoft: '#f8d7dc',
                blue: '#5b8def',
                teal: '#18a999',
                amber: '#f0a928',
                pink: '#e45a92',
                purple: '#8b5cf6',
                gray: '#64748b',
                grid: '#e7edf4',
                ink: '#102033',
            };

            const number = (value) => Number.isFinite(Number(value)) ? Number(value) : null;
            const comma = (value, digits = 0) => {
                const numeric = number(value);
                if (numeric === null) return '-';
                return numeric.toLocaleString('zh-TW', { maximumFractionDigits: digits, minimumFractionDigits: digits });
            };
            const short = (value) => {
                const numeric = number(value);
                if (numeric === null) return '-';
                const abs = Math.abs(numeric);
                if (abs >= 100000000) return `${comma(numeric / 100000000, 1)}億`;
                if (abs >= 10000) return `${comma(numeric / 10000, 1)}萬`;
                return comma(numeric, 0);
            };

            const baseOption = (extra = {}) => ({
                animationDuration: 450,
                color: [palette.red, palette.blue, palette.teal, palette.amber, palette.pink, palette.purple],
                grid: { left: 28, right: 20, top: 28, bottom: 30, containLabel: true },
                tooltip: {
                    trigger: 'axis',
                    confine: true,
                    backgroundColor: 'rgba(255,255,255,.96)',
                    borderColor: '#d8e1ec',
                    textStyle: { color: palette.ink, fontSize: 12, fontWeight: 700 },
                    extraCssText: 'box-shadow:0 10px 30px rgba(15,23,42,.12);border-radius:10px;',
                },
                xAxis: { axisLine: { lineStyle: { color: '#d8e1ec' } }, axisLabel: { color: palette.gray, fontSize: 11 } },
                yAxis: { axisLine: { show: false }, splitLine: { lineStyle: { color: palette.grid } }, axisLabel: { color: palette.gray, fontSize: 11 } },
                ...extra,
            });

            const showEmpty = (node, text = '資料不足，暫無法繪製圖表') => {
                node.innerHTML = `<div style="height:100%;display:grid;place-items:center;color:#64748b;font-weight:800;font-size:13px">${text}</div>`;
            };

            let supportPeriod = 'month';
            let institutionalPeriod = 'day';
            let marginPeriod = 'week';

            const periodKey = (dateText, period) => {
                const date = new Date(`${dateText}T00:00:00+08:00`);
                if (Number.isNaN(date.getTime())) return dateText;

                if (period === 'day') {
                    return dateText;
                }

                if (period === 'quarter') {
                    return `${date.getFullYear()} Q${Math.floor(date.getMonth() / 3) + 1}`;
                }

                if (period === 'month') {
                    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
                }

                const day = date.getDay() || 7;
                date.setDate(date.getDate() - day + 1);

                return `${String(date.getMonth() + 1).padStart(2, '0')}/${String(date.getDate()).padStart(2, '0')}`;
            };

            const groupedChipRows = (period, mode) => {
                const groups = new Map();
                (stockCharts.chips || []).forEach((row) => {
                    const key = periodKey(row.date, period);
                    if (!groups.has(key)) {
                        groups.set(key, {
                            date: key,
                            foreign: 0,
                            trust: 0,
                            dealer: 0,
                            margin: null,
                            short: null,
                            lendingAvailable: null,
                        });
                    }

                    const item = groups.get(key);

                    if (mode === 'sum') {
                        item.foreign += Number(row.foreign || 0);
                        item.trust += Number(row.trust || 0);
                        item.dealer += Number(row.dealer || 0);
                        return;
                    }

                    item.margin = row.margin;
                    item.short = row.short;
                    item.lendingAvailable = row.lendingAvailable;
                });

                return Array.from(groups.values());
            };

            const supportOption = () => {
                const pack = stockCharts.support?.[supportPeriod] || stockCharts.support?.month || stockCharts.support?.week || {};
                const rows = Array.isArray(pack) ? pack : (pack.rows || []);
                if (!rows.length) return null;
                const periodText = pack.period || '';
                const currentText = pack.current === null || pack.current === undefined ? '-' : comma(pack.current, 2);
                const supportText = pack.support || '-';
                const pressureText = pack.pressure || (pack.note || '-');

                return baseOption({
                    title: {
                        text: `${periodText}｜目前價 ${currentText}｜支撐 ${supportText}｜壓力 ${pressureText}`,
                        left: 0,
                        top: 0,
                        textStyle: { color: palette.gray, fontSize: 12, fontWeight: 900 },
                    },
                    grid: { left: 86, right: 42, top: 34, bottom: 8, containLabel: false },
                    tooltip: {
                        trigger: 'item',
                        confine: true,
                        formatter: ({ data }) => [
                            `<b>${data.name}</b>`,
                            `成交量：${comma(data.value)} 股`,
                            `位置：${data.role || '成交密集區'}`,
                        ].join('<br>'),
                    },
                    xAxis: { type: 'value', show: false },
                    yAxis: {
                        type: 'category',
                        inverse: true,
                        data: rows.map((row) => row.label),
                        axisLabel: { color: palette.ink, fontSize: 11, fontWeight: 700 },
                    },
                    series: [{
                        name: '成交量',
                        type: 'bar',
                        barMaxWidth: 16,
                        data: rows.map((row) => ({
                            name: row.label,
                            value: Number(row.volume || 0),
                            kind: row.type,
                            role: row.role || '',
                            itemStyle: {
                                borderRadius: [0, 8, 8, 0],
                                color: row.type === 'current'
                                    ? 'rgba(246,199,102,.78)'
                                    : (row.type === 'pressure'
                                        ? palette.red
                                        : (row.type === 'support' ? 'rgba(139,92,246,.68)' : 'rgba(148,163,184,.35)')),
                            },
                        })),
                        label: {
                            show: true,
                            position: 'right',
                            color: palette.ink,
                            fontSize: 11,
                            fontWeight: 800,
                            formatter: ({ value, data }) => data.role ? `${data.role} ${short(value)}` : short(value),
                        },
                    }],
                });
            };

            const priceVolumeOption = () => {
                const rows = (kData.daily || []).map((row) => ({
                    date: row.time,
                    close: number(row.close),
                    volume: number(row.volume),
                })).filter((row) => row.date && row.close !== null && row.volume !== null);
                if (!rows.length) return null;
                return baseOption({
                    legend: { top: 0, right: 0, itemWidth: 10, itemHeight: 10, textStyle: { color: palette.gray, fontWeight: 800 } },
                    grid: { left: 30, right: 30, top: 34, bottom: 42, containLabel: true },
                    tooltip: {
                        trigger: 'axis',
                        confine: true,
                        formatter: (items) => {
                            const row = rows[items[0].dataIndex];
                            return [`<b>${row.date}</b>`, `收盤價：${comma(row.close, 2)}`, `成交量：${comma(row.volume)} 股`].join('<br>');
                        },
                    },
                    dataZoom: [{ type: 'inside', xAxisIndex: 0 }, { type: 'slider', height: 14, bottom: 6, showDetail: false, start: 62, end: 100 }],
                    xAxis: { type: 'category', data: rows.map((row) => row.date.slice(5)), boundaryGap: true },
                    yAxis: [
                        { type: 'value', axisLabel: { formatter: (v) => short(v) } },
                        { type: 'value', position: 'right', axisLabel: { formatter: (v) => comma(v, 0) } },
                    ],
                    series: [
                        { name: '成交量', type: 'bar', yAxisIndex: 0, data: rows.map((row) => row.volume), itemStyle: { color: 'rgba(91,141,239,.55)', borderRadius: [4, 4, 0, 0] } },
                        { name: '收盤價', type: 'line', yAxisIndex: 1, data: rows.map((row) => row.close), smooth: true, symbolSize: 5, lineStyle: { width: 2, color: palette.red }, itemStyle: { color: palette.red } },
                    ],
                });
            };

            const institutionalOption = () => {
                const rows = groupedChipRows(institutionalPeriod, 'sum');
                if (!rows.length) return null;
                return baseOption({
                    legend: { top: 0, right: 0, itemWidth: 10, itemHeight: 10, textStyle: { color: palette.gray, fontWeight: 800 } },
                    grid: { left: 30, right: 14, top: 34, bottom: 40, containLabel: true },
                    tooltip: {
                        trigger: 'axis',
                        formatter: (items) => {
                            const row = rows[items[0].dataIndex];
                            return [`<b>${row.date}</b>`, `外資：${comma(row.foreign)} 股`, `投信：${comma(row.trust)} 股`, `自營商：${comma(row.dealer)} 股`].join('<br>');
                        },
                    },
                    xAxis: { type: 'category', data: rows.map((row) => String(row.date).slice(5)) },
                    dataZoom: [{ type: 'inside', xAxisIndex: 0 }, { type: 'slider', height: 14, bottom: 6, showDetail: false, start: 0, end: 100 }],
                    yAxis: { type: 'value', axisLabel: { formatter: (v) => short(v) } },
                    series: [
                        { name: '外資', type: 'bar', data: rows.map((row) => row.foreign), itemStyle: { color: palette.teal } },
                        { name: '投信', type: 'bar', data: rows.map((row) => row.trust), itemStyle: { color: palette.amber } },
                        { name: '自營商', type: 'bar', data: rows.map((row) => row.dealer), itemStyle: { color: palette.pink } },
                    ],
                });
            };

            const marginOption = () => {
                const rows = groupedChipRows(marginPeriod, 'last');
                if (!rows.length) return null;
                const hasLendingAvailable = rows.some((row) => row.lendingAvailable !== null && row.lendingAvailable !== undefined);
                return baseOption({
                    legend: { top: 0, right: 0, itemWidth: 10, itemHeight: 10, textStyle: { color: palette.gray, fontWeight: 800 } },
                    grid: { left: 30, right: 14, top: 34, bottom: 40, containLabel: true },
                    tooltip: {
                        trigger: 'axis',
                        formatter: (items) => {
                            const row = rows[items[0].dataIndex];
                            const lines = [`<b>${row.date}</b>`, `融資餘額：${comma(row.margin)} 股`, `融券餘額：${comma(row.short)} 股`];
                            if (hasLendingAvailable) lines.push(`借券可用額度：${comma(row.lendingAvailable)} 股`);
                            return lines.join('<br>');
                        },
                    },
                    xAxis: { type: 'category', data: rows.map((row) => String(row.date).slice(5)) },
                    dataZoom: [{ type: 'inside', xAxisIndex: 0 }, { type: 'slider', height: 14, bottom: 6, showDetail: false, start: 0, end: 100 }],
                    yAxis: { type: 'value', axisLabel: { formatter: (v) => short(v) } },
                    series: [
                        { name: '融資', type: 'line', smooth: true, data: rows.map((row) => row.margin), lineStyle: { color: palette.blue, width: 2 }, itemStyle: { color: palette.blue } },
                        { name: '融券', type: 'line', smooth: true, data: rows.map((row) => row.short), lineStyle: { color: palette.red, width: 2 }, itemStyle: { color: palette.red } },
                        ...(hasLendingAvailable ? [{ name: '借券可用額度', type: 'line', smooth: true, data: rows.map((row) => row.lendingAvailable), lineStyle: { color: palette.purple, width: 2, type: 'dashed' }, itemStyle: { color: palette.purple } }] : []),
                    ],
                });
            };

            const revenueOption = () => {
                const rows = stockCharts.revenues || [];
                if (!rows.length) return null;
                return baseOption({
                    legend: { top: 0, right: 0, itemWidth: 10, itemHeight: 10, textStyle: { color: palette.gray, fontWeight: 800 } },
                    grid: { left: 30, right: 30, top: 34, bottom: 42, containLabel: true },
                    tooltip: {
                        trigger: 'axis',
                        formatter: (items) => {
                            const row = rows[items[0].dataIndex];
                            return [`<b>${row.date}</b>`, `月營收：${comma(row.revenue)} 仟元`, `年增率：${row.yoy === null ? '-' : `${comma(row.yoy, 2)}%`}`, `月增率：${row.mom === null ? '-' : `${comma(row.mom, 2)}%`}`].join('<br>');
                        },
                    },
                    dataZoom: [{ type: 'inside', xAxisIndex: 0 }, { type: 'slider', height: 14, bottom: 6, showDetail: false, start: 50, end: 100 }],
                    xAxis: { type: 'category', data: rows.map((row) => String(row.date).slice(0, 7)) },
                    yAxis: [
                        { type: 'value', axisLabel: { formatter: (v) => short(v) } },
                        { type: 'value', position: 'right', axisLabel: { formatter: (v) => `${comma(v, 0)}%` } },
                    ],
                    series: [
                        { name: '月營收', type: 'bar', yAxisIndex: 0, data: rows.map((row) => row.revenue), itemStyle: { color: 'rgba(91,141,239,.62)', borderRadius: [4, 4, 0, 0] } },
                        { name: '年增率', type: 'line', yAxisIndex: 1, smooth: true, data: rows.map((row) => row.yoy), lineStyle: { color: palette.red, width: 2 }, itemStyle: { color: palette.red } },
                    ],
                });
            };

            const financialOption = () => {
                const rows = stockCharts.financials || [];
                if (!rows.length) return null;
                return baseOption({
                    legend: { top: 0, right: 0, itemWidth: 10, itemHeight: 10, textStyle: { color: palette.gray, fontWeight: 800 } },
                    grid: { left: 28, right: 30, top: 34, bottom: 40, containLabel: true },
                    tooltip: {
                        trigger: 'axis',
                        formatter: (items) => {
                            const row = rows[items[0].dataIndex];
                            return [`<b>${row.date}</b>`, `毛利率：${comma(row.grossMargin, 2)}%`, `營益率：${comma(row.operatingMargin, 2)}%`, `ROE：${comma(row.roe, 2)}%`, `EPS：${comma(row.eps, 2)} 元`].join('<br>');
                        },
                    },
                    xAxis: { type: 'category', data: rows.map((row) => String(row.date).slice(0, 7)) },
                    dataZoom: [{ type: 'inside', xAxisIndex: 0 }, { type: 'slider', height: 14, bottom: 6, showDetail: false, start: 35, end: 100 }],
                    yAxis: [
                        { type: 'value', axisLabel: { formatter: (v) => `${comma(v, 0)}%` } },
                        { type: 'value', position: 'right', axisLabel: { formatter: (v) => comma(v, 1) } },
                    ],
                    series: [
                        { name: '毛利率', type: 'line', yAxisIndex: 0, smooth: true, data: rows.map((row) => row.grossMargin), lineStyle: { color: palette.blue, width: 2 }, itemStyle: { color: palette.blue } },
                        { name: '營益率', type: 'line', yAxisIndex: 0, smooth: true, data: rows.map((row) => row.operatingMargin), lineStyle: { color: palette.red, width: 2 }, itemStyle: { color: palette.red } },
                        { name: 'ROE', type: 'line', yAxisIndex: 0, smooth: true, data: rows.map((row) => row.roe), lineStyle: { color: palette.amber, width: 2 }, itemStyle: { color: palette.amber } },
                        { name: 'EPS', type: 'line', yAxisIndex: 1, smooth: true, data: rows.map((row) => row.eps), lineStyle: { color: palette.teal, width: 2 }, itemStyle: { color: palette.teal } },
                    ],
                });
            };

            const builders = { support: supportOption, priceVolume: priceVolumeOption, institutional: institutionalOption, margin: marginOption, revenue: revenueOption, financial: financialOption };

            const renderCharts = () => {
                if (!window.echarts) {
                    nodes.forEach((node) => showEmpty(node, '圖表套件載入失敗'));
                    return;
                }

                nodes.forEach((node) => {
                    const builder = builders[node.dataset.stockEchart];
                    if (!builder) return;
                    const option = builder();
                    if (!option) {
                        const chart = charts.get(node);
                        if (chart) {
                            chart.dispose();
                            charts.delete(node);
                        }
                        showEmpty(node);
                        return;
                    }
                    let chart = charts.get(node);
                    if (!chart) {
                        chart = window.echarts.init(node, null, { renderer: 'canvas' });
                        charts.set(node, chart);
                    }
                    chart.setOption(option, true);
                    chart.resize();
                });
            };

            document.querySelectorAll('[data-support-period]').forEach((button) => {
                button.addEventListener('click', () => {
                    supportPeriod = button.dataset.supportPeriod || 'month';
                    document.querySelectorAll('[data-support-period]').forEach((item) => item.classList.toggle('active', item === button));
                    renderCharts();
                });
            });

            document.querySelectorAll('[data-institutional-period]').forEach((button) => {
                button.addEventListener('click', () => {
                    institutionalPeriod = button.dataset.institutionalPeriod || 'day';
                    document.querySelectorAll('[data-institutional-period]').forEach((item) => item.classList.toggle('active', item === button));
                    renderCharts();
                });
            });

            document.querySelectorAll('[data-margin-period]').forEach((button) => {
                button.addEventListener('click', () => {
                    marginPeriod = button.dataset.marginPeriod || 'week';
                    document.querySelectorAll('[data-margin-period]').forEach((item) => item.classList.toggle('active', item === button));
                    renderCharts();
                });
            });

            window.addEventListener('resize', () => charts.forEach((chart) => chart.resize()));
            window.addEventListener('stock-tab-change', () => setTimeout(renderCharts, 80));
            window.addEventListener('load', renderCharts);
            setTimeout(renderCharts, 1200);
        })();

        (() => {
            const chartData = @json($chartData);
            const canvas = document.getElementById('stock-k-chart');
            const empty = document.getElementById('stock-chart-empty');
            const buttons = Array.from(document.querySelectorAll('[data-chart-tabs] .chart-tab'));
            return;
            const ctx = canvas.getContext('2d');
            let activeRange = 'daily';
            let start = 0;
            let count = 80;
            let crossIndex = null;
            let dragging = false;
            let dragStartX = 0;
            let dragStartStart = 0;
            let pinchDistance = null;
            let longPressTimer = null;
            let crosshairMode = false;
            let pointerMoved = false;
            let longPressFired = false;

            const colors = {
                up: '#b42318',
                down: '#147d55',
                flat: '#657385',
                axis: '#dbe1e8',
                text: '#657385',
                grid: '#edf0f3',
                panel: '#fff',
                cross: '#1f2a37',
                labelBg: 'rgba(255, 255, 255, .94)',
            };

            const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
            const data = () => chartData[activeRange] || [];
            const visible = () => data().slice(start, start + count);
            const fmt = (value) => Number(value).toLocaleString('zh-TW', {
                minimumFractionDigits: Number(value) >= 100 ? 0 : 2,
                maximumFractionDigits: 2,
            });

            const resizeCanvas = () => {
                const rect = canvas.getBoundingClientRect();
                const ratio = window.devicePixelRatio || 1;
                canvas.width = Math.max(1, Math.floor(rect.width * ratio));
                canvas.height = Math.max(1, Math.floor(rect.height * ratio));
                ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            };

            const normalizeWindow = () => {
                const length = data().length;
                count = clamp(count, Math.min(20, length || 20), Math.max(20, length || 20));
                start = clamp(start, 0, Math.max(0, length - count));
            };

            const setRange = (range) => {
                activeRange = range;
                const length = data().length;
                count = Math.min(length || 80, range === 'yearly' ? 60 : (range === 'weekly' ? 80 : 100));
                start = Math.max(0, length - count);
                crossIndex = null;
                buttons.forEach((button) => button.classList.toggle('active', button.dataset.range === range));
                draw();
            };

            const xToIndex = (x, metrics) => {
                const local = clamp(x - metrics.pad.left, 0, metrics.plotWidth);
                return clamp(start + Math.floor(local / metrics.step), start, Math.min(data().length - 1, start + count - 1));
            };

            const drawText = (text, x, y, align = 'left', fill = colors.text) => {
                ctx.fillStyle = fill;
                ctx.font = '12px "Microsoft JhengHei", sans-serif';
                ctx.textAlign = align;
                ctx.fillText(text, x, y);
            };

            const draw = () => {
                resizeCanvas();
                normalizeWindow();

                const rows = visible();
                const hasData = rows.length > 0;
                canvas.style.display = hasData ? 'block' : 'none';
                empty.style.display = hasData ? 'none' : 'flex';

                if (!hasData) return;

                const width = canvas.getBoundingClientRect().width;
                const height = canvas.getBoundingClientRect().height;
                const pad = { top: 18, right: 48, bottom: 28, left: 8 };
                const volumeTop = Math.floor(height * 0.74);
                const priceBottom = volumeTop - 12;
                const volumeHeight = height - volumeTop - pad.bottom;
                const plotWidth = width - pad.left - pad.right;
                const highs = rows.map((item) => Number(item.high));
                const lows = rows.map((item) => Number(item.low));
                const volumes = rows.map((item) => Number(item.volume || 0));
                const maxPrice = Math.max(...highs);
                const minPrice = Math.min(...lows);
                const maxVolume = Math.max(...volumes, 1);
                const priceRange = Math.max(maxPrice - minPrice, 1);
                const step = plotWidth / rows.length;
                const candleWidth = Math.max(2, Math.min(14, step * 0.62));
                const yPrice = (price) => pad.top + ((maxPrice - price) / priceRange) * (priceBottom - pad.top);
                const metrics = { pad, plotWidth, step, yPrice, priceBottom, volumeTop, volumeHeight, width, height };

                ctx.clearRect(0, 0, width, height);
                ctx.fillStyle = colors.panel;
                ctx.fillRect(0, 0, width, height);

                ctx.strokeStyle = colors.grid;
                ctx.lineWidth = 1;
                for (let i = 0; i <= 4; i++) {
                    const y = pad.top + ((priceBottom - pad.top) / 4) * i;
                    ctx.beginPath();
                    ctx.moveTo(pad.left, y);
                    ctx.lineTo(width - pad.right, y);
                    ctx.stroke();
                    drawText(fmt(maxPrice - (priceRange / 4) * i), width - 4, y + 4, 'right');
                }

                rows.forEach((item, index) => {
                    const open = Number(item.open);
                    const high = Number(item.high);
                    const low = Number(item.low);
                    const close = Number(item.close);
                    const volume = Number(item.volume || 0);
                    const x = pad.left + step * index + step / 2;
                    const color = close > open ? colors.up : (close < open ? colors.down : colors.flat);
                    const yOpen = yPrice(open);
                    const yClose = yPrice(close);
                    const bodyTop = Math.min(yOpen, yClose);
                    const bodyHeight = Math.max(1, Math.abs(yOpen - yClose));

                    ctx.strokeStyle = color;
                    ctx.fillStyle = color;
                    ctx.beginPath();
                    ctx.moveTo(x, yPrice(high));
                    ctx.lineTo(x, yPrice(low));
                    ctx.stroke();
                    ctx.fillRect(x - candleWidth / 2, bodyTop, candleWidth, bodyHeight);

                    const volumeHeightPx = (volume / maxVolume) * volumeHeight;
                    ctx.globalAlpha = 0.28;
                    ctx.fillRect(x - candleWidth / 2, volumeTop + volumeHeight - volumeHeightPx, candleWidth, volumeHeightPx);
                    ctx.globalAlpha = 1;
                });

                ctx.strokeStyle = colors.axis;
                ctx.beginPath();
                ctx.moveTo(pad.left, priceBottom + 8);
                ctx.lineTo(width - pad.right, priceBottom + 8);
                ctx.moveTo(pad.left, volumeTop + volumeHeight);
                ctx.lineTo(width - pad.right, volumeTop + volumeHeight);
                ctx.stroke();

                drawText(rows[0]?.time || '', pad.left, height - 8);
                drawText(rows[rows.length - 1]?.time || '', width - pad.right, height - 8, 'right');

                if (crossIndex !== null && data()[crossIndex]) {
                    const local = crossIndex - start;
                    const item = data()[crossIndex];
                    const x = pad.left + step * local + step / 2;
                    const y = yPrice(Number(item.close));
                    ctx.strokeStyle = colors.cross;
                    ctx.setLineDash([4, 4]);
                    ctx.beginPath();
                    ctx.moveTo(x, pad.top);
                    ctx.lineTo(x, volumeTop + volumeHeight);
                    ctx.moveTo(pad.left, y);
                    ctx.lineTo(width - pad.right, y);
                    ctx.stroke();
                    ctx.setLineDash([]);

                    const label = `${item.time}  開 ${fmt(item.open)} 高 ${fmt(item.high)} 低 ${fmt(item.low)} 收 ${fmt(item.close)}`;
                    const boxWidth = Math.min(width - 16, Math.max(250, ctx.measureText(label).width + 16));
                    const boxX = x > width / 2 ? 8 : width - boxWidth - 8;
                    ctx.fillStyle = colors.labelBg;
                    ctx.fillRect(boxX, 8, boxWidth, 28);
                    ctx.strokeStyle = colors.axis;
                    ctx.strokeRect(boxX, 8, boxWidth, 28);
                    drawText(label, boxX + 8, 27, 'left', colors.text);
                }

                canvas._metrics = metrics;
            };

            const zoomAt = (clientX, direction) => {
                const length = data().length;
                if (!length) return;
                const rect = canvas.getBoundingClientRect();
                const metrics = canvas._metrics;
                const beforeIndex = metrics ? xToIndex(clientX - rect.left, metrics) : start + Math.floor(count / 2);
                const nextCount = clamp(Math.round(count * (direction > 0 ? 0.82 : 1.22)), 20, length);
                const ratio = (beforeIndex - start) / Math.max(1, count);
                count = nextCount;
                start = Math.round(beforeIndex - count * ratio);
                draw();
            };

            buttons.forEach((button) => button.addEventListener('click', () => setRange(button.dataset.range)));
            window.addEventListener('resize', draw);

            canvas.addEventListener('wheel', (event) => {
                event.preventDefault();
                zoomAt(event.clientX, event.deltaY < 0 ? 1 : -1);
            }, { passive: false });

            canvas.addEventListener('pointerdown', (event) => {
                event.preventDefault();
                canvas.setPointerCapture(event.pointerId);
                dragging = true;
                dragStartX = event.clientX;
                dragStartStart = start;
                pointerMoved = false;
                longPressFired = false;
                if (longPressTimer) {
                    window.clearTimeout(longPressTimer);
                }
                longPressTimer = window.setTimeout(() => {
                    if (!canvas._metrics || pointerMoved) return;
                    const rect = canvas.getBoundingClientRect();
                    crosshairMode = true;
                    longPressFired = true;
                    crossIndex = xToIndex(event.clientX - rect.left, canvas._metrics);
                    draw();
                }, 420);
            });

            canvas.addEventListener('pointermove', (event) => {
                if (!canvas._metrics) return;
                if (!dragging) return;
                const delta = event.clientX - dragStartX;
                if (Math.abs(delta) < 6) {
                    return;
                }
                pointerMoved = true;
                if (longPressTimer) {
                    window.clearTimeout(longPressTimer);
                    longPressTimer = null;
                }
                const shift = Math.round(delta / canvas._metrics.step);
                start = dragStartStart - shift;
                draw();
            });

            canvas.addEventListener('pointerup', (event) => {
                if (longPressTimer) {
                    window.clearTimeout(longPressTimer);
                    longPressTimer = null;
                }
                if (dragging && canvas._metrics && !pointerMoved && !longPressFired && crosshairMode) {
                    const rect = canvas.getBoundingClientRect();
                    crossIndex = xToIndex(event.clientX - rect.left, canvas._metrics);
                    draw();
                }
                dragging = false;
            });

            canvas.addEventListener('pointerleave', () => {
                if (longPressTimer) {
                    window.clearTimeout(longPressTimer);
                    longPressTimer = null;
                }
                dragging = false;
                draw();
            });

            canvas.addEventListener('contextmenu', (event) => event.preventDefault());
            canvas.addEventListener('selectstart', (event) => event.preventDefault());

            canvas.addEventListener('touchstart', (event) => {
                if (event.touches.length === 2) {
                    if (longPressTimer) {
                        window.clearTimeout(longPressTimer);
                        longPressTimer = null;
                    }
                    dragging = false;
                    const [a, b] = event.touches;
                    pinchDistance = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
                }
            }, { passive: true });

            canvas.addEventListener('touchmove', (event) => {
                if (event.touches.length === 2 && pinchDistance) {
                    event.preventDefault();
                    const [a, b] = event.touches;
                    const nextDistance = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
                    if (Math.abs(nextDistance - pinchDistance) > 8) {
                        zoomAt((a.clientX + b.clientX) / 2, nextDistance > pinchDistance ? 1 : -1);
                        pinchDistance = nextDistance;
                    }
                }
            }, { passive: false });

            canvas.addEventListener('touchend', () => { pinchDistance = null; });

            setRange(activeRange);
        })();

        (() => {
            const chartData = @json($chartData);
            const container = document.getElementById('stock-k-chart');
            const empty = document.getElementById('stock-chart-empty');
            const buttons = Array.from(document.querySelectorAll('[data-chart-tabs] .chart-tab'));
            let chart = null;
            let resizeObserver = null;

            const formatPrice = (value) => Number(value).toLocaleString('zh-TW', {
                minimumFractionDigits: Number(value) >= 100 ? 0 : 2,
                maximumFractionDigits: 2,
            });

            const rowsFor = (range) => (chartData[range] || [])
                .map((item) => ({
                    time: range === 'intraday' ? Number(item.time) : item.time,
                    label: item.label || item.time,
                    open: Number(item.open),
                    high: Number(item.high),
                    low: Number(item.low),
                    close: Number(item.close),
                    volume: Number(item.volume || 0),
                }))
                .filter((item) => item.time && Number.isFinite(item.open) && Number.isFinite(item.high) && Number.isFinite(item.low) && Number.isFinite(item.close));

            const destroyChart = () => {
                if (resizeObserver) {
                    resizeObserver.disconnect();
                    resizeObserver = null;
                }
                if (chart) {
                    chart.remove();
                    chart = null;
                }
                container.replaceChildren();
            };

            const render = (range) => {
                const rows = rowsFor(range);
                buttons.forEach((button) => button.classList.toggle('active', button.dataset.range === range));
                destroyChart();

                const hasData = rows.length > 0 && window.LightweightCharts;
                container.style.display = hasData ? 'block' : 'none';
                empty.style.display = hasData ? 'none' : 'flex';
                if (!hasData) return;

                chart = LightweightCharts.createChart(container, {
                    width: container.clientWidth,
                    height: container.clientHeight,
                    layout: {
                        background: { type: 'solid', color: '#ffffff' },
                        textColor: '#657385',
                        fontFamily: '"Microsoft JhengHei", system-ui, sans-serif',
                    },
                    grid: {
                        vertLines: { color: '#edf0f3' },
                        horzLines: { color: '#edf0f3' },
                    },
                    crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
                    rightPriceScale: { borderColor: '#dbe1e8', scaleMargins: { top: 0.08, bottom: 0.24 } },
                    timeScale: {
                        borderColor: '#dbe1e8',
                        timeVisible: range === 'intraday',
                        secondsVisible: false,
                    },
                    localization: { priceFormatter: formatPrice },
                    handleScroll: {
                        mouseWheel: true,
                        pressedMouseMove: true,
                        horzTouchDrag: true,
                        vertTouchDrag: false,
                    },
                    handleScale: {
                        axisPressedMouseMove: true,
                        mouseWheel: true,
                        pinch: true,
                    },
                });

                const candles = chart.addCandlestickSeries({
                    upColor: '#b42318',
                    downColor: '#147d55',
                    borderUpColor: '#b42318',
                    borderDownColor: '#147d55',
                    wickUpColor: '#b42318',
                    wickDownColor: '#147d55',
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                });
                candles.setData(rows.map(({ time, open, high, low, close }) => ({ time, open, high, low, close })));

                const volume = chart.addHistogramSeries({
                    priceScaleId: '',
                    priceFormat: { type: 'volume' },
                    color: '#94a3b8',
                });
                chart.priceScale('').applyOptions({ scaleMargins: { top: 0.78, bottom: 0 } });
                volume.setData(rows.map((item) => ({
                    time: item.time,
                    value: item.volume,
                    color: item.close >= item.open ? 'rgba(180, 35, 24, .24)' : 'rgba(20, 125, 85, .24)',
                })));

                chart.timeScale().fitContent();
                resizeObserver = new ResizeObserver(() => {
                    if (!chart) return;
                    chart.applyOptions({
                        width: container.clientWidth,
                        height: container.clientHeight,
                    });
                });
                resizeObserver.observe(container);
            };

            buttons.forEach((button) => button.addEventListener('click', () => render(button.dataset.range || 'daily')));
            const startWhenReady = () => {
                if (window.LightweightCharts) {
                    render('daily');
                    return;
                }
                window.setTimeout(startWhenReady, 80);
            };
            startWhenReady();
        })();
    </script>
@endsection
