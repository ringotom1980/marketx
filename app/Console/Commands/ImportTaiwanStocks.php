<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockPrice1d;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
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

        if ($rows === []) {
            $this->warn('TWSE main site daily quotes were empty; falling back to TWSE OpenAPI.');
            $rows = $this->fetchJson(self::TWSE_DAILY_QUOTES_OPENAPI_URL);
        }

        $count = 0;

        foreach ($rows as $row) {
            $symbol = trim((string) ($row['Code'] ?? ''));
            $stock = Stock::query()->where('symbol', $symbol)->first();

            if (! $stock) {
                continue;
            }

            StockPrice1d::query()->updateOrCreate(
                [
                    'stock_id' => $stock->id,
                    'trade_date' => $this->parseRocDate((string) ($row['Date'] ?? '')),
                ],
                [
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

            $count++;
        }

        $this->line('TWSE daily quotes imported: '.$count);
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

    private function importTpexDailyQuotes(): void
    {
        $rows = $this->fetchJson(self::TPEX_DAILY_QUOTES_URL);
        $count = 0;

        foreach ($rows as $row) {
            $symbol = trim((string) ($row['SecuritiesCompanyCode'] ?? ''));
            $stock = Stock::query()->where('symbol', $symbol)->first();

            if (! $stock) {
                continue;
            }

            StockPrice1d::query()->updateOrCreate(
                [
                    'stock_id' => $stock->id,
                    'trade_date' => $this->parseRocDate((string) ($row['Date'] ?? '')),
                ],
                [
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

            $count++;
        }

        $this->line('TPEx daily quotes imported: '.$count);
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
