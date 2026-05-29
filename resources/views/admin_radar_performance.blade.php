@extends('welcome')

@php
    $fmtPct = fn ($value) => $value === null ? '無資料' : number_format((float) $value, 2).'%';
    $tone = fn ($value) => $value === null ? 'amber' : ((float) $value > 0 ? 'red' : ((float) $value < 0 ? 'green' : 'amber'));
@endphp

@section('content')
    <section class="page-head">
        <div>
            <h1>五張卡片績效驗證</h1>
            <p class="lead">績效以「被選入卡片當日收盤價」起算，追蹤後續 1、3、5 日累積漲跌幅，直到條件消失為止。</p>
        </div>
        <a class="button ghost" href="/admin">回後台</a>
    </section>

    <section class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
        @foreach ($horizonStats as $type => $card)
            @php
                $condition = $conditionStats->get($type);
                $activeRate = $condition && (int) $condition->total > 0
                    ? round(((int) $condition->active_count / (int) $condition->total) * 100, 1)
                    : null;
            @endphp
            <article class="panel">
                <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start">
                    <h2>{{ $card['label'] }}</h2>
                    <span class="badge {{ $tone($condition->tracked_avg_change_pct ?? null) }}">{{ $fmtPct($condition->tracked_avg_change_pct ?? null) }}</span>
                </div>
                <p class="lead" style="font-size:13px;margin-top:4px">
                    累積 {{ $condition->total ?? 0 }} 檔，條件維持 {{ $activeRate === null ? '無資料' : $activeRate.'%' }}
                </p>
                <div style="display:grid;gap:10px;margin-top:14px">
                    @foreach ([1, 3, 5] as $day)
                        @php $row = $card['horizons']->get($day); @endphp
                        <div style="border-top:1px solid var(--line);padding-top:10px">
                            <div style="display:flex;justify-content:space-between;gap:12px">
                                <strong>{{ $day }} 日後累積</strong>
                                <strong class="{{ $tone($row->avg_change_pct ?? null) }}">{{ $fmtPct($row->avg_change_pct ?? null) }}</strong>
                            </div>
                            <p class="lead" style="font-size:12px">
                                有效 {{ $row->valid_count ?? 0 }} / {{ $row->total ?? 0 }}，
                                上漲 {{ $row->up_count ?? 0 }}，
                                下跌 {{ $row->down_count ?? 0 }}，
                                最大 {{ $fmtPct($row->max_change_pct ?? null) }}，
                                最小 {{ $fmtPct($row->min_change_pct ?? null) }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </article>
        @endforeach
    </section>

    <section class="panel" style="margin-top:16px">
        <h2>原因標籤勝率</h2>
        @if ($reasonStats->isEmpty())
            <p class="lead">目前追蹤天數還太少，尚未累積原因標籤統計。</p>
        @else
            <table class="table">
                <tbody>
                @foreach ($reasonStats as $reason)
                    <tr>
                        <th>
                            {{ $cardLabels[$reason['card_type']] ?? $reason['card_type'] }}<br>
                            <span class="badge amber">{{ $reason['label'] }}</span>
                        </th>
                        <td>
                            選入後 1 日平均 <strong class="{{ $tone($reason['avg_change_pct']) }}">{{ $fmtPct($reason['avg_change_pct']) }}</strong>，
                            勝率 {{ $reason['win_rate'] === null ? '無資料' : $reason['win_rate'].'%' }}<br>
                            <span class="lead" style="font-size:12px">
                                有效 {{ $reason['valid'] }} / {{ $reason['total'] }}，
                                上漲 {{ $reason['up'] }}，
                                下跌 {{ $reason['down'] }}
                            </span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="panel" style="margin-top:16px">
        <h2>個股追蹤明細</h2>
        @if ($observations->isEmpty())
            <p class="lead">目前尚未建立五張卡片觀察紀錄。</p>
        @else
            <table class="table">
                <tbody>
                @foreach ($observations as $item)
                    @php
                        $check = $item->latest_check;
                        $statusText = $item->status === 'active' ? '條件仍在' : '條件消失';
                    @endphp
                    <tr>
                        <th>
                            {{ $item->name }} {{ $item->symbol }}<br>
                            <span class="lead" style="font-size:12px">{{ $item->selected_date }}｜{{ $cardLabels[$item->card_type] ?? $item->card_type }} #{{ $item->entry_rank }}</span>
                        </th>
                        <td>
                            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px">
                                @foreach ($item->reason_labels as $label)
                                    <span class="pill pill-red">{{ $label }}</span>
                                @endforeach
                            </div>
                            @if ($check)
                                最新追蹤 {{ $check->check_date }}，
                                第 {{ $check->days_since_selected }} 天，
                                收盤 {{ $check->close ?? '無資料' }}，
                                累積漲跌幅 <strong class="{{ $tone($check->change_pct) }}">{{ $fmtPct($check->change_pct) }}</strong><br>
                                <span class="lead" style="font-size:12px">{{ $statusText }}，累積有效檢查 {{ $item->performance['valid_checks'] ?? 0 }} 次</span>
                            @else
                                <span class="lead">尚未有追蹤價格。</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
