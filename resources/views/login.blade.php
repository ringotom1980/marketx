<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登入｜股市在幹嘛</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png?v=20260524-logo2">
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
        .auth-switch {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 18px;
        }
        .auth-switch a {
            border: 1px solid #dbe1e8;
            border-radius: 8px;
            padding: 10px;
            color: #657385;
            text-align: center;
            text-decoration: none;
            font-weight: 800;
        }
        .auth-switch a.active {
            border-color: #c1121f;
            background: #fff7f7;
            color: #c1121f;
        }
        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0 4px;
            color: #6b7280;
            font-size: 13px;
            font-weight: 700;
        }
        .divider::before,
        .divider::after {
            content: "";
            height: 1px;
            background: #dbe1e8;
            flex: 1;
        }
        .hint {
            margin: 10px 0 0;
            color: #6b7280;
            font-size: 13px;
            line-height: 1.6;
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

        <div class="auth-switch">
            <a class="{{ ($mode ?? 'login') === 'login' ? 'active' : '' }}" href="/login">登入</a>
            <a class="{{ ($mode ?? 'login') === 'register' ? 'active' : '' }}" href="/register">註冊</a>
        </div>

        @if ($errors->any())
            <p class="error">{{ $errors->first() }}</p>
        @endif

        @if (($mode ?? 'login') === 'register')
            <form method="post" action="/register">
                @csrf
                <label for="name">名稱</label>
                <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" autofocus required>

                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>

                <label for="password">密碼</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required>

                <label for="password_confirmation">確認密碼</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>

                <button type="submit">建立帳號</button>
                <p class="hint">一般帳號可以使用網站與追蹤清單，但不能產生 AI 分析報告。</p>
            </form>
        @else
            <form method="post" action="/login">
                @csrf
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" autofocus>

                <label for="password">密碼</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>

                <button type="submit">登入</button>
            </form>

            <div class="divider">管理者</div>
            <form method="post" action="/login">
                @csrf
                <input type="hidden" name="admin_login" value="1">
                <label for="admin_password">管理員密碼</label>
                <input id="admin_password" name="password" type="password" autocomplete="current-password">
                <button type="submit">管理者登入</button>
            </form>
        @endif
    </main>
</body>
</html>
