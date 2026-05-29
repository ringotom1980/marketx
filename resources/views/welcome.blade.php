<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ?? '股市在幹嘛' }}</title>
    <meta name="description" content="《股市在幹嘛》整合台股、全球市場、題材熱度、技術線圖、籌碼與財務資料，幫助投資人用更清楚的脈絡觀察市場變化。">
    <meta name="theme-color" content="#c1121f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="股市在幹嘛">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta property="og:site_name" content="股市在幹嘛">
    <meta property="og:type" content="website">
    <meta property="og:title" content="股市在幹嘛">
    <meta property="og:description" content="整合台股、全球市場、題材熱度、技術線圖、籌碼與財務資料，用白話方式看懂市場正在發生什麼。">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ config('app.url') }}/assets/og-marketx.png?v=20260525-pwa1">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="股市在幹嘛">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="股市在幹嘛">
    <meta name="twitter:description" content="整合台股、全球市場、題材熱度、技術線圖、籌碼與財務資料，用白話方式看懂市場正在發生什麼。">
    <meta name="twitter:image" content="{{ config('app.url') }}/assets/og-marketx.png?v=20260525-pwa1">
    <link rel="manifest" href="/manifest.json?v=20260525-pwa1">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png?v=20260524-logo2">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/app-icon-180.png?v=20260525-pwa1">
    <style>
        :root {
            --bg: #f7f8fa;
            --panel: #ffffff;
            --ink: #16202a;
            --muted: #657385;
            --line: #dbe1e8;
            --green: #147d55;
            --red: #b42318;
            --button: #c1121f;
            --button-hover: #9f0f1a;
            --blue: #1d4ed8;
            --amber: #b45309;
        }

        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: "Noto Sans TC", "Microsoft JhengHei", system-ui, sans-serif;
            letter-spacing: 0;
        }

        a { color: inherit; text-decoration: none; }
        .shell { min-height: 100vh; }

        .topbar {
            border-bottom: 1px solid var(--line);
            background: rgba(255, 255, 255, .96);
            position: sticky;
            top: 0;
            z-index: 10;
            backdrop-filter: blur(10px);
        }

        .topbar-inner {
            width: 100%;
            max-width: 1180px;
            margin: 0 auto;
            padding: 8px max(14px, env(safe-area-inset-left)) 8px max(14px, env(safe-area-inset-right));
            display: grid;
            gap: 7px;
        }

        .topbar-main {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            min-width: 0;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            min-width: 0;
        }

        .brand img {
            width: 50px;
            height: 50px;
            border-radius: 0;
            object-fit: cover;
            display: block;
        }

        .brand-mark {
            display: grid;
            gap: 2px;
            min-width: 0;
        }

        .brand-name {
            font-weight: 900;
            font-size: clamp(19px, 6vw, 24px);
            line-height: 1.05;
            white-space: nowrap;
            color: #222831;
        }

        .brand-tagline {
            color: var(--muted);
            font-size: clamp(8px, 2.45vw, 11px);
            font-weight: 700;
            letter-spacing: .2em;
            white-space: nowrap;
        }

        .account-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            flex: 0 0 auto;
            font-size: clamp(12px, 3.2vw, 13px);
        }

        .account-actions a {
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 6px 8px;
            color: var(--muted);
            line-height: 1;
            white-space: nowrap;
            background: #fff;
        }

        .account-actions a.active,
        .account-actions a:hover {
            color: var(--button);
            border-color: rgba(193, 18, 31, .28);
        }

        .site-stats {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1;
            white-space: nowrap;
        }

        .site-stats span {
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .site-stats strong {
            color: var(--button);
            font-size: 12px;
            font-weight: 900;
        }

        .site-stats .dot {
            width: 3px;
            height: 3px;
            border-radius: 999px;
            background: var(--line);
        }

        .freshness-bar {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 6px;
            color: var(--muted);
            font-size: clamp(10px, 2.8vw, 12px);
            line-height: 1.3;
            white-space: nowrap;
        }

        .freshness-pill {
            min-width: 0;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fff;
            padding: 5px 7px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .freshness-pill strong {
            color: var(--ink);
            font-weight: 900;
        }

        .nav {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
            overflow: hidden;
            padding: 2px 0 4px;
            color: var(--muted);
            font-size: clamp(12px, 3.35vw, 14px);
        }

        .nav a {
            min-width: 0;
            border: 1px solid transparent;
            border-radius: 999px;
            padding: 7px 4px;
            line-height: 1;
            white-space: nowrap;
            text-align: center;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .nav a.active {
            color: var(--ink);
            border-color: var(--line);
            background: #fff;
            font-weight: 800;
        }

        main {
            width: 100%;
            max-width: 1180px;
            margin: 0 auto;
            padding: 16px max(14px, env(safe-area-inset-left)) 42px max(14px, env(safe-area-inset-right));
        }

        .page-head {
            display: grid;
            gap: 14px;
            margin-bottom: 16px;
        }

        h1 {
            font-size: 26px;
            line-height: 1.22;
            margin: 0 0 8px;
            overflow-wrap: anywhere;
        }

        .lead {
            margin: 0;
            color: var(--muted);
            line-height: 1.65;
            overflow-wrap: anywhere;
        }

        .search {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
        }

        .search input {
            width: 100%;
            min-width: 0;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 12px;
            font-size: 16px;
        }

        .search button,
        .button {
            border: 0;
            border-radius: 8px;
            background: var(--button);
            color: #fff;
            padding: 12px 14px;
            font-weight: 800;
            cursor: pointer;
            white-space: nowrap;
        }

        .search button:hover,
        .button:hover { background: var(--button-hover); }

        .grid { display: grid; gap: 12px; }
        .grid.two,
        .grid.three { grid-template-columns: 1fr; }

        .panel {
            min-width: 0;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 14px;
        }

        .panel h2 {
            margin: 0 0 12px;
            font-size: 17px;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            table-layout: fixed;
        }

        .table th,
        .table td {
            padding: 10px 0;
            border-bottom: 1px solid #edf0f3;
            text-align: left;
            vertical-align: top;
            overflow-wrap: anywhere;
        }

        .table th {
            width: 42%;
            color: var(--muted);
            font-weight: 700;
            padding-right: 10px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            border-radius: 999px;
            padding: 4px 9px;
            font-size: 12px;
            font-weight: 800;
            background: #eef2ff;
            color: var(--blue);
            white-space: normal;
            line-height: 1.35;
        }

        .badge.green { background: #e8f5ee; color: var(--green); }
        .badge.red { background: #fee4e2; color: var(--red); }
        .badge.amber { background: #fef3c7; color: var(--amber); }

        .score {
            font-size: 36px;
            line-height: 1;
            font-weight: 900;
        }

        .meter {
            width: 100%;
            min-width: 64px;
            height: 8px;
            border-radius: 999px;
            background: #edf0f3;
            overflow: hidden;
        }

        .meter span { display: block; height: 100%; background: var(--blue); }

        .chain {
            display: grid;
            gap: 10px;
            color: var(--muted);
            overflow-wrap: anywhere;
        }

        .chain strong { color: var(--ink); }

        .signal-list {
            display: grid;
            gap: 10px;
            margin-bottom: 14px;
        }

        .signal-item {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 10px;
            background: #fafafa;
        }

        .signal-item p {
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.55;
        }

        .install-tip-backdrop {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(22, 32, 42, .44);
        }

        .install-tip-backdrop.show {
            display: flex;
        }

        .install-tip-modal {
            width: min(100%, 430px);
            max-height: calc(100vh - 36px);
            overflow: auto;
            border-radius: 14px;
            background: #fff;
            border: 1px solid var(--line);
            box-shadow: 0 22px 60px rgba(15, 23, 42, .2);
            padding: 20px;
        }

        .install-tip-head {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .install-tip-head img {
            width: 54px;
            height: 54px;
            display: block;
        }

        .install-tip-head h2 {
            margin: 0;
            font-size: 22px;
            line-height: 1.25;
        }

        .install-tip-body {
            display: grid;
            gap: 12px;
            color: var(--muted);
            line-height: 1.7;
            font-size: 15px;
        }

        .install-tip-section {
            border: 1px solid #edf0f3;
            border-radius: 10px;
            padding: 12px;
            background: #fafafa;
        }

        .install-tip-section strong {
            display: block;
            color: var(--ink);
            margin-bottom: 6px;
            font-size: 15px;
        }

        .install-tip-section ol {
            margin: 0;
            padding-left: 20px;
        }

        .install-tip-section li + li {
            margin-top: 3px;
        }

        .install-tip-actions {
            display: grid;
            gap: 8px;
            margin-top: 16px;
        }

        .install-tip-actions-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .install-tip-secondary {
            width: 100%;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--muted);
        }

        .install-tip-secondary:hover {
            background: #f7f8fa;
            color: var(--ink);
        }

        .install-tip-note {
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        @media (min-width: 821px) {
            .topbar-inner {
                padding: 10px 20px;
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: center;
                gap: 8px 24px;
            }

            .brand { gap: 14px; }
            .brand img { width: 72px; height: 72px; }
            .brand-name { font-size: 34px; }
            .brand-tagline { font-size: 13px; }
            .topbar-main { grid-column: 1 / -1; }
            .account-actions { font-size: 13px; }
            .account-actions a { padding: 7px 11px; }
            .site-stats { grid-column: 1; font-size: 12px; }
            .freshness-bar {
                grid-column: 1;
                display: flex;
                justify-content: flex-start;
            }
            .freshness-pill { flex: 0 0 auto; padding: 6px 10px; }
            .nav { grid-column: 2; grid-row: 2 / 4; align-self: end; display: flex; flex-wrap: wrap; justify-content: flex-end; overflow: visible; }
            .nav a { padding: 0; border: 0; background: transparent; }
            .nav a.active,
            .nav a:hover { color: var(--ink); }

            main { padding: 28px 20px 56px; }

            .page-head {
                grid-template-columns: minmax(0, 1fr) 320px;
                gap: 24px;
                align-items: end;
                margin-bottom: 24px;
            }

            h1 { font-size: 34px; }
            .grid { gap: 16px; }
            .grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .grid.three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .panel { padding: 18px; }
            .panel h2 { font-size: 18px; }
            .table { table-layout: auto; }
            .table th { width: auto; }
            .score { font-size: 42px; }
        }
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div class="topbar-inner">
            <div class="topbar-main">
                <a class="brand" href="/">
                    <img src="/assets/marketx-logo.png?v=20260524-logo2" alt="股市在幹嘛">
                    <span class="brand-mark">
                        <span class="brand-name">股市在幹嘛</span>
                        <span class="brand-tagline">看懂市場・掌握機會</span>
                    </span>
                </a>
                <div class="account-actions" aria-label="帳號功能">
                    @if (session('marketx_admin') === true || session('marketx_is_admin') === true)
                        <a class="{{ request()->is('admin') || request()->is('admin/*') ? 'active' : '' }}" href="/admin">後台</a>
                    @endif
                    <a href="/logout">登出</a>
                </div>
            </div>
            <div class="site-stats" aria-label="網站狀態">
                <span>會員 <strong>{{ number_format($siteStats['members'] ?? 0) }}</strong></span>
                <i class="dot" aria-hidden="true"></i>
                <span>線上 <strong>{{ number_format($siteStats['online'] ?? 0) }}</strong></span>
            </div>
            <div class="freshness-bar" aria-label="資料更新時間">
                <span class="freshness-pill">台股更新 <strong>{{ $dataFreshness['taiwan_updated_at'] ? \Carbon\CarbonImmutable::parse($dataFreshness['taiwan_updated_at'])->timezone('Asia/Taipei')->format('m/d H:i') : '待更新' }}</strong></span>
                <span class="freshness-pill">全球更新 <strong>{{ $dataFreshness['global_updated_at'] ? \Carbon\CarbonImmutable::parse($dataFreshness['global_updated_at'])->timezone('Asia/Taipei')->format('m/d H:i') : '待更新' }}</strong></span>
            </div>
            <nav class="nav">
                <a class="{{ request()->is('/') ? 'active' : '' }}" href="/">今日狀態</a>
                <a class="{{ request()->is('global') ? 'active' : '' }}" href="/global">全球雷達</a>
                <a class="{{ request()->is('themes') ? 'active' : '' }}" href="/themes">題材雷達</a>
                <a class="{{ request()->is('watchlist') ? 'active' : '' }}" href="/watchlist">追蹤清單</a>
            </nav>
        </div>
    </header>

    <main>
        @yield('content')
    </main>
</div>
@if (session('show_home_screen_tip'))
    <div class="install-tip-backdrop" id="install-tip" role="dialog" aria-modal="true" aria-labelledby="install-tip-title">
        <div class="install-tip-modal">
            <div class="install-tip-head">
                <img src="/assets/app-icon-180.png?v=20260525-pwa1" alt="股市在幹嘛">
                <div>
                    <h2 id="install-tip-title">把股市在幹嘛加入手機主畫面</h2>
                    <p class="lead" style="font-size:13px">下次就能像 APP 一樣直接開啟。</p>
                </div>
            </div>

            <div class="install-tip-body">
                <div class="install-tip-section">
                    <strong>iPhone / Safari</strong>
                    <ol>
                        <li>點瀏覽器下方的「分享」按鈕。</li>
                        <li>往下滑，選「加入主畫面」。</li>
                        <li>名稱確認為「股市在幹嘛」，點右上角「新增」。</li>
                    </ol>
                </div>

                <div class="install-tip-section">
                    <strong>Android / Chrome</strong>
                    <ol>
                        <li>點右上角「⋮」選單。</li>
                        <li>選「加入主畫面」或「安裝應用程式」。</li>
                        <li>確認名稱後點「新增」或「安裝」。</li>
                    </ol>
                </div>
            </div>

            <div class="install-tip-actions">
                <div class="install-tip-actions-row">
                    <button class="button install-tip-secondary" id="install-tip-close" type="button">我知道了</button>
                    <button class="button" id="install-tip-done" type="button">我已加入主畫面</button>
                </div>
                <div class="install-tip-note">加入主畫面後，手機桌面會顯示股市在幹嘛的圖示。</div>
            </div>
        </div>
    </div>
@endif
<script>
    (() => {
        const tip = document.getElementById('install-tip');
        const close = document.getElementById('install-tip-close');
        const done = document.getElementById('install-tip-done');
        const storageKey = 'marketx.home_screen_tip.dismissed';

        if (tip && close && done && localStorage.getItem(storageKey) !== '1') {
            tip.classList.add('show');
            close.addEventListener('click', () => {
                tip.classList.remove('show');
            });
            done.addEventListener('click', () => {
                localStorage.setItem(storageKey, '1');
                tip.classList.remove('show');
            });
            tip.addEventListener('click', (event) => {
                if (event.target === tip) {
                    tip.classList.remove('show');
                }
            });
        }
    })();

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js?v=20260525-pwa1').catch(() => {});
        });
    }
</script>
</body>
</html>
