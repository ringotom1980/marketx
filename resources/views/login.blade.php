<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登入｜股市在幹嘛</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
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
            padding: 28px;
            box-shadow: 0 20px 70px rgba(0, 0, 0, .35);
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 22px;
        }
        .brand img {
            width: 56px;
            height: 56px;
            border-radius: 10px;
            object-fit: cover;
        }
        h1 {
            margin: 0;
            font-size: 24px;
        }
        label {
            display: block;
            margin-bottom: 8px;
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
            background: #16202a;
            color: #fff;
            font-weight: 800;
            cursor: pointer;
        }
        .error {
            margin: 0 0 14px;
            color: #b42318;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <form class="login" method="post" action="/login">
        @csrf
        <div class="brand">
            <img src="/assets/marketx-logo.png" alt="股市在幹嘛">
            <h1>股市在幹嘛</h1>
        </div>

        @if ($errors->any())
            <p class="error">{{ $errors->first() }}</p>
        @endif

        <label for="password">管理員密碼</label>
        <input id="password" name="password" type="password" autocomplete="current-password" autofocus required>
        <button type="submit">登入</button>
    </form>
</body>
</html>
