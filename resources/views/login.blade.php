<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登入｜股市在幹嘛</title>
    <meta name="description" content="《股市在幹嘛》整合台股、全球市場、題材熱度、技術線圖、籌碼與財務資料，幫助投資人用更清楚的脈絡觀察市場變化。">
    <meta name="theme-color" content="#c1121f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="股市在幹嘛">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta property="og:site_name" content="股市在幹嘛">
    <meta property="og:type" content="website">
    <meta property="og:title" content="股市在幹嘛">
    <meta property="og:description" content="整合台股、全球市場、題材熱度、技術線圖、籌碼與財務資料，用白話方式看懂市場正在發生什麼。">
    <meta property="og:url" content="{{ config('app.url') }}">
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
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #111827;
            color: #16202a;
            font-family: "Noto Sans TC", "Microsoft JhengHei", system-ui, sans-serif;
        }
        .login {
            width: min(420px, calc(100vw - 32px));
            background: #fff;
            border-radius: 12px;
            padding: 36px 28px 28px;
            box-shadow: 0 20px 70px rgba(0, 0, 0, .35);
        }
        .brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            margin-bottom: 30px;
            text-align: center;
        }
        .brand img {
            width: 142px;
            height: 142px;
            border-radius: 0;
            object-fit: cover;
        }
        h1 {
            margin: 0;
            font-size: 31px;
            line-height: 1.05;
            color: #222831;
        }
        .tagline {
            margin: 4px 0 0;
            color: #6b7280;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .3em;
        }
        label {
            display: block;
            margin: 14px 0 8px;
            font-weight: 700;
        }
        input {
            width: 100%;
            border: 1px solid #dbe1e8;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 16px;
        }
        .remember-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            color: #657385;
            font-size: 14px;
            font-weight: 700;
        }
        .remember-row input {
            width: 18px;
            height: 18px;
            margin: 0;
            accent-color: #c1121f;
        }
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        button {
            width: 100%;
            border: 0;
            border-radius: 8px;
            margin-top: 16px;
            padding: 12px 14px;
            background: #c1121f;
            color: #fff;
            font-weight: 800;
            cursor: pointer;
        }
        button:hover {
            background: #9f0f1a;
        }
        .error {
            margin: 0 0 14px;
            color: #b42318;
            font-weight: 700;
        }
        .hint {
            margin: 10px 0 0;
            color: #6b7280;
            font-size: 13px;
            line-height: 1.6;
        }
        .auth-link {
            margin: 14px 0 0;
            color: #657385;
            font-size: 14px;
            line-height: 1.6;
            text-align: center;
        }
        .auth-link a {
            color: #c1121f;
            font-weight: 900;
            text-decoration: none;
        }
        .auth-title {
            margin: 0 0 16px;
            color: #16202a;
            font-size: 22px;
            font-weight: 900;
            text-align: center;
        }
    </style>
</head>
<body>
    <main class="login">
        <div class="brand">
            <img src="/assets/marketx-logo.png?v=20260524-logo2" alt="股市在幹嘛">
            <div class="brand-copy">
                <h1>股市在幹嘛</h1>
                <p class="tagline">看懂市場・掌握機會</p>
            </div>
        </div>

        @if ($errors->any())
            <p class="error">{{ $errors->first() }}</p>
        @endif

        @if (($mode ?? 'login') === 'register')
            <h2 class="auth-title">建立帳號</h2>
            <form method="post" action="/register" autocomplete="on">
                @csrf
                <label for="name">名稱</label>
                <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" autofocus required>

                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required>

                <label for="password">密碼</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required>

                <label for="password_confirmation">確認密碼</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>

                <button type="submit">建立帳號</button>
                <p class="hint">一般帳號可以使用網站與追蹤清單，但不能產生 AI 分析報告。</p>
                <p class="auth-link">已經有帳號，<a href="/login">回登入頁</a></p>
            </form>
        @else
            <h2 class="auth-title">登入</h2>
            <form id="member-login-form" method="post" action="/login" autocomplete="on">
                @csrf
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" autofocus>

                <label for="password">密碼</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>

                <label class="remember-row" for="remember_login">
                    <input id="remember_login" name="remember_login" type="checkbox" value="1">
                    <span>記住帳號，密碼由瀏覽器或手機安全保存</span>
                </label>

                <button type="submit">登入</button>
                <p class="auth-link">還沒有帳號，<a href="/register">點我註冊</a></p>
            </form>
        @endif
    </main>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js?v=20260525-pwa1').catch(() => {});
        });
    }

    const memberLoginForm = document.getElementById('member-login-form');
    const emailInput = document.getElementById('email');
    const rememberLogin = document.getElementById('remember_login');

    if (memberLoginForm && emailInput && rememberLogin) {
        const rememberedEmail = localStorage.getItem('marketx.remember_email');

        if (rememberedEmail && !emailInput.value) {
            emailInput.value = rememberedEmail;
            rememberLogin.checked = true;
        }

        memberLoginForm.addEventListener('submit', () => {
            if (rememberLogin.checked && emailInput.value.trim()) {
                localStorage.setItem('marketx.remember_email', emailInput.value.trim());
                return;
            }

            localStorage.removeItem('marketx.remember_email');
        });
    }
    </script>
</body>
</html>
