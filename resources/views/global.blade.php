@extends('welcome')

@section('content')
    <style>
        .global-head {
            display: grid;
            gap: 12px;
        }

        .global-score {
            display: grid;
            gap: 10px;
        }

        .global-score-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .global-score-meter {
            height: 12px;
            border-radius: 999px;
            background: linear-gradient(90deg, #d7e6f8 0%, #f3d9a6 52%, #efb0ac 100%);
            overflow: hidden;
            position: relative;
        }

        .global-score-meter::after {
            content: "";
            position: absolute;
            inset: 0 0 0 var(--score);
            background: rgba(241, 243, 245, .86);
            border-radius: 0 999px 999px 0;
        }

        .market-section {
            margin-top: 16px;
        }

        .market-section-head {
            display: flex;
            align-items: flex-end;
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
        .market-date,
        .market-source {
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

        .market-read {
            color: var(--muted);
            line-height: 1.6;
            font-size: 14px;
        }

        @media (min-width: 900px) {
            .global-head { grid-template-columns: minmax(0, 1fr) 360px; align-items: stretch; }
        }
    </style>

    <section class="page-head global-head">
        <div>
            <h1>全球雷達</h1>
            <p class="lead">用主要國際指數看全球資金風向：美股、費半、VIX、日本、香港、韓國、匯率、利率、商品與台股關聯指標。</p>
        </div>
        <div class="panel global-score">
            <div class="global-score-row">
                <span class="badge {{ $radar['wind']['tone'] }}">{{ $radar['wind']['title'] }}</span>
                <strong>{{ $radar['wind']['score'] }}%</strong>
            </div>
            <div class="global-score-meter" style="--score: {{ $radar['wind']['score'] }}%"></div>
            <p class="lead">支撐：{{ $radar['wind']['support'] }}</p>
            <p class="lead">壓力：{{ $radar['wind']['pressure'] }}</p>
            <p class="market-date">資料更新：{{ $radar['asOf'] ? \Carbon\CarbonImmutable::parse($radar['asOf'])->timezone('Asia/Taipei')->format('m/d H:i') : '待更新' }}</p>
        </div>
    </section>

    @foreach ($radar['groups'] as $group)
        <section class="market-section">
            <div class="market-section-head">
                <div>
                    <h2>{{ $group['title'] }}</h2>
                    <p class="lead">{{ $group['lead'] }}</p>
                </div>
            </div>
            <div class="panel" style="margin-bottom:12px">
                <p class="lead">{{ $group['summary'] }}</p>
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
                            <span class="market-change {{ $card['tone'] }}">
                                {{ $card['changePct'] }}
                            </span>
                        </div>

                        <p class="market-read">{{ $card['read'] }}</p>
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

    <section class="grid two" style="margin-top:16px">
        <div class="panel">
            <h2>今日全球事件</h2>
            <div class="chain">
                @forelse ($radar['events'] as $event)
                    <div><strong>{{ $event['title'] }}</strong><br>{{ $event['body'] }}</div>
                @empty
                    <div>目前尚未聚合今日全球事件。</div>
                @endforelse
            </div>
        </div>

        <div class="panel">
            <h2>觀察重點</h2>
            <div class="chain">
                @foreach ($radar['watchpoints'] as $point)
                    <div>{{ $loop->iteration }}. {{ $point }}</div>
                @endforeach
            </div>
        </div>
    </section>
@endsection
