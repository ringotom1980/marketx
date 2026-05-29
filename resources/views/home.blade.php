@extends('welcome')

@section('content')
    @php
        $marketCharts = $marketCharts ?? [];
    @endphp

    <style>
        .market-chart-grid {
            display: grid;
            gap: 12px;
        }
        .market-chart-head {
            display: flex;
            align-items: start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }
        .market-chart-head h2 { margin-bottom: 4px; }
        .market-chart-subtitle {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.4;
        }
        .market-chart-tabs {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 6px;
            margin-bottom: 10px;
        }
        .market-chart-tab {
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fff;
            color: var(--muted);
            padding: 7px 4px;
            font-weight: 900;
            font-size: 12px;
            cursor: pointer;
        }
        .market-chart-tab.active {
            border-color: rgba(193, 18, 31, .28);
            color: var(--button);
            background: #fff7f7;
        }
        .market-chart-wrap {
            position: relative;
            width: 100%;
            height: 280px;
            border: 1px solid var(--line);
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
            touch-action: none;
            user-select: none;
            -webkit-user-select: none;
            -webkit-touch-callout: none;
        }
        .market-chart-wrap canvas {
            width: 100%;
            height: 100%;
            display: block;
            user-select: none;
            -webkit-user-select: none;
            -webkit-touch-callout: none;
        }
        .market-chart-empty {
            min-height: 120px;
            display: grid;
            place-items: center;
            color: var(--muted);
            border: 1px dashed var(--line);
            border-radius: 8px;
            font-size: 13px;
            text-align: center;
            padding: 16px;
        }
        .market-chart-empty[hidden] {
            display: none;
        }
        .market-chart-source,
        .market-chart-tip {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }
        .market-chart-source {
            color: #7b2d2d;
            font-weight: 800;
        }
        .stock-signal-list {
            display: grid;
            gap: 0;
        }
        .stock-signal-item {
            display: grid;
            gap: 10px;
            padding: 12px 0;
            border-bottom: 1px solid var(--line);
        }
        .stock-signal-item:first-child {
            padding-top: 0;
        }
        .stock-signal-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }
        .stock-signal-name {
            color: var(--muted);
            font-size: inherit;
            font-weight: 700;
            text-decoration: none;
            min-width: 0;
        }
        .stock-signal-identity {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            min-width: 0;
        }
        .stock-theme-pill {
            display: inline-flex;
            align-items: center;
            min-height: 22px;
            padding: 2px 8px;
            border: 1px solid #f3b8b8;
            border-radius: 999px;
            background: #fff6f6;
            color: var(--button);
            font-size: 12px;
            font-weight: 800;
            line-height: 1.2;
            white-space: nowrap;
        }
        .stock-signal-confidence {
            color: var(--ink);
            font-size: inherit;
            font-weight: 400;
            white-space: nowrap;
        }
        .reason-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .reason-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: #fff1f1;
            color: var(--button);
            border: 1px solid rgba(193, 18, 31, .18);
            padding: 5px 9px;
            font-size: 12px;
            font-weight: 800;
            line-height: 1;
            white-space: nowrap;
        }
        .reason-pill.warning {
            background: #fff7df;
            color: #9f5b00;
            border-color: rgba(245, 158, 11, .22);
        }
        .reason-pill.down {
            background: #e8f5ee;
            color: var(--green);
            border-color: rgba(20, 125, 85, .18);
        }
        .theme-heat-list {
            display: grid;
            gap: 12px;
        }
        .theme-heat-row {
            display: grid;
            gap: 6px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--line);
        }
        .theme-heat-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }
        .theme-heat-name {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            color: var(--muted);
            font-weight: 800;
            font-size: 14px;
        }
        .theme-trend-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 3px 8px;
            font-size: 11px;
            font-weight: 900;
            white-space: nowrap;
            background: #fff7df;
            color: #9f5b00;
            border: 1px solid rgba(245, 158, 11, .18);
        }
        .theme-trend-pill.up {
            background: #fff1f1;
            color: var(--button);
            border-color: rgba(193, 18, 31, .14);
        }
        .theme-trend-pill.down {
            background: #eef3f8;
            color: #58677a;
            border-color: rgba(88, 103, 122, .14);
        }
        .theme-heat-meter {
            position: relative;
            height: 22px;
            border-radius: 999px;
            background: linear-gradient(90deg, #c9d8ee 0%, #f0d6b0 58%, #f4b8bd 100%);
            overflow: hidden;
            box-shadow: inset 0 0 0 1px rgba(101, 115, 133, .06);
        }
        .theme-heat-meter span {
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            width: calc(100% - var(--heat-score));
            border-radius: 0 999px 999px 0;
            background: #f1f1f1;
            box-shadow: -10px 0 0 #f1f1f1;
        }
        .theme-heat-score {
            position: absolute;
            inset: 0 auto 0 0;
            display: flex;
            align-items: center;
            padding-left: 10px;
            color: var(--ink);
            font-size: 12px;
            font-weight: 900;
            pointer-events: none;
        }
        .site-notice {
            display: grid;
            gap: 12px;
        }
        .site-notice p {
            margin: 0;
        }
        .site-notice-list {
            display: grid;
            gap: 8px;
            margin: 0;
            padding-left: 1.25em;
        }
        .site-notice-list li {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }
        .site-notice-list strong {
            color: var(--ink);
        }
        .data-health-panel {
            margin-bottom: 16px;
        }
        .data-health-head {
            display: grid;
            gap: 10px;
            margin-bottom: 12px;
        }
        .data-health-head h2 {
            margin-bottom: 4px;
        }
        .data-health-head p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }
        .data-health-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .data-health-summary span,
        .data-health-title span {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 900;
            white-space: nowrap;
        }
        .data-health-summary .ok,
        .data-health-item.ok .data-health-title span {
            color: var(--green);
            background: #e8f5ee;
        }
        .data-health-summary .partial,
        .data-health-item.partial .data-health-title span {
            color: #9f5b00;
            background: #fff7df;
        }
        .data-health-summary .stale,
        .data-health-item.stale .data-health-title span,
        .data-health-summary .missing,
        .data-health-item.missing .data-health-title span {
            color: var(--button);
            background: #fff1f1;
        }
        .data-health-grid {
            display: grid;
            gap: 10px;
        }
        .data-health-item {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 12px;
            background: #fff;
        }
        .data-health-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 8px;
        }
        .data-health-title strong {
            color: var(--ink);
            font-size: 14px;
        }
        .data-health-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 10px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }
        .data-health-coverage {
            position: relative;
            height: 18px;
            margin-top: 8px;
            border-radius: 999px;
            overflow: hidden;
            background: #eef1f5;
        }
        .data-health-coverage span {
            position: absolute;
            inset: 0 auto 0 0;
            width: var(--coverage);
            border-radius: 999px;
            background: linear-gradient(90deg, #c9d8ee, #f4c8c8);
        }
        .data-health-coverage b {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ink);
            font-size: 11px;
            font-weight: 900;
        }
        .data-health-count {
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }
        .data-health-item p {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }
        @media (min-width: 821px) {
            .market-chart-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
            .market-chart-wrap { height: 340px; }
            .data-health-head {
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: start;
            }
            .data-health-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
    </style>

    <section class="page-head">
        <div>
            <h1>今日全球 × 台股狀態中心</h1>
        </div>
        @include('partials.stock_search', [
            'value' => request('q'),
            'placeholder' => '搜尋股票代號、名稱、產業',
        ])
    </section>

    <section class="panel" style="margin-bottom:16px;border-color:#fcd34d;background:#fffbeb">
        <h2>使用聲明</h2>
        <div class="site-notice">
            <p class="lead">投資有風險，本站資訊僅供研究與自我判斷參考，不構成任何買賣建議、收益保證或投資邀約。AI 與規則式引擎只負責摘要、分類與白話解讀，不預測價格，也不取代個人風險控管。</p>
            <ul class="site-notice-list">
                <li><strong>06:10 全球更新：</strong>更新美股盤後、國際指數、匯率、利率、原物料與全球雷達資料。</li>
                <li><strong>08:00 盤前分析：</strong>產生全球盤前觀察；08:25 前完成題材盤前觀察補跑。</li>
                <li><strong>08:30 五張卡片：</strong>固定產生今日優先觀察、風險升高、潛力觀察、低檔爆量與持續弱勢名單，當日不再因盤中資料重排。</li>
                <li><strong>16:30 台股盤後：</strong>陸續更新收盤行情與日 K 資料，部分法人、融資融券與盤後資料可能尚未完整。</li>
                <li><strong>21:30 完整重跑：</strong>重新補齊台股日盤、籌碼、融資融券、財務營收、技術指標與分數資料。</li>
            </ul>
        </div>
    </section>

    @include('partials.data_health')

    <section class="market-chart-grid">
        @forelse ($marketCharts as $chart)
            <div class="panel market-chart-panel" data-market-chart='@json($chart['ranges'])'>
                <div class="market-chart-head">
                    <div>
                        <h2>{{ $chart['title'] }}</h2>
                        <p class="market-chart-subtitle">{{ $chart['subtitle'] }}</p>
                    </div>
                </div>
                <div class="market-chart-tabs">
                    <button class="market-chart-tab active" type="button" data-range="daily">日K</button>
                    <button class="market-chart-tab" type="button" data-range="weekly">周K</button>
                    <button class="market-chart-tab" type="button" data-range="monthly">月K</button>
                </div>
                <div class="market-chart-wrap">
                    <canvas></canvas>
                </div>
                <p class="market-chart-empty" hidden>目前官方資料還不足以產生 K 線。</p>
                <p class="market-chart-source">資料來源：{{ $chart['source'] }}</p>
                <p class="market-chart-tip">長按顯示十字線；點其他 K 棒可移動十字線，按住左右拖曳可平移，雙指可縮放。</p>
            </div>
        @empty
            <div class="panel">
                <h2>台股大盤 K 線</h2>
                <p class="lead">大盤 K 線資料準備中。</p>
            </div>
        @endforelse
    </section>

    <section class="grid three" style="margin-top:16px">
        <div class="panel">
            <h2>今日題材熱度</h2>
            <div class="theme-heat-list">
                @forelse ($themes as $theme)
                    @php($themeScore = min(100, max(0, (int) $theme['score'])))
                    <div class="theme-heat-row">
                        <div class="theme-heat-name">
                            <a href="/themes#theme-{{ $theme['slug'] }}" style="color:inherit;text-decoration:none">{{ $theme['name'] }}</a>
                            <span class="theme-trend-pill {{ $theme['trend'] ?? 'watch' }}">{{ $theme['trend_label'] ?? '觀察中' }}</span>
                        </div>
                        <div class="theme-heat-meter" style="--heat-score: {{ $themeScore }}%" aria-label="{{ $theme['name'] }} 熱度 {{ $themeScore }}">
                            <span></span>
                            <strong class="theme-heat-score">{{ $themeScore }}</strong>
                        </div>
                    </div>
                @empty
                    <p class="lead">目前沒有題材熱度資料</p>
                @endforelse
            </div>
        </div>

        @foreach ($stockRadarCards as $card)
            <div class="panel">
                <h2>今日{{ $card['title'] }}</h2>
                <div class="stock-signal-list">
                    @forelse ($card['items'] as $stock)
                        <div class="stock-signal-item">
                            <div class="stock-signal-top">
                                <div class="stock-signal-identity">
                                    <a class="stock-signal-name" href="/s/{{ $stock['symbol'] }}">{{ $stock['name'] }}</a>
                                    @foreach (array_slice($stock['themes'] ?? [], 0, 2) as $theme)
                                        <span class="stock-theme-pill">{{ $theme['name'] }}</span>
                                    @endforeach
                                </div>
                                <div class="stock-signal-confidence">信心指數{{ $stock['confidence'] }}%</div>
                            </div>
                            <div class="reason-pills">
                                @foreach ($stock['reasons'] as $reason)
                                    <span class="reason-pill {{ $reason['tone'] === 'warning' ? 'warning' : ($reason['tone'] === 'down' ? 'down' : '') }}">
                                        {{ $reason['label'] }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <p class="lead">{{ $card['empty'] }}</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </section>

    <script>
        (() => {
            const colors = {
                up: '#b42318',
                down: '#147d55',
                flat: '#657385',
                axis: '#dbe1e8',
                text: '#657385',
                grid: '#edf0f3',
                panel: '#ffffff',
                cross: '#9ca3af',
                labelBg: 'rgba(255,255,255,.94)',
            };

            const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
            const fmt = (value) => Number(value).toLocaleString('zh-TW', { maximumFractionDigits: 2 });

            document.querySelectorAll('[data-market-chart]').forEach((panel) => {
                const ranges = JSON.parse(panel.dataset.marketChart || '{}');
                const canvas = panel.querySelector('canvas');
                const wrap = panel.querySelector('.market-chart-wrap');
                const empty = panel.querySelector('.market-chart-empty');
                const tabs = Array.from(panel.querySelectorAll('.market-chart-tab'));
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

                const data = () => ranges[activeRange] || [];
                const visible = () => data().slice(start, start + count);

                const resizeCanvas = () => {
                    const rect = canvas.getBoundingClientRect();
                    const ratio = window.devicePixelRatio || 1;
                    canvas.width = Math.max(1, Math.floor(rect.width * ratio));
                    canvas.height = Math.max(1, Math.floor(rect.height * ratio));
                    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                };

                const normalizeWindow = () => {
                    const length = data().length;
                    count = clamp(count, 20, Math.max(20, length || 20));
                    start = clamp(start, 0, Math.max(0, length - count));
                    if (crossIndex !== null) {
                        crossIndex = clamp(crossIndex, start, Math.max(start, start + count - 1));
                    }
                };

                const setRange = (range) => {
                    activeRange = range;
                    const length = data().length;
                    count = Math.min(length || 80, range === 'monthly' ? 48 : (range === 'weekly' ? 80 : 100));
                    start = Math.max(0, length - count);
                    crossIndex = null;
                    tabs.forEach((tab) => tab.classList.toggle('active', tab.dataset.range === range));
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
                    wrap.hidden = !hasData;
                    empty.hidden = hasData;

                    if (!hasData) {
                        return;
                    }

                    const rect = canvas.getBoundingClientRect();
                    const width = rect.width;
                    const height = rect.height;
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

                    rows.forEach((item, localIndex) => {
                        const open = Number(item.open);
                        const high = Number(item.high);
                        const low = Number(item.low);
                        const close = Number(item.close);
                        const volume = Number(item.volume || 0);
                        const x = pad.left + step * localIndex + step / 2;
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
                        ctx.globalAlpha = 0.25;
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

                tabs.forEach((tab) => tab.addEventListener('click', () => setRange(tab.dataset.range)));
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

                setRange('daily');
            });
        })();
    </script>
@endsection
