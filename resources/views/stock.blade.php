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

        .k-chart-wrap canvas {
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

        @media (max-width: 520px) {
            .k-chart-wrap { height: 300px; }
            .chart-tab { padding: 9px 4px; font-size: 13px; }
        }
    </style>

    <section class="page-head">
        <div>
            <h1>{{ $stock['name'] }} {{ $stock['symbol'] }}</h1>
            <p class="lead">{{ $stock['market'] }}｜收盤 {{ $stock['close'] }}｜漲跌 {{ $stock['change'] }}｜成交量 {{ $stock['volume'] }}</p>
        </div>
        <div class="panel">
            <div class="badge {{ $decisionTone }}">{{ $stock['decision'] }}</div>
            <p class="lead" style="margin-top:12px">信心指數</p>
            <div class="score">{{ $stock['confidence'] }}%</div>
            @if ($stock['isWatched'])
                <form method="post" action="/watchlist/{{ $stock['symbol'] }}" style="margin-top:12px">
                    @csrf
                    @method('DELETE')
                    <button class="button" type="submit" style="width:100%">取消追蹤</button>
                </form>
            @else
                <form method="post" action="/watchlist" style="margin-top:12px">
                    @csrf
                    <input type="hidden" name="symbol" value="{{ $stock['symbol'] }}">
                    <button class="button" type="submit" style="width:100%">加入追蹤</button>
                </form>
            @endif
        </div>
    </section>

    <section class="grid two">
        <div class="panel">
            <h2>六大模組狀態</h2>
            <table class="table">
                <tbody>
                @foreach ($modules as $module)
                    <tr>
                        <th>{{ $module['name'] }}</th>
                        <td><span class="badge {{ $module['tone'] }}">{{ $module['label'] }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="panel">
            <h2>全球事件影響鏈</h2>
            @if (! empty($eventChains))
                <div class="signal-list">
                    @foreach ($eventChains as $chain)
                        <div class="signal-item">
                            <span class="badge amber">{{ $chain['event'] }}</span>
                            <p>{{ $chain['path'] }}</p>
                            <p>{{ $chain['judgement'] }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="lead">目前沒有明確全球事件直接連到此股票，先以技術、籌碼與財務分數觀察。</p>
            @endif
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
            <canvas id="stock-k-chart"></canvas>
        </div>
        <p class="chart-tip">長按顯示十字線；點其他 K 棒可移動十字線，按住左右拖曳可平移，雙指可縮放。</p>
        <p class="chart-empty" id="stock-chart-empty">目前尚未接入個股當日分時資料，先看日 K、周 K、年 K。</p>
    </section>

    <section class="grid two" style="margin-top:16px">
        <div class="panel">
            <h2>K 線與技術分析</h2>
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

        <div class="panel">
            <h2>籌碼分析</h2>
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
    </section>

    <section class="grid two" style="margin-top:16px">
        <div class="panel">
            <h2>財務營收分析</h2>
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

        <div class="panel">
            <h2>評價</h2>
            <p class="lead">{!! nl2br(e($summary)) !!}</p>
        </div>
    </section>

    <script>
        (() => {
            const chartData = @json($chartData);
            const canvas = document.getElementById('stock-k-chart');
            const empty = document.getElementById('stock-chart-empty');
            const buttons = Array.from(document.querySelectorAll('[data-chart-tabs] .chart-tab'));
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
    </script>
@endsection
