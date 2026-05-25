<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockPrice1d;
use App\Models\SystemLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportTaiwanStocks extends Command
{
    protected $signature = 'market:import-stocks
        {--skip-prices : Only import stock master data}
        {--deactivate-missing : Mark previously imported TWSE/TPEx stocks inactive when absent from official feeds}';

    protected $description = 'Import TWSE and TPEx stock master data and latest daily prices from official exchange feeds.';

    private const TWSE_STOCKS_URL = 'https://openapi.twse.com.tw/v1/opendata/t187ap03_L';

    private const TPEX_STOCKS_URL = 'https://www.tpex.org.tw/openapi/v1/mopsfin_t187ap03_O';

    private const TWSE_DAILY_QUOTES_URL = 'https://www.twse.com.tw/exchangeReport/STOCK_DAY_ALL?response=json';

    private const TWSE_DAILY_QUOTES_OPENAPI_URL = 'https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL';

    private const TPEX_DAILY_QUOTES_URL = 'https://www.tpex.org.tw/openapi/v1/tpex_mainboard_daily_close_quotes';

    public function handle(): int
    {
        $this->info('Importing official TWSE / TPEx stock master data...');

        $activeSymbols = collect()
            ->merge($this->importTwseStocks())
            ->merge($this->importTpexStocks())
            ->unique()
            ->values();

        if ($this->option('deactivate-missing')) {
            Stock::query()
                ->whereIn('market', ['TWSE', 'TPEx'])
                ->whereNotIn('symbol', $activeSymbols)
                ->update(['is_active' => false]);
        }

        if (! $this->option('skip-prices')) {
            $this->info('Importing latest official daily quotes...');
            $this->importTwseDailyQuotes();
            $this->importTpexDailyQuotes();
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function importTwseStocks(): array
    {
        $rows = $this->fetchJson(self::TWSE_STOCKS_URL);
        $symbols = [];

        foreach ($rows as $row) {
            $symbol = trim((string) ($row['公司代號'] ?? ''));

            if (! $this->isCommonStockSymbol($symbol)) {
                continue;
            }

            Stock::query()->updateOrCreate(
                ['symbol' => $symbol],
                [
                    'name' => trim((string) ($row['公司簡稱'] ?? $row['公司名稱'] ?? '')),
                    'market' => 'TWSE',
                    'industry' => trim((string) ($row['產業別'] ?? '')) ?: null,
                    'is_active' => true,
                ],
            );

            $symbols[] = $symbol;
        }

        $this->line('TWSE stocks imported: '.count($symbols));

        return $symbols;
    }

    private function importTpexStocks(): array
    {
        $rows = $this->fetchJson(self::TPEX_STOCKS_URL);
        $symbols = [];

        foreach ($rows as $row) {
            $symbol = trim((string) ($row['SecuritiesCompanyCode'] ?? ''));

            if (! $this->isCommonStockSymbol($symbol)) {
                continue;
            }

            Stock::query()->updateOrCreate(
                ['symbol' => $symbol],
                [
                    'name' => trim((string) ($row['CompanyAbbreviation'] ?? $row['CompanyName'] ?? '')),
                    'market' => 'TPEx',
                    'industry' => trim((string) ($row['SecuritiesIndustryCode'] ?? '')) ?: null,
                    'is_active' => true,
                ],
            );

            $symbols[] = $symbol;
        }

        $this->line('TPEx stocks imported: '.count($symbols));

        return $symbols;
    }

    private function importTwseDailyQuotes(): void
    {
        $payload = $this->fetchJson(self::TWSE_DAILY_QUOTES_URL);
        $rows = $this->twseDailyRows($payload);
        $source = 'twse_main';

        if ($rows === []) {
            $this->warn('TWSE main site daily quotes were empty; falling back to TWSE OpenAPI.');
            $rows = $this->fetchJson(self::TWSE_DAILY_QUOTES_OPENAPI_URL);
            $source = 'twse_openapi';
        }

        $result = $this->upsertDailyQuoteRows(
            rows: collect($rows),
            market: 'TWSE',
            source: $source,
            symbolResolver: fn (array $row): string => trim((string) ($row['Code'] ?? '')),
            dateResolver: fn (array $row): CarbonImmutable => $this->parseRocDate((string) ($row['Date'] ?? '')),
            valuesResolver: fn (array $row): array => [
                'open' => $this->decimal($row['OpeningPrice'] ?? null),
                'high' => $this->decimal($row['HighestPrice'] ?? null),
                'low' => $this->decimal($row['LowestPrice'] ?? null),
                'close' => $this->decimal($row['ClosingPrice'] ?? null),
                'change' => $this->decimal($row['Change'] ?? null),
                'change_pct' => null,
                'volume' => $this->integer($row['TradeVolume'] ?? null),
                'turnover' => $this->integer($row['TradeValue'] ?? null),
            ],
        );

        $this->line(sprintf(
            'TWSE daily quotes imported: %d; skipped invalid: %d; missing active: %d',
            $result['imported'],
            $result['skipped_invalid'],
            $result['missing_active'],
        ));
    }

    private function twseDailyRows(array $payload): array
    {
        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            return $payload;
        }

        $tradeDate = $this->parseTwseDate((string) ($payload['date'] ?? ''));

        return collect($payload['data'])
            ->map(function (array $row) use ($tradeDate): array {
                return [
                    'Code' => $row[0] ?? null,
                    'Date' => $tradeDate->format('Ymd'),
                    'TradeVolume' => $row[2] ?? null,
                    'TradeValue' => $row[3] ?? null,
                    'OpeningPrice' => $row[4] ?? null,
                    'HighestPrice' => $row[5] ?? null,
                    'LowestPrice' => $row[6] ?? null,
                    'ClosingPrice' => $row[7] ?? null,
                    'Change' => $row[8] ?? null,
                ];
            })
            ->all();
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return array{imported:int, skipped_invalid:int, missing_active:int}
     */
    private function upsertDailyQuoteRows(Collection $rows, string $market, string $source, callable $symbolResolver, callable $dateResolver, callable $valuesResolver): array
    {
        $activeSymbols = Stock::query()
            ->where('market', $market)
            ->where('is_active', true)
            ->pluck('id', 'symbol');
        $seenSymbols = collect();
        $quoteDates = collect();
        $invalidOhlcSymbols = collect();
        $imported = 0;
        $skippedInvalid = 0;

        foreach ($rows as $row) {
            $symbol = $symbolResolver($row);

            if (! $this->isCommonStockSymbol($symbol) || ! $activeSymbols->has($symbol)) {
                continue;
            }

            $values = $valuesResolver($row);

            if ($values['open'] === null || $values['high'] === null || $values['low'] === null || $values['close'] === null) {
                $invalidOhlcSymbols->push($symbol);
                $skippedInvalid++;
                continue;
            }

            $tradeDate = $dateResolver($row);
            $seenSymbols->push($symbol);
            $quoteDates->push($tradeDate->toDateString());

            StockPrice1d::query()->updateOrCreate(
                [
                    'stock_id' => $activeSymbols[$symbol],
                    'trade_date' => $tradeDate,
                ],
                $values,
            );

            $imported++;
        }

        $missingActive = $activeSymbols->keys()->diff($seenSymbols->unique())->values();
        $latestDate = $quoteDates->count() > 0 ? $quoteDates->max() : null;

        $this->logFeedCoverage($source, $market, $latestDate, $imported, $skippedInvalid, $missingActive, $invalidOhlcSymbols);

        return [
            'imported' => $imported,
            'skipped_invalid' => $skippedInvalid,
            'missing_active' => $missingActive->count(),
        ];
    }

    private function logFeedCoverage(string $source, string $market, ?string $latestDate, int $imported, int $skippedInvalid, Collection $missingActive, Collection $invalidOhlcSymbols): void
    {
        SystemLog::query()->create([
            'level' => $missingActive->isEmpty() && $skippedInvalid === 0 ? 'info' : 'warning',
            'source' => 'market_data_feed',
            'message' => sprintf('%s %s daily quote coverage: %d imported', $market, $latestDate ?? '-', $imported),
            'context' => [
                'source' => $source,
                'market' => $market,
                'date' => $latestDate,
                'imported' => $imported,
                'skipped_invalid_ohlc' => $skippedInvalid,
                'invalid_ohlc_symbols' => $invalidOhlcSymbols->unique()->take(30)->values()->all(),
                'missing_active_count' => $missingActive->count(),
                'missing_active_symbols' => $missingActive->take(30)->values()->all(),
                'first_seen_key' => $source.':'.$market.':'.$latestDate,
            ],
        ]);

        if ($latestDate !== null) {
            $firstSeenKey = $source.':'.$market.':'.$latestDate;
            $exists = DB::table('system_logs')
                ->where('source', 'market_data_first_seen')
                ->where('message', $firstSeenKey)
                ->exists();

            if (! $exists) {
                DB::table('system_logs')->insert([
                    'level' => 'info',
                    'source' => 'market_data_first_seen',
                    'message' => $firstSeenKey,
                    'context' => json_encode([
                        'source' => $source,
                        'market' => $market,
                        'date' => $latestDate,
                        'imported' => $imported,
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function importTpexDailyQuotes(): void
    {
        $rows = $this->fetchJson(self::TPEX_DAILY_QUOTES_URL);
        $result = $this->upsertDailyQuoteRows(
            rows: collect($rows),
            market: 'TPEx',
            source: 'tpex_openapi',
            symbolResolver: fn (array $row): string => trim((string) ($row['SecuritiesCompanyCode'] ?? '')),
            dateResolver: fn (array $row): CarbonImmutable => $this->parseRocDate((string) ($row['Date'] ?? '')),
            valuesResolver: fn (array $row): array => [
                'open' => $this->decimal($row['Open'] ?? null),
                'high' => $this->decimal($row['High'] ?? null),
                'low' => $this->decimal($row['Low'] ?? null),
                'close' => $this->decimal($row['Close'] ?? null),
                'change' => $this->decimal($row['Change'] ?? null),
                'change_pct' => null,
                'volume' => $this->integer($row['TradingShares'] ?? null),
                'turnover' => $this->integer($row['TransactionAmount'] ?? null),
            ],
        );

        $this->line(sprintf(
            'TPEx daily quotes imported: %d; skipped invalid: %d; missing active: %d',
            $result['imported'],
            $result['skipped_invalid'],
            $result['missing_active'],
        ));
    }

    private function fetchJson(string $url): array
    {
        $response = Http::retry(3, 500)
            ->timeout(30)
            ->acceptJson()
            ->get($url);

        if (! $response->ok()) {
            $response->throw();
        }

        return $response->json() ?? [];
    }

    private function isCommonStockSymbol(string $symbol): bool
    {
        return preg_match('/^\d{4}$/', $symbol) === 1;
    }

    private function parseRocDate(string $value): CarbonImmutable
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches)) {
            return CarbonImmutable::create(
                (int) $matches[1],
                (int) $matches[2],
                (int) $matches[3],
            )->startOfDay();
        }

        if (! preg_match('/^(\d{3})(\d{2})(\d{2})$/', $value, $matches)) {
            throw new \InvalidArgumentException('Invalid ROC date: '.$value);
        }

        return CarbonImmutable::create(
            ((int) $matches[1]) + 1911,
            (int) $matches[2],
            (int) $matches[3],
        )->startOfDay();
    }

    private function parseTwseDate(string $value): CarbonImmutable
    {
        $value = trim($value);

        if (! preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches)) {
            throw new \InvalidArgumentException('Invalid TWSE date: '.$value);
        }

        return CarbonImmutable::create(
            (int) $matches[1],
            (int) $matches[2],
            (int) $matches[3],
        )->startOfDay();
    }

    private function decimal(mixed $value): ?string
    {
        $normalized = $this->normalizeNumber($value);

        return $normalized === null ? null : (string) $normalized;
    }

    private function integer(mixed $value): ?int
    {
        $normalized = $this->normalizeNumber($value);

        return $normalized === null ? null : (int) round($normalized);
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
