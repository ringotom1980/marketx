@extends('welcome')

@php
    $statusLabel = [
        'pending' => '待處理',
        'observing' => '觀察中',
        'accepted' => '已採納',
        'rejected' => '已拒絕',
        'resolved' => '已處理',
        'success' => '成功',
        'failed' => '失敗',
        'running' => '執行中',
    ];
    $severityLabel = [
        'critical' => '嚴重',
        'high' => '高',
        'medium' => '中',
        'low' => '低',
        'info' => '資訊',
    ];
    $badgeClass = fn (?string $value) => in_array($value, ['critical', 'high', 'failed'], true) ? 'red' : 'amber';
    $formatTime = fn ($time) => $time ? \Carbon\CarbonImmutable::parse($time)->timezone('Asia/Taipei')->format('m/d H:i') : '尚未執行';
@endphp

@section('content')
    <section class="page-head">
        <div>
            <h1>AI 代理人</h1>
            <p class="lead">這裡是 Codex 與代理人的 DB 溝通管道。代理人寫入觀察與建議，凌晨修正後再把學習回饋寫回資料庫。</p>
        </div>
        <a class="button ghost" href="/admin">回後台</a>
    </section>

    <section class="grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
        <div class="panel">
            <h2>{{ $summary['active_roles'] }} / {{ $summary['roles'] }}</h2>
            <p class="lead">啟用代理人</p>
        </div>
        <div class="panel">
            <h2>{{ $summary['pending_findings'] }}</h2>
            <p class="lead">待處理發現</p>
        </div>
        <div class="panel">
            <h2>{{ $summary['active_memories'] }}</h2>
            <p class="lead">啟用記憶</p>
        </div>
        <div class="panel">
            <h2>{{ $summary['reviewed_findings'] }}</h2>
            <p class="lead">處理紀錄</p>
        </div>
    </section>

    <section class="panel" style="margin-top:16px">
        <h2>代理人清單</h2>
        <div class="grid three" style="margin-top:12px">
            @foreach ($roles as $role)
                <article style="border:1px solid var(--line);border-radius:8px;padding:14px;background:#fff">
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start">
                        <div>
                            <h3 style="margin:0 0 4px">{{ $role->name }}</h3>
                            <p class="lead" style="font-size:13px">{{ $role->scope }}</p>
                        </div>
                        <span class="badge {{ $role->is_active ? 'red' : 'amber' }}">{{ $role->is_active ? '啟用' : '停用' }}</span>
                    </div>
                    <p class="lead" style="margin-top:10px;font-size:14px">{{ $role->mission }}</p>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px">
                        <div>
                            <strong>{{ $role->runs_count }}</strong>
                            <p class="lead" style="font-size:12px">執行</p>
                        </div>
                        <div>
                            <strong>{{ $role->pending_findings_count }}</strong>
                            <p class="lead" style="font-size:12px">待處理</p>
                        </div>
                        <div>
                            <strong>{{ $role->memories_count }}</strong>
                            <p class="lead" style="font-size:12px">記憶</p>
                        </div>
                    </div>
                    <p class="lead" style="margin-top:10px;font-size:12px">最近執行：{{ $formatTime($role->latest_run?->started_at) }}</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="grid two" style="margin-top:16px">
        <div class="panel">
            <h2>待處理發現</h2>
            @if ($pendingFindings->isEmpty())
                <p class="lead">目前沒有待處理問題。代理人上工後，分類錯誤、資料缺漏與規則疑點會出現在這裡。</p>
            @else
                <table class="table">
                    <tbody>
                    @foreach ($pendingFindings as $finding)
                        @php
                            $payload = is_array($finding->payload) ? $finding->payload : (is_string($finding->payload) ? json_decode($finding->payload, true) : []);
                            $relatedMemories = is_array($payload) ? ($payload['related_memories'] ?? []) : [];
                        @endphp
                        <tr>
                            <th>
                                {{ $finding->title }}<br>
                                <span class="badge {{ $badgeClass($finding->severity) }}">{{ $severityLabel[$finding->severity] ?? $finding->severity }}</span>
                            </th>
                            <td>
                                {{ $finding->role?->name ?? '未指定代理人' }}｜{{ $finding->page ?? '全站' }}<br>
                                <span class="lead" style="font-size:13px">{{ \Illuminate\Support\Str::limit($finding->description, 120) }}</span><br>
                                @if (! empty($relatedMemories))
                                    <div style="margin-top:8px;display:grid;gap:6px">
                                        @foreach (array_slice($relatedMemories, 0, 2) as $memory)
                                            <div style="border-left:3px solid #fecaca;padding-left:8px">
                                                <strong style="font-size:12px">引用記憶：{{ $memory['title'] ?? '未命名記憶' }}</strong><br>
                                                <span class="lead" style="font-size:12px">{{ \Illuminate\Support\Str::limit($memory['rule_summary'] ?? '', 90) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                <span class="lead" style="font-size:12px">{{ $formatTime($finding->created_at) }}</span>
                                <form method="post" action="/admin/agents/findings/{{ $finding->id }}/review" style="margin-top:10px;display:grid;gap:8px">
                                    @csrf
                                    <select name="status" style="width:100%;border:1px solid var(--line);border-radius:8px;padding:10px;font-size:14px;background:#fff">
                                        <option value="accepted">採納</option>
                                        <option value="resolved">已處理</option>
                                        <option value="observing">觀察中</option>
                                        <option value="rejected">拒絕</option>
                                    </select>
                                    <textarea name="codex_feedback" rows="3" placeholder="寫下處理原因，這會成為我回饋給代理人的紀錄。" required style="width:100%;border:1px solid var(--line);border-radius:8px;padding:10px;font-size:14px"></textarea>
                                    <input name="memory_title" type="text" placeholder="記憶標題，留空會自動使用案例標題" style="width:100%;border:1px solid var(--line);border-radius:8px;padding:10px;font-size:14px">
                                    <p class="lead" style="font-size:12px;margin:0">送出後會自動寫入學習記憶庫，作為代理人下次判斷依據。</p>
                                    <button class="button" type="submit" style="width:100%">送出回饋</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="panel">
            <h2>最近執行</h2>
            @if ($latestRuns->isEmpty())
                <p class="lead">尚無代理人執行紀錄。</p>
            @else
                <table class="table">
                    <tbody>
                    @foreach ($latestRuns as $run)
                        <tr>
                            <th>
                                {{ $run->role?->name ?? '未知代理人' }}<br>
                                <span class="badge {{ $badgeClass($run->status) }}">{{ $statusLabel[$run->status] ?? $run->status }}</span>
                            </th>
                            <td>
                                發現 {{ $run->findings_count }}｜記憶 {{ $run->memories_count }}<br>
                                <span class="lead" style="font-size:13px">{{ $run->summary ? \Illuminate\Support\Str::limit($run->summary, 110) : '尚無摘要' }}</span><br>
                                <span class="lead" style="font-size:12px">{{ $formatTime($run->started_at ?? $run->created_at) }}</span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    <section class="panel" style="margin-top:16px">
        <h2>最近處理紀錄</h2>
        @if ($reviewedFindings->isEmpty())
            <p class="lead">尚無處理紀錄。待處理發現送出回饋後，會保留在這裡。</p>
        @else
            <table class="table">
                <tbody>
                @foreach ($reviewedFindings as $finding)
                    <tr>
                        <th>
                            {{ $finding->title }}<br>
                            <span class="badge {{ $finding->status === 'accepted' || $finding->status === 'resolved' ? 'red' : 'amber' }}">{{ $statusLabel[$finding->status] ?? $finding->status }}</span>
                        </th>
                        <td>
                            {{ $finding->role?->name ?? '未指定代理人' }}｜{{ $finding->page ?? '全站' }}{{ $finding->symbol ? '｜'.$finding->symbol : '' }}<br>
                            <span class="lead" style="font-size:13px;white-space:pre-line">{{ \Illuminate\Support\Str::limit($finding->codex_feedback ?? '尚無回饋', 220) }}</span><br>
                            <span class="lead" style="font-size:12px">處理 {{ $formatTime($finding->reviewed_at ?? $finding->updated_at) }}</span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="panel" style="margin-top:16px">
        <h2>學習記憶庫</h2>
        @if ($latestMemories->isEmpty())
            <p class="lead">尚無學習記憶。凌晨修正後，我會把採納、拒絕、需觀察的規則寫回這裡。</p>
        @else
            <table class="table">
                <tbody>
                @foreach ($latestMemories as $memory)
                    <tr>
                        <th>
                            {{ $memory->title }}<br>
                            <span class="badge amber">{{ $memory->memory_type }}</span>
                        </th>
                        <td>
                            {{ $memory->role?->name ?? '通用記憶' }}｜信心 {{ $memory->confidence }}%<br>
                            <span class="lead" style="font-size:13px">{{ \Illuminate\Support\Str::limit($memory->rule_summary ?? $memory->codex_feedback ?? '', 150) }}</span><br>
                            <span class="lead" style="font-size:12px">更新 {{ $formatTime($memory->updated_at) }}</span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
