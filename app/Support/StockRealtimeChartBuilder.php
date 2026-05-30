<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockRealtimeChartBuilder
{
    public function latestSnapshot(string $symbol): ?object
    {
        if (! Schema::hasTable('stock_snapshots')) {
            return null;
        }

        return DB::table('stock_snapshots')
            ->where('symbol', $symbol)
            ->whereNotNull('close')
            ->orderByDesc('snapshot_at')
            ->first();
    }

    public function rows(string $symbol, ?object $latestSnapshot = null): array
    {
        $now = CarbonImmutable::now('Asia/Taipei');
        $today = $now->toDateString();
        $isLiveSession = $now->isWeekday()
            && $now->betweenIncluded($now->setTime(9, 0), $now->setTime(13, 30));
        $snapshotAt = $latestSnapshot?->snapshot_at
            ? CarbonImmutable::parse((string) $latestSnapshot->snapshot_at, 'Asia/Taipei')
            : null;
        $snapshotDate = $snapshotAt?->toDateString();
        $latestKbarDate = Schema::hasTable('stock_kbars_1m')
            ? DB::table('stock_kbars_1m')->where('symbol', $symbol)->max('trade_date')
            : null;

        $kbarRows = $latestKbarDate ? $this->kbarRows($symbol, (string) $latestKbarDate) : [];
        $snapshotRowsFor = fn (string $date): array => $this->snapshotRows($symbol, $date);

        if (! $isLiveSession || $snapshotDate !== $today || ! Schema::hasTable('stock_snapshots')) {
            return $kbarRows ?: ($snapshotDate ? $snapshotRowsFor($snapshotDate) : []);
        }

        $snapshotRows = $snapshotRowsFor($today);
        if ($snapshotRows !== []) {
            $marketOpen = $now->setTime(9, 0)->utc()->timestamp;
            if (($snapshotRows[0]['time'] ?? $marketOpen) > $marketOpen) {
                array_unshift($snapshotRows, array_merge($snapshotRows[0], [
                    'time' => $marketOpen,
                    'label' => '09:00',
                ]));
            }
        }

        return $snapshotRows;
    }

    private function kbarRows(string $symbol, string $date): array
    {
        return DB::table('stock_kbars_1m')
            ->where('symbol', $symbol)
            ->where('trade_date', $date)
            ->whereNotNull('close')
            ->orderBy('minute')
            ->get(['trade_date', 'minute', 'open', 'high', 'low', 'close', 'volume'])
            ->map(function ($row) {
                $at = CarbonImmutable::parse($row->trade_date.' '.$row->minute, 'Asia/Taipei');

                return [
                    'time' => $at->utc()->timestamp,
                    'label' => substr((string) $row->minute, 0, 5),
                    'value' => (float) $row->close,
                    'open' => $row->open === null ? null : (float) $row->open,
                    'high' => $row->high === null ? null : (float) $row->high,
                    'low' => $row->low === null ? null : (float) $row->low,
                    'close' => (float) $row->close,
                    'change' => null,
                    'changePct' => null,
                    'volume' => (int) ($row->volume ?? 0),
                ];
            })
            ->all();
    }

    private function snapshotRows(string $symbol, string $date): array
    {
        return DB::table('stock_snapshots')
            ->where('symbol', $symbol)
            ->whereDate('snapshot_at', $date)
            ->whereNotNull('close')
            ->orderBy('snapshot_at')
            ->limit(720)
            ->get(['snapshot_at', 'open', 'high', 'low', 'close', 'change_price', 'change_rate', 'volume', 'total_volume'])
            ->map(function ($row) {
                $at = CarbonImmutable::parse((string) $row->snapshot_at, 'Asia/Taipei');

                return [
                    'time' => $at->utc()->timestamp,
                    'label' => $at->format('H:i'),
                    'value' => (float) $row->close,
                    'open' => $row->open === null ? null : (float) $row->open,
                    'high' => $row->high === null ? null : (float) $row->high,
                    'low' => $row->low === null ? null : (float) $row->low,
                    'close' => (float) $row->close,
                    'change' => $row->change_price === null ? null : (float) $row->change_price,
                    'changePct' => $row->change_rate === null ? null : (float) $row->change_rate,
                    'volume' => (int) ($row->total_volume ?? $row->volume ?? 0),
                ];
            })
            ->all();
    }
}
