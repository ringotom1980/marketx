<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? '股市在幹嘛' }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/favicon-180.png">
    <style>
        :root {
            --bg: #f7f8fa;
            --panel: #ffffff;
            --ink: #16202a;
            --muted: #657385;
            --line: #dbe1e8;
            --green: #147d55;
            --red: #b42318;
            --blue: #1d4ed8;
            --amber: #b45309;
        }

        * { box-sizing: border-box; }

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
            background: rgba(255, 255, 255, .94);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .topbar-inner {
            max-width: 1180px;
            margin: 0 auto;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .brand img {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
            display: block;
            box-shadow: 0 0 0 1px rgba(22, 32, 42, .08);
        }

        .brand span {
            font-weight: 800;
            font-size: 20px;
            white-space: nowrap;
        }

        .nav {
            display: flex;
            gap: 14px;
            color: var(--muted);
            font-size: 14px;
            flex-wrap: wrap;
        }

        .nav a.active,
        .nav a:hover { color: var(--ink); }

        main {
            max-width: 1180px;
            margin: 0 auto;
            padding: 28px 20px 56px;
        }

        .page-head {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 320px;
            gap: 24px;
            align-items: end;
            margin-bottom: 24px;
        }

        h1 { font-size: 34px; line-height: 1.2; margin: 0 0 8px; }
        .lead { margin: 0; color: var(--muted); line-height: 1.7; }

        .search { display: flex; gap: 8px; }

        .search input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 14px;
        }

        .search button,
        .button {
            border: 0;
            border-radius: 8px;
            background: var(--ink);
            color: #fff;
            padding: 12px 16px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .grid { display: grid; gap: 16px; }
        .grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .grid.three { grid-template-columns: repeat(3, minmax(0, 1fr)); }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 18px;
        }

        .panel h2 { margin: 0 0 14px; font-size: 18px; line-height: 1.45; }

        .table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .table th,
        .table td {
            padding: 10px 0;
            border-bottom: 1px solid #edf0f3;
            text-align: left;
            vertical-align: top;
        }

        .table th { color: var(--muted); font-weight: 600; }

        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 9px;
            font-size: 12px;
            font-weight: 800;
            background: #eef2ff;
            color: var(--blue);
            white-space: nowrap;
        }

        .badge.green { background: #e8f5ee; color: var(--green); }
        .badge.red { background: #fee4e2; color: var(--red); }
        .badge.amber { background: #fef3c7; color: var(--amber); }

        .score { font-size: 42px; line-height: 1; font-weight: 900; }

        .meter {
            height: 8px;
            border-radius: 999px;
            background: #edf0f3;
            overflow: hidden;
        }

        .meter span { display: block; height: 100%; background: var(--blue); }

        .chain { display: grid; gap: 10px; color: var(--muted); }
        .chain strong { color: var(--ink); }

        @media (max-width: 820px) {
            .topbar-inner { display: block; }
            .brand img { width: 42px; height: 42px; }
            .nav { margin-top: 12px; }
            .page-head { display: block; }
            .search { margin-top: 16px; }
            .grid.two,
            .grid.three { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="/">
                <img src="/assets/marketx-logo.png" alt="股市在幹嘛">
                <span>股市在幹嘛</span>
            </a>
            <nav class="nav">
                <a class="{{ request()->is('/') ? 'active' : '' }}" href="/">今日狀態</a>
                <a class="{{ request()->is('global') ? 'active' : '' }}" href="/global">全球雷達</a>
                <a class="{{ request()->is('themes') ? 'active' : '' }}" href="/themes">題材雷達</a>
                <a class="{{ request()->is('watchlist') ? 'active' : '' }}" href="/watchlist">追蹤清單</a>
                <a class="{{ request()->is('admin') ? 'active' : '' }}" href="/admin">後台</a>
            </nav>
        </div>
    </header>

    <main>
        @yield('content')
    </main>
</div>
</body>
</html>
