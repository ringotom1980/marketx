<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportGlobalMarketData extends Command
{
    protected $signature = 'market:import-global-market';

    protected $description = 'Import global market indicators from public Yahoo Finance chart data.';

    private const INDICATORS = [
        ['indicator' => 'S&P 500', 'symbol' => '^GSPC'],
        ['indicator' => 'NASDAQ', 'symbol' => '^IXIC'],
        ['indicator' => 'SOX', 'symbol' => '^SOX'],
        ['indicator' => 'VIX', 'symbol' => '^VIX'],
        ['indicator' => 'DXY', 'symbol' => 'DX-Y.NYB'],
        ['indicator' => 'US10Y', 'symbol' => '^TNX'],
        ['indicator' => 'Crude Oil', 'symbol' => 'CL=F'],
        ['indicator' => 'Gold', 'symbol' => 'GC=F'],
        ['indicator' => 'TSM ADR', 'symbol' => 'TSM'],
    ];

    public function handle(): int
    {
        $imported = 0;
        $failed = 0;

        foreach (self::INDICATORS as $indicator) {
            try {
                $data = $this->fetchIndicator($indicator['symbol']);

                if (! $data) {
                    $failed++;
                    $this->warn('No data: '.$indicator['indicator']);
                    continue;
                }

                DB::table('global_market_data')->updateOrInsert(
                    ['indicator' => $indicator['indicator'], 'trade_date' => $data['trade_date']],
                    [
                        'value' => $data['value'],
                        'change' => $data['change'],
                        'change_pct' => $data['change_pct'],
                        'state' => $this->state($indicator['indicator'], $data['change_pct'], $data['value']),
                        'source' => 'Yahoo Finance chart API: '.$indicator['symbol'],
                        'raw_payload' => json_encode($data['raw_payload'], JSON_UNESCAPED_SLASHES),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                $imported++;
            } catch (\Throwable $exception) {
                $failed++;
                DB::table('system_logs')->insert([
                    'level' => 'warning',
                    'source' => 'Global Engine',
                    'message' => 'Global market indicator failed: '.$indicator['indicator'],
                    'context' => json_encode(['error' => $exception->getMessage(), 'symbol' => $indicator['symbol']]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->info('Global indicators imported: '.$imported);
        $this->line('Failed indicators: '.$failed);

        return self::SUCCESS;
    }

    private function fetchIndicator(string $symbol): ?array
    {
        $url = 'https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol);
        $response = Http::retry(2, 500)
            ->timeout(20)
            ->acceptJson()
            ->get($url, ['range' => '10d', 'interval' => '1d']);

        if (! $response->ok()) {
            $response->throw();
        }

        $result = $response->json('chart.result.0');

        if (! $result) {
            return null;
        }

        $timestamps = $result['timestamp'] ?? [];
        $closes = $result['indicators']['quote'][0]['close'] ?? [];
        $points = [];

        foreach ($timestamps as $index => $timestamp) {
            $close = $closes[$index] ?? null;

            if ($timestamp && $close !== null) {
                $points[] = ['timestamp' => $timestamp, 'close' => (float) $close];
            }
        }

        if (count($points) < 1) {
            return null;
        }

        $last = $points[count($points) - 1];
        $previous = $points[count($points) - 2] ?? null;
        $change = $previous ? $last['close'] - $previous['close'] : null;
        $changePct = $previous && $previous['close'] != 0.0 ? ($change / $previous['close']) * 100 : null;

        return [
            'trade_date' => CarbonImmutable::createFromTimestamp($last['timestamp'], 'UTC')->setTimezone('Asia/Taipei')->toDateString(),
            'value' => round($last['close'], 6),
            'change' => $change === null ? null : round($change, 6),
            'change_pct' => $changePct === null ? null : round($changePct, 6),
            'raw_payload' => ['symbol' => $symbol, 'points' => array_slice($points, -3)],
        ];
    }

    private function state(string $indicator, ?float $changePct, float $value): string
    {
        if ($indicator === 'VIX') {
            return match (true) {
                $value < 16 => 'low_risk',
                $value < 22 => 'neutral',
                default => 'high_risk',
            };
        }

        if ($indicator === 'US10Y' || $indicator === 'DXY') {
            return $changePct !== null && $changePct <= 0 ? 'pressure_down' : 'pressure_up';
        }

        return match (true) {
            $changePct === null => 'unknown',
            $changePct >= 1 => 'strong',
            $changePct >= 0 => 'positive',
            $changePct > -1 => 'soft',
            default => 'weak',
        };
    }
}
