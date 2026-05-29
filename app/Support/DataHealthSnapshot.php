<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DataHealthSnapshot
{
    public function build(): array
    {
        $activeStocks = $this->activeStockCount();

        $items = [
            $this->stockDataset('tw_prices', '台股日K', 'stock_prices_1d', 'trade_date', $activeStocks, '收盤價、成交量、漲跌幅'),
            $this->stockDataset('technical', '技術指標', 'stock_technical_indicators_1d', 'trade_date', $activeStocks, '均線、KD、MACD、RSI、乖離、布林'),
            $this->stockDataset('chips', '籌碼融資', 'stock_chips_1d', 'trade_date', $activeStocks, '三大法人、融資融券與籌碼分數'),
            $this->stockDataset('scores', '信心指數', 'stock_scores', 'score_date', $activeStocks, '個股信心指數與評價方向'),
            $this->stockDataset('financials', '財務資料', 'stock_financials', 'period', $activeStocks, 'EPS、ROE、毛利率、本益比'),
            $this->stockDataset('revenues', '月營收', 'stock_revenues', 'year_month', $activeStocks, '每月營收、月增率、年增率'),
            $this->simpleDataset('themes', '題材熱度', 'theme_scores', 'score_date', '題材分數、升溫降溫與代表股'),
            $this->simpleDataset('radar_cards', '五張卡片', 'stock_radar_cards', 'card_date', '每日 08:30 固定篩選名單'),
            $this->simpleDataset('global_markets', '全球指數', 'global_market_data', 'trade_date', '美股、亞洲、匯率、利率、商品'),
            $this->simpleDataset('global_ai', '全球AI觀察', 'global_ai_reports', 'report_date', '每日全球盤前觀察'),
            $this->simpleDataset('theme_ai', '題材AI觀察', 'theme_ai_reports', 'report_date', '每日題材盤前觀察'),
        ];

        $items = collect($items)->filter()->values()->all();

        return [
            'as_of' => CarbonImmutable::now('Asia/Taipei')->toDateTimeString(),
            'active_stocks' => $activeStocks,
            'summary' => $this->summary($items),
            'items' => $items,
        ];
    }

    private function activeStockCount(): int
    {
        try {
            if (! Schema::hasTable('stocks')) {
                return 0;
            }

            return (int) DB::table('stocks')->where('is_active', true)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function stockDataset(string $key, string $label, string $table, string $dateColumn, int $activeStocks, string $description): ?array
    {
        if (! $this->hasTableAndColumns($table, [$dateColumn, 'updated_at', 'stock_id'])) {
            return null;
        }

        $latest = DB::table($table)->max($dateColumn);
        $updatedAt = DB::table($table)->max('updated_at');
        $latestCount = $latest
            ? (int) DB::table($table)->where($dateColumn, $latest)->distinct('stock_id')->count('stock_id')
            : 0;
        $coverage = $activeStocks > 0 ? (int) round(($latestCount / $activeStocks) * 100) : null;

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'latest' => $latest,
            'updated_at' => $updatedAt,
            'count' => $latestCount,
            'expected' => $activeStocks,
            'coverage' => $coverage,
            'status' => $this->status($latest, $updatedAt, $coverage),
            'status_label' => $this->statusLabel($this->status($latest, $updatedAt, $coverage)),
        ];
    }

    private function simpleDataset(string $key, string $label, string $table, string $dateColumn, string $description): ?array
    {
        if (! $this->hasTableAndColumns($table, [$dateColumn, 'updated_at'])) {
            return null;
        }

        $latest = DB::table($table)->max($dateColumn);
        $updatedAt = DB::table($table)->max('updated_at');
        $count = $latest ? (int) DB::table($table)->where($dateColumn, $latest)->count() : 0;
        $status = $this->status($latest, $updatedAt, $count > 0 ? 100 : 0);

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'latest' => $latest,
            'updated_at' => $updatedAt,
            'count' => $count,
            'expected' => null,
            'coverage' => null,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
        ];
    }

    private function summary(array $items): array
    {
        $counts = collect($items)->countBy('status');
        $latestTaiwan = collect($items)
            ->whereIn('key', ['tw_prices', 'technical', 'chips', 'scores'])
            ->pluck('updated_at')
            ->filter()
            ->max();
        $latestGlobal = collect($items)
            ->whereIn('key', ['global_markets', 'global_ai', 'theme_ai'])
            ->pluck('updated_at')
            ->filter()
            ->max();

        return [
            'ok' => (int) ($counts['ok'] ?? 0),
            'partial' => (int) ($counts['partial'] ?? 0),
            'stale' => (int) ($counts['stale'] ?? 0),
            'missing' => (int) ($counts['missing'] ?? 0),
            'taiwan_updated_at' => $latestTaiwan,
            'global_updated_at' => $latestGlobal,
        ];
    }

    private function status(?string $latest, mixed $updatedAt, ?int $coverage): string
    {
        if (! $latest || ! $updatedAt) {
            return 'missing';
        }

        if ($coverage !== null && $coverage < 70) {
            return 'partial';
        }

        $updated = CarbonImmutable::parse($updatedAt, 'Asia/Taipei');

        if ($updated->lt(CarbonImmutable::now('Asia/Taipei')->subDays(3))) {
            return 'stale';
        }

        return 'ok';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'ok' => '正常',
            'partial' => '待補齊',
            'stale' => '偏舊',
            default => '待更新',
        };
    }

    private function hasTableAndColumns(string $table, array $columns): bool
    {
        try {
            if (! Schema::hasTable($table)) {
                return false;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
