<?php

namespace App\Console\Commands;

use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportFinMindSponsorData extends Command
{
    protected $signature = 'market:import-finmind-sponsor
        {dataset : snapshot|futures-snapshot|stock-kbar|stock-tick|broker-daily|broker-agg|government-bank|block-report|block-trade|holding-shares|market-value|market-value-weight|margin-maintenance}
        {--symbol= : Stock symbol for per-stock datasets}
        {--broker= : Securities trader id for broker daily report}
        {--date= : Single trade date, defaults to today}
        {--start= : Start date, defaults to date option}
        {--end= : End date, defaults to start option}
        {--limit=0 : Limit per-stock imports, 0 means all requested rows}
        {--sleep=0 : Seconds to sleep between per-stock requests}
        {--token= : FinMind API token, optional}';

    protected $description = 'Import FinMind Sponsor datasets into MarketX normalized tables.';

    private const DATA_URL = 'https://api.finmindtrade.com/api/v4/data';

    private const BROKER_DAILY_URL = 'https://api.finmindtrade.com/api/v4/taiwan_stock_trading_daily_report';

    private const BROKER_AGG_URL = 'https://api.finmindtrade.com/api/v4/taiwan_stock_trading_daily_report_secid_agg';

    private const STOCK_SNAPSHOT_URL = 'https://api.finmindtrade.com/api/v4/taiwan_stock_tick_snapshot';

    private const FUTURES_SNAPSHOT_URL = 'https://api.finmindtrade.com/api/v4/taiwan_futures_snapshot';

    public function handle(): int
    {
        $dataset = (string) $this->argument('dataset');

        $count = match ($dataset) {
            'snapshot' => $this->importSnapshot(),
            'futures-snapshot' => $this->importFuturesSnapshot(),
            'stock-kbar' => $this->importPerStockSingleDay('TaiwanStockKBar', fn (array $rows) => $this->storeKbars($rows)),
            'stock-tick' => $this->importPerStockSingleDay('TaiwanStockPriceTick', fn (array $rows) => $this->storeTicks($rows)),
            'broker-daily' => $this->importBrokerDaily(),
            'broker-agg' => $this->importBrokerAggregate(),
            'government-bank' => $this->storeGovernmentBank($this->fetchData('TaiwanstockGovernmentBankBuySell')),
            'block-report' => $this->storeBlockReports($this->fetchData('TaiwanStockBlockTradingDailyReport')),
            'block-trade' => $this->storeBlockTrades($this->fetchData('TaiwanStockBlockTrade', $this->option('symbol') ?: null)),
            'holding-shares' => $this->importPerStockRange('TaiwanStockHoldingSharesPer', fn (array $rows) => $this->storeHoldingShares($rows)),
            'market-value' => $this->importMarketValue(false),
            'market-value-weight' => $this->importMarketValue(true),
            'margin-maintenance' => $this->storeMarginMaintenance($this->fetchData('TaiwanTotalExchangeMarginMaintenance')),
            default => throw new \InvalidArgumentException('Unsupported Sponsor dataset: '.$dataset),
        };

        $this->info("FinMind Sponsor {$dataset} rows upserted: {$count}");

        return self::SUCCESS;
    }

    private function importSnapshot(): int
    {
        $params = [];

        if ($this->option('symbol')) {
            $params['data_id'] = (string) $this->option('symbol');
        }

        return $this->storeSnapshots($this->fetchSpecial(self::STOCK_SNAPSHOT_URL, $params));
    }

    private function importFuturesSnapshot(): int
    {
        $params = [];

        if ($this->option('symbol')) {
            $params['data_id'] = (string) $this->option('symbol');
        }

        $rows = $this->fetchSpecial(self::FUTURES_SNAPSHOT_URL, $params);

        foreach ($rows as $row) {
            DB::table('global_market_data')->updateOrInsert(
                [
                    'indicator' => (string) ($row['futures_id'] ?? 'TXF'),
                    'trade_date' => $this->dateValue($row['date'] ?? null),
                ],
                [
                    'value' => $this->number($row['close'] ?? null),
                    'change' => $this->number($row['change_price'] ?? null),
                    'change_pct' => $this->number($row['change_rate'] ?? null),
                    'state' => 'futures_snapshot',
                    'source' => 'FinMind Sponsor',
                    'raw_payload' => $this->json($row),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return count($rows);
    }

    private function importPerStockSingleDay(string $dataset, callable $store): int
    {
        $date = $this->date();
        $symbols = $this->symbols();
        $sleep = max(0, (int) $this->option('sleep'));
        $total = 0;

        foreach ($symbols as $index => $symbol) {
            $rows = $this->fetchData($dataset, $symbol, false, ['start_date' => $date]);
            $total += (int) $store($rows);

            $this->line(sprintf('[%d/%d] %s rows=%d', $index + 1, count($symbols), $symbol, count($rows)));

            if ($sleep > 0 && $index < count($symbols) - 1) {
                sleep($sleep);
            }
        }

        return $total;
    }

    private function importPerStockRange(string $dataset, callable $store): int
    {
        $symbols = $this->symbols();
        $sleep = max(0, (int) $this->option('sleep'));
        $total = 0;

        foreach ($symbols as $index => $symbol) {
            $rows = $this->fetchData($dataset, $symbol);
            $total += (int) $store($rows);

            $this->line(sprintf('[%d/%d] %s rows=%d', $index + 1, count($symbols), $symbol, count($rows)));

            if ($sleep > 0 && $index < count($symbols) - 1) {
                sleep($sleep);
            }
        }

        return $total;
    }

    private function importBrokerDaily(): int
    {
        $params = ['date' => $this->date()];

        if ($this->option('symbol')) {
            $params['data_id'] = (string) $this->option('symbol');
        }

        if ($this->option('broker')) {
            $params['securities_trader_id'] = (string) $this->option('broker');
        }

        return $this->storeBrokerDaily($this->fetchSpecial(self::BROKER_DAILY_URL, $params));
    }

    private function importBrokerAggregate(): int
    {
        $symbol = $this->option('symbol');

        if (! $symbol) {
            $this->warn('broker-agg requires --symbol because FinMind endpoint aggregates by one stock.');

            return 0;
        }

        $rows = $this->fetchSpecial(self::BROKER_AGG_URL, [
            'data_id' => (string) $symbol,
            'start_date' => $this->startDate(),
            'end_date' => $this->endDate(),
        ]);

        return $this->storeBrokerAggregate($rows);
    }

    private function importMarketValue(bool $withWeight): int
    {
        $dataset = $withWeight ? 'TaiwanStockMarketValueWeight' : 'TaiwanStockMarketValue';

        if ($this->option('symbol')) {
            return $this->importPerStockRange($dataset, fn (array $rows) => $this->storeMarketValues($rows, $withWeight));
        }

        return $this->storeMarketValues($this->fetchData($dataset), $withWeight);
    }

    private function fetchData(string $dataset, ?string $symbol = null, bool $withRange = true, array $extra = []): array
    {
        $params = ['dataset' => $dataset] + $extra;

        if ($symbol) {
            $params['data_id'] = $symbol;
        }

        if ($withRange) {
            $params['start_date'] ??= $this->startDate();
            $params['end_date'] ??= $this->endDate();
        }

        return $this->fetchSpecial(self::DATA_URL, $params);
    }

    private function fetchSpecial(string $url, array $params): array
    {
        $request = Http::retry(2, 1000)->timeout(60);
        $token = $this->token();

        if ($token !== '') {
            $request = $request->withToken($token);
            $params['token'] ??= $token;
        }

        $response = $request->get($url, $params);

        if ($response->status() === 402) {
            throw new \RuntimeException('FinMind API quota exceeded');
        }

        $json = $response->throw()->json();

        if (($json['status'] ?? null) !== 200) {
            throw new \RuntimeException((string) ($json['msg'] ?? 'FinMind request failed'));
        }

        return $json['data'] ?? [];
    }

    private function storeSnapshots(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $symbol = (string) ($row['stock_id'] ?? '');
            $date = $this->dateTimeValue($row['date'] ?? null);

            if ($symbol === '' || $date === null) {
                continue;
            }

            DB::table('stock_snapshots')->updateOrInsert(
                ['symbol' => $symbol, 'snapshot_at' => $date],
                [
                    'stock_id' => $this->stockId($symbol),
                    'open' => $this->number($row['open'] ?? null),
                    'high' => $this->number($row['high'] ?? null),
                    'low' => $this->number($row['low'] ?? null),
                    'close' => $this->number($row['close'] ?? null),
                    'change_price' => $this->number($row['change_price'] ?? null),
                    'change_rate' => $this->number($row['change_rate'] ?? null),
                    'average_price' => $this->number($row['average_price'] ?? null),
                    'volume' => $this->integer($row['volume'] ?? null),
                    'total_volume' => $this->integer($row['total_volume'] ?? null),
                    'amount' => $this->integer($row['amount'] ?? null),
                    'total_amount' => $this->integer($row['total_amount'] ?? null),
                    'buy_price' => $this->number($row['buy_price'] ?? null),
                    'buy_volume' => $this->integer($row['buy_volume'] ?? null),
                    'sell_price' => $this->number($row['sell_price'] ?? null),
                    'sell_volume' => $this->integer($row['sell_volume'] ?? null),
                    'volume_ratio' => $this->number($row['volume_ratio'] ?? null),
                    'yesterday_volume' => $this->integer($row['yesterday_volume'] ?? null),
                    'tick_type' => isset($row['TickType']) ? (string) $row['TickType'] : null,
                    'raw_payload' => $this->json($row),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function storeKbars(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $symbol = (string) ($row['stock_id'] ?? '');
            $date = $this->dateValue($row['date'] ?? null);
            $minute = $this->timeValue($row['minute'] ?? null);

            if ($symbol === '' || $date === null || $minute === null) {
                continue;
            }

            DB::table('stock_kbars_1m')->updateOrInsert(
                ['symbol' => $symbol, 'trade_date' => $date, 'minute' => $minute],
                [
                    'stock_id' => $this->stockId($symbol),
                    'open' => $this->number($row['open'] ?? null),
                    'high' => $this->number($row['high'] ?? null),
                    'low' => $this->number($row['low'] ?? null),
                    'close' => $this->number($row['close'] ?? null),
                    'volume' => $this->integer($row['volume'] ?? null),
                    'raw_payload' => $this->json($row),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function storeTicks(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $symbol = (string) ($row['stock_id'] ?? '');
            $date = $this->dateValue($row['date'] ?? null);
            $time = $this->timeValue($row['Time'] ?? null);
            $price = $this->number($row['deal_price'] ?? null);

            if ($symbol === '' || $date === null || $time === null) {
                continue;
            }

            DB::table('stock_ticks')->updateOrInsert(
                ['symbol' => $symbol, 'trade_date' => $date, 'trade_time' => $time, 'deal_price' => $price],
                [
                    'stock_id' => $this->stockId($symbol),
                    'volume' => $this->integer($row['volume'] ?? null),
                    'tick_type' => isset($row['TickType']) ? (string) $row['TickType'] : null,
                    'raw_payload' => $this->json($row),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function storeBrokerDaily(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $symbol = (string) ($row['stock_id'] ?? '');
            $date = $this->dateValue($row['date'] ?? null);
            $brokerId = $this->brokerBranchId((string) ($row['securities_trader_id'] ?? ''), $row['securities_trader'] ?? null);
            $stockId = $this->stockId($symbol);

            if ($symbol === '' || $date === null || $brokerId === null || $stockId === null) {
                continue;
            }

            $buy = $this->integer($row['buy'] ?? null) ?? 0;
            $sell = $this->integer($row['sell'] ?? null) ?? 0;

            DB::table('stock_broker_trades_1d')->updateOrInsert(
                ['stock_id' => $stockId, 'broker_branch_id' => $brokerId, 'trade_date' => $date],
                [
                    'buy_volume' => $buy,
                    'sell_volume' => $sell,
                    'net_volume' => $buy - $sell,
                    'raw_payload' => $this->json($row),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function storeBrokerAggregate(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $symbol = (string) ($row['stock_id'] ?? '');
            $date = $this->dateValue($row['date'] ?? null);
            $brokerId = $this->brokerBranchId((string) ($row['securities_trader_id'] ?? ''), $row['securities_trader'] ?? null);

            if ($symbol === '' || $date === null || $brokerId === null) {
                continue;
            }

            $buy = $this->integer($row['buy_volume'] ?? null) ?? 0;
            $sell = $this->integer($row['sell_volume'] ?? null) ?? 0;

            DB::table('stock_broker_trade_sec_aggregates')->updateOrInsert(
                ['symbol' => $symbol, 'broker_branch_id' => $brokerId, 'trade_date' => $date],
                [
                    'stock_id' => $this->stockId($symbol),
                    'buy_volume' => $buy,
                    'sell_volume' => $sell,
                    'buy_price' => $this->number($row['buy_price'] ?? null),
                    'sell_price' => $this->number($row['sell_price'] ?? null),
                    'net_volume' => $buy - $sell,
                    'raw_payload' => $this->json($row),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function storeGovernmentBank(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $symbol = (string) ($row['stock_id'] ?? '');
            $date = $this->dateValue($row['date'] ?? null);
            $bank = (string) ($row['bank_name'] ?? '八大行庫');

            if ($symbol === '' || $date === null) {
                continue;
            }

            $buy = $this->integer($row['buy'] ?? null) ?? 0;
            $sell = $this->integer($row['sell'] ?? null) ?? 0;
            $buyAmount = $this->integer($row['buy_amount'] ?? null) ?? 0;
            $sellAmount = $this->integer($row['sell_amount'] ?? null) ?? 0;

            DB::table('government_bank_trades')->updateOrInsert(
                ['symbol' => $symbol, 'trade_date' => $date, 'bank_name' => $bank],
                [
                    'stock_id' => $this->stockId($symbol),
                    'buy_volume' => $buy,
                    'sell_volume' => $sell,
                    'net_volume' => $buy - $sell,
                    'buy_amount' => $buyAmount,
                    'sell_amount' => $sellAmount,
                    'net_amount' => $buyAmount - $sellAmount,
                    'raw_payload' => $this->json($row),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function storeBlockReports(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $symbol = (string) ($row['stock_id'] ?? '');
            $date = $this->dateValue($row['date'] ?? null);
            $brokerId = $this->brokerBranchId((string) ($row['securities_trader_id'] ?? ''), $row['securities_trader'] ?? null);

            if ($symbol === '' || $date === null) {
                continue;
            }

            DB::table('stock_block_trade_reports')->updateOrInsert(
                [
                    'symbol' => $symbol,
                    'trade_date' => $date,
                    'broker_branch_id' => $brokerId,
                    'trade_type' => (string) ($row['trade_type'] ?? ''),
                    'price' => $this->number($row['price'] ?? null),
                ],
                [
                    'stock_id' => $this->stockId($symbol),
                    'buy_volume' => $this->integer($row['buy'] ?? null) ?? 0,
                    'sell_volume' => $this->integer($row['sell'] ?? null) ?? 0,
                    'raw_payload' => $this->json($row),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function storeBlockTrades(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $symbol = (string) ($row['stock_id'] ?? '');
            $date = $this->dateValue($row['date'] ?? null);

            if ($symbol === '' || $date === null) {
                continue;
            }

            DB::table('stock_block_trades')->updateOrInsert(
                [
                    'symbol' => $symbol,
                    'trade_date' => $date,
                    'trade_type' => (string) ($row['trade_type'] ?? ''),
                    'price' => $this->number($row['price'] ?? null),
                ],
                [
                    'stock_id' => $this->stockId($symbol),
                    'volume' => $this->integer($row['volume'] ?? null) ?? 0,
                    'trading_money' => $this->integer($row['trading_money'] ?? null) ?? 0,
                    'raw_payload' => $this->json($row),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function storeHoldingShares(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $symbol = (string) ($row['stock_id'] ?? '');
            $date = $this->dateValue($row['date'] ?? null);
            $level = (string) ($row['HoldingSharesLevel'] ?? '');

            if ($symbol === '' || $date === null || $level === '') {
                continue;
            }

            DB::table('stock_holding_shares_levels')->updateOrInsert(
                ['symbol' => $symbol, 'trade_date' => $date, 'holding_level' => $level],
                [
                    'stock_id' => $this->stockId($symbol),
                    'people' => $this->integer($row['people'] ?? null),
                    'percent' => $this->number($row['percent'] ?? null),
                    'unit' => isset($row['unit']) ? (string) $row['unit'] : null,
                    'raw_payload' => $this->json($row),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function storeMarketValues(array $rows, bool $withWeight): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $symbol = (string) ($row['stock_id'] ?? '');
            $date = $this->dateValue($row['date'] ?? null);

            if ($symbol === '' || $date === null) {
                continue;
            }

            DB::table('stock_market_values')->updateOrInsert(
                ['symbol' => $symbol, 'trade_date' => $date],
                [
                    'stock_id' => $this->stockId($symbol),
                    'market_value' => $this->integer($row['market_value'] ?? null),
                    'rank' => $this->integer($row['rank'] ?? null),
                    'weight_per' => $this->number($row['weight_per'] ?? null),
                    'market_type' => $withWeight ? (string) ($row['type'] ?? '') : null,
                    'raw_payload' => $this->json($row),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function storeMarginMaintenance(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $date = $this->dateValue($row['date'] ?? null);

            if ($date === null) {
                continue;
            }

            DB::table('market_margin_maintenance')->updateOrInsert(
                ['trade_date' => $date],
                [
                    'total_exchange_margin_maintenance' => $this->number($row['TotalExchangeMarginMaintenance'] ?? null),
                    'raw_payload' => $this->json($row),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function symbols(): array
    {
        if ($this->option('symbol')) {
            return [(string) $this->option('symbol')];
        }

        $query = Stock::query()
            ->where('is_active', true)
            ->whereRaw("symbol ~ '^[0-9]{4}$'")
            ->orderBy('symbol');

        if ((int) $this->option('limit') > 0) {
            $query->limit((int) $this->option('limit'));
        }

        return $query->pluck('symbol')->all();
    }

    private function brokerBranchId(string $code, mixed $name): ?int
    {
        if ($code === '') {
            return null;
        }

        DB::table('broker_branches')->updateOrInsert(
            ['market' => 'TW', 'code' => $code],
            ['name' => $name ? (string) $name : null, 'updated_at' => now(), 'created_at' => now()],
        );

        return (int) DB::table('broker_branches')
            ->where('market', 'TW')
            ->where('code', $code)
            ->value('id');
    }

    private function stockId(string $symbol): ?int
    {
        static $cache = [];

        if (array_key_exists($symbol, $cache)) {
            return $cache[$symbol];
        }

        return $cache[$symbol] = Stock::query()->where('symbol', $symbol)->value('id');
    }

    private function token(): string
    {
        return (string) ($this->option('token') ?: config('services.marketx.finmind_token') ?: env('FINMIND_TOKEN'));
    }

    private function date(): string
    {
        return (string) ($this->option('date') ?: now('Asia/Taipei')->toDateString());
    }

    private function startDate(): string
    {
        return (string) ($this->option('start') ?: $this->date());
    }

    private function endDate(): string
    {
        return (string) ($this->option('end') ?: $this->startDate());
    }

    private function dateValue(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'Asia/Taipei')->toDateString();
    }

    private function dateTimeValue(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        $text = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return now('Asia/Taipei')->toDateTimeString();
        }

        return CarbonImmutable::parse($text, 'Asia/Taipei')->toDateTimeString();
    }

    private function timeValue(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        $text = trim((string) $value);

        if (preg_match('/^\d{2}:\d{2}$/', $text)) {
            return $text.':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $text)) {
            return $text;
        }

        return CarbonImmutable::parse($text, 'Asia/Taipei')->format('H:i:s');
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = str_replace(',', '', (string) $value);

        return is_numeric($value) ? (float) $value : null;
    }

    private function integer(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = str_replace(',', '', (string) $value);

        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function json(array $row): string
    {
        return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
