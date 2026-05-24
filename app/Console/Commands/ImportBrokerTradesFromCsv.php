<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportBrokerTradesFromCsv extends Command
{
    protected $signature = 'market:import-broker-trades-csv
        {file : Official broker branch daily trade CSV path}
        {--market=TWSE : Market label, TWSE or TPEx}
        {--date= : Trade date YYYY-MM-DD when the CSV does not contain a date column}';

    protected $description = 'Import official broker branch stock buy/sell daily report CSV.';

    public function handle(): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            throw new RuntimeException('CSV file not found: '.$file);
        }

        $rows = $this->readCsv($file);

        if ($rows === []) {
            $this->warn('CSV has no rows.');

            return self::SUCCESS;
        }

        $header = array_map(fn ($value) => $this->normalizeHeader($value), array_shift($rows));
        $market = strtoupper((string) $this->option('market'));
        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $record = $this->associate($header, $row);
            $symbol = $this->pick($record, ['stock_symbol', 'symbol', '證券代號', '股票代號', '代號']);
            $brokerCode = $this->pick($record, ['broker_code', 'branch_code', '券商代號', '證商代號', '分點代號']);
            $brokerName = $this->pick($record, ['broker_name', 'branch_name', '券商名稱', '證商名稱', '分點名稱']);
            $buyVolume = $this->integer($this->pick($record, ['buy_volume', 'buy', '買進股數', '買進', '買進張數']));
            $sellVolume = $this->integer($this->pick($record, ['sell_volume', 'sell', '賣出股數', '賣出', '賣出張數']));
            $dateValue = $this->pick($record, ['trade_date', 'date', '交易日期', '日期']) ?: $this->option('date');

            if (! $symbol || ! $brokerCode || ! $dateValue) {
                $skipped++;
                continue;
            }

            $stockId = DB::table('stocks')->where('symbol', $symbol)->value('id');

            if (! $stockId) {
                $skipped++;
                continue;
            }

            $tradeDate = $this->date((string) $dateValue);
            $brokerBranchId = $this->brokerBranchId($market, (string) $brokerCode, $brokerName);

            DB::table('stock_broker_trades_1d')->updateOrInsert(
                [
                    'stock_id' => $stockId,
                    'broker_branch_id' => $brokerBranchId,
                    'trade_date' => $tradeDate->toDateString(),
                ],
                [
                    'buy_volume' => $buyVolume,
                    'sell_volume' => $sellVolume,
                    'net_volume' => $buyVolume - $sellVolume,
                    'raw_payload' => json_encode($record, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $imported++;
        }

        $this->info('Broker branch trades imported: '.$imported);
        $this->line('Rows skipped: '.$skipped);

        return self::SUCCESS;
    }

    private function brokerBranchId(string $market, string $code, ?string $name): int
    {
        DB::table('broker_branches')->updateOrInsert(
            ['market' => $market, 'code' => $code],
            ['name' => $name, 'updated_at' => now(), 'created_at' => now()],
        );

        return (int) DB::table('broker_branches')
            ->where('market', $market)
            ->where('code', $code)
            ->value('id');
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    private function readCsv(string $file): array
    {
        $content = file_get_contents($file);

        if ($content === false) {
            return [];
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'BIG5,CP950,UTF-8');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param array<int, string> $header
     * @param array<int, string|null> $row
     * @return array<string, string|null>
     */
    private function associate(array $header, array $row): array
    {
        $record = [];

        foreach ($header as $index => $key) {
            $record[$key] = isset($row[$index]) ? trim((string) $row[$index]) : null;
        }

        return $record;
    }

    private function normalizeHeader(?string $value): string
    {
        return trim(str_replace(["\u{feff}", ' ', '　'], '', (string) $value));
    }

    /**
     * @param array<string, string|null> $record
     * @param array<int, string> $keys
     */
    private function pick(array $record, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($record[$key]) && trim((string) $record[$key]) !== '') {
                return trim((string) $record[$key]);
            }
        }

        return null;
    }

    private function integer(?string $value): int
    {
        if ($value === null) {
            return 0;
        }

        $value = str_replace([',', '+'], '', trim($value));

        if ($value === '' || $value === '-' || $value === '--') {
            return 0;
        }

        $number = is_numeric($value) ? (float) $value : 0.0;

        return (int) round($number);
    }

    private function date(string $value): CarbonImmutable
    {
        $value = trim($value);

        if (preg_match('/^(\d{3})\/(\d{2})\/(\d{2})$/', $value, $matches)) {
            return CarbonImmutable::create(((int) $matches[1]) + 1911, (int) $matches[2], (int) $matches[3])->startOfDay();
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches)) {
            return CarbonImmutable::create((int) $matches[1], (int) $matches[2], (int) $matches[3])->startOfDay();
        }

        return CarbonImmutable::parse($value, 'Asia/Taipei')->startOfDay();
    }
}
