@extends('welcome')

@php
    $decisionTone = function (string $decision): string {
        if (str_contains($decision, '買')) {
            return 'red';
        }

        if (str_contains($decision, '賣') || str_contains($decision, '減')) {
            return 'green';
        }

        return 'amber';
    };

    $changeTone = function ($change): string {
        if ($change === null) {
            return 'amber';
        }

        return (float) $change >= 0 ? 'red' : 'green';
    };
@endphp

@section('content')
    <section class="page-head">
        <div>
            <h1>追蹤清單</h1>
            <p class="lead">自選股集中看分數、決策、收盤價與資料完整度。今日個股 AI 報告已用 {{ $aiUsage['used'] }} / {{ $aiUsage['limit'] }}，剩餘 {{ $aiUsage['remaining'] }} 檔。</p>
        </div>
        <form class="search" action="/watchlist" method="post">
            @csrf
            <input name="symbol" value="{{ old('symbol') }}" placeholder="輸入股票代號，例如 2330">
            <button type="submit">加入</button>
        </form>
    </section>

    @if (session('status') || session('error'))
        <section class="panel" style="margin-bottom:16px;border-color:#fecaca;background:#fff7f7">
            <p class="lead" style="white-space:pre-line;color:{{ session('error') ? 'var(--amber)' : 'var(--red)' }}">{{ session('status') ?? session('error') }}</p>
        </section>
    @endif

    @if ($errors->any())
        <section class="panel" style="margin-bottom:16px;border-color:#fcd34d;background:#fffbeb">
            <p class="lead" style="color:var(--amber)">{{ $errors->first() }}</p>
        </section>
    @endif

    @if ($items->isEmpty())
        <section class="panel">
            <h2>尚未加入股票</h2>
            <p class="lead">先輸入股票代號加入追蹤。建議從你每天會看的股票開始，例如台積電、鴻海、廣達這類核心觀察標的。</p>
        </section>
    @else
        <section class="grid two">
            @foreach ($items as $item)
                <article class="panel">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
                        <div style="min-width:0">
                            <h2 style="margin-bottom:4px">
                                <a href="/s/{{ $item['symbol'] }}">{{ $item['name'] }} {{ $item['symbol'] }}</a>
                            </h2>
                            <p class="lead">{{ $item['market'] }}｜{{ $item['industry'] }}</p>
                        </div>
                        <span class="badge {{ $decisionTone($item['decision']) }}">{{ $item['decision'] }}</span>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:14px 0">
                        <div>
                            <p class="lead" style="font-size:12px">總分</p>
                            <strong style="font-size:24px">{{ $item['score'] ?? '無' }}</strong>
                        </div>
                        <div>
                            <p class="lead" style="font-size:12px">信心度</p>
                            <strong style="font-size:24px">{{ $item['confidence'] ?? '無' }}</strong>
                        </div>
                        <div>
                            <p class="lead" style="font-size:12px">收盤</p>
                            <strong style="font-size:24px">{{ $item['close'] ?? '無' }}</strong>
                        </div>
                    </div>

                    <div class="chain">
                        <div>
                            <span class="badge {{ $changeTone($item['change']) }}">
                                漲跌 {{ $item['change'] === null ? '無資料' : $item['change'] }}
                            </span>
                            <span class="badge amber">模組 {{ $item['complete_modules'] }} / 6</span>
                        </div>
                        @if (! empty($item['weak_modules']))
                            <div>
                                @foreach ($item['weak_modules'] as $weakModule)
                                    <span class="badge amber">{{ $weakModule }}</span>
                                @endforeach
                            </div>
                        @endif
                        <p class="lead">資料日期：{{ $item['trade_date'] ?? '無資料' }}</p>
                    </div>

                    @if (! empty($item['report_summary']))
                        <div class="signal-item" style="margin-top:12px">
                            <span class="badge {{ $item['report_is_ai'] ? 'red' : 'amber' }}">
                                {{ $item['report_is_ai'] ? 'AI 報告' : '規則式評價' }} {{ $item['report_date'] }}
                            </span>
                            <p>{!! nl2br(e(\Illuminate\Support\Str::limit($item['report_summary'], 180))) !!}</p>
                        </div>
                    @else
                        <div class="signal-item" style="margin-top:12px">
                            <span class="badge amber">尚未產生 AI 報告</span>
                            <p>之後執行追蹤清單 AI 任務後，這裡會顯示 Gemini 四段式研究摘要。</p>
                        </div>
                    @endif

                    <div style="display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:14px">
                        <a class="button" href="/s/{{ $item['symbol'] }}" style="text-align:center">查看</a>
                        <form method="post" action="/watchlist/{{ $item['symbol'] }}">
                            @csrf
                            @method('DELETE')
                            <button class="button" type="submit">移除</button>
                        </form>
                    </div>
                    @if (! $item['report_is_ai'])
                        <form method="post" action="/watchlist/{{ $item['symbol'] }}/ai-report" style="margin-top:8px">
                            @csrf
                            <button class="button" type="submit" style="width:100%" {{ $aiUsage['remaining'] <= 0 ? 'disabled' : '' }}>
                                產生AI報告
                            </button>
                        </form>
                    @endif
                </article>
            @endforeach
        </section>
    @endif
@endsection
