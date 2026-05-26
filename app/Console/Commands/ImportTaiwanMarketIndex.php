<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportTaiwanMarketIndex extends Command
{
    protected $signature = 'market:import-taiwan-index {--months=4 : Number of recent months to refresh}';

    protected $description = 'Import official TWSE TAIEX daily OHLC index data.';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $today = CarbonImmutable::now('Asia/Taipei')->startOfMonth();
        $imported = 0;

        for ($i = 0; $i < $months; $i++) {
            $month = $today->subMonths($i);
            $rows = $this->fetchMonth($month);
            $volumeRows = $this->fetchVolumeMonth($month);

            foreach ($rows as $row) {
                $volume = $volumeRows[$row['trade_date']]['volume'] ?? 0;
                $turnover = $volumeRows[$row['trade_date']]['turnover'] ?? null;
                $change = $volumeRows[$row['trade_date']]['change'] ?? null;
                $previousClose = $change !== null ? $row['close'] - $change : null;
                $changePct = $previousClose && $previousClose != 0.0 ? ($change / $previousClose) * 100 : null;

                DB::table('global_market_data')->updateOrInsert(
                    ['indicator' => 'TAIEX', 'trade_date' => $row['trade_date']],
                    [
                        'value' => $row['close'],
                        'change' => $change,
                        'change_pct' => $changePct === null ? null : round($changePct, 6),
                        'state' => $this->state($changePct),
                        'source' => 'TWSE MI_5MINS_HIST / FMTQIK',
                        'raw_payload' => json_encode([
                            'symbol' => 'TAIEX',
                            'open' => $row['open'],
                            'high' => $row['high'],
                            'low' => $row['low'],
                            'close' => $row['close'],
                            'volume' => $volume,
                            'turnover' => $turnover,
                        ], JSON_UNESCAPED_SLASHES),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                $imported++;
            }
        }

        $this->info('Official TAIEX rows imported: '.$imported);

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchMonth(CarbonImmutable $month): array
    {
        $response = Http::retry(2, 500)
            ->timeout(20)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->get('https://www.twse.com.tw/rwd/zh/TAIEX/MI_5MINS_HIST', [
                'response' => 'json',
                'date' => $month->format('Ymd'),
            ]);

        $response->throw();

        return collect($response->json('data') ?? [])
            ->map(function (array $row) {
                return [
                    'trade_date' => $this->rocDate((string) ($row[0] ?? '')),
                    'open' => $this->number($row[1] ?? null),
                    'high' => $this->number($row[2] ?? null),
                    'low' => $this->number($row[3] ?? null),
                    'close' => $this->number($row[4] ?? null),
                ];
            })
            ->filter(fn (array $row) => $row['trade_date'] && $row['close'] !== null)
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchVolumeMonth(CarbonImmutable $month): array
    {
        $response = Http::retry(2, 500)
            ->timeout(20)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->get('https://www.twse.com.tw/rwd/zh/afterTrading/FMTQIK', [
                'response' => 'json',
                'date' => $month->format('Ymd'),
            ]);

        $response->throw();

        return collect($response->json('data') ?? [])
            ->mapWithKeys(function (array $row) {
                $date = $this->rocDate((string) ($row[0] ?? ''));

                if (! $date) {
                    return [];
                }

                return [
                    $date => [
                        'volume' => (int) $this->number($row[1] ?? null),
                        'turnover' => (int) $this->number($row[2] ?? null),
                        'change' => $this->number($row[5] ?? null),
                    ],
                ];
            })
            ->all();
    }

    private function rocDate(string $value): ?string
    {
        if (! preg_match('/^(\d{2,3})\/(\d{2})\/(\d{2})$/', trim($value), $matches)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', ((int) $matches[1]) + 1911, (int) $matches[2], (int) $matches[3]);
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === '--') {
            return null;
        }

        return (float) str_replace(',', '', (string) $value);
    }

    private function state(?float $changePct): string
    {
        return match (true) {
            $changePct === null => 'unknown',
            $changePct >= 1 => 'strong',
            $changePct >= 0 => 'positive',
            $changePct > -1 => 'soft',
            default => 'weak',
        };
    }
}
