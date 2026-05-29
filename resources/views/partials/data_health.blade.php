@php
    $healthItems = collect($dataHealth['items'] ?? []);
    $summary = $dataHealth['summary'] ?? [];
    $formatDate = static function ($value) {
        if (! $value) {
            return '待更新';
        }

        try {
            return \Carbon\CarbonImmutable::parse($value)->timezone('Asia/Taipei')->format('m/d');
        } catch (\Throwable) {
            return '待更新';
        }
    };
    $formatTime = static function ($value) {
        if (! $value) {
            return '待更新';
        }

        try {
            return \Carbon\CarbonImmutable::parse($value)->timezone('Asia/Taipei')->format('m/d H:i');
        } catch (\Throwable) {
            return '待更新';
        }
    };
@endphp

@if ($healthItems->isNotEmpty())
    <section class="panel data-health-panel">
        <div class="data-health-head">
            <div>
                <h2>資料狀態</h2>
                <p>檢查各資料源最新日期、更新時間與覆蓋率。</p>
            </div>
            <div class="data-health-summary" aria-label="資料狀態統計">
                <span class="ok">正常 {{ $summary['ok'] ?? 0 }}</span>
                <span class="partial">待補 {{ $summary['partial'] ?? 0 }}</span>
                <span class="stale">偏舊 {{ $summary['stale'] ?? 0 }}</span>
                <span class="missing">待更 {{ $summary['missing'] ?? 0 }}</span>
            </div>
        </div>

        <div class="data-health-grid">
            @foreach ($healthItems as $item)
                <article class="data-health-item {{ $item['status'] ?? 'missing' }}">
                    <div class="data-health-title">
                        <strong>{{ $item['label'] }}</strong>
                        <span>{{ $item['status_label'] }}</span>
                    </div>
                    <div class="data-health-meta">
                        <span>資料 {{ $formatDate($item['latest'] ?? null) }}</span>
                        <span>更新 {{ $formatTime($item['updated_at'] ?? null) }}</span>
                    </div>
                    @if (($item['expected'] ?? null) && ($item['coverage'] ?? null) !== null)
                        <div class="data-health-coverage" style="--coverage: {{ max(0, min(100, (int) $item['coverage'])) }}%">
                            <span></span>
                            <b>{{ number_format((int) $item['count']) }} / {{ number_format((int) $item['expected']) }}</b>
                        </div>
                    @else
                        <div class="data-health-count">筆數 {{ number_format((int) ($item['count'] ?? 0)) }}</div>
                    @endif
                    <p>{{ $item['description'] }}</p>
                </article>
            @endforeach
        </div>
    </section>
@endif
