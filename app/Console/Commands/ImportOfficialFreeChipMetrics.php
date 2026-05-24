<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockChip1d;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportOfficialFreeChipMetrics extends Command
{
    protected $signature = 'market:import-official-chip-metrics
        {--date= : Trade date YYYY-MM-DD. Defaults to latest stock price date.}';

    protected $description = 'Import free official TWSE chip add-ons: day-trading eligibility, lending availability, and foreign holding.';

    private const TWSE_DAY_TRADING_URL = 'https://openapi.twse.com.tw/v1/exchangeReport/TWTB4U';

    private const TWSE_DAY_TRADING_SUSPENSION_URL = 'https://openapi.twse.com.tw/v1/exchangeReport/TWTBAU1';

    private const TWSE_LENDING_AVAILABLE_URL = 'https://openapi.twse.com.tw/v1/SBL/TWT96U';

    private const TWSE_FOREIGN_HOLDING_URL = 'https://www.twse.com.tw/rwd/zh/fund/MI_QFIIS';

    private const TWSE_FOREIGN_SELECT_TYPES = [
        '01', '02', '03', '04', '05', '06', '07', '08',
        '09', '10', '11', '12', '14', '15', '16', '17',
        '18', '20', '21', '22', '23', '24', '25', '26',
        '27', '28', '29', '30', '31', '32', '80',
    ];

    public function handle(): int
    {
        $date = $this->tradeDate();
        $this->info('Importing free official chip metrics for '.$date->toDateString());

        $dayTrading = $this->importDayTradingEligibility($date);
        $suspended = $this->importDayTradingSuspensions($date);
        $lending = $this->importLendingAvailability($date);
        $foreignHolding = $this->importForeignHolding($date);

        $this->line('Day-trading eligibility rows updated: '.$dayTrading);
        $this->line('Day-trading suspension rows updated: '.$suspended);
        $this->line('Lending availability rows updated: '.$lending);
        $this->line('Foreign holding rows updated: '.$foreignHolding);

        return self::SUCCESS;
    }

    private function tradeDate(): CarbonImmutable
    {
        if ($this->option('date')) {
            return CarbonImmutable::parse((string) $this->option('date'), 'Asia/Taipei')->startOfDay();
        }

        $latest = DB::table('stock_prices_1d')->max('trade_date');

        if (! $latest) {
            throw new \RuntimeException('No stock price data available.');
        }

        return CarbonImmutable::parse($latest, 'Asia/Taipei')->startOfDay();
    }

    private function importDayTradingEligibility(CarbonImmutable $date): int
    {
        $rows = Http::retry(3, 500)->timeout(30)->get(self::TWSE_DAY_TRADING_URL)->throw()->json();
        $count = 0;

        foreach ($rows ?? [] as $row) {
            $symbol = trim((string) ($row['Code'] ?? ''));

            if (! $this->isCommonStockSymbol($symbol)) {
                continue;
            }

            $stock = Stock::query()->where('symbol', $symbol)->first();

            if (! $stock) {
                continue;
            }

            $this->chip($stock->id, $date)->fill([
                'day_trade_eligible' => true,
                'day_trade_suspended' => trim((string) ($row['Suspension'] ?? '')) !== '',
            ])->save();

            $count++;
        }

        return $count;
    }

    private function importDayTradingSuspensions(CarbonImmutable $date): int
    {
        $rows = Http::retry(3, 500)->timeout(30)->get(self::TWSE_DAY_TRADING_SUSPENSION_URL)->throw()->json();
        $count = 0;

        foreach ($rows ?? [] as $row) {
            $symbol = trim((string) ($row['Code'] ?? ''));

            if (! $this->isCommonStockSymbol($symbol)) {
                continue;
            }

            $stock = Stock::query()->where('symbol', $symbol)->first();

            if (! $stock) {
                continue;
            }

            $this->chip($stock->id, $date)->fill([
                'day_trade_eligible' => true,
                'day_trade_suspended' => true,
            ])->save();

            $count++;
        }

        return $count;
    }

    private function importLendingAvailability(CarbonImmutable $date): int
    {
        $rows = Http::retry(3, 500)->timeout(30)->get(self::TWSE_LENDING_AVAILABLE_URL)->throw()->json();
        $count = 0;

        foreach ($rows ?? [] as $row) {
            foreach ([
                ['symbol' => $row['TWSECode'] ?? null, 'volume' => $row['TWSEAvailableVolume'] ?? null],
                ['symbol' => $row['GRETAICode'] ?? null, 'volume' => $row['GRETAIAvailableVolume'] ?? null],
            ] as $item) {
                $symbol = trim((string) ($item['symbol'] ?? ''));

                if (! $this->isCommonStockSymbol($symbol)) {
                    continue;
                }

                $stock = Stock::query()->where('symbol', $symbol)->first();

                if (! $stock) {
                    continue;
                }

                $this->chip($stock->id, $date)->fill([
                    'lending_available_volume' => $this->integer($item['volume'] ?? null),
                ])->save();

                $count++;
            }
        }

        return $count;
    }

    private function importForeignHolding(CarbonImmutable $date): int
    {
        $count = 0;
        $seen = [];

        foreach (self::TWSE_FOREIGN_SELECT_TYPES as $selectType) {
            $payload = Http::retry(3, 500)
                ->timeout(30)
                ->get(self::TWSE_FOREIGN_HOLDING_URL, [
                    'response' => 'json',
                    'date' => $date->format('Ymd'),
                    'selectType' => $selectType,
                ])
                ->throw()
                ->json();

            if (($payload['stat'] ?? null) !== 'OK') {
                continue;
            }

            foreach (($payload['data'] ?? []) as $row) {
                $symbol = trim((string) ($row[0] ?? ''));

                if (isset($seen[$symbol]) || ! $this->isCommonStockSymbol($symbol)) {
                    continue;
                }

                $stock = Stock::query()->where('symbol', $symbol)->where('market', 'TWSE')->first();

                if (! $stock) {
                    continue;
                }

                $chip = $this->chip($stock->id, $date);
                $rawPayload = $chip->raw_payload ?? [];
                $rawPayload['foreign_holding'] = ['source' => 'TWSE_MI_QFIIS', 'selectType' => $selectType, 'row' => $row];

                $chip->fill([
                    'foreign_available_shares' => $this->integer($row[4] ?? null),
                    'foreign_held_shares' => $this->integer($row[5] ?? null),
                    'foreign_available_ratio' => $this->decimal($row[6] ?? null),
                    'foreign_held_ratio' => $this->decimal($row[7] ?? null),
                    'raw_payload' => $rawPayload,
                ])->save();

                $seen[$symbol] = true;
                $count++;
            }
        }

        return $count;
    }

    private function chip(int $stockId, CarbonImmutable $date): StockChip1d
    {
        return StockChip1d::query()->firstOrNew([
            'stock_id' => $stockId,
            'trade_date' => $date,
        ]);
    }

    private function isCommonStockSymbol(string $symbol): bool
    {
        return preg_match('/^\d{4}$/', $symbol) === 1;
    }

    private function integer(mixed $value): ?int
    {
        $normalized = $this->normalizeNumber($value);

        return $normalized === null ? null : (int) round($normalized);
    }

    private function decimal(mixed $value): ?string
    {
        $normalized = $this->normalizeNumber($value);

        return $normalized === null ? null : (string) $normalized;
    }

    private function normalizeNumber(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace([',', '+'], '', trim((string) $value));

        if ($value === '' || $value === '-' || $value === '--') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
