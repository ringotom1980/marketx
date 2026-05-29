@extends('welcome')

@section('content')
    <section class="page-head">
        <div>
            <h1>搜尋台股</h1>
        </div>
        <form class="search" action="/search" method="get">
            <input name="q" value="{{ $query }}" placeholder="例如 2330、台積電、半導體">
            <button type="submit">搜尋</button>
        </form>
    </section>

    <section class="panel">
        <h2>搜尋結果</h2>
        @if ($query === '')
            <p class="lead">請輸入股票代號、名稱或產業。</p>
        @elseif ($stocks->isEmpty())
            <p class="lead">找不到符合「{{ $query }}」的股票。</p>
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>代號</th>
                    <th>名稱</th>
                    <th>市場</th>
                    <th>產業</th>
                    <th>狀態</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($stocks as $stock)
                    <tr>
                        <td><a href="/s/{{ $stock->symbol }}">{{ $stock->symbol }}</a></td>
                        <td><a href="/s/{{ $stock->symbol }}">{{ $stock->name }}</a></td>
                        <td>{{ $stock->market }}</td>
                        <td>{{ $stock->industry ?? '未分類' }}</td>
                        <td><span class="badge {{ $stock->is_active ? 'green' : 'amber' }}">{{ $stock->is_active ? '交易中' : '未啟用' }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
