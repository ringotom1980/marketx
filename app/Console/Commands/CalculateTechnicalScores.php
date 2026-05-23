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
        $rsi14 = $this->rsi($closes, 14);
        $macd = $this->macd($closes);
        $kd = $this->kd($prices, 9, 3, 3);
        $bollinger = $this->bollinger($closes, 20);
        $atr14 = $this->atr($prices, 14);
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

        if ($rsi14 !== null) {
            if ($rsi14 >= 55 && $rsi14 <= 72) {
                $score += 5;
            } elseif ($rsi14 > 78) {
                $score -= 5;
                $riskFlags[] = 'rsi_overheated';
            } elseif ($rsi14 < 35) {
                $score -= 5;
                $riskFlags[] = 'rsi_weak';
            }
        }

        if ($macd['macd'] !== null && $macd['signal'] !== null) {
            if ($macd['macd'] > $macd['signal'] && $macd['histogram'] > 0) {
                $score += 5;
            } elseif ($macd['macd'] < $macd['signal'] && $macd['histogram'] < 0) {
                $score -= 5;
                $riskFlags[] = 'macd_bearish';
            }
        }

        if ($kd['k'] !== null && $kd['d'] !== null) {
            if ($kd['k'] > $kd['d'] && $kd['k'] >= 50 && $kd['k'] <= 85) {
                $score += 4;
            } elseif ($kd['k'] < $kd['d'] && $kd['k'] < 45) {
                $score -= 4;
                $riskFlags[] = 'kd_weak';
            } elseif ($kd['k'] > 90 && $kd['d'] > 85) {
                $score -= 3;
                $riskFlags[] = 'kd_overheated';
            }
        }

        if ($bollinger['upper'] !== null && $bollinger['lower'] !== null) {
            if ($close > $bollinger['upper'] && $volumeRatio !== null && $volumeRatio >= 1.2) {
                $score += 4;
            } elseif ($close < $bollinger['lower']) {
                $score -= 6;
                $riskFlags[] = 'below_bollinger_lower';
            }
        }

        if ($atr14 !== null && $close > 0) {
            $atrPct = $atr14 / $close;

            if ($atrPct > 0.055) {
                $score -= 4;
                $riskFlags[] = 'atr_expanded';
            }
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
            'rsi14' => $rsi14,
            'macd' => $macd['macd'],
            'macd_signal' => $macd['signal'],
            'macd_histogram' => $macd['histogram'],
            'k9' => $kd['k'],
            'd9' => $kd['d'],
            'bollinger_upper20' => $bollinger['upper'],
            'bollinger_middle20' => $bollinger['middle'],
            'bollinger_lower20' => $bollinger['lower'],
            'atr14' => $atr14,
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

    private function emaSeries(array $values, int $period): array
    {
        if (count($values) < $period) {
            return [];
        }

        $multiplier = 2 / ($period + 1);
        $ema = $this->average(array_slice($values, 0, $period));
        $series = array_fill(0, $period - 1, null);
        $series[] = $ema;

        foreach (array_slice($values, $period) as $value) {
            $ema = ($value - $ema) * $multiplier + $ema;
            $series[] = $ema;
        }

        return $series;
    }

    private function rsi(array $values, int $period): ?float
    {
        if (count($values) <= $period) {
            return null;
        }

        $gains = [];
        $losses = [];

        for ($i = count($values) - $period; $i < count($values); $i++) {
            $change = $values[$i] - $values[$i - 1];
            $gains[] = max(0, $change);
            $losses[] = abs(min(0, $change));
        }

        $avgGain = $this->average($gains);
        $avgLoss = $this->average($losses);

        if ($avgLoss == 0.0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return round(100 - (100 / (1 + $rs)), 2);
    }

    private function macd(array $values): array
    {
        if (count($values) < 35) {
            return ['macd' => null, 'signal' => null, 'histogram' => null];
        }

        $ema12 = $this->emaSeries($values, 12);
        $ema26 = $this->emaSeries($values, 26);
        $macdSeries = [];

        foreach ($values as $index => $_) {
            if (($ema12[$index] ?? null) !== null && ($ema26[$index] ?? null) !== null) {
                $macdSeries[] = $ema12[$index] - $ema26[$index];
            }
        }

        if (count($macdSeries) < 9) {
            return ['macd' => null, 'signal' => null, 'histogram' => null];
        }

        $signalSeries = $this->emaSeries($macdSeries, 9);
        $macd = $macdSeries[count($macdSeries) - 1];
        $signal = $signalSeries[count($signalSeries) - 1] ?? null;

        return [
            'macd' => round($macd, 4),
            'signal' => $signal === null ? null : round($signal, 4),
            'histogram' => $signal === null ? null : round($macd - $signal, 4),
        ];
    }

    /**
     * @param array<int, \App\Models\StockPrice1d> $prices
     */
    private function kd(array $prices, int $period, int $kSmooth, int $dSmooth): array
    {
        if (count($prices) < $period + $kSmooth + $dSmooth) {
            return ['k' => null, 'd' => null];
        }

        $rawK = [];

        for ($i = $period - 1; $i < count($prices); $i++) {
            $slice = array_slice($prices, $i - $period + 1, $period);
            $high = max(array_map(fn ($price) => (float) $price->high, $slice));
            $low = min(array_map(fn ($price) => (float) $price->low, $slice));
            $close = (float) $prices[$i]->close;
            $rawK[] = $high == $low ? 50.0 : (($close - $low) / ($high - $low)) * 100;
        }

        $kValues = $this->simpleMovingSeries($rawK, $kSmooth);
        $dValues = $this->simpleMovingSeries(array_values(array_filter($kValues, fn ($value) => $value !== null)), $dSmooth);

        return [
            'k' => round((float) end($kValues), 2),
            'd' => $dValues === [] ? null : round((float) end($dValues), 2),
        ];
    }

    private function bollinger(array $values, int $period): array
    {
        if (count($values) < $period) {
            return ['upper' => null, 'middle' => null, 'lower' => null];
        }

        $slice = array_slice($values, -$period);
        $middle = $this->average($slice);
        $sd = $this->standardDeviation($slice);

        if ($sd === null) {
            return ['upper' => null, 'middle' => round($middle, 4), 'lower' => null];
        }

        return [
            'upper' => round($middle + ($sd * 2), 4),
            'middle' => round($middle, 4),
            'lower' => round($middle - ($sd * 2), 4),
        ];
    }

    /**
     * @param array<int, \App\Models\StockPrice1d> $prices
     */
    private function atr(array $prices, int $period): ?float
    {
        if (count($prices) <= $period) {
            return null;
        }

        $trueRanges = [];

        for ($i = 1; $i < count($prices); $i++) {
            $high = (float) $prices[$i]->high;
            $low = (float) $prices[$i]->low;
            $previousClose = (float) $prices[$i - 1]->close;
            $trueRanges[] = max($high - $low, abs($high - $previousClose), abs($low - $previousClose));
        }

        return round($this->average(array_slice($trueRanges, -$period)), 4);
    }

    private function simpleMovingSeries(array $values, int $period): array
    {
        if (count($values) < $period) {
            return [];
        }

        $series = [];

        for ($i = 0; $i < count($values); $i++) {
            if ($i + 1 < $period) {
                $series[] = null;
                continue;
            }

            $series[] = $this->average(array_slice($values, $i - $period + 1, $period));
        }

        return $series;
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
