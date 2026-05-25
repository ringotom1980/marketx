<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockScore;
use App\Models\StockTechnicalIndicator1d;
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
                ->limit(280)
                ->get()
                ->sortBy('trade_date')
                ->filter(fn ($price) => (float) ($price->close ?? 0) > 0)
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

            StockTechnicalIndicator1d::query()->updateOrCreate(
                [
                    'stock_id' => $stock->id,
                    'trade_date' => $payload['score_date'],
                ],
                $this->indicatorRow($payload),
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
        $highs = array_map(fn ($price) => (float) ($price->high ?: $price->close), $prices);
        $volumes = array_map(fn ($price) => (float) $price->volume, $prices);

        $close = (float) $latest->close;
        $previousClose = $previous ? (float) $previous->close : $close;
        $open = (float) ($latest->open ?: $close);
        $high = (float) ($latest->high ?: max($open, $close));
        $low = (float) ($latest->low ?: min($open, $close));
        $previousVolume = $previous ? (float) $previous->volume : null;
        $sma5 = $this->sma($closes, 5);
        $sma10 = $this->sma($closes, 10);
        $sma20 = $this->sma($closes, 20);
        $sma60 = $this->sma($closes, 60);
        $sma120 = $this->sma($closes, 120);
        $sma240 = $this->sma($closes, 240);
        $ema12 = $this->ema($closes, 12);
        $ema26 = $this->ema($closes, 26);
        $rsi6 = $this->rsi($closes, 6);
        $rsi12 = $this->rsi($closes, 12);
        $rsi14 = $this->rsi($closes, 14);
        $macd = $this->macd($closes);
        $kd = $this->kd($prices, 9, 3, 3);
        $bollinger = $this->bollinger($closes, 20);
        $atr14 = $this->atr($prices, 14);
        $avgVolume20 = $this->average(array_slice($volumes, -20));
        $avgVolume5 = $this->average(array_slice($volumes, -5));
        $volumeRatio5 = $avgVolume5 > 0 ? ((float) $latest->volume / $avgVolume5) : null;
        $volumeRatio = $avgVolume20 > 0 ? ((float) $latest->volume / $avgVolume20) : null;
        $returns20 = $this->returns(array_slice($closes, -21));
        $volatility20 = $this->standardDeviation($returns20);
        $high20BeforeToday = max(array_slice($highs, -21, 20) ?: [$close]);
        $base20 = count($closes) >= 21 ? $closes[count($closes) - 21] : null;
        $base5 = count($closes) >= 6 ? $closes[count($closes) - 6] : null;
        $base10 = count($closes) >= 11 ? $closes[count($closes) - 11] : null;
        $base60 = count($closes) >= 61 ? $closes[count($closes) - 61] : null;
        $return5 = $base5 && $base5 > 0 ? ($close / $base5 - 1) : 0.0;
        $return10 = $base10 && $base10 > 0 ? ($close / $base10 - 1) : 0.0;
        $return20 = $base20 && $base20 > 0 ? ($close / $base20 - 1) : 0.0;
        $return60 = $base60 && $base60 > 0 ? ($close / $base60 - 1) : 0.0;
        $support20 = min(array_map(fn ($price) => (float) ($price->low ?: $price->close), array_slice($prices, -20)) ?: [$close]);
        $resistance20 = max(array_slice($highs, -20) ?: [$close]);
        $bais20 = $this->bais($close, $sma20);
        $highVolumeWeakReversal = $this->isHighVolumeWeakReversal(
            open: $open,
            high: $high,
            low: $low,
            close: $close,
            previousClose: $previousClose,
            volume: (float) $latest->volume,
            previousVolume: $previousVolume,
            return20: $return20,
            bais20: $bais20,
            high20BeforeToday: $high20BeforeToday,
        );

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

        if ($volumeRatio !== null && $volumeRatio >= 1.5 && $close > $previousClose && ! $highVolumeWeakReversal) {
            $score += 6;
        } elseif ($highVolumeWeakReversal) {
            $score -= 10;
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
            'sma10' => $sma10,
            'sma20' => $sma20,
            'sma60' => $sma60,
            'sma120' => $sma120,
            'sma240' => $sma240,
            'ema12' => $ema12,
            'ema26' => $ema26,
            'rsi6' => $rsi6,
            'rsi12' => $rsi12,
            'rsi14' => $rsi14,
            'macd' => $macd['macd'],
            'macd_signal' => $macd['signal'],
            'macd_histogram' => $macd['histogram'],
            'macd_previous' => $macd['previous_macd'],
            'macd_signal_previous' => $macd['previous_signal'],
            'macd_histogram_previous' => $macd['previous_histogram'],
            'k9' => $kd['k'],
            'd9' => $kd['d'],
            'k9_previous' => $kd['previous_k'],
            'd9_previous' => $kd['previous_d'],
            'bollinger_upper20' => $bollinger['upper'],
            'bollinger_middle20' => $bollinger['middle'],
            'bollinger_lower20' => $bollinger['lower'],
            'atr14' => $atr14,
            'bais5' => $this->bais($close, $sma5),
            'bais10' => $this->bais($close, $sma10),
            'bais20' => $bais20,
            'bais60' => $this->bais($close, $sma60),
            'return5' => round($return5 * 100, 2),
            'return10' => round($return10 * 100, 2),
            'return20' => round($return20 * 100, 2),
            'return60' => round($return60 * 100, 2),
            'volume_ratio5' => $volumeRatio5 === null ? null : round($volumeRatio5, 2),
            'volume_ratio20' => $volumeRatio === null ? null : round($volumeRatio, 2),
            'volatility20' => $volatility20 === null ? null : round($volatility20 * 100, 2),
            'support20' => round($support20, 4),
            'resistance20' => round($resistance20, 4),
            'breakout20' => $close >= $high20BeforeToday && $close > $previousClose,
            'signals' => $this->technicalSignals(
                close: $close,
                previousClose: $previousClose,
                sma5: $sma5,
                sma20: $sma20,
                sma60: $sma60,
                ema12: $ema12,
                ema26: $ema26,
                rsi14: $rsi14,
                macd: $macd,
                kd: $kd,
                bollinger: $bollinger,
                atr14: $atr14,
                volumeRatio: $volumeRatio,
                return20: $return20,
                volatility20: $volatility20,
                breakout20: $close >= $high20BeforeToday && $close > $previousClose,
                highVolumeWeakReversal: $highVolumeWeakReversal,
            ),
            'risk_flags' => array_values(array_unique($riskFlags)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function indicatorRow(array $payload): array
    {
        $keys = [
            'close', 'sma5', 'sma10', 'sma20', 'sma60', 'sma120', 'sma240',
            'ema12', 'ema26', 'rsi6', 'rsi12', 'rsi14',
            'macd', 'macd_signal', 'macd_histogram',
            'macd_previous', 'macd_signal_previous', 'macd_histogram_previous',
            'k9', 'd9', 'k9_previous', 'd9_previous',
            'bollinger_upper20', 'bollinger_middle20', 'bollinger_lower20',
            'atr14', 'bais5', 'bais10', 'bais20', 'bais60',
            'return5', 'return10', 'return20', 'return60',
            'volume_ratio5', 'volume_ratio20', 'volatility20',
            'support20', 'resistance20',
        ];

        $row = [
            'breakout20' => (bool) ($payload['breakout20'] ?? false),
            'technical_score' => $payload['score'] ?? null,
            'signals' => $payload['signals'] ?? [],
            'risk_flags' => $payload['risk_flags'] ?? [],
        ];

        foreach ($keys as $key) {
            $row[$key] = $payload[$key] ?? null;
        }

        return $row;
    }

    private function technicalSignals(
        float $close,
        float $previousClose,
        ?float $sma5,
        ?float $sma20,
        ?float $sma60,
        ?float $ema12,
        ?float $ema26,
        ?float $rsi14,
        array $macd,
        array $kd,
        array $bollinger,
        ?float $atr14,
        ?float $volumeRatio,
        float $return20,
        ?float $volatility20,
        bool $breakout20,
        bool $highVolumeWeakReversal,
    ): array {
        $signals = [];

        if ($kd['k'] !== null && $kd['d'] !== null && $kd['previous_k'] !== null && $kd['previous_d'] !== null) {
            if ($kd['previous_k'] <= $kd['previous_d'] && $kd['k'] > $kd['d']) {
                $signals[] = ['tone' => 'green', 'title' => 'KD 黃金交叉', 'body' => 'K 值由下往上突破 D 值，短線動能轉強。'];
            } elseif ($kd['previous_k'] >= $kd['previous_d'] && $kd['k'] < $kd['d']) {
                $signals[] = ['tone' => 'red', 'title' => 'KD 死亡交叉', 'body' => 'K 值跌破 D 值，短線動能轉弱。'];
            } elseif ($kd['k'] > $kd['d'] && $kd['k'] >= 50 && $kd['k'] <= 85) {
                $signals[] = ['tone' => 'green', 'title' => 'KD 多方排列', 'body' => 'K 值維持在 D 值之上，短線買盤仍占優勢。'];
            } elseif ($kd['k'] < $kd['d'] && $kd['k'] < 45) {
                $signals[] = ['tone' => 'amber', 'title' => 'KD 偏弱', 'body' => 'K 值低於 D 值且落在弱勢區，短線需觀察止穩。'];
            }

            if ($kd['k'] > 90 && $kd['d'] > 85) {
                $signals[] = ['tone' => 'amber', 'title' => 'KD 過熱', 'body' => 'KD 已進入高檔區，追價風險提高。'];
            }
        }

        if ($macd['macd'] !== null && $macd['signal'] !== null && $macd['histogram'] !== null) {
            if ($macd['previous_macd'] !== null && $macd['previous_signal'] !== null) {
                if ($macd['previous_macd'] <= $macd['previous_signal'] && $macd['macd'] > $macd['signal']) {
                    $signals[] = ['tone' => 'green', 'title' => 'MACD 黃金交叉', 'body' => 'DIF 向上突破 MACD 線，中期動能轉強。'];
                } elseif ($macd['previous_macd'] >= $macd['previous_signal'] && $macd['macd'] < $macd['signal']) {
                    $signals[] = ['tone' => 'red', 'title' => 'MACD 死亡交叉', 'body' => 'DIF 跌破 MACD 線，中期動能轉弱。'];
                }
            }

            if ($macd['previous_histogram'] !== null) {
                if ($macd['histogram'] > 0 && $macd['previous_histogram'] <= 0) {
                    $signals[] = ['tone' => 'green', 'title' => 'MACD 翻正', 'body' => '柱狀體由負轉正，動能開始轉為多方。'];
                } elseif ($macd['histogram'] < 0 && $macd['previous_histogram'] < 0 && $macd['histogram'] > $macd['previous_histogram']) {
                    $signals[] = ['tone' => 'amber', 'title' => 'MACD 負數縮減', 'body' => '柱狀體仍在零軸下，但空方力道正在收斂。'];
                } elseif ($macd['histogram'] > 0 && $macd['histogram'] < $macd['previous_histogram']) {
                    $signals[] = ['tone' => 'amber', 'title' => 'MACD 正數縮小', 'body' => '柱狀體仍為正，但多方力道開始降溫。'];
                }
            }
        }

        if ($sma5 !== null && $sma20 !== null && $sma60 !== null) {
            if ($close > $sma5 && $sma5 > $sma20 && $sma20 > $sma60) {
                $signals[] = ['tone' => 'green', 'title' => '均線多頭排列', 'body' => '收盤價站上短中長期均線，趨勢結構偏多。'];
            } elseif ($close < $sma20) {
                $signals[] = ['tone' => 'red', 'title' => '跌破月線', 'body' => '收盤價低於 20 日均線，短中線轉弱。'];
            } elseif ($sma20 < $sma60) {
                $signals[] = ['tone' => 'amber', 'title' => '月線低於季線', 'body' => '中期趨勢尚未轉強，仍需等待結構改善。'];
            }
        }

        if ($ema12 !== null && $ema26 !== null) {
            $signals[] = $ema12 > $ema26
                ? ['tone' => 'green', 'title' => 'EMA 動能偏多', 'body' => 'EMA12 高於 EMA26，價格動能偏向多方。']
                : ['tone' => 'amber', 'title' => 'EMA 動能偏弱', 'body' => 'EMA12 低於 EMA26，價格動能仍偏保守。'];
        }

        if ($rsi14 !== null) {
            if ($rsi14 >= 55 && $rsi14 <= 72) {
                $signals[] = ['tone' => 'green', 'title' => 'RSI 強勢區', 'body' => 'RSI 位於健康強勢區，買盤動能尚可。'];
            } elseif ($rsi14 > 78) {
                $signals[] = ['tone' => 'amber', 'title' => 'RSI 過熱', 'body' => 'RSI 偏高，短線容易出現震盪或拉回。'];
            } elseif ($rsi14 < 35) {
                $signals[] = ['tone' => 'red', 'title' => 'RSI 弱勢', 'body' => 'RSI 偏低，短線賣壓仍需消化。'];
            }
        }

        if (($bollinger['upper'] ?? null) !== null && ($bollinger['lower'] ?? null) !== null) {
            if ($close > $bollinger['upper']) {
                $signals[] = ['tone' => 'green', 'title' => '突破布林上緣', 'body' => '價格突破布林通道上緣，代表波動放大且買盤積極。'];
            } elseif ($close < $bollinger['lower']) {
                $signals[] = ['tone' => 'red', 'title' => '跌破布林下緣', 'body' => '價格跌破布林通道下緣，短線弱勢或超跌風險提高。'];
            }
        }

        if ($breakout20) {
            $signals[] = ['tone' => 'green', 'title' => '20 日突破', 'body' => '收盤價突破近 20 日高點，價格結構轉強。'];
        } elseif ($return20 >= 0.08) {
            $signals[] = ['tone' => 'green', 'title' => '20 日動能強', 'body' => '近 20 日漲幅超過 8%，波段動能偏強。'];
        } elseif ($return20 <= -0.08) {
            $signals[] = ['tone' => 'red', 'title' => '20 日動能弱', 'body' => '近 20 日跌幅超過 8%，波段動能偏弱。'];
        }

        if ($volumeRatio !== null) {
            if ($volumeRatio >= 1.5 && $close > $previousClose) {
                $signals[] = ['tone' => 'green', 'title' => '價漲量增', 'body' => '成交量高於 20 日均量，且價格上漲，買盤確認度較高。'];
            } elseif ($volumeRatio < 0.7) {
                $signals[] = ['tone' => 'amber', 'title' => '量能不足', 'body' => '成交量低於近期均量，突破或反彈的確認度較低。'];
            }
        }

        if ($highVolumeWeakReversal) {
            $signals[] = ['tone' => 'red', 'title' => '爆量轉弱', 'body' => '高檔震盪中盤中拉高後回落，留下明顯上影線，且量能達前一日 2 倍以上。'];
        }

        if ($atr14 !== null && $close > 0 && ($atr14 / $close) > 0.055) {
            $signals[] = ['tone' => 'amber', 'title' => '波動擴大', 'body' => 'ATR 占股價比重偏高，短線震盪風險較大。'];
        }

        if ($volatility20 !== null && $volatility20 > 0.045) {
            $signals[] = ['tone' => 'amber', 'title' => '20 日波動偏高', 'body' => '近 20 日價格波動較大，操作上需保留風險空間。'];
        }

        return array_slice($signals, 0, 8);
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
            return ['macd' => null, 'signal' => null, 'histogram' => null, 'previous_macd' => null, 'previous_signal' => null, 'previous_histogram' => null];
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
            return ['macd' => null, 'signal' => null, 'histogram' => null, 'previous_macd' => null, 'previous_signal' => null, 'previous_histogram' => null];
        }

        $signalSeries = $this->emaSeries($macdSeries, 9);
        $macd = $macdSeries[count($macdSeries) - 1];
        $signal = $signalSeries[count($signalSeries) - 1] ?? null;
        $previousMacd = $macdSeries[count($macdSeries) - 2] ?? null;
        $previousSignal = $signalSeries[count($signalSeries) - 2] ?? null;

        return [
            'macd' => round($macd, 4),
            'signal' => $signal === null ? null : round($signal, 4),
            'histogram' => $signal === null ? null : round($macd - $signal, 4),
            'previous_macd' => $previousMacd === null ? null : round($previousMacd, 4),
            'previous_signal' => $previousSignal === null ? null : round($previousSignal, 4),
            'previous_histogram' => $previousMacd === null || $previousSignal === null ? null : round($previousMacd - $previousSignal, 4),
        ];
    }

    /**
     * @param array<int, \App\Models\StockPrice1d> $prices
     */
    private function kd(array $prices, int $period, int $kSmooth, int $dSmooth): array
    {
        if (count($prices) < $period + $kSmooth + $dSmooth) {
            return ['k' => null, 'd' => null, 'previous_k' => null, 'previous_d' => null];
        }

        $rawK = [];

        for ($i = $period - 1; $i < count($prices); $i++) {
            $slice = array_slice($prices, $i - $period + 1, $period);
            $high = max(array_map(fn ($price) => (float) $price->high, $slice));
            $low = min(array_map(fn ($price) => (float) ($price->low ?: $price->close), $slice));
            $close = (float) $prices[$i]->close;
            $rawK[] = $high == $low ? 50.0 : (($close - $low) / ($high - $low)) * 100;
        }

        $kValues = $this->simpleMovingSeries($rawK, $kSmooth);
        $dValues = $this->simpleMovingSeries(array_values(array_filter($kValues, fn ($value) => $value !== null)), $dSmooth);

        return [
            'k' => round((float) end($kValues), 2),
            'd' => $dValues === [] ? null : round((float) end($dValues), 2),
            'previous_k' => count($kValues) < 2 ? null : round((float) $kValues[count($kValues) - 2], 2),
            'previous_d' => count($dValues) < 2 ? null : round((float) $dValues[count($dValues) - 2], 2),
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
            $high = (float) ($prices[$i]->high ?: $prices[$i]->close);
            $low = (float) ($prices[$i]->low ?: $prices[$i]->close);
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

    private function bais(float $close, ?float $movingAverage): ?float
    {
        if ($close <= 0 || $movingAverage === null || $movingAverage <= 0) {
            return null;
        }

        return round((($close / $movingAverage) - 1) * 100, 4);
    }

    private function isHighVolumeWeakReversal(
        float $open,
        float $high,
        float $low,
        float $close,
        float $previousClose,
        float $volume,
        ?float $previousVolume,
        float $return20,
        ?float $bais20,
        float $high20BeforeToday,
    ): bool {
        if ($previousVolume === null || $previousVolume <= 0 || $volume < ($previousVolume * 2)) {
            return false;
        }

        $range = max(0.0001, $high - $low);
        $upperShadowRatio = ($high - max($open, $close)) / $range;
        $pulledBackFromHigh = $close <= $open || $close <= ($high * 0.97);
        $intradayLift = $high >= (max($open, $previousClose) * 1.015);
        $highPosition = $return20 >= 0.08
            || ($bais20 !== null && $bais20 >= 8)
            || $high >= ($high20BeforeToday * 0.97);

        return $highPosition
            && $intradayLift
            && $pulledBackFromHigh
            && $upperShadowRatio >= 0.35;
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
