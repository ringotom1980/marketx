<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockPrice1d;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class BackfillTaiwanPrices extends Command
{
    protected $signature = 'market:backfill-prices
        {--symbol= : Backfill one stock symbol}
        {--from= : Start month in YYYY-MM format}
        {--to= : End month in YYYY-MM format}
        {--months=1 : Number of recent months when --from is not provided}
        {--sleep-ms=150 : Delay between official API requests}';

    protected $description = 'Backfill historical daily K data from official TWSE and TPEx monthly quote endpoints.';

    private const TWSE_STOCK_DAY_URL = 'https://www.twse.com.tw/exchangeReport/STOCK_DAY';

    private const TPEX_TRADING_STOCK_URL = 'https://www.tpex.org.tw/www/zh-tw/afterTrading/tradingStock';

    public function handle(): int
    {
        $stocks = $this->stocks();
        $months = $this->months();
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        if ($stocks->isEmpty()) {
            $this->warn('No active stocks found.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Backfilling %d stock(s), %d month(s): %s -> %s',
            $stocks->count(),
            count($months),
            $months[0]->format('Y-m'),
            $months[count($months) - 1]->format('Y-m'),
        ));

        $totalRows = 0;

        foreach ($stocks as $stock) {
            foreach ($months as $month) {
                $count = match ($stock->market) {
                    'TWSE' => $this->backfillTwseMonth($stock, $month),
                    'TPEx' => $this->backfillTpexMonth($stock, $month),
                    default => 0,
                };

                $totalRows += $count;
                $this->line(sprintf('%s %s %s rows: %d', $stock->symbol, $stock->market, $month->format('Y-m'), $count));

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        }

        $this->info('Historical price rows upserted: '.$totalRows);

        return self::SUCCESS;
    }

    private function stocks()
    {
        return Stock::query()
            ->where('is_active', true)
            ->when($this->option('symbol'), fn ($query, $symbol) => $query->where('symbol', $symbol))
            ->whereIn('market', ['TWSE', 'TPEx'])
            ->orderBy('market')
            ->orderBy('symbol')
            ->get();
    }

    /**
     * @return array<int, CarbonImmutable>
     */
    private function months(): array
    {
        if ($this->option('from')) {
            $from = CarbonImmutable::createFromFormat('Y-m', (string) $this->option('from'))->startOfMonth();
            $to = $this->option('to')
                ? CarbonImmutable::createFromFormat('Y-m', (string) $this->option('to'))->startOfMonth()
                : CarbonImmutable::now('Asia/Taipei')->startOfMonth();
        } else {
            $months = max(1, (int) $this->option('months'));
            $to = CarbonImmutable::now('Asia/Taipei')->startOfMonth();
            $from = $to->subMonths($months - 1);
        }

        return collect(CarbonPeriod::create($from, '1 month', $to))
            ->map(fn ($date) => CarbonImmutable::instance($date)->startOfMonth())
            ->values()
            ->all();
    }

    private function backfillTwseMonth(Stock $stock, CarbonImmutable $month): int
    {
        $response = Http::retry(3, 500)
            ->timeout(30)
            ->get(self::TWSE_STOCK_DAY_URL, [
                'response' => 'json',
                'date' => $month->format('Ymd'),
                'stockNo' => $stock->symbol,
            ]);

        if (! $response->ok()) {
            $this->warn("TWSE request failed: {$stock->symbol} {$month->format('Y-m')}");

            return 0;
        }

        $payload = $response->json();

        if (($payload['stat'] ?? null) !== 'OK') {
            return 0;
        }

        $count = 0;

        foreach (($payload['data'] ?? []) as $row) {
            StockPrice1d::query()->updateOrCreate(
                [
                    'stock_id' => $stock->id,
                    'trade_date' => $this->parseSlashRocDate((string) ($row[0] ?? '')),
                ],
                [
                    'volume' => $this->integer($row[1] ?? null),
                    'turnover' => $this->integer($row[2] ?? null),
                    'open' => $this->decimal($row[3] ?? null),
                    'high' => $this->decimal($row[4] ?? null),
                    'low' => $this->decimal($row[5] ?? null),
                    'close' => $this->decimal($row[6] ?? null),
                    'change' => $this->decimal($row[7] ?? null),
                    'change_pct' => null,
                ],
            );

            $count++;
        }

        return $count;
    }

    private function backfillTpexMonth(Stock $stock, CarbonImmutable $month): int
    {
        $response = Http::retry(3, 500)
            ->timeout(30)
            ->get(self::TPEX_TRADING_STOCK_URL, [
                'code' => $stock->symbol,
                'date' => $month->format('Y/m/d'),
                'response' => 'json',
            ]);

        if (! $response->ok()) {
            $this->warn("TPEx request failed: {$stock->symbol} {$month->format('Y-m')}");

            return 0;
        }

        $payload = $response->json();
        $rows = $payload['tables'][0]['data'] ?? [];
        $count = 0;

        foreach ($rows as $row) {
            StockPrice1d::query()->updateOrCreate(
                [
                    'stock_id' => $stock->id,
                    'trade_date' => $this->parseSlashRocDate((string) ($row[0] ?? '')),
                ],
                [
                    'volume' => $this->integer($row[1] ?? null, 1000),
                    'turnover' => $this->integer($row[2] ?? null, 1000),
                    'open' => $this->decimal($row[3] ?? null),
                    'high' => $this->decimal($row[4] ?? null),
                    'low' => $this->decimal($row[5] ?? null),
                    'close' => $this->decimal($row[6] ?? null),
                    'change' => $this->decimal($row[7] ?? null),
                    'change_pct' => null,
                ],
            );

            $count++;
        }

        return $count;
    }

    private function parseSlashRocDate(string $value): CarbonImmutable
    {
        $value = trim($value);

        if (! preg_match('/^(\d{3})\/(\d{2})\/(\d{2})$/', $value, $matches)) {
            throw new \InvalidArgumentException('Invalid ROC date: '.$value);
        }

        return CarbonImmutable::create(
            ((int) $matches[1]) + 1911,
            (int) $matches[2],
            (int) $matches[3],
        )->startOfDay();
    }

    private function decimal(mixed $value): ?string
    {
        $normalized = $this->normalizeNumber($value);

        return $normalized === null ? null : (string) $normalized;
    }

    private function integer(mixed $value, int $multiplier = 1): ?int
    {
        $normalized = $this->normalizeNumber($value);

        return $normalized === null ? null : (int) round($normalized * $multiplier);
    }

    private function normalizeNumber(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace([',', '+'], '', trim((string) $value));

        if ($value === '' || $value === '-' || $value === '--' || $value === 'N/A') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}

