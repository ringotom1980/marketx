@extends('welcome')

@php
    $stateBadge = fn (bool $ok) => $ok ? 'red' : 'amber';
@endphp

@section('content')
    <section class="page-head">
        <div>
            <h1>後台</h1>
            <p class="lead">資料狀態、AI 額度與手動任務控制。AI 預設保持關閉，只在按下手動任務時執行少量追蹤清單報告。</p>
        </div>
    </section>

    @if (session('status') || session('error'))
        <section class="panel" style="margin-bottom:16px;border-color:{{ session('error') ? '#fcd34d' : '#fecaca' }};background:{{ session('error') ? '#fffbeb' : '#fff7f7' }}">
            <h2>{{ session('error') ? '任務回報' : '任務完成' }}</h2>
            <p class="lead" style="white-space:pre-line;color:{{ session('error') ? 'var(--amber)' : 'var(--red)' }}">{{ session('status') ?? session('error') }}</p>
        </section>
    @endif

    <section class="grid two">
        <div class="panel">
            <h2>AI 控制台</h2>
            <table class="table">
                <tbody>
                <tr>
                    <th>預設狀態</th>
                    <td><span class="badge {{ $aiStatus['enabled'] ? 'red' : 'amber' }}">{{ $aiStatus['enabled'] ? '開啟' : '關閉，手動執行才開' }}</span></td>
                </tr>
                <tr>
                    <th>Gemini</th>
                    <td><span class="badge {{ $stateBadge($aiStatus['gemini_configured']) }}">{{ $aiStatus['gemini_configured'] ? '已設定' : '未設定' }}</span><br>{{ $aiStatus['gemini_model'] }}</td>
                </tr>
                <tr>
                    <th>Groq</th>
                    <td><span class="badge {{ $stateBadge($aiStatus['groq_configured']) }}">{{ $aiStatus['groq_configured'] ? '已設定' : '未設定' }}</span><br>{{ $aiStatus['groq_model'] }}</td>
                </tr>
                <tr>
                    <th>追蹤股數</th>
                    <td>{{ $watchlistCount }}</td>
                </tr>
                <tr>
                    <th>今日 AI 個股報告</th>
                    <td>{{ $todayAiReports }}</td>
                </tr>
                <tr>
                    <th>個股報告額度</th>
                    <td>{{ $aiStatus['limits']['stock_research']['remaining'] }} / {{ $aiStatus['limits']['stock_research']['limit'] }}</td>
                </tr>
                <tr>
                    <th>事件前處理額度</th>
                    <td>{{ $aiStatus['limits']['event_preprocess']['remaining'] }} / {{ $aiStatus['limits']['event_preprocess']['limit'] }}</td>
                </tr>
                </tbody>
            </table>

            <form method="post" action="/admin/ai/watchlist-reports" style="margin-top:14px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px">
                @csrf
                <input name="limit" type="number" min="1" max="5" value="3" style="width:100%;border:1px solid var(--line);border-radius:8px;padding:12px;font-size:16px">
                <button class="button" type="submit">產生追蹤清單 AI 報告</button>
            </form>
            <p class="lead" style="margin-top:10px;font-size:13px">安全限制：每次最多 5 檔，同日已產生 Gemini 報告會自動跳過。</p>
        </div>

        <div class="panel">
            <h2>最近 AI 紀錄</h2>
            @if ($latestAiLogs->isEmpty())
                <p class="lead">尚無 AI 執行紀錄。</p>
            @else
                <table class="table">
                    <tbody>
                    @foreach ($latestAiLogs as $log)
                        <tr>
                            <th>{{ $log->task }}</th>
                            <td>
                                <span class="badge {{ $log->status === 'success_ai' ? 'red' : 'amber' }}">{{ $log->status }}</span><br>
                                {{ $log->model }}<br>
                                <span class="lead" style="font-size:12px">{{ $log->created_at }}</span>
                                @if ($log->error_message)
                                    <p class="lead" style="font-size:12px;color:var(--amber)">{{ \Illuminate\Support\Str::limit($log->error_message, 90) }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    <section class="grid two" style="margin-top:16px">
        <div class="panel">
            <h2>會員名單</h2>
            @if ($members->isEmpty())
                <p class="lead">目前尚無一般會員。</p>
            @else
                <table class="table">
                    <tbody>
                    @foreach ($members as $member)
                        <tr>
                            <th>
                                {{ $member->name }}
                                @if ($member->is_admin)
                                    <br><span class="badge red">管理者</span>
                                @endif
                            </th>
                            <td>
                                {{ $member->email }}<br>
                                <span class="lead" style="font-size:12px">註冊 {{ \Carbon\CarbonImmutable::parse($member->created_at)->timezone('Asia/Taipei')->format('Y/m/d H:i') }}</span><br>
                                <span class="lead" style="font-size:12px">最後在線 {{ $member->last_seen_at ? $member->last_seen_at->format('m/d H:i') : '尚無紀錄' }}</span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="panel">
            <h2>線上名單</h2>
            @if ($onlineMembers->isEmpty())
                <p class="lead">目前沒有線上會員。</p>
            @else
                <table class="table">
                    <tbody>
                    @foreach ($onlineMembers as $online)
                        <tr>
                            <th>
                                {{ $online->name ?? '未登入訪客 / 管理者' }}
                                @if ($online->is_admin)
                                    <br><span class="badge red">管理者</span>
                                @endif
                            </th>
                            <td>
                                @if ($online->email)
                                    {{ $online->email }}<br>
                                @endif
                                <span class="lead" style="font-size:12px">{{ $online->device }}｜{{ $online->ip_address ?? '無 IP' }}</span><br>
                                <span class="lead" style="font-size:12px">最後活動 {{ $online->last_seen_at->format('m/d H:i:s') }}</span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    <section class="grid three" style="margin-top:16px">
        @foreach ($stats as $item)
            <div class="panel">
                <h2>{{ $item['title'] }}</h2>
                <p class="lead">{{ $item['body'] }}</p>
            </div>
        @endforeach
    </section>
@endsection
