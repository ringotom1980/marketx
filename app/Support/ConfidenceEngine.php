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

        $modules = [
            'technical' => $this->technical($technical),
            'chip' => $this->chip($chip, $stock),
            'fundamental' => $this->fundamental($financial, $revenue),
            'theme' => $this->theme((int) ($score->theme_score ?? 0), (int) ($score->event_score ?? 0)),
        ];

        $weights = [
            'technical' => 0.40,
            'chip' => 0.25,
            'fundamental' => 0.25,
            'theme' => 0.10,
        ];

        $confidence = 0.0;

        foreach ($weights as $name => $weight) {
            $confidence += ((int) $modules[$name]['score']) * $weight;
        }

        $confidence = max(0, min(100, (int) round($confidence)));
        $riskConfidence = $this->weightedPressure($modules, $weights, true);
        $weakConfidence = $this->weightedPressure($modules, $weights, false);

        $reasons = [
            'bull' => array_slice(array_values(array_unique(array_merge(
                $modules['technical']['bull_reasons'],
                $modules['chip']['bull_reasons'],
                $modules['fundamental']['bull_reasons'],
                $modules['theme']['bull_reasons'],
            ))), 0, 10),
            'bear' => array_slice(array_values(array_unique(array_merge(
                $modules['technical']['bear_reasons'],
                $modules['chip']['bear_reasons'],
                $modules['fundamental']['bear_reasons'],
                $modules['theme']['bear_reasons'],
            ))), 0, 10),
            'risk' => array_slice(array_values(array_unique(array_merge(
                $modules['technical']['risk_reasons'],
                $modules['chip']['risk_reasons'],
                $modules['fundamental']['risk_reasons'],
                $modules['theme']['risk_reasons'],
            ))), 0, 10),
        ];

        $mergedRiskFlags = array_values(array_unique(array_merge(
            $riskFlags,
            $modules['technical']['risk_flags'],
            $modules['chip']['risk_flags'],
            $modules['fundamental']['risk_flags'],
            $modules['theme']['risk_flags'],
        )));

        return [
            'score' => $confidence,
            'payload' => [
                'version' => 3,
                'method' => 'indicator_points_weighted',
                'weights' => [
                    'technical' => 40,
                    'chip' => 25,
                    'fundamental' => 25,
                    'theme' => 10,
                ],
                'technical_score' => $modules['technical']['score'],
                'chip_score' => $modules['chip']['score'],
                'fundamental_score' => $modules['fundamental']['score'],
                'theme_score' => $modules['theme']['score'],
                'technical_bull_score' => $modules['technical']['bull_score'],
                'technical_bear_score' => $modules['technical']['bear_score'],
                'chip_bull_score' => $modules['chip']['bull_score'],
                'chip_bear_score' => $modules['chip']['bear_score'],
                'fundamental_bull_score' => $modules['fundamental']['bull_score'],
                'fundamental_bear_score' => $modules['fundamental']['bear_score'],
                'theme_bonus_score' => $modules['theme']['bull_score'],
                'risk_penalty_score' => array_sum(array_column($modules, 'risk_penalty')),
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
        $module = $this->module();

        if (! $row) {
            $this->risk($module, true, 8, '技術資料不足', 'technical_missing');

            return $this->finish($module);
        }

        $close = $this->num($row->close);
        $sma5 = $this->num($row->sma5);
        $sma20 = $this->num($row->sma20);
        $sma60 = $this->num($row->sma60);
        $sma120 = $this->num($row->sma120);
        $sma240 = $this->num($row->sma240);
        $ema12 = $this->num($row->ema12);
        $ema26 = $this->num($row->ema26);
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
        $bollMiddle = $this->num($row->bollinger_middle20);
        $bollLower = $this->num($row->bollinger_lower20);
        $bais20 = $this->num($row->bais20);
        $return5 = $this->num($row->return5);
        $return20 = $this->num($row->return20);
        $return60 = $this->num($row->return60);
        $volumeRatio20 = $this->num($row->volume_ratio20);

        $this->bull($module, $close !== null && $sma5 !== null && $close > $sma5, 4, '站上5日線');
        $this->bull($module, $close !== null && $sma20 !== null && $close > $sma20, 5, '站上月線');
        $this->bull($module, $sma20 !== null && $sma60 !== null && $sma20 > $sma60, 5, '月線高於季線');
        $this->bull($module, $sma60 !== null && $sma120 !== null && $sma60 > $sma120, 5, '季線高於半年線');
        $this->bull($module, $sma120 !== null && $sma240 !== null && $sma120 > $sma240, 4, '長期均線偏多');
        $this->bull($module, $ema12 !== null && $ema26 !== null && $ema12 > $ema26, 4, 'EMA 動能偏多');
        $this->bull($module, $this->crossUp($macdPrev, $macdSignalPrev, $macd, $macdSignal), 7, 'MACD 黃金交叉');
        $this->bull($module, $macd !== null && $macdSignal !== null && $macd > $macdSignal, 4, 'MACD 多方排列');
        $this->bull($module, $macdHist !== null && $macdHistPrev !== null && $macdHist > $macdHistPrev, 4, 'MACD 動能增加');
        $this->bull($module, $macdHist !== null && $macdHist > 0, 4, 'MACD 翻正');
        $this->bull($module, $this->crossUp($kPrev, $dPrev, $k9, $d9), 6, 'KD 黃金交叉');
        $this->bull($module, $k9 !== null && $d9 !== null && $k9 > $d9, 3, 'KD 多方排列');
        $this->bull($module, $rsi14 !== null && $rsi14 >= 55 && $rsi14 <= 72, 5, 'RSI 強勢區');
        $this->bull($module, (bool) ($row->breakout20 ?? false), 6, '20日突破');
        $this->bull($module, $close !== null && $bollMiddle !== null && $close > $bollMiddle, 4, '站上布林中線');
        $this->bull($module, $volumeRatio20 !== null && $volumeRatio20 >= 1.2 && $return5 !== null && $return5 > 0, 5, '價漲量增');
        $this->bull($module, $return5 !== null && $return5 > 0, 3, '短線轉強');
        $this->bull($module, $return20 !== null && $return20 > 0 && $return20 < 15, 4, '月線趨勢向上');
        $this->bull($module, $bais20 !== null && $bais20 >= 0 && $bais20 <= 8, 4, '乖離健康');

        $this->bear($module, $close !== null && $sma20 !== null && $close < $sma20, 5, '跌破月線', 'below_sma20');
        $this->bear($module, $sma20 !== null && $sma60 !== null && $sma20 < $sma60, 5, '月線低於季線');
        $this->bear($module, $sma60 !== null && $sma120 !== null && $sma60 < $sma120, 5, '季線低於半年線');
        $this->bear($module, $ema12 !== null && $ema26 !== null && $ema12 < $ema26, 4, 'EMA 動能偏空');
        $this->bear($module, $this->crossDown($macdPrev, $macdSignalPrev, $macd, $macdSignal), 7, 'MACD 死亡交叉', 'macd_dead_cross');
        $this->bear($module, $macd !== null && $macdSignal !== null && $macd < $macdSignal, 4, 'MACD 空方排列');
        $this->bear($module, $macdHist !== null && $macdHistPrev !== null && $macdHist > 0 && $macdHist < $macdHistPrev, 4, 'MACD 正數縮減');
        $this->bear($module, $macdHist !== null && $macdHist < 0, 4, 'MACD 偏空');
        $this->bear($module, $this->crossDown($kPrev, $dPrev, $k9, $d9), 6, 'KD 死亡交叉');
        $this->bear($module, $k9 !== null && $d9 !== null && $k9 < $d9, 3, 'KD 空方排列');
        $this->bear($module, $rsi14 !== null && $rsi14 < 35, 5, 'RSI 弱勢區');
        $this->bear($module, $close !== null && $bollLower !== null && $close < $bollLower, 6, '跌破布林下緣', 'below_bollinger_lower');
        $this->bear($module, $return5 !== null && $return5 <= -5, 4, '短線轉弱');
        $this->bear($module, $return20 !== null && $return20 <= -8, 5, '月線趨勢轉弱');
        $this->bear($module, $return60 !== null && $return60 <= -12, 4, '中期趨勢轉弱');
        $this->risk($module, $rsi14 !== null && $rsi14 > 78, 5, 'RSI 過熱', 'rsi_overheated');
        $this->risk($module, $bais20 !== null && $bais20 >= 12, 6, '乖離過大', 'bais_overheated');
        $this->bear($module, $bais20 !== null && $bais20 <= -10, 4, '乖離偏弱');

        foreach ($this->jsonArray($row->risk_flags ?? null) as $flag) {
            if ($flag === 'high_volume_down') {
                $this->risk($module, true, 8, '爆量轉弱', $flag);
            }
        }

        return $this->finish($module);
    }

    /**
     * @return array<string,mixed>
     */
    private function chip(mixed $chip, Stock $stock): array
    {
        $module = $this->module();

        if (! $chip) {
            $this->risk($module, true, 8, '籌碼資料不足', 'chip_missing');

            return $this->finish($module);
        }

        $latestPrice = $stock->dailyPrices()->latest('trade_date')->first();
        $volume = max(1, (float) ($latestPrice?->volume ?? 1));
        $previous = DB::table('stock_chips_1d')
            ->where('stock_id', $stock->id)
            ->where('trade_date', '<', $chip->trade_date)
            ->orderByDesc('trade_date')
            ->first();

        $foreignRatio = (float) ($chip->foreign_net_buy ?? 0) / $volume;
        $trustRatio = (float) ($chip->investment_trust_net_buy ?? 0) / $volume;
        $dealerRatio = (float) ($chip->dealer_net_buy ?? 0) / $volume;
        $institutionalRatio = (float) ($chip->institutional_net_buy ?? 0) / $volume;
        $marginBalance = (float) ($chip->margin_balance ?? 0);
        $shortBalance = (float) ($chip->short_balance ?? 0);
        $marginChangeRatio = $previous && (float) ($previous->margin_balance ?? 0) > 0
            ? ($marginBalance - (float) $previous->margin_balance) / (float) $previous->margin_balance
            : null;

        $this->bull($module, $institutionalRatio >= 0.04, 18, '三大法人明顯買超');
        $this->bull($module, $institutionalRatio > 0 && $institutionalRatio < 0.04, 8, '三大法人買超');
        $this->bull($module, $foreignRatio >= 0.025, 10, '外資買超');
        $this->bull($module, $trustRatio >= 0.012, 10, '投信買超');
        $this->bull($module, $dealerRatio >= 0.012, 6, '自營商買超');
        $this->bull($module, (float) ($chip->foreign_net_buy ?? 0) > 0 && (float) ($chip->investment_trust_net_buy ?? 0) > 0, 10, '外資投信同步買超');
        $this->bull($module, $marginChangeRatio !== null && $marginChangeRatio <= -0.03, 6, '融資下降');
        $this->bull($module, $shortBalance / $volume < 0.15, 4, '券資壓力低');
        $this->bull($module, $this->num($chip->foreign_held_ratio ?? null) !== null && $this->num($chip->foreign_held_ratio) >= 20, 5, '外資持股穩定');

        $this->bear($module, $institutionalRatio <= -0.04, 18, '三大法人明顯賣超', 'institutional_selling');
        $this->bear($module, $institutionalRatio < 0 && $institutionalRatio > -0.04, 8, '三大法人賣超');
        $this->bear($module, $foreignRatio <= -0.025, 10, '外資賣超');
        $this->bear($module, $trustRatio <= -0.012, 10, '投信賣超');
        $this->bear($module, $dealerRatio <= -0.012, 6, '自營商賣超');
        $this->bear($module, (float) ($chip->foreign_net_buy ?? 0) < 0 && (float) ($chip->investment_trust_net_buy ?? 0) < 0, 10, '外資投信同步賣超', 'foreign_and_trust_selling');
        $this->risk($module, $marginChangeRatio !== null && $marginChangeRatio >= 0.05, 8, '融資快速增加', 'margin_increasing');
        $this->risk($module, $marginBalance / $volume >= 5, 7, '融資偏重', 'margin_heavy');
        $this->risk($module, $shortBalance / $volume >= 0.5, 5, '券資壓力高');
        $this->risk($module, (bool) ($chip->day_trade_suspended ?? false), 4, '當沖受限', 'day_trade_suspended');

        return $this->finish($module);
    }

    /**
     * @return array<string,mixed>
     */
    private function fundamental(mixed $financial, mixed $revenue): array
    {
        $module = $this->module();

        if (! $financial && ! $revenue) {
            $this->risk($module, true, 8, '財報營收資料不足', 'fundamental_missing');

            return $this->finish($module);
        }

        $yoy = $this->num($revenue?->yoy_pct ?? null);
        $mom = $this->num($revenue?->mom_pct ?? null);
        $eps = $this->num($financial?->eps ?? null);
        $roe = $this->num($financial?->roe ?? null);
        $gross = $this->num($financial?->gross_margin ?? null);
        $operating = $this->num($financial?->operating_margin ?? null);
        $per = $this->num($financial?->per ?? null);
        $pb = $this->num($financial?->pb_ratio ?? null);

        $this->bull($module, $yoy !== null && $yoy >= 20, 20, '營收年增強');
        $this->bull($module, $yoy !== null && $yoy >= 5 && $yoy < 20, 12, '營收年增');
        $this->bull($module, $mom !== null && $mom >= 8, 8, '營收月增');
        $this->bull($module, $eps !== null && $eps >= 3, 12, 'EPS 表現佳');
        $this->bull($module, $eps !== null && $eps > 0 && $eps < 3, 6, 'EPS 為正');
        $this->bull($module, $roe !== null && $roe >= 15, 12, 'ROE 良好');
        $this->bull($module, $gross !== null && $gross >= 30, 8, '毛利率佳');
        $this->bull($module, $operating !== null && $operating >= 15, 5, '營益率佳');
        $this->bull($module, $per !== null && $per > 0 && $per <= 15, 8, '本益比偏低');
        $this->bull($module, $per !== null && $per > 15 && $per <= 25, 4, '本益比尚可');
        $this->bull($module, $pb !== null && $pb > 0 && $pb <= 2, 4, '股價淨值比尚可');

        $this->bear($module, $yoy !== null && $yoy <= -15, 20, '營收年減擴大', 'revenue_decline');
        $this->bear($module, $yoy !== null && $yoy < 0 && $yoy > -15, 8, '營收年減');
        $this->bear($module, $mom !== null && $mom <= -8, 8, '營收月減');
        $this->bear($module, $eps !== null && $eps < 0, 15, 'EPS 虧損', 'eps_loss');
        $this->bear($module, $roe !== null && $roe < 5, 8, 'ROE 偏低');
        $this->bear($module, $gross !== null && $gross < 10, 8, '毛利率偏低');
        $this->bear($module, $operating !== null && $operating < 0, 6, '營益率轉負');
        $this->risk($module, $per !== null && $per > 40, 12, '本益比過高', 'high_per');
        $this->risk($module, $per !== null && $per > 30 && $per <= 40, 6, '本益比偏高');
        $this->risk($module, $pb !== null && $pb > 6, 4, '股價淨值比偏高');

        return $this->finish($module);
    }

    /**
     * @return array<string,mixed>
     */
    private function theme(int $themeScore, int $eventScore): array
    {
        $module = $this->module();

        $this->bull($module, $themeScore >= 85, 70, '題材熱度高');
        $this->bull($module, $themeScore >= 70 && $themeScore < 85, 55, '題材明顯升溫');
        $this->bull($module, $themeScore >= 55 && $themeScore < 70, 40, '題材升溫');
        $this->bull($module, $themeScore >= 40 && $themeScore < 55, 25, '題材開始升溫');
        $this->bull($module, $eventScore >= 75, 15, '事件面偏多');
        $this->bull($module, $eventScore >= 55 && $eventScore < 75, 8, '事件面有支撐');
        $this->bear($module, $themeScore < 30, 12, '題材熱度低');
        $this->bear($module, $eventScore > 0 && $eventScore < 40, 8, '事件面支撐不足');
        $this->risk($module, $themeScore >= 90, 10, '題材過熱', 'theme_overheated');

        return $this->finish($module);
    }

    /**
     * @return array<string,mixed>
     */
    private function module(): array
    {
        return [
            'bull_score' => 0.0,
            'bear_score' => 0.0,
            'risk_penalty' => 0.0,
            'bull_reasons' => [],
            'bear_reasons' => [],
            'risk_reasons' => [],
            'risk_flags' => [],
        ];
    }

    /**
     * @param array<string,mixed> $module
     */
    private function bull(array &$module, bool $condition, float $points, string $reason): void
    {
        if (! $condition) {
            return;
        }

        $module['bull_score'] += $points;
        $module['bull_reasons'][] = $reason;
    }

    /**
     * @param array<string,mixed> $module
     */
    private function bear(array &$module, bool $condition, float $points, string $reason, ?string $flag = null): void
    {
        if (! $condition) {
            return;
        }

        $module['bear_score'] += $points;
        $module['bear_reasons'][] = $reason;

        if ($flag !== null) {
            $module['risk_flags'][] = $flag;
        }
    }

    /**
     * @param array<string,mixed> $module
     */
    private function risk(array &$module, bool $condition, float $points, string $reason, ?string $flag = null): void
    {
        if (! $condition) {
            return;
        }

        $module['risk_penalty'] += $points;
        $module['risk_reasons'][] = $reason;

        if ($flag !== null) {
            $module['risk_flags'][] = $flag;
        }
    }

    /**
     * @param array<string,mixed> $module
     * @return array<string,mixed>
     */
    private function finish(array $module): array
    {
        $score = (int) round(max(0, min(100, $module['bull_score'] - $module['bear_score'] - $module['risk_penalty'])));

        return [
            'score' => $score,
            'bull_score' => round($module['bull_score'], 2),
            'bear_score' => round($module['bear_score'], 2),
            'risk_penalty' => round($module['risk_penalty'], 2),
            'bull_reasons' => array_values(array_unique($module['bull_reasons'])),
            'bear_reasons' => array_values(array_unique($module['bear_reasons'])),
            'risk_reasons' => array_values(array_unique($module['risk_reasons'])),
            'risk_flags' => array_values(array_unique($module['risk_flags'])),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $modules
     * @param array<string,float> $weights
     */
    private function weightedPressure(array $modules, array $weights, bool $includeRisk): int
    {
        $score = 0.0;

        foreach ($weights as $name => $weight) {
            $points = (float) $modules[$name]['bear_score'];

            if ($includeRisk) {
                $points += (float) $modules[$name]['risk_penalty'];
            }

            $score += min(100, $points) * $weight;
        }

        return max(0, min(100, (int) round($score)));
    }

    private function crossUp(?float $previousValue, ?float $previousSignal, ?float $value, ?float $signal): bool
    {
        return $previousValue !== null
            && $previousSignal !== null
            && $value !== null
            && $signal !== null
            && $previousValue <= $previousSignal
            && $value > $signal;
    }

    private function crossDown(?float $previousValue, ?float $previousSignal, ?float $value, ?float $signal): bool
    {
        return $previousValue !== null
            && $previousSignal !== null
            && $value !== null
            && $signal !== null
            && $previousValue >= $previousSignal
            && $value < $signal;
    }

    /**
     * @return array<int, mixed>
     */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
