@extends('welcome')

@section('content')
    <section class="page-head">
        <div>
            <h1>全球雷達</h1>
            <p class="lead">把美股、費半、VIX、美元、美債、油價、黃金與全球事件整理成台股風向。</p>
        </div>
        <div class="panel">
            <span class="badge {{ $radar['wind']['tone'] }}">{{ $radar['wind']['title'] }}</span>
            <div class="score" style="margin-top:12px">{{ $radar['wind']['score'] }} / 100</div>
            <p class="lead">支撐：{{ $radar['wind']['support'] }}</p>
            <p class="lead">壓力：{{ $radar['wind']['pressure'] }}</p>
        </div>
    </section>

    <section class="grid three">
        @foreach ($radar['indicators'] as $indicator)
            <div class="panel">
                <h2>{{ $indicator['name'] }}</h2>
                <span class="badge {{ $indicator['tone'] }}">{{ $indicator['state'] }}</span>
                <p class="lead" style="margin-top:10px">數值 {{ $indicator['value'] }}｜漲跌 {{ $indicator['change'] }}</p>
                <p class="lead">{{ $indicator['note'] }}</p>
            </div>
        @endforeach
    </section>

    <section class="grid two" style="margin-top:16px">
        <div class="panel">
            <h2>今日熱門事件</h2>
            <div class="chain">
                @foreach ($radar['events'] as $event)
                    <div><strong>{{ $event['title'] }}</strong><br>{{ $event['body'] }}</div>
                @endforeach
            </div>
        </div>

        <div class="panel">
            <h2>台股影響鏈</h2>
            <div class="chain">
                @foreach ($radar['chains'] as $chain)
                    <div>{{ $chain }}</div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="panel" style="margin-top:16px">
        <h2>今日觀察重點</h2>
        <div class="chain">
            @foreach ($radar['watchpoints'] as $point)
                <div>{{ $loop->iteration }}. {{ $point }}</div>
            @endforeach
        </div>
    </section>
@endsection
