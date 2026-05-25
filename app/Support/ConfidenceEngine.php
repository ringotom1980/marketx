<?php

namespace App\Support;

use App\Models\Stock;
use App\Models\StockScore;
use Illuminate\Support\Facades\DB;

class ConfidenceEngine
{
    /**
     * @return array{score:int,payload:array<string,mixed>,risk_flags:array<int,string>}
     */
    public function evaluate(Stock $stock, StockScore $score, array $riskFlags = []): array
    {
        $technical = DB::table('stock_technical_indicators_1d')
            ->where('stock_id', $stock->id)
            ->orderByDesc('trade_date')
            ->first();
        $chip = DB::table('stock_chips_1d')
            ->where('stock_id', $stock->id)
            ->orderByDesc('trade_date')
            ->first();
        $financial = DB::table('stock_financials')
            ->where('stock_id', $stock->id)
            ->orderByDesc('period')
            ->first();
        $revenue = DB::table('stock_revenues')
            ->where('stock_id', $stock->id)
            ->orderByDesc('year_month')
            ->first();

        $technicalResult = $this->technical($technical);
        $chipResult = $this->chip($chip, $stock);
        $fundamentalResult = $this->fundamental($financial, $revenue);
        $themeResult = $this->theme((int) ($score->theme_score ?? 0));

        $weights = [
            'technical' => 0.35,
            'chip' => 0.25,
            'fundamental' => 0.25,
            'theme' => 0.15,
        ];

        $technicalOpportunity = $this->moduleConfidence(
            $technicalResult['bull_score'],
            $technicalResult['bear_score'],
            $technicalResult['risk_penalty'],
            35,
            18,
        );
        $chipOpportunity = $this->moduleConfidence(
            $chipResult['bull_score'],
            $chipResult['bear_score'],
            $chipResult['risk_penalty'],
            25,
            12,
        );
        $fundamentalOpportunity = $this->moduleConfidence(
            $fundamentalResult['bull_score'],
            $fundamentalResult['bear_score'],
            $fundamentalResult['risk_penalty'],
            25,
            12,
        );
        $themeOpportunity = max(35, min(82, 45 + ($themeResult['bonus_score'] * 2) - ($themeResult['risk_penalty'] * 3)));

        $confidence = (int) round(
            ($technicalOpportunity * $weights['technical'])
            + ($chipOpportunity * $weights['chip'])
            + ($fundamentalOpportunity * $weights['fundamental'])
            + ($themeOpportunity * $weights['theme'])
        );
        $confidence = max(5, min(95, $confidence));

        $bull = $technicalResult['bull_score'] + $chipResult['bull_score'] + $fundamentalResult['bull_score'] + $themeResult['bonus_score'];
        $bear = $technicalResult['bear_score'] + $chipResult['bear_score'] + $fundamentalResult['bear_score'];
        $riskPenalty = min(42, $technicalResult['risk_penalty'] + $chipResult['risk_penalty'] + $fundamentalResult['risk_penalty'] + $themeResult['risk_penalty']);
        $riskConfidence = $this->riskConfidence($technicalResult, $chipResult, $fundamentalResult, $themeResult);
        $weakConfidence = $this->weakConfidence($technicalResult, $chipResult, $fundamentalResult);

        $reasons = [
            'bull' => array_slice(array_merge($technicalResult['bull_reasons'], $chipResult['bull_reasons'], $fundamentalResult['bull_reasons'], $themeResult['bull_reasons']), 0, 8),
            'bear' => array_slice(array_merge($technicalResult['bear_reasons'], $chipResult['bear_reasons'], $fundamentalResult['bear_reasons']), 0, 8),
            'risk' => array_slice(array_merge($technicalResult['risk_reasons'], $chipResult['risk_reasons'], $fundamentalResult['risk_reasons'], $themeResult['risk_reasons']), 0, 8),
        ];

        $mergedRiskFlags = array_values(array_unique(array_merge(
            $riskFlags,
            $technicalResult['risk_flags'],
            $chipResult['risk_flags'],
            $fundamentalResult['risk_flags'],
            $themeResult['risk_flags'],
        )));

        return [
            'score' => $confidence,
            'payload' => [
                'version' => 2,
                'bull_score' => round($bull, 2),
                'bear_score' => round($bear, 2),
                'risk_penalty_score' => round($riskPenalty, 2),
                'technical_bull_score' => round($technicalResult['bull_score'], 2),
                'technical_bear_score' => round($technicalResult['bear_score'], 2),
                'chip_bull_score' => round($chipResult['bull_score'], 2),
                'chip_bear_score' => round($chipResult['bear_score'], 2),
                'fundamental_bull_score' => round($fundamentalResult['bull_score'], 2),
                'fundamental_bear_score' => round($fundamentalResult['bear_score'], 2),
                'theme_bonus_score' => round($themeResult['bonus_score'], 2),
                'technical_opportunity_confidence' => $technicalOpportunity,
                'chip_opportunity_confidence' => $chipOpportunity,
                'fundamental_opportunity_confidence' => $fundamentalOpportunity,
                'theme_opportunity_confidence' => $themeOpportunity,
                'opportunity_confidence' => $confidence,
                'risk_confidence' => $riskConfidence,
                'weak_confidence' => $weakConfidence,
                'reasons' => $reasons,
            ],
            'risk_flags' => $mergedRiskFlags,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function technical(mixed $row): array
    {
        $bull = 0.0;
        $bear = 0.0;
        $risk = 0.0;
        $bullReasons = [];
        $bearReasons = [];
        $riskReasons = [];
        $riskFlags = [];

        if (! $row) {
            return compact('bull', 'bear', 'risk') + [
                'bull_score' => 0, 'bear_score' => 0, 'risk_penalty' => 6,
                'bull_reasons' => [], 'bear_reasons' => [], 'risk_reasons' => ['技術資料不足'], 'risk_flags' => ['technical_missing'],
            ];
        }

        $close = (float) ($row->close ?? 0);
        $sma20 = $this->num($row->sma20);
        $sma60 = $this->num($row->sma60);
        $sma120 = $this->num($row->sma120);
        $rsi14 = $this->num($row->rsi14);
        $macd = $this->num($row->macd);
        $macdSignal = $this->num($row->macd_signal);
        $macdHist = $this->num($row->macd_histogram);
        $macdPrev = $this->num($row->macd_previous);
        $macdSignalPrev = $this->num($row->macd_signal_previous);
        $macdHistPrev = $this->num($row->macd_histogram_previous);
        $k9 = $this->num($row->k9);
        $d9 = $this->num($row->d9);
        $kPrev = $this->num($row->k9_previous);
        $dPrev = $this->num($row->d9_previous);
        $bais20 = $this->num($row->bais20);
        $return20 = $this->num($row->return20);
        $volumeRatio20 = $this->num($row->volume_ratio20);
        $bollUpper = $this->num($row->bollinger_upper20);
        $bollLower = $this->num($row->bollinger_lower20);

        if ($sma20 !== null && $sma60 !== null && $close > $sma20 && $sma20 > $sma60) {
            $bull += 8; $bullReasons[] = '站上月線且均線偏多';
        }
        if ($sma20 !== null && $close < $sma20) {
            $bear += 8; $bearReasons[] = '跌破月線'; $riskFlags[] = 'below_sma20';
        }
        if ($sma60 !== null && $sma120 !== null && $sma60 < $sma120) {
            $bear += 5; $bearReasons[] = '中期均線偏空';
        }
        if ($macdPrev !== null && $macdSignalPrev !== null && $macd !== null && $macdSignal !== null) {
            if ($macdPrev <= $macdSignalPrev && $macd > $macdSignal) {
                $bull += 8; $bullReasons[] = 'MACD 黃金交叉';
            } elseif ($macdPrev >= $macdSignalPrev && $macd < $macdSignal) {
                $bear += 8; $bearReasons[] = 'MACD 死亡交叉'; $riskFlags[] = 'macd_dead_cross';
            }
        }
        if ($macdHist !== null && $macdHistPrev !== null) {
            if ($macdHist > 0 && $macdHistPrev <= 0) {
                $bull += 5; $bullReasons[] = 'MACD 翻正';
            } elseif ($macdHist > 0 && $macdHist < $macdHistPrev) {
                $bear += 4; $bearReasons[] = 'MACD 正數縮減';
            } elseif ($macdHist < 0 && $macdHist > $macdHistPrev) {
                $bull += 3; $bullReasons[] = 'MACD 負數縮減';
            }
        }
        if ($kPrev !== null && $dPrev !== null && $k9 !== null && $d9 !== null) {
            if ($kPrev <= $dPrev && $k9 > $d9) {
                $bull += 6; $bullReasons[] = 'KD 黃金交叉';
            } elseif ($kPrev >= $dPrev && $k9 < $d9) {
                $bear += 6; $bearReasons[] = 'KD 死亡交叉';
            }
        }
        if ($rsi14 !== null) {
            if ($rsi14 >= 55 && $rsi14 <= 72) {
                $bull += 5; $bullReasons[] = 'RSI 強勢區';
            } elseif ($rsi14 > 78) {
                $risk += 5; $riskReasons[] = 'RSI 過熱'; $riskFlags[] = 'rsi_overheated';
            } elseif ($rsi14 < 35) {
                $bear += 5; $bearReasons[] = 'RSI 弱勢';
            }
        }
        if ($bollUpper !== null && $bollLower !== null) {
            if ($close > $bollUpper) {
                $bull += 4; $bullReasons[] = '突破布林上緣';
            } elseif ($close < $bollLower) {
                $bear += 5; $bearReasons[] = '跌破布林下緣'; $riskFlags[] = 'below_bollinger_lower';
            }
        }
        if ((bool) ($row->breakout20 ?? false)) {
            $bull += 6; $bullReasons[] = '突破 20 日高點';
        }
        if ($volumeRatio20 !== null && $volumeRatio20 >= 1.5 && $return20 !== null && $return20 > 0) {
            $bull += 5; $bullReasons[] = '價量同步增溫';
        }
        if ($bais20 !== null && $bais20 >= 12) {
            $risk += 6; $riskReasons[] = '乖離率過大'; $riskFlags[] = 'bais_overheated';
        } elseif ($bais20 !== null && $bais20 <= -10) {
            $bear += 4; $bearReasons[] = '負乖離偏弱';
        }

        foreach (($row->risk_flags ? json_decode((string) $row->risk_flags, true) : []) ?: [] as $flag) {
            if ($flag === 'high_volume_down') {
                $risk += 8; $riskReasons[] = '高檔爆量轉弱'; $riskFlags[] = $flag;
            }
        }

        return [
            'bull_score' => min(35, $bull),
            'bear_score' => min(35, $bear),
            'risk_penalty' => min(18, $risk),
            'bull_reasons' => array_values(array_unique($bullReasons)),
            'bear_reasons' => array_values(array_unique($bearReasons)),
            'risk_reasons' => array_values(array_unique($riskReasons)),
            'risk_flags' => array_values(array_unique($riskFlags)),
        ];
    }

    private function chip(mixed $chip, Stock $stock): array
    {
        if (! $chip) {
            return ['bull_score' => 0, 'bear_score' => 0, 'risk_penalty' => 5, 'bull_reasons' => [], 'bear_reasons' => [], 'risk_reasons' => ['籌碼資料不足'], 'risk_flags' => ['chip_missing']];
        }

        $latestPrice = $stock->dailyPrices()->latest('trade_date')->first();
        $volume = max(1, (int) ($latestPrice?->volume ?? 1));
        $foreignRatio = (float) $chip->foreign_net_buy / $volume;
        $trustRatio = (float) $chip->investment_trust_net_buy / $volume;
        $institutionalRatio = (float) $chip->institutional_net_buy / $volume;
        $marginRatio = (float) ($chip->margin_balance ?? 0) / $volume;
        $shortRatio = (float) ($chip->short_balance ?? 0) / $volume;
        $bull = 0.0; $bear = 0.0; $risk = 0.0;
        $bullReasons = []; $bearReasons = []; $riskReasons = []; $riskFlags = [];

        if ($institutionalRatio >= 0.04) {
            $bull += 8; $bullReasons[] = '三大法人明顯買超';
        } elseif ($institutionalRatio <= -0.04) {
            $bear += 8; $bearReasons[] = '三大法人明顯賣超'; $riskFlags[] = 'institutional_selling';
        }
        if ($foreignRatio >= 0.03) {
            $bull += 6; $bullReasons[] = '外資買超';
        } elseif ($foreignRatio <= -0.03) {
            $bear += 6; $bearReasons[] = '外資賣超';
        }
        if ($trustRatio >= 0.015) {
            $bull += 5; $bullReasons[] = '投信買超';
        } elseif ($trustRatio <= -0.015) {
            $bear += 5; $bearReasons[] = '投信賣超';
        }
        if ((float) $chip->foreign_net_buy > 0 && (float) $chip->investment_trust_net_buy > 0) {
            $bull += 5; $bullReasons[] = '外資與投信同步買超';
        }
        if ((float) $chip->foreign_net_buy < 0 && (float) $chip->investment_trust_net_buy < 0) {
            $bear += 5; $bearReasons[] = '外資與投信同步賣超'; $riskFlags[] = 'foreign_and_trust_selling';
        }
        if ($marginRatio >= 5) {
            $risk += 5; $riskReasons[] = '融資餘額偏重'; $riskFlags[] = 'margin_heavy';
        }
        if ($shortRatio >= 0.5) {
            $risk += 3; $riskReasons[] = '融券壓力偏高';
        }

        return [
            'bull_score' => min(25, $bull),
            'bear_score' => min(25, $bear),
            'risk_penalty' => min(12, $risk),
            'bull_reasons' => array_values(array_unique($bullReasons)),
            'bear_reasons' => array_values(array_unique($bearReasons)),
            'risk_reasons' => array_values(array_unique($riskReasons)),
            'risk_flags' => array_values(array_unique($riskFlags)),
        ];
    }

    private function fundamental(mixed $financial, mixed $revenue): array
    {
        $bull = 0.0; $bear = 0.0; $risk = 0.0;
        $bullReasons = []; $bearReasons = []; $riskReasons = []; $riskFlags = [];

        if ($revenue?->yoy_pct !== null) {
            $yoy = (float) $revenue->yoy_pct;
            if ($yoy >= 20) {
                $bull += 8; $bullReasons[] = '月營收年增強勁';
            } elseif ($yoy >= 5) {
                $bull += 5; $bullReasons[] = '月營收年增';
            } elseif ($yoy <= -15) {
                $bear += 8; $bearReasons[] = '月營收明顯衰退'; $riskFlags[] = 'revenue_decline';
            } elseif ($yoy < 0) {
                $bear += 4; $bearReasons[] = '月營收年減';
            }
        }
        if ($revenue?->mom_pct !== null) {
            $mom = (float) $revenue->mom_pct;
            if ($mom >= 8) {
                $bull += 4; $bullReasons[] = '月營收月增';
            } elseif ($mom <= -8) {
                $bear += 4; $bearReasons[] = '月營收月減';
            }
        }
        if ($financial?->eps !== null) {
            $eps = (float) $financial->eps;
            if ($eps >= 3) {
                $bull += 5; $bullReasons[] = 'EPS 表現強';
            } elseif ($eps < 0) {
                $bear += 6; $bearReasons[] = 'EPS 轉虧'; $riskFlags[] = 'eps_loss';
            }
        }
        if ($financial?->roe !== null) {
            $roe = (float) $financial->roe;
            if ($roe >= 15) {
                $bull += 5; $bullReasons[] = 'ROE 表現佳';
            } elseif ($roe < 5) {
                $bear += 4; $bearReasons[] = 'ROE 偏低';
            }
        }
        if ($financial?->gross_margin !== null) {
            $gross = (float) $financial->gross_margin;
            if ($gross >= 30) {
                $bull += 4; $bullReasons[] = '毛利率具支撐';
            } elseif ($gross < 10) {
                $bear += 4; $bearReasons[] = '毛利率偏低';
            }
        }
        if ($financial?->per !== null) {
            $per = (float) $financial->per;
            if ($per > 0 && $per <= 15) {
                $bull += 3; $bullReasons[] = '評價相對保守';
            } elseif ($per > 40) {
                $risk += 6; $riskReasons[] = '本益比偏高'; $riskFlags[] = 'high_per';
            } elseif ($per > 25) {
                $risk += 3; $riskReasons[] = '本益比略高';
            }
        }

        return [
            'bull_score' => min(25, $bull),
            'bear_score' => min(25, $bear),
            'risk_penalty' => min(12, $risk),
            'bull_reasons' => array_values(array_unique($bullReasons)),
            'bear_reasons' => array_values(array_unique($bearReasons)),
            'risk_reasons' => array_values(array_unique($riskReasons)),
            'risk_flags' => array_values(array_unique($riskFlags)),
        ];
    }

    private function theme(int $themeScore): array
    {
        $bonus = match (true) {
            $themeScore >= 85 => 15,
            $themeScore >= 75 => 12,
            $themeScore >= 60 => 8,
            $themeScore >= 45 => 4,
            default => 0,
        };
        $risk = $themeScore >= 90 ? 3 : 0;

        return [
            'bonus_score' => $bonus,
            'risk_penalty' => $risk,
            'bull_reasons' => $bonus >= 12 ? ['題材熱度高'] : ($bonus > 0 ? ['題材有熱度'] : []),
            'risk_reasons' => $risk > 0 ? ['題材過熱需留意'] : [],
            'risk_flags' => $risk > 0 ? ['theme_overheated'] : [],
        ];
    }

    private function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function moduleConfidence(float $bull, float $bear, float $risk, float $maxDirection, float $maxRisk): int
    {
        $bullRatio = $maxDirection > 0 ? min(1.0, $bull / $maxDirection) : 0.0;
        $bearRatio = $maxDirection > 0 ? min(1.0, $bear / $maxDirection) : 0.0;
        $riskRatio = $maxRisk > 0 ? min(1.0, $risk / $maxRisk) : 0.0;

        $score = 50 + ($bullRatio * 40) - ($bearRatio * 30) - ($riskRatio * 22);

        return max(5, min(95, (int) round($score)));
    }

    /**
     * @param array<string,mixed> $technical
     * @param array<string,mixed> $chip
     * @param array<string,mixed> $fundamental
     * @param array<string,mixed> $theme
     */
    private function riskConfidence(array $technical, array $chip, array $fundamental, array $theme): int
    {
        $technicalRisk = min(1.0, ((float) $technical['risk_penalty'] + ((float) $technical['bear_score'] * 0.55)) / 32);
        $chipRisk = min(1.0, ((float) $chip['risk_penalty'] + ((float) $chip['bear_score'] * 0.55)) / 24);
        $fundamentalRisk = min(1.0, ((float) $fundamental['risk_penalty'] + ((float) $fundamental['bear_score'] * 0.45)) / 22);
        $themeRisk = min(1.0, ((float) $theme['risk_penalty']) / 8);

        $score = 20
            + ($technicalRisk * 35)
            + ($chipRisk * 25)
            + ($fundamentalRisk * 25)
            + ($themeRisk * 15);

        return max(5, min(95, (int) round($score)));
    }

    /**
     * @param array<string,mixed> $technical
     * @param array<string,mixed> $chip
     * @param array<string,mixed> $fundamental
     */
    private function weakConfidence(array $technical, array $chip, array $fundamental): int
    {
        $technicalWeak = min(1.0, ((float) $technical['bear_score'] + ((float) $technical['risk_penalty'] * 0.45)) / 38);
        $chipWeak = min(1.0, ((float) $chip['bear_score'] + ((float) $chip['risk_penalty'] * 0.35)) / 28);
        $fundamentalWeak = min(1.0, ((float) $fundamental['bear_score'] + ((float) $fundamental['risk_penalty'] * 0.35)) / 28);

        $score = 20
            + ($technicalWeak * 45)
            + ($chipWeak * 25)
            + ($fundamentalWeak * 30);

        return max(5, min(95, (int) round($score)));
    }
}
