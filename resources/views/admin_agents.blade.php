@extends('welcome')

@php
    $statusLabel = [
        'pending' => '待處理',
        'observing' => '觀察中',
        'accepted' => '已採納',
        'rejected' => '已略過',
        'resolved' => '已處理',
        'success' => '成功',
        'failed' => '失敗',
        'running' => '執行中',
    ];

    $severityLabel = [
        'critical' => '重大',
        'high' => '高',
        'medium' => '中',
        'low' => '低',
        'info' => '資訊',
    ];

    $typeLabel = [
        'data_missing' => '資料缺漏',
        'stale_data' => '資料過舊',
        'classification_mismatch' => '分類矛盾',
        'confidence_mismatch' => '信心矛盾',
        'ai_report_missing' => 'AI 報告缺漏',
        'ai_report_stale' => 'AI 報告時間差',
        'empty_card' => '卡片無資料',
        'local_agent_review' => '本機 AI 複核',
    ];

    $statusClass = function (?string $value) {
        return match ($value) {
            'success', 'resolved', 'accepted' => 'green',
            'failed', 'critical', 'high' => 'red',
            default => 'amber',
        };
    };

    $severityClass = fn (?string $value) => in_array($value, ['critical', 'high'], true) ? 'red' : 'amber';
    $formatTime = fn ($time) => $time ? \Carbon\CarbonImmutable::parse($time)->timezone('Asia/Taipei')->format('m/d H:i') : '無紀錄';
    $formatDuration = fn ($ms) => $ms ? number_format($ms / 1000, 1).' 秒' : '-';
    $caseNo = fn ($finding) => 'AG-'.\Carbon\CarbonImmutable::parse($finding->created_at)->timezone('Asia/Taipei')->format('Ymd').'-'.str_pad((string) $finding->id, 5, '0', STR_PAD_LEFT);
@endphp

@section('content')
    <style>
        .agent-hero {
            display: grid;
            gap: 12px;
        }

        .agent-kpis {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .agent-kpi {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            padding: 14px;
        }

        .agent-kpi strong {
            display: block;
            font-size: 26px;
            line-height: 1.1;
        }

        .agent-kpi span {
            color: var(--muted);
            font-size: 13px;
        }

        .agent-case {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 14px;
            background: #fff;
        }

        .agent-case + .agent-case {
            margin-top: 10px;
        }

        .agent-case-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .agent-case-title {
            margin: 4px 0 6px;
            font-size: 18px;
        }

        .agent-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }

        .agent-note {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.65;
        }

        .agent-run-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            padding: 12px 0;
            border-bottom: 1px solid var(--line);
        }

        .agent-run-row:last-child {
            border-bottom: 0;
        }

        .agent-role-list {
            display: grid;
            gap: 10px;
        }

        .agent-role {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            padding: 12px;
        }

        .agent-role-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .agent-compact-table td,
        .agent-compact-table th {
            font-size: 14px;
            vertical-align: top;
        }

        details.agent-details {
            margin-top: 16px;
        }

        details.agent-details summary {
            cursor: pointer;
            list-style: none;
            font-weight: 800;
            color: var(--ink);
        }

        details.agent-details summary::-webkit-details-marker {
            display: none;
        }

        details.agent-details summary::after {
            content: '展開';
            float: right;
            color: var(--red);
            font-size: 14px;
        }

        details.agent-details[open] summary::after {
            content: '收合';
        }

        @media (min-width: 760px) {
            .agent-hero {
                grid-template-columns: minmax(0, 1.35fr) minmax(300px, .65fr);
                align-items: start;
            }

            .agent-kpis {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .agent-role-list {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>

    <section class="page-head">
        <div>
            <h1>AI 代理人</h1>
        </div>
        <a class="button ghost" href="/admin">回後台</a>
    </section>

    <section class="agent-kpis">
        <div class="agent-kpi">
            <strong>{{ $summary['pending_findings'] }}</strong>
            <span>待處理案件</span>
        </div>
        <div class="agent-kpi">
            <strong>{{ $summary['today_runs'] }}</strong>
            <span>今日巡檢次數</span>
        </div>
        <div class="agent-kpi">
            <strong>{{ $summary['active_roles'] }} / {{ $summary['roles'] }}</strong>
            <span>啟用代理人</span>
        </div>
        <div class="agent-kpi">
            <strong>{{ $summary['active_memories'] }}</strong>
            <span>學習記憶</span>
        </div>
    </section>

    <section class="agent-hero" style="margin-top:16px">
        <div class="panel">
            <h2>需要處理的案件</h2>
            <p class="lead">只列真正待處理的問題。案件編號可以直接拿來跟我說「處理 AG-...」。</p>

            @if ($pendingFindings->isEmpty())
                <div class="agent-case" style="margin-top:12px">
                    <span class="badge green">目前正常</span>
                    <h3 class="agent-case-title">沒有待處理案件</h3>
                    <p class="agent-note">代理人目前沒有留下需要 Codex 修正的問題。若凌晨巡檢發現分類矛盾、資料缺漏或 AI 報告異常，會出現在這裡。</p>
                </div>
            @else
                <div style="margin-top:12px">
                    @foreach ($pendingFindings as $finding)
                        @php
                            $payload = is_array($finding->payload) ? $finding->payload : [];
                            $relatedMemories = $payload['related_memories'] ?? [];
                        @endphp
                        <article class="agent-case">
                            <div class="agent-case-head">
                                <div>
                                    <span class="agent-note">{{ $caseNo($finding) }}</span>
                                    <h3 class="agent-case-title">{{ $finding->title }}</h3>
                                </div>
                                <span class="badge {{ $severityClass($finding->severity) }}">{{ $severityLabel[$finding->severity] ?? $finding->severity }}</span>
                            </div>

                            <p class="agent-note">{{ $finding->description }}</p>

                            @if ($finding->recommendation)
                                <p class="agent-note"><strong>建議處理：</strong>{{ $finding->recommendation }}</p>
                            @endif

                            <div class="agent-meta">
                                <span class="badge amber">{{ $finding->role?->name ?? '未指定代理人' }}</span>
                                <span class="badge amber">{{ $finding->page ?? '全站' }}</span>
                                @if ($finding->symbol)
                                    <span class="badge amber">{{ $finding->symbol }}</span>
                                @endif
                                <span class="badge amber">{{ $typeLabel[$finding->finding_type] ?? $finding->finding_type }}</span>
                                <span class="badge amber">{{ $formatTime($finding->created_at) }}</span>
                            </div>

                            @if (! empty($relatedMemories))
                                <details class="agent-details">
                                    <summary>相關學習記憶</summary>
                                    <div style="display:grid;gap:8px;margin-top:10px">
                                        @foreach (array_slice($relatedMemories, 0, 3) as $memory)
                                            <div style="border-left:3px solid #fecaca;padding-left:10px">
                                                <strong>{{ $memory['title'] ?? '未命名記憶' }}</strong>
                                                <p class="agent-note" style="margin:4px 0 0">{{ $memory['rule_summary'] ?? '' }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </div>

        <aside class="panel">
            <h2>最近執行</h2>
            @if ($latestRuns->isEmpty())
                <p class="lead">尚無代理人執行紀錄。</p>
            @else
                @foreach ($latestRuns->take(8) as $run)
                    <div class="agent-run-row">
                        <div>
                            <strong>{{ $run->role?->name ?? '未知代理人' }}</strong>
                            <p class="agent-note" style="margin:4px 0 0">{{ $run->summary ? \Illuminate\Support\Str::limit($run->summary, 68) : ($run->error_message ? \Illuminate\Support\Str::limit($run->error_message, 68) : '尚無摘要') }}</p>
                            <p class="agent-note" style="margin:4px 0 0">{{ $formatTime($run->started_at ?? $run->created_at) }}｜{{ $formatDuration($run->duration_ms) }}</p>
                        </div>
                        <div style="text-align:right">
                            <span class="badge {{ $statusClass($run->status) }}">{{ $statusLabel[$run->status] ?? $run->status }}</span>
                            <p class="agent-note" style="margin:8px 0 0">{{ $run->findings_count }} 件</p>
                        </div>
                    </div>
                @endforeach
            @endif
        </aside>
    </section>

    <section class="panel" style="margin-top:16px">
        <h2>代理人分工</h2>
        <p class="lead">這裡看每個員工負責什麼，不把所有案件明細塞在一起。</p>
        <div class="agent-role-list" style="margin-top:12px">
            @foreach ($roles as $role)
                <article class="agent-role">
                    <div class="agent-role-top">
                        <div>
                            <strong>{{ $role->name }}</strong>
                            <p class="agent-note" style="margin:4px 0 0">{{ $role->scope }}</p>
                        </div>
                        <span class="badge {{ $role->is_active ? 'green' : 'amber' }}">{{ $role->is_active ? '啟用' : '停用' }}</span>
                    </div>
                    <p class="agent-note" style="margin-top:10px">{{ $role->mission }}</p>
                    <div class="agent-meta">
                        <span class="badge amber">執行 {{ $role->runs_count }}</span>
                        <span class="badge amber">待處理 {{ $role->pending_findings_count }}</span>
                        <span class="badge amber">記憶 {{ $role->memories_count }}</span>
                        <span class="badge amber">最近 {{ $formatTime($role->latest_run?->started_at) }}</span>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <details class="panel agent-details">
        <summary>已處理與觀察中的案件</summary>
        @if ($reviewedFindings->isEmpty())
            <p class="lead" style="margin-top:12px">尚無已處理案件。</p>
        @else
            <table class="table agent-compact-table" style="margin-top:12px">
                <tbody>
                @foreach ($reviewedFindings as $finding)
                    <tr>
                        <th>
                            {{ $caseNo($finding) }}<br>
                            <span class="badge {{ $statusClass($finding->status) }}">{{ $statusLabel[$finding->status] ?? $finding->status }}</span>
                        </th>
                        <td>
                            <strong>{{ $finding->title }}</strong><br>
                            <span class="lead">{{ $finding->role?->name ?? '未指定代理人' }}｜{{ $finding->page ?? '全站' }}{{ $finding->symbol ? '｜'.$finding->symbol : '' }}</span><br>
                            <span class="lead" style="white-space:pre-line">{{ \Illuminate\Support\Str::limit($finding->codex_feedback ?? $finding->description ?? '尚無處理說明', 180) }}</span><br>
                            <span class="lead">{{ $formatTime($finding->reviewed_at ?? $finding->updated_at) }}</span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </details>

    <details class="panel agent-details">
        <summary>學習記憶庫</summary>
        @if ($latestMemories->isEmpty())
            <p class="lead" style="margin-top:12px">尚無學習記憶。</p>
        @else
            <table class="table agent-compact-table" style="margin-top:12px">
                <tbody>
                @foreach ($latestMemories as $memory)
                    <tr>
                        <th>
                            {{ $memory->title }}<br>
                            <span class="badge amber">{{ $memory->memory_type }}</span>
                        </th>
                        <td>
                            <span class="lead">{{ $memory->role?->name ?? '通用記憶' }}｜信心 {{ $memory->confidence }}%</span><br>
                            <span class="lead">{{ \Illuminate\Support\Str::limit($memory->rule_summary ?? $memory->codex_feedback ?? '', 180) }}</span><br>
                            <span class="lead">更新 {{ $formatTime($memory->updated_at) }}</span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </details>
@endsection
