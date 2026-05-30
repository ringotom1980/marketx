<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SponsorDataHealth
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        return collect($this->specs())
            ->map(fn (array $spec) => $this->status($spec))
            ->values()
            ->all();
    }

    /**
     * @return array{ok:int,partial:int,stale:int,missing:int,total:int}
     */
    public function summary(): array
    {
        $items = collect($this->items());

        return [
            'ok' => $items->where('status', 'ok')->count(),
            'partial' => $items->where('status', 'partial')->count(),
            'stale' => $items->where('status', 'stale')->count(),
            'missing' => $items->whereIn('status', ['missing', 'empty'])->count(),
            'total' => $items->count(),
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function specs(): array
    {
        return [
            ['label' => '即時報價快照', 'table' => 'stock_snapshots', 'date_column' => 'snapshot_at', 'symbol_column' => 'symbol', 'source' => 'taiwan_stock_tick_snapshot'],
            ['label' => '個股 1 分K', 'table' => 'stock_kbars_1m', 'date_column' => 'trade_date', 'symbol_column' => 'symbol', 'source' => 'TaiwanStockKBar'],
            ['label' => '個股逐筆成交', 'table' => 'stock_ticks', 'date_column' => 'trade_date', 'symbol_column' => 'symbol', 'source' => 'TaiwanStockPriceTick'],
            ['label' => '分點買賣日報', 'table' => 'stock_broker_trades_1d', 'date_column' => 'trade_date', 'symbol_column' => null, 'source' => 'TaiwanStockTradingDailyReport'],
            ['label' => '分點區間統計', 'table' => 'stock_broker_trade_sec_aggregates', 'date_column' => 'trade_date', 'symbol_column' => 'symbol', 'source' => 'TaiwanStockTradingDailyReportSecIdAgg'],
            ['label' => '八大行庫買賣', 'table' => 'government_bank_trades', 'date_column' => 'trade_date', 'symbol_column' => 'symbol', 'source' => 'TaiwanstockGovernmentBankBuySell'],
            ['label' => '鉅額交易買賣日報', 'table' => 'stock_block_trade_reports', 'date_column' => 'trade_date', 'symbol_column' => 'symbol', 'source' => 'TaiwanStockBlockTradingDailyReport'],
            ['label' => '鉅額交易成交資訊', 'table' => 'stock_block_trades', 'date_column' => 'trade_date', 'symbol_column' => 'symbol', 'source' => 'TaiwanStockBlockTrade'],
            ['label' => '股權持股分級', 'table' => 'stock_holding_shares_levels', 'date_column' => 'trade_date', 'symbol_column' => 'symbol', 'source' => 'TaiwanStockHoldingSharesPer'],
            ['label' => '市值與市值權重', 'table' => 'stock_market_values', 'date_column' => 'trade_date', 'symbol_column' => 'symbol', 'source' => 'TaiwanStockMarketValue / Weight'],
            ['label' => '大盤融資維持率', 'table' => 'market_margin_maintenance', 'date_column' => 'trade_date', 'symbol_column' => null, 'source' => 'TaiwanTotalExchangeMarginMaintenance'],
        ];
    }

    /**
     * @param array<string, string|null> $spec
     * @return array<string, mixed>
     */
    private function status(array $spec): array
    {
        if (! Schema::hasTable($spec['table'])) {
            return $this->row($spec, 'missing', null, 0, 0, '資料表尚未建立');
        }

        $count = DB::table($spec['table'])->count();
        if ($count === 0) {
            return $this->row($spec, 'empty', null, 0, 0, '尚未匯入資料');
        }

        $latest = DB::table($spec['table'])->max($spec['date_column']);
        $symbolCount = $spec['symbol_column']
            ? DB::table($spec['table'])->distinct()->count($spec['symbol_column'])
            : null;
        $latestDate = $latest ? CarbonImmutable::parse((string) $latest, 'Asia/Taipei') : null;
        $ageDays = $latestDate ? $latestDate->diffInDays(CarbonImmutable::now('Asia/Taipei')) : null;
        $status = match (true) {
            $latestDate === null => 'empty',
            $ageDays <= 3 => 'ok',
            $ageDays <= 7 => 'partial',
            default => 'stale',
        };
        $note = match ($status) {
            'ok' => '資料在合理更新範圍內',
            'partial' => '資料略舊，需觀察是否補齊',
            'stale' => '資料過舊，需補跑匯入',
            default => '尚未匯入資料',
        };

        return $this->row($spec, $status, $latestDate, $count, $symbolCount, $note);
    }

    /**
     * @param array<string, string|null> $spec
     * @return array<string, mixed>
     */
    private function row(array $spec, string $status, ?CarbonImmutable $latest, int $count, ?int $symbolCount, string $note): array
    {
        return [
            'label' => $spec['label'],
            'table' => $spec['table'],
            'source' => $spec['source'],
            'status' => $status,
            'status_label' => match ($status) {
                'ok' => '正常',
                'partial' => '需觀察',
                'stale' => '過舊',
                'empty' => '無資料',
                default => '缺資料表',
            },
            'latest_at' => $latest?->format('Y/m/d H:i'),
            'latest_date' => $latest?->toDateString(),
            'count' => $count,
            'symbol_count' => $symbolCount,
            'note' => $note,
        ];
    }
}
