<?php

namespace App\Console\Commands;

use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportTaiwanRevenues extends Command
{
    protected $signature = 'market:import-revenues {--year= : Gregorian year} {--month= : Month 1-12}';

    protected $description = 'Import monthly Taiwan stock revenues from official MOPS monthly revenue pages.';

    public function handle(): int
    {
        $target = CarbonImmutable::now('Asia/Taipei')->subMonthNoOverflow();

        if ($this->option('year') || $this->option('month')) {
            $months = [CarbonImmutable::create(
                (int) ($this->option('year') ?: $target->year),
                (int) ($this->option('month') ?: $target->month),
                1,
                0,
                0,
                0,
                'Asia/Taipei',
            )];
        } else {
            $months = collect(range(0, 24))->map(fn (int $offset) => $target->subMonthsNoOverflow($offset)->startOfMonth())->all();
        }

        foreach ($months as $monthDate) {
            $year = $monthDate->year;
            $month = $monthDate->month;
            $rocYear = $year - 1911;
            $yearMonth = sprintf('%04d-%02d', $year, $month);
            $count = 0;

            foreach (['sii' => 'TWSE', 'otc' => 'TPEx'] as $marketCode => $market) {
                $count += $this->importMarket($marketCode, $market, $rocYear, $month, $yearMonth);
            }

            if ($count > 0 || $this->option('year') || $this->option('month')) {
                $this->info('Revenue rows imported for '.$yearMonth.': '.$count);

                return self::SUCCESS;
            }
        }

        $this->warn('No available MOPS revenue page found in the fallback window.');

        return self::SUCCESS;
    }

    private function importMarket(string $marketCode, string $market, int $rocYear, int $month, string $yearMonth): int
    {
        $url = sprintf('https://mops.twse.com.tw/nas/t21/%s/t21sc03_%d_%d_0.html', $marketCode, $rocYear, $month);
        $response = Http::retry(3, 700)->timeout(60)->get($url);

        if (! $response->ok() || str_contains($response->body(), 'HTTP Status 404')) {
            $this->warn($market.' revenue page unavailable: '.$response->status());
            return 0;
        }

        $html = mb_convert_encoding($response->body(), 'UTF-8', 'BIG5,UTF-8');
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $matches);

        $count = 0;

        foreach ($matches[1] as $rowHtml) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $rowHtml, $cells);
            $values = array_map(fn ($cell) => trim(html_entity_decode(strip_tags($cell))), $cells[1] ?? []);

            if (count($values) < 6) {
                continue;
            }

            $symbol = preg_replace('/\D/', '', $values[0] ?? '');

            if (! $symbol || preg_match('/^\d{4}$/', $symbol) !== 1) {
                continue;
            }

            $stockId = Stock::query()->where('symbol', $symbol)->value('id');

            if (! $stockId) {
                continue;
            }

            DB::table('stock_revenues')->updateOrInsert(
                ['stock_id' => $stockId, 'year_month' => $yearMonth],
                [
                    'revenue' => $this->integer($values[2] ?? null),
                    'mom_pct' => $this->decimal($values[5] ?? null),
                    'yoy_pct' => $this->decimal($values[6] ?? null),
                    'raw_payload' => json_encode([
                        'market' => $market,
                        'source' => $url,
                        'row' => $values,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $count++;
        }

        $this->line($market.' revenue rows imported: '.$count);

        return $count;
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

        $value = str_replace([',', '+', '%'], '', trim((string) $value));

        if ($value === '' || $value === '-' || $value === '--') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
