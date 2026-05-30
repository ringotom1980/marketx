@extends('welcome')

@section('content')
    <section class="page-head">
        <div>
            <h1>FinMind Sponsor 資料狀態</h1>
        </div>
        <a class="button ghost" href="/admin">回後台</a>
    </section>

    <section class="grid four" style="margin-bottom:16px">
        <div class="panel">
            <h2>正常</h2>
            <p class="lead">{{ $summary['ok'] }} 個資料源</p>
        </div>
        <div class="panel">
            <h2>需觀察</h2>
            <p class="lead">{{ $summary['partial'] }} 個資料源</p>
        </div>
        <div class="panel">
            <h2>過舊</h2>
            <p class="lead">{{ $summary['stale'] }} 個資料源</p>
        </div>
        <div class="panel">
            <h2>無資料</h2>
            <p class="lead">{{ $summary['missing'] }} 個資料源</p>
        </div>
    </section>

    <section class="panel">
        <h2>Sponsor 資料表</h2>
        <table class="table">
            <tbody>
            @foreach ($items as $item)
                @php
                    $tone = match ($item['status']) {
                        'ok' => 'red',
                        'partial' => 'amber',
                        'stale', 'empty', 'missing' => 'green',
                        default => 'amber',
                    };
                @endphp
                <tr>
                    <th>
                        {{ $item['label'] }}<br>
                        <span class="badge {{ $tone }}">{{ $item['status_label'] }}</span>
                    </th>
                    <td>
                        <strong>{{ $item['source'] }}</strong><br>
                        最新：{{ $item['latest_at'] ?? '無資料' }}<br>
                        筆數：{{ number_format((int) $item['count']) }}
                        @if ($item['symbol_count'] !== null)
                            ｜股票數：{{ number_format((int) $item['symbol_count']) }}
                        @endif
                        <br>
                        <span class="lead" style="font-size:12px">{{ $item['table'] }}｜{{ $item['note'] }}</span>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endsection
