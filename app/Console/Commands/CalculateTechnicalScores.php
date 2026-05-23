<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockScore;
use Illuminate\Console\Command;

class CalculateTechnicalScores extends Command
{
    protected $signature = 'market:calculate-technical-scores
        {--symbol= : Calculate one stock symbol}
        {--min-days=20 : Minimum price rows required}';

    protected $description = 'Calculate deterministic technical scores from official daily K data.';

    public function handle(): int
    {
        $minDays = max(10, (int) $this->option('min-days'));
        $stocks = Stock::query()
            ->where('is_active', true)
            ->when($this->option('symbol'), fn ($query, $symbol) => $query->where('symbol', $symbol))
            ->orderBy('symbol')
            ->get();

        $calculated = 0;
        $skipped = 0;

        foreach ($stocks as $stock) {
            $prices = $stock->dailyPrices()
                ->orderByDesc('trade_date')
                ->limit(120)
                ->get()
                ->sortBy('trade_date')
                ->values();

            if ($prices->count() < $minDays) {
                $skipped++;
                continue;
            }

            $payload = $this->calculatePayload($prices->all());
            $score = $payload['score'];

            StockScore::query()->updateOrCreate(
                [
                    'stock_id' => $stock->id,
                    'score_date' => $payload['score_date'],
                ],
                [
                    'technical_score' => $score,
                    'technical_payload' => $payload,
                    'risk_flags' => $payload['risk_flags'],
                ],
            );

            $calculated++;
        }

        $this->info('Technical scores calculated: '.$calculated);
        $this->line('Skipped for insufficient data: '.$skipped);

        return self::SUCCESS;
    }

    /**
     * @param array<int, \App\Models\StockPrice1d> $prices
     * @return array<string, mixed>
     */
    private function calculatePayload(array $prices): array
    {
        $latest = $prices[count($prices) - 1];
        $previous = $prices[count($prices) - 2] ?? null;
        $closes = array_map(fn ($price) => (float) $price->close, $prices);
        $highs = array_map(fn ($price) => (float) $price->high, $prices);
        $volumes = array_map(fn ($price) => (float) $price->volume, $prices);

        $close = (float) $latest->close;
        $previousClose = $previous ? (float) $previous->close : $close;
        $sma5 = $this->sma($closes, 5);
        $sma20 = $this->sma($closes, 20);
        $sma60 = $this->sma($closes, 60);
        $ema12 = $this->ema($closes, 12);
        $ema26 = $this->ema($closes, 26);
        $avgVolume20 = $this->average(array_slice($volumes, -20));
        $volumeRatio = $avgVolume20 > 0 ? ((float) $latest->volume / $avgVolume20) : null;
        $returns20 = $this->returns(array_slice($closes, -21));
        $volatility20 = $this->standardDeviation($returns20);
        $high20BeforeToday = max(array_slice($highs, -21, 20) ?: [$close]);
        $return20 = count($closes) >= 21 ? ($close / $closes[count($closes) - 21] - 1) : 0.0;

        $score = 50;
        $riskFlags = [];

        if ($sma20 !== null && $close > $sma20) {
            $score += 12;
        } elseif ($sma20 !== null) {
            $score -= 12;
            $riskFlags[] = 'below_sma20';
        }

        if ($sma20 !== null && $sma60 !== null && $sma20 > $sma60) {
            $score += 10;
        } elseif ($sma60 !== null) {
            $score -= 8;
            $riskFlags[] = 'sma20_below_sma60';
        }

        if ($ema12 !== null && $ema26 !== null && $ema12 > $ema26) {
            $score += 8;
        } elseif ($ema26 !== null) {
            $score -= 6;
            $riskFlags[] = 'ema_momentum_weak';
        }

        if ($return20 > 0.08) {
            $score += 10;
        } elseif ($return20 > 0.02) {
            $score += 5;
        } elseif ($return20 < -0.08) {
            $score -= 10;
            $riskFlags[] = 'negative_20d_momentum';
        }

        if ($close >= $high20BeforeToday && $close > $previousClose) {
            $score += 10;
        } elseif ($close >= $high20BeforeToday * 0.97) {
            $score += 4;
        }

        if ($volumeRatio !== null && $volumeRatio >= 1.5 && $close > $previousClose) {
            $score += 6;
        } elseif ($volumeRatio !== null && $volumeRatio >= 1.5 && $close < $previousClose) {
            $score -= 8;
            $riskFlags[] = 'high_volume_down';
        }

        if ($volatility20 !== null && $volatility20 > 0.045) {
            $score -= 8;
            $riskFlags[] = 'high_volatility';
        } elseif ($volatility20 !== null && $volatility20 < 0.018) {
            $score += 3;
        }

        $score = max(0, min(100, (int) round($score)));

        return [
            'score_date' => $latest->trade_date->toDateString(),
            'score' => $score,
            'close' => $close,
            'sma5' => $sma5,
            'sma20' => $sma20,
            'sma60' => $sma60,
            'ema12' => $ema12,
            'ema26' => $ema26,
            'return20' => round($return20 * 100, 2),
            'volume_ratio20' => $volumeRatio === null ? null : round($volumeRatio, 2),
            'volatility20' => $volatility20 === null ? null : round($volatility20 * 100, 2),
            'breakout20' => $close >= $high20BeforeToday && $close > $previousClose,
            'risk_flags' => array_values(array_unique($riskFlags)),
        ];
    }

    private function sma(array $values, int $period): ?float
    {
        if (count($values) < $period) {
            return null;
        }

        return round($this->average(array_slice($values, -$period)), 4);
    }

    private function ema(array $values, int $period): ?float
    {
        if (count($values) < $period) {
            return null;
        }

        $slice = array_slice($values, -($period * 3));
        $multiplier = 2 / ($period + 1);
        $ema = $slice[0];

        foreach (array_slice($slice, 1) as $value) {
            $ema = ($value - $ema) * $multiplier + $ema;
        }

        return round($ema, 4);
    }

    private function average(array $values): float
    {
        $values = array_values(array_filter($values, fn ($value) => $value !== null));

        return count($values) === 0 ? 0.0 : array_sum($values) / count($values);
    }

    private function returns(array $values): array
    {
        $returns = [];

        for ($i = 1; $i < count($values); $i++) {
            if ($values[$i - 1] > 0) {
                $returns[] = $values[$i] / $values[$i - 1] - 1;
            }
        }

        return $returns;
    }

    private function standardDeviation(array $values): ?float
    {
        if (count($values) < 2) {
            return null;
        }

        $mean = $this->average($values);
        $variance = array_sum(array_map(fn ($value) => ($value - $mean) ** 2, $values)) / (count($values) - 1);

        return sqrt($variance);
    }
}

