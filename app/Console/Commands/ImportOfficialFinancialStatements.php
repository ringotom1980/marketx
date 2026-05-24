<?php

namespace App\Console\Commands;

use App\Models\Stock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportOfficialFinancialStatements extends Command
{
    protected $signature = 'market:import-official-financials';

    protected $description = 'Import free official financial statement metrics from TWSE OpenAPI/MOPS open data.';

    private const BASE_URL = 'https://openapi.twse.com.tw/v1';

    private const INCOME_PATHS = [
        '/opendata/t187ap06_L_ci',
        '/opendata/t187ap06_L_basi',
        '/opendata/t187ap06_L_bd',
        '/opendata/t187ap06_L_fh',
        '/opendata/t187ap06_L_ins',
        '/opendata/t187ap06_L_mim',
        '/opendata/t187ap06_X_ci',
        '/opendata/t187ap06_X_basi',
        '/opendata/t187ap06_X_bd',
        '/opendata/t187ap06_X_fh',
        '/opendata/t187ap06_X_ins',
        '/opendata/t187ap06_X_mim',
    ];

    private const BALANCE_PATHS = [
        '/opendata/t187ap07_L_ci',
        '/opendata/t187ap07_L_basi',
        '/opendata/t187ap07_L_bd',
        '/opendata/t187ap07_L_fh',
        '/opendata/t187ap07_L_ins',
        '/opendata/t187ap07_L_mim',
        '/opendata/t187ap07_X_ci',
        '/opendata/t187ap07_X_basi',
        '/opendata/t187ap07_X_bd',
        '/opendata/t187ap07_X_fh',
        '/opendata/t187ap07_X_ins',
        '/opendata/t187ap07_X_mim',
    ];

    public function handle(): int
    {
        $this->info('Importing official financial statement metrics...');

        $balances = $this->balanceRows();
        $imported = 0;
        $skipped = 0;

        foreach (self::INCOME_PATHS as $path) {
            $rows = $this->rows($path);

            foreach ($rows as $row) {
                $symbol = trim((string) ($row['公司代號'] ?? ''));

                if (preg_match('/^\d{4}$/', $symbol) !== 1) {
                    $skipped++;
                    continue;
                }

                $stockId = Stock::query()->where('symbol', $symbol)->value('id');

                if (! $stockId) {
                    $skipped++;
                    continue;
                }

                $period = $this->period($row);
                $revenue = $this->number($row['營業收入'] ?? null);
                $grossProfit = $this->number($row['營業毛利（毛損）'] ?? $row['營業毛利（毛損）淨額'] ?? null);
                $operatingProfit = $this->number($row['營業利益（損失）'] ?? null);
                $netIncome = $this->number($row['淨利（淨損）歸屬於母公司業主'] ?? $row['本期淨利（淨損）'] ?? null);
                $equity = $balances[$symbol][$period]['equity'] ?? null;

                $grossMargin = $revenue && $grossProfit !== null ? ($grossProfit / $revenue) * 100 : null;
                $operatingMargin = $revenue && $operatingProfit !== null ? ($operatingProfit / $revenue) * 100 : null;
                $roe = $equity && $netIncome !== null ? ($netIncome / $equity) * 100 : null;
                $eps = $this->number($row['基本每股盈餘（元）'] ?? null);

                $existing = DB::table('stock_financials')
                    ->where('stock_id', $stockId)
                    ->where('period', $period)
                    ->first();
                $rawPayload = $existing && $existing->raw_payload ? json_decode($existing->raw_payload, true) : [];
                $rawPayload['financial_statement'] = ['income' => $row, 'balance' => $balances[$symbol][$period]['row'] ?? null];

                DB::table('stock_financials')->updateOrInsert(
                    ['stock_id' => $stockId, 'period' => $period],
                    [
                        'eps' => $eps ?? $existing?->eps,
                        'roe' => $roe === null ? $existing?->roe : round($roe, 4),
                        'gross_margin' => $grossMargin === null ? $existing?->gross_margin : round($grossMargin, 4),
                        'operating_margin' => $operatingMargin === null ? $existing?->operating_margin : round($operatingMargin, 4),
                        'per' => $existing?->per,
                        'dividend_yield' => $existing?->dividend_yield ?? null,
                        'pb_ratio' => $existing?->pb_ratio ?? null,
                        'raw_payload' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );

                $imported++;
            }
        }

        $this->info('Financial statement rows imported: '.$imported);
        $this->line('Rows skipped: '.$skipped);

        return self::SUCCESS;
    }

    private function balanceRows(): array
    {
        $balances = [];

        foreach (self::BALANCE_PATHS as $path) {
            foreach ($this->rows($path) as $row) {
                $symbol = trim((string) ($row['公司代號'] ?? ''));

                if (preg_match('/^\d{4}$/', $symbol) !== 1) {
                    continue;
                }

                $period = $this->period($row);
                $equity = $this->number($row['歸屬於母公司業主之權益合計'] ?? $row['權益總額'] ?? null);

                if ($equity === null) {
                    continue;
                }

                $balances[$symbol][$period] = ['equity' => $equity, 'row' => $row];
            }
        }

        return $balances;
    }

    private function rows(string $path): array
    {
        $response = Http::retry(3, 500)
            ->timeout(45)
            ->get(self::BASE_URL.$path)
            ->throw();

        return $response->json() ?? [];
    }

    private function period(array $row): string
    {
        $year = (int) ($row['年度'] ?? 0);
        $quarter = (int) ($row['季別'] ?? 0);

        if ($year > 1911) {
            $year -= 1911;
        }

        return sprintf('%03dQ%d', $year, max(1, $quarter));
    }

    private function number(mixed $value): ?float
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
