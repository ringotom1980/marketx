<?php

namespace App\Console\Commands;

use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportFinMindFinancialHistory extends Command
{
    protected $signature = 'market:import-finmind-financial-history
        {--start=2016-01-01 : Start date for historical financial data}
        {--symbol= : Import one stock symbol only}
        {--limit=0 : Limit number of stocks, 0 means all}
        {--sleep=13 : Seconds to sleep between stocks to respect free API limits}
        {--token= : FinMind API token, optional}';

    protected $description = 'Backfill long-term EPS, gross margin, operating margin and ROE from FinMind free per-stock datasets.';

    private const API_URL = 'https://api.finmindtrade.com/api/v4/data';

    public function handle(): int
    {
        $query = Stock::query()
            ->where('is_active', true)
            ->whereRaw("symbol ~ '^[0-9]{4}$'")
            ->orderBy('symbol');

        if ($this->option('symbol')) {
            $query->where('symbol', (string) $this->option('symbol'));
        }

        if ((int) $this->option('limit') > 0) {
            $query->limit((int) $this->option('limit'));
        }

        $stocks = $query->get(['id', 'symbol', 'name']);
        $sleep = max(0, (int) $this->option('sleep'));
        $imported = 0;
        $skipped = 0;

        $this->info('FinMind financial history backfill stocks: '.$stocks->count());

        foreach ($stocks as $index => $stock) {
            try {
                $count = $this->importStock($stock->id, $stock->symbol);
                $imported += $count;
                $this->line(sprintf(
                    '[%d/%d] %s %s rows=%d',
                    $index + 1,
                    $stocks->count(),
                    $stock->symbol,
                    $stock->name,
                    $count,
                ));
            } catch (\Throwable $exception) {
                $skipped++;
                $this->warn(sprintf('[%d/%d] %s failed: %s', $index + 1, $stocks->count(), $stock->symbol, $exception->getMessage()));
            }

            if ($sleep > 0 && $index < $stocks->count() - 1) {
                sleep($sleep);
            }
        }

        $this->info('Financial history rows upserted: '.$imported);
        $this->line('Stocks failed/skipped: '.$skipped);

        return self::SUCCESS;
    }

    private function importStock(int $stockId, string $symbol): int
    {
        $financialRows = $this->fetch('TaiwanStockFinancialStatements', $symbol);
        $balanceRows = $this->fetch('TaiwanStockBalanceSheet', $symbol);

        if ($financialRows === [] && $balanceRows === []) {
            return 0;
        }

        $financialByDate = $this->groupByDate($financialRows);
        $balanceByDate = $this->groupByDate($balanceRows);
        $dates = collect(array_unique(array_merge(array_keys($financialByDate), array_keys($balanceByDate))))
            ->sort()
            ->values();

        $count = 0;

        foreach ($dates as $date) {
            $income = $financialByDate[$date] ?? [];
            $balance = $balanceByDate[$date] ?? [];
            $period = $this->period((string) $date);

            $revenue = $income['Revenue'] ?? null;
            $grossProfit = $income['GrossProfit'] ?? null;
            $operatingIncome = $income['OperatingIncome'] ?? null;
            $netIncome = $income['NetIncome'] ?? $income['IncomeAfterTaxes'] ?? null;
            $equity = $balance['EquityAttributableToOwnersOfParent'] ?? $balance['Equity'] ?? null;
            $eps = $income['EPS'] ?? null;

            if ($eps === null && $revenue === null && $grossProfit === null && $operatingIncome === null && $netIncome === null && $equity === null) {
                continue;
            }

            $existing = DB::table('stock_financials')
                ->where('stock_id', $stockId)
                ->where('period', $period)
                ->first();

            $rawPayload = $existing?->raw_payload ? json_decode($existing->raw_payload, true) : [];
            $rawPayload = is_array($rawPayload) ? $rawPayload : [];
            $rawPayload['finmind_financial_history'] = [
                'source' => 'FinMind',
                'date' => $date,
                'income' => $income,
                'balance' => [
                    'EquityAttributableToOwnersOfParent' => $balance['EquityAttributableToOwnersOfParent'] ?? null,
                    'Equity' => $balance['Equity'] ?? null,
                ],
            ];

            DB::table('stock_financials')->updateOrInsert(
                ['stock_id' => $stockId, 'period' => $period],
                [
                    'eps' => $eps ?? $existing?->eps,
                    'roe' => ($netIncome !== null && $equity) ? round(($netIncome / $equity) * 100, 4) : $existing?->roe,
                    'gross_margin' => ($grossProfit !== null && $revenue) ? round(($grossProfit / $revenue) * 100, 4) : $existing?->gross_margin,
                    'operating_margin' => ($operatingIncome !== null && $revenue) ? round(($operatingIncome / $revenue) * 100, 4) : $existing?->operating_margin,
                    'per' => $existing?->per,
                    'dividend_yield' => $existing?->dividend_yield ?? null,
                    'pb_ratio' => $existing?->pb_ratio ?? null,
                    'raw_payload' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $count++;
        }

        return $count;
    }

    private function fetch(string $dataset, string $symbol): array
    {
        $params = [
            'dataset' => $dataset,
            'data_id' => $symbol,
            'start_date' => (string) $this->option('start'),
        ];

        $token = $this->option('token') ?: env('FINMIND_TOKEN');
        $request = Http::retry(2, 1000)->timeout(45);

        if ($token) {
            $request = $request->withToken((string) $token);
        }

        $response = $request->get(self::API_URL, $params);

        if ($response->status() === 402) {
            throw new \RuntimeException('FinMind API quota exceeded');
        }

        $json = $response->throw()->json();

        if (($json['status'] ?? null) !== 200) {
            throw new \RuntimeException((string) ($json['msg'] ?? 'FinMind request failed'));
        }

        return $json['data'] ?? [];
    }

    private function groupByDate(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $date = (string) ($row['date'] ?? '');
            $type = (string) ($row['type'] ?? '');

            if ($date === '' || $type === '') {
                continue;
            }

            $grouped[$date][$type] = $this->number($row['value'] ?? null);
        }

        return $grouped;
    }

    private function period(string $date): string
    {
        $date = CarbonImmutable::parse($date, 'Asia/Taipei');
        $quarter = (int) ceil($date->month / 3);

        return sprintf('%dQ%d', $date->year, $quarter);
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
