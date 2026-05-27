@extends('welcome')

@section('content')
    <style>
        .global-head { display: grid; gap: 12px; }
        .market-section { margin-top: 16px; }
        .market-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }
        .market-section-head h2 {
            margin: 0;
            font-size: 19px;
        }
        .market-card {
            display: grid;
            gap: 10px;
        }
        .market-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }
        .market-name {
            display: grid;
            gap: 2px;
            min-width: 0;
        }
        .market-name strong {
            font-size: 17px;
            line-height: 1.25;
        }
        .market-name span,
        .market-date {
            color: var(--muted);
            font-size: 12px;
        }
        .market-value {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            border-top: 1px solid #edf0f3;
            padding-top: 10px;
        }
        .market-value strong {
            font-size: 22px;
            line-height: 1;
        }
        .market-change {
            font-weight: 900;
            white-space: nowrap;
        }
        .market-change.red { color: var(--red); }
        .market-change.green { color: var(--green); }
        .market-change.amber { color: var(--amber); }
    </style>

    <section class="page-head global-head">
        <div>
            <h1>全球雷達</h1>
            <p class="lead">主要國際指數與台股相關 ADR 觀察。</p>
            <p class="market-date">資料更新：{{ $radar['asOf'] ? \Carbon\CarbonImmutable::parse($radar['asOf'])->timezone('Asia/Taipei')->format('m/d H:i') : '待更新' }}</p>
        </div>
    </section>

    @foreach ($radar['groups'] as $group)
        <section class="market-section">
            <div class="market-section-head">
                <h2>{{ $group['title'] }}</h2>
            </div>

            <div class="grid three">
                @forelse ($group['cards'] as $card)
                    <article class="panel market-card">
                        <div class="market-card-top">
                            <div class="market-name">
                                <strong>{{ $card['name'] }}</strong>
                                <span>{{ $card['region'] }}</span>
                            </div>
                            <span class="badge {{ $card['tone'] }}">{{ $card['state'] }}</span>
                        </div>

                        <div class="market-value">
                            <strong>{{ $card['value'] }}</strong>
                            <span class="market-change {{ $card['tone'] }}">{{ $card['changePct'] }}</span>
                        </div>

                        <div class="market-date">交易日：{{ $card['tradeDate'] }}</div>
                    </article>
                @empty
                    <div class="panel">
                        <p class="lead">目前尚未匯入此區市場資料。</p>
                    </div>
                @endforelse
            </div>
        </section>
    @endforeach
@endsection
