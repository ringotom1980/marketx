<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ?? '股市在幹嘛' }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png?v=20260524-logo2">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/favicon-180.png?v=20260524-logo2">
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
            gap: 8px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .brand img {
            width: 58px;
            height: 58px;
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
            font-size: 24px;
            line-height: 1.05;
            white-space: nowrap;
            color: #222831;
        }

        .brand-tagline {
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .34em;
            white-space: nowrap;
        }

        .nav {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding: 2px 0 4px;
            color: var(--muted);
            font-size: 14px;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }

        .nav::-webkit-scrollbar { display: none; }

        .nav a {
            flex: 0 0 auto;
            border: 1px solid transparent;
            border-radius: 999px;
            padding: 7px 10px;
            line-height: 1;
            white-space: nowrap;
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

        @media (min-width: 821px) {
            .topbar-inner {
                padding: 10px 20px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 24px;
            }

            .brand { gap: 14px; }
            .brand img { width: 72px; height: 72px; }
            .brand-name { font-size: 34px; }
            .brand-tagline { font-size: 13px; }
            .nav { flex-wrap: wrap; justify-content: flex-end; overflow: visible; }
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
            <a class="brand" href="/">
                <img src="/assets/marketx-logo.png?v=20260524-logo2" alt="股市在幹嘛">
                <span class="brand-mark">
                    <span class="brand-name">股市在幹嘛</span>
                    <span class="brand-tagline">看懂市場・掌握機會</span>
                </span>
            </a>
            <nav class="nav">
                <a class="{{ request()->is('/') ? 'active' : '' }}" href="/">今日狀態</a>
                <a class="{{ request()->is('global') ? 'active' : '' }}" href="/global">全球雷達</a>
                <a class="{{ request()->is('themes') ? 'active' : '' }}" href="/themes">題材雷達</a>
                <a class="{{ request()->is('watchlist') ? 'active' : '' }}" href="/watchlist">追蹤清單</a>
                <a class="{{ request()->is('admin') ? 'active' : '' }}" href="/admin">後台</a>
                <a href="/logout">登出</a>
            </nav>
        </div>
    </header>

    <main>
        @yield('content')
    </main>
</div>
</body>
</html>
