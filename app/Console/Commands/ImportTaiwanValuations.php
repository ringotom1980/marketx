<?php

namespace App\Console\Commands;

use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportTaiwanValuations extends Command
{
    protected $signature = 'market:import-valuations';

    protected $description = 'Import official TWSE and TPEx daily valuation data: PER, dividend yield, and P/B ratio.';

    private const TWSE_VALUATION_URL = 'https://openapi.twse.com.tw/v1/exchangeReport/BWIBBU_ALL';

    private const TPEX_VALUATION_URL = 'https://www.tpex.org.tw/openapi/v1/tpex_mainboard_peratio_analysis';

    public function handle(): int
    {
        $twse = $this->importTwse();
        $tpex = $this->importTpex();

        $this->line('TWSE valuation rows imported: '.$twse);
        $this->line('TPEx valuation rows imported: '.$tpex);
        $this->info('Done.');

        return self::SUCCESS;
    }

    private function importTwse(): int
    {
        $rows = Http::retry(3, 500)
            ->timeout(30)
            ->acceptJson()
            ->get(self::TWSE_VALUATION_URL)
            ->throw()
            ->json() ?? [];

        $count = 0;

        foreach ($rows as $row) {
            $symbol = trim((string) ($row['Code'] ?? ''));

            if (preg_match('/^\d{4}$/', $symbol) !== 1) {
                continue;
            }

            $stockId = Stock::query()->where('symbol', $symbol)->where('market', 'TWSE')->value('id');

            if (! $stockId) {
                continue;
            }

            $this->upsertFinancial($stockId, $this->period((string) ($row['Date'] ?? '')), [
                'per' => $this->decimal($row['PEratio'] ?? null),
                'dividend_yield' => $this->decimal($row['DividendYield'] ?? null),
                'pb_ratio' => $this->decimal($row['PBratio'] ?? null),
                'raw_payload' => ['source' => 'TWSE_BWIBBU_ALL', 'row' => $row],
            ]);

            $count++;
        }

        return $count;
    }

    private function importTpex(): int
    {
        $rows = Http::retry(3, 500)
            ->timeout(30)
            ->acceptJson()
            ->get(self::TPEX_VALUATION_URL)
            ->throw()
            ->json() ?? [];

        $count = 0;

        foreach ($rows as $row) {
            $symbol = trim((string) ($row['SecuritiesCompanyCode'] ?? ''));

            if (preg_match('/^\d{4}$/', $symbol) !== 1) {
                continue;
            }

            $stockId = Stock::query()->where('symbol', $symbol)->where('market', 'TPEx')->value('id');

            if (! $stockId) {
                continue;
            }

            $this->upsertFinancial($stockId, $this->period((string) ($row['Date'] ?? '')), [
                'per' => $this->decimal($row['PriceEarningRatio'] ?? null),
                'dividend_yield' => $this->decimal($row['YieldRatio'] ?? null),
                'pb_ratio' => $this->decimal($row['PriceBookRatio'] ?? null),
                'raw_payload' => ['source' => 'TPEX_PERATIO_ANALYSIS', 'row' => $row],
            ]);

            $count++;
        }

        return $count;
    }

    private function upsertFinancial(int $stockId, string $period, array $data): void
    {
        $existing = DB::table('stock_financials')
            ->where('stock_id', $stockId)
            ->where('period', $period)
            ->first();

        $rawPayload = [];

        if ($existing?->raw_payload) {
            $decoded = json_decode($existing->raw_payload, true);
            $rawPayload = is_array($decoded) ? $decoded : [];
        }

        $rawPayload['valuation'] = $data['raw_payload'];

        DB::table('stock_financials')->updateOrInsert(
            ['stock_id' => $stockId, 'period' => $period],
            [
                'per' => $data['per'],
                'dividend_yield' => $data['dividend_yield'],
                'pb_ratio' => $data['pb_ratio'],
                'raw_payload' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function period(string $rocDate): string
    {
        $rocDate = trim($rocDate);

        if (preg_match('/^(\d{3})(\d{2})(\d{2})$/', $rocDate, $matches) === 1) {
            return CarbonImmutable::create(((int) $matches[1]) + 1911, (int) $matches[2], (int) $matches[3])->toDateString();
        }

        return CarbonImmutable::now('Asia/Taipei')->toDateString();
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace([',', '+', '%'], '', trim((string) $value));

        if ($value === '' || $value === '-' || $value === '--' || strtolower($value) === 'nan') {
            return null;
        }

        return is_numeric($value) ? (string) (float) $value : null;
    }
}
