<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImportTaifexNightSession extends Command
{
    protected $signature = 'market:import-taifex-night';

    protected $description = 'Import TAIFEX Taiwan futures after-hours session as a global radar indicator.';

    private const URL = 'https://openapi.taifex.com.tw/v1/DailyMarketReportFut';

    public function handle(): int
    {
        $response = Http::timeout(30)->retry(2, 800)->get(self::URL);

        if (! $response->ok()) {
            $this->error('TAIFEX night session unavailable: HTTP '.$response->status());

            return self::FAILURE;
        }

        $rows = collect($response->json() ?: []);
        $row = $rows
            ->filter(fn ($item) => ($item['Contract'] ?? null) === 'TX')
            ->filter(fn ($item) => ($item['TradingSession'] ?? null) === '盤後')
            ->filter(fn ($item) => $this->decimal($item['Last'] ?? null) !== null)
            ->sortBy(fn ($item) => (string) ($item['ContractMonth(Week)'] ?? '999999'))
            ->first();

        if (! $row) {
            $this->warn('Skipped: no TX night session row available.');

            return self::SUCCESS;
        }

        $tradeDate = $this->date($row['Date'] ?? null);
        $last = $this->decimal($row['Last'] ?? null);
        $change = $this->decimal($row['Change'] ?? null);
        $changePct = $this->percent($row['%'] ?? null);

        if (! $tradeDate || $last === null) {
            $this->warn('Skipped: TX night session row has incomplete data.');

            return self::SUCCESS;
        }

        DB::table('global_market_data')->updateOrInsert(
            ['indicator' => 'TAIFEX TX Night', 'trade_date' => $tradeDate],
            [
                'value' => $last,
                'change' => $change,
                'change_pct' => $changePct,
                'state' => $this->state($changePct),
                'source' => 'TAIFEX OpenAPI DailyMarketReportFut',
                'raw_payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->info('TAIFEX TX night imported: '.$tradeDate.' '.$last.' ('.($changePct ?? 0).'%).');

        return self::SUCCESS;
    }

    private function date(?string $value): ?string
    {
        if (! $value || ! preg_match('/^\d{8}$/', $value)) {
            return null;
        }

        return substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2);
    }

    private function decimal(mixed $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '-' || Str::upper($value) === 'NULL') {
            return null;
        }

        return is_numeric(str_replace(',', '', $value)) ? (float) str_replace(',', '', $value) : null;
    }

    private function percent(mixed $value): ?float
    {
        $value = str_replace('%', '', trim((string) $value));

        return $this->decimal($value);
    }

    private function state(?float $changePct): string
    {
        return match (true) {
            $changePct === null => 'unknown',
            $changePct >= 0.3 => 'positive',
            $changePct <= -0.3 => 'weak',
            default => 'neutral',
        };
    }
}
