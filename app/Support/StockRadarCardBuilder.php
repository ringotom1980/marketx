<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockRadarCardBuilder
{
    /**
     * @return array<string, Collection<int, array<string, mixed>>>
     */
    public function build(?string $cardDate = null, int $limit = 6): array
    {
        $cardDate ??= $this->latestScoreDate();
        $candidates = $this->candidates();
        $used = [];

        $risk = $this->select($candidates, 'risk', $limit, $used);
        $used = array_merge($used, $risk->pluck('symbol')->all());

        $priority = $this->select($candidates, 'priority', $limit, $used);
        $used = array_merge($used, $priority->pluck('symbol')->all());

        $lowVolume = $this->select($candidates, 'low_volume', $limit, $used);
        $used = array_merge($used, $lowVolume->pluck('symbol')->all());

        $potential = $this->select($candidates, 'potential', $limit, $used);
        $used = array_merge($used, $potential->pluck('symbol')->all());

        $weak = $this->select($candidates, 'weak', $limit, $used);

        return [
            'card_date' => $cardDate,
            'risk' => $risk,
            'priority' => $priority,
            'low_volume' => $lowVolume,
            'potential' => $potential,
            'weak' => $weak,
        ];
    }

    public function latestScoreDate(): string
    {
        return (string) (DB::table('stock_scores')->max('score_date')
            ?: CarbonImmutable::now('Asia/Taipei')->toDateString());
    }

    /**
     * @return Collection<int, object>
     */
    private function candidates(): Collection
    {
        return DB::table('stocks')
            ->join('stock_scores', function ($join) {
                $join->on('stocks.id', '=', 'stock_scores.stock_id')
                    ->whereRaw('stock_scores.score_date = (select max(ss.score_date) from stock_scores ss where ss.stock_id = stocks.id)');
            })
            ->leftJoin('stock_prices_1d', function ($join) {
                $join->on('stocks.id', '=', 'stock_prices_1d.stock_id')
                    ->whereRaw('stock_prices_1d.trade_date = (select max(sp.trade_date) from stock_prices_1d sp where sp.stock_id = stocks.id)');
            })
            ->leftJoin('stock_technical_indicators_1d', function ($join) {
                $join->on('stocks.id', '=', 'stock_technical_indicators_1d.stock_id')
                    ->whereRaw('stock_technical_indicators_1d.trade_date = (select max(sti.trade_date) from stock_technical_indicators_1d sti where sti.stock_id = stocks.id)');
            })
            ->leftJoin('stock_chips_1d', function ($join) {
                $join->on('stocks.id', '=', 'stock_chips_1d.stock_id')
                    ->whereRaw('stock_chips_1d.trade_date = (select max(sc.trade_date) from stock_chips_1d sc where sc.stock_id = stocks.id)');
            })
            ->leftJoin('stock_financials', function ($join) {
                $join->on('stocks.id', '=', 'stock_financials.stock_id')
                    ->whereRaw('stock_financials.period = (select max(sf.period) from stock_financials sf where sf.stock_id = stocks.id)');
            })
            ->leftJoin('stock_revenues', function ($join) {
                $join->on('stocks.id', '=', 'stock_revenues.stock_id')
                    ->whereRaw('stock_revenues.year_month = (select max(sr.year_month) from stock_revenues sr where sr.stock_id = stocks.id)');
            })
            ->where('stocks.is_active', true)
            ->whereNotNull('stock_scores.total_score')
            ->where('stock_scores.technical_score', '>', 0)
            ->select([
                'stocks.id as stock_id',
                'stocks.symbol',
                'stocks.name',
                'stock_scores.total_score',
                'stock_scores.confidence_score',
                'stock_scores.confidence_payload',
                'stock_scores.theme_score',
                'stock_scores.technical_score',
                'stock_scores.chip_score',
                'stock_scores.fundamental_score',
                'stock_prices_1d.open',
                'stock_prices_1d.high',
                'stock_prices_1d.low',
                'stock_prices_1d.close',
                'stock_prices_1d.change_pct',
                'stock_prices_1d.volume',
                'stock_technical_indicators_1d.sma20',
                'stock_technical_indicators_1d.sma60',
                'stock_technical_indicators_1d.sma120',
                'stock_technical_indicators_1d.sma240',
                'stock_technical_indicators_1d.bais20',
                'stock_technical_indicators_1d.return10',
                'stock_technical_indicators_1d.return20',
                'stock_technical_indicators_1d.return60',
                'stock_technical_indicators_1d.volume_ratio20',
                'stock_technical_indicators_1d.rsi14',
                'stock_technical_indicators_1d.macd',
                'stock_technical_indicators_1d.macd_signal',
                'stock_technical_indicators_1d.macd_histogram',
                'stock_technical_indicators_1d.macd_previous',
                'stock_technical_indicators_1d.macd_signal_previous',
                'stock_technical_indicators_1d.macd_histogram_previous',
                'stock_technical_indicators_1d.k9',
                'stock_technical_indicators_1d.d9',
                'stock_technical_indicators_1d.k9_previous',
                'stock_technical_indicators_1d.d9_previous',
                'stock_technical_indicators_1d.bollinger_upper20',
                'stock_technical_indicators_1d.bollinger_lower20',
                'stock_technical_indicators_1d.breakout20',
                'stock_chips_1d.foreign_net_buy',
                'stock_chips_1d.investment_trust_net_buy',
                'stock_chips_1d.institutional_net_buy',
                'stock_chips_1d.margin_balance',
                'stock_chips_1d.short_balance',
                'stock_financials.per',
                'stock_financials.pb_ratio',
                'stock_revenues.mom_pct',
                'stock_revenues.yoy_pct',
                DB::raw('(select sp_prev.close from stock_prices_1d sp_prev where sp_prev.stock_id = stocks.id and sp_prev.trade_date < stock_prices_1d.trade_date order by sp_prev.trade_date desc limit 1) as previous_close'),
                DB::raw('(select sp_prev.volume from stock_prices_1d sp_prev where sp_prev.stock_id = stocks.id and sp_prev.trade_date < stock_prices_1d.trade_date order by sp_prev.trade_date desc limit 1) as previous_volume'),
            ])
            ->orderBy('stocks.symbol')
            ->get()
            ->map(function (object $stock) {
                $payload = $this->confidencePayload($stock);
                $reasonCounts = $this->payloadReasonCounts($payload);

                $stock->confidence = $this->opportunityConfidence($stock);
                $stock->risk_confidence = (int) ($payload['risk_confidence'] ?? 0);
                $stock->weak_confidence = (int) ($payload['weak_confidence'] ?? 0);
                $stock->bull_reason_count = $reasonCounts['bull'];
                $stock->bear_reason_count = $reasonCounts['bear'];
                $stock->risk_reason_count = $reasonCounts['risk'];
                $stock->volume_multiple = $this->volumeMultiple($stock);

                return $stock;
            });
    }

    /**
     * @param Collection<int, object> $candidates
     * @param array<int, string> $usedSymbols
     * @return Collection<int, array<string, mixed>>
     */
    private function select(Collection $candidates, string $type, int $limit, array $usedSymbols): Collection
    {
        return $candidates
            ->reject(fn (object $stock) => in_array($stock->symbol, $usedSymbols, true))
            ->map(function (object $stock) use ($type) {
                $stock->radar_reasons = $this->reasons($stock, $type);

                return $stock;
            })
            ->filter(fn (object $stock) => $this->matches($stock, $type) && count($stock->radar_reasons) >= $this->minReasonCount($type))
            ->sortByDesc(fn (object $stock) => sprintf(
                '%03d-%03d-%06.2f-%06.2f-%s',
                $this->rankConfidence($stock, $type),
                (int) ($stock->theme_score ?? 0),
                (float) ($stock->volume_multiple ?? 0),
                (float) ($stock->return20 ?? 0),
                $stock->symbol,
            ))
            ->take($limit)
            ->values()
            ->map(fn (object $stock) => [
                'stock_id' => (int) $stock->stock_id,
                'symbol' => $stock->symbol,
                'name' => $stock->name,
                'confidence' => $this->displayConfidence($stock),
                'reasons' => $stock->radar_reasons,
                'metrics' => $this->metrics($stock),
            ]);
    }

    private function matches(object $stock, string $type): bool
    {
        $return20 = (float) ($stock->return20 ?? 0);
        $bais20 = $this->num($stock->bais20);
        $technicalScore = (int) ($stock->technical_score ?? 0);
        $themeScore = (int) ($stock->theme_score ?? 0);
        $opportunity = (int) ($stock->confidence ?? 0);
        $riskConfidence = (int) ($stock->risk_confidence ?? 0);
        $weakConfidence = (int) ($stock->weak_confidence ?? 0);
        $bull = (int) ($stock->bull_reason_count ?? 0);
        $bear = (int) ($stock->bear_reason_count ?? 0);
        $risk = (int) ($stock->risk_reason_count ?? 0);

        if (in_array($type, ['priority', 'potential'], true) && ($riskConfidence >= 35 || $weakConfidence >= 45)) {
            return false;
        }

        if ($type === 'priority' && $themeScore < 30) {
            return false;
        }

        if ($type === 'risk') {
            return $this->isHighExpectationRisk($stock)
                && ! $this->isLowBaseVolumeBreakout($stock)
                && count($stock->radar_reasons) >= 2;
        }

        if ($type === 'weak') {
            return $weakConfidence >= 28
                && $opportunity <= 62
                && ($bear + $risk) >= max(3, $bull)
                && ! $this->isLowBaseVolumeBreakout($stock)
                && ($technicalScore < 48 || $return20 <= -8 || $this->hasAny($stock->radar_reasons, ['??蝛粹??', '頝??', 'MACD 甇颱滿鈭文?']));
        }

        return match ($type) {
            'priority' => (int) ($stock->total_score ?? 0) >= 58
                && ($bais20 === null || abs($bais20) <= 12)
                && $this->hasAny($stock->radar_reasons, ['MACD 黃金交叉', 'KD 黃金交叉', '20日突破', '價漲量增', '均線多頭排列', '題材升溫', '營收轉強']),
            'risk' => $this->hasAny($stock->radar_reasons, ['高檔放量轉弱', '爆量轉弱', '乖離過大', 'RSI 過熱', 'KD 過熱', 'MACD 正數縮減', '評價偏高', '融資偏重', '營收轉弱']),
            'potential' => (int) ($stock->total_score ?? 0) >= 45
                && $return20 < 12
                && ($themeScore >= 40 || (int) ($stock->technical_score ?? 0) >= 55 || $this->revenueStrong($stock) || $this->institutionalBuying($stock))
                && ! $this->isOverheated($stock),
            'low_volume' => $this->isLowBaseVolumeBreakout($stock),
            'weak' => (int) ($stock->confidence ?? 0) < 55
                && ! $this->isLowBaseVolumeBreakout($stock)
                && ($technicalScore < 48 || $return20 <= -8 || $this->hasAny($stock->radar_reasons, ['均線空頭排列', '跌破月線', 'MACD 死亡交叉'])),
            default => false,
        };
    }

    private function minReasonCount(string $type): int
    {
        return $type === 'low_volume' ? 2 : 2;
    }

    /**
     * @return array<int, array{label:string,tone:string}>
     */
    private function reasons(object $stock, string $type): array
    {
        $reasons = match ($type) {
            'priority' => $this->bullReasons($stock),
            'risk' => $this->riskReasons($stock),
            'potential' => $this->potentialReasons($stock),
            'low_volume' => $this->lowVolumeReasons($stock),
            'weak' => $this->weakReasons($stock),
            default => [],
        };

        return collect($reasons)->unique('label')->take(4)->values()->all();
    }

    /**
     * @return array<int, array{label:string,tone:string}>
     */
    private function bullReasons(object $stock): array
    {
        $reasons = [];

        if ($this->macdGoldenCross($stock)) {
            $reasons[] = $this->reason('MACD 黃金交叉', 'up');
        }
        if ($this->kdGoldenCross($stock)) {
            $reasons[] = $this->reason('KD 黃金交叉', 'up');
        }
        if ($this->movingAverageBull($stock)) {
            $reasons[] = $this->reason('均線多頭排列', 'up');
        }
        if ((bool) ($stock->breakout20 ?? false)) {
            $reasons[] = $this->reason('20日突破', 'up');
        }
        if ($this->priceVolumeUp($stock)) {
            $reasons[] = $this->reason('價漲量增', 'up');
        }
        if ($this->num($stock->rsi14) !== null && $this->num($stock->rsi14) >= 55 && $this->num($stock->rsi14) <= 72) {
            $reasons[] = $this->reason('RSI 強勢區', 'up');
        }
        if ((int) ($stock->theme_score ?? 0) >= 60) {
            $reasons[] = $this->reason('題材升溫', 'up');
        }
        if ($this->revenueStrong($stock)) {
            $reasons[] = $this->reason('營收轉強', 'up');
        }
        if ($this->institutionalBuying($stock)) {
            $reasons[] = $this->reason('法人買超', 'up');
        }

        return $reasons;
    }

    /**
     * @return array<int, array{label:string,tone:string}>
     */
    private function riskReasons(object $stock): array
    {
        $reasons = [];

        if ($this->priceExtended($stock)) {
            $reasons[] = $this->reason('漲幅偏高', 'warning');
        }
        if ($this->valuationStretched($stock)) {
            $reasons[] = $this->reason('評價偏貴', 'warning');
        }
        if ($this->fundamentalsLagPrice($stock)) {
            $reasons[] = $this->reason('營收跟不上', 'warning');
        }
        if ($this->highVolumeWeakReversal($stock)) {
            $reasons[] = $this->reason('高檔放量轉弱', 'warning');
        }
        if ($this->num($stock->bais20) !== null && $this->num($stock->bais20) >= 12) {
            $reasons[] = $this->reason('乖離過大', 'warning');
        }
        if ($this->num($stock->rsi14) !== null && $this->num($stock->rsi14) > 78) {
            $reasons[] = $this->reason('RSI 過熱', 'warning');
        }
        if ($this->num($stock->k9) !== null && $this->num($stock->k9) > 85) {
            $reasons[] = $this->reason('KD 過熱', 'warning');
        }
        if ($this->macdPositiveShrinking($stock)) {
            $reasons[] = $this->reason('MACD 正數縮減', 'warning');
        }
        if ($this->num($stock->per) !== null && $this->num($stock->per) > 35) {
            $reasons[] = $this->reason('評價偏高', 'warning');
        }
        if ($this->marginHeavy($stock)) {
            $reasons[] = $this->reason('融資偏重', 'warning');
        }
        if ($this->fragileChipAtHighPrice($stock)) {
            $reasons[] = $this->reason('高檔籌碼轉弱', 'warning');
        }
        if ($this->revenueWeak($stock)) {
            $reasons[] = $this->reason('營收轉弱', 'down');
        }
        if ($this->institutionalSelling($stock)) {
            $reasons[] = $this->reason('法人賣超', 'down');
        }

        return $reasons;
    }

    /**
     * @return array<int, array{label:string,tone:string}>
     */
    private function potentialReasons(object $stock): array
    {
        $reasons = [];

        if ((int) ($stock->theme_score ?? 0) >= 45 && (int) ($stock->theme_score ?? 0) < 75) {
            $reasons[] = $this->reason('題材逐漸升溫', 'warning');
        }
        if ((int) ($stock->technical_score ?? 0) >= 55) {
            $reasons[] = $this->reason('技術轉強', 'warning');
        }
        if ((int) ($stock->confidence ?? 0) >= 50) {
            $reasons[] = $this->reason('信心中上', 'warning');
        }
        if ($this->num($stock->per) !== null && $this->num($stock->per) > 0 && $this->num($stock->per) <= 25) {
            $reasons[] = $this->reason('評價尚可', 'warning');
        }
        if ($this->num($stock->return20) !== null && $this->num($stock->return20) < 8) {
            $reasons[] = $this->reason('漲幅未過熱', 'warning');
        }
        if ($this->priceVolumeUp($stock)) {
            $reasons[] = $this->reason('價漲量增', 'up');
        }
        if ($this->institutionalBuying($stock)) {
            $reasons[] = $this->reason('法人買超', 'up');
        }
        if ($this->revenueStrong($stock)) {
            $reasons[] = $this->reason('營收轉強', 'up');
        }

        return $reasons;
    }

    /**
     * @return array<int, array{label:string,tone:string}>
     */
    private function lowVolumeReasons(object $stock): array
    {
        $reasons = [];

        if ($this->lowBase($stock)) {
            $reasons[] = $this->reason('低檔整理', 'warning');
        }
        if ((float) ($stock->return20 ?? 0) <= -6 || (float) ($stock->return60 ?? 0) <= -10) {
            $reasons[] = $this->reason('前段修正', 'warning');
        }
        if ((float) ($stock->volume_multiple ?? 0) >= 1.8 || (float) ($stock->volume_ratio20 ?? 0) >= 1.5) {
            $reasons[] = $this->reason('量能放大', 'up');
        }
        if ($this->priceUp($stock)) {
            $reasons[] = $this->reason('股價轉強', 'up');
        }
        if ((bool) ($stock->breakout20 ?? false)) {
            $reasons[] = $this->reason('20日突破', 'up');
        }

        return $reasons;
    }

    /**
     * @return array<int, array{label:string,tone:string}>
     */
    private function weakReasons(object $stock): array
    {
        $reasons = [];

        if ($this->movingAverageBear($stock)) {
            $reasons[] = $this->reason('均線空頭排列', 'down');
        }
        if ($this->num($stock->close) !== null && $this->num($stock->sma20) !== null && $this->num($stock->close) < $this->num($stock->sma20)) {
            $reasons[] = $this->reason('跌破月線', 'down');
        }
        if ($this->macdDeadCross($stock)) {
            $reasons[] = $this->reason('MACD 死亡交叉', 'down');
        }
        if ($this->kdDeadCross($stock)) {
            $reasons[] = $this->reason('KD 死亡交叉', 'down');
        }
        if ($this->num($stock->rsi14) !== null && $this->num($stock->rsi14) < 35) {
            $reasons[] = $this->reason('RSI 弱勢區', 'down');
        }
        if ($this->revenueWeak($stock)) {
            $reasons[] = $this->reason('營收轉弱', 'down');
        }
        if ($this->institutionalSelling($stock)) {
            $reasons[] = $this->reason('法人賣超', 'down');
        }

        return $reasons;
    }

    private function isLowBaseVolumeBreakout(object $stock): bool
    {
        $close = $this->num($stock->close);
        $return20 = (float) ($stock->return20 ?? 0);
        $return60 = (float) ($stock->return60 ?? 0);
        $volumeMultiple = (float) ($stock->volume_multiple ?? 0);
        $volumeRatio20 = (float) ($stock->volume_ratio20 ?? 0);

        return $this->lowBase($stock)
            && $this->priceUp($stock)
            && ($volumeMultiple >= 1.8 || $volumeRatio20 >= 1.5)
            && ! $this->isOverheated($stock);
    }

    private function lowBase(object $stock): bool
    {
        $close = $this->num($stock->close);
        $previousClose = $this->num($stock->previous_close);
        $sma60 = $this->num($stock->sma60);
        $sma120 = $this->num($stock->sma120);
        $sma240 = $this->num($stock->sma240);
        $return20 = (float) ($stock->return20 ?? 0);
        $return60 = (float) ($stock->return60 ?? 0);

        return ($close !== null && $sma60 !== null && $close <= $sma60 * 1.03)
            || ($previousClose !== null && $sma60 !== null && $previousClose < $sma60)
            || ($close !== null && $sma120 !== null && $close < $sma120)
            || ($close !== null && $sma240 !== null && $close < $sma240)
            || $return20 <= -4
            || $return60 <= -8
            || abs($return60) <= 6;
    }

    private function highVolumeWeakReversal(object $stock): bool
    {
        $open = $this->num($stock->open);
        $high = $this->num($stock->high);
        $low = $this->num($stock->low);
        $close = $this->num($stock->close);

        if ($open === null || $high === null || $low === null || $close === null || $high <= $low) {
            return false;
        }

        $upperShadowRatio = ($high - max($open, $close)) / max(0.01, $high - $low);

        return ((float) ($stock->return20 ?? 0) >= 12 || (float) ($stock->bais20 ?? 0) >= 10)
            && $close < $open
            && $upperShadowRatio >= 0.4
            && (float) ($stock->volume_multiple ?? 0) >= 2.0;
    }

    private function opportunityConfidence(object $stock): int
    {
        $payload = $this->confidencePayload($stock);

        if (is_array($payload) && isset($payload['opportunity_confidence'])) {
            return max(5, min(95, (int) $payload['opportunity_confidence']));
        }

        return max(5, min(95, (int) ($stock->confidence_score ?? 0)));
    }

    private function rankConfidence(object $stock, string $type): int
    {
        return match ($type) {
            'risk' => (int) ($stock->risk_confidence ?? 0),
            'weak' => (int) ($stock->weak_confidence ?? 0),
            default => (int) ($stock->confidence ?? 0),
        };
    }

    private function displayConfidence(object $stock): int
    {
        return max(5, min(95, (int) ($stock->confidence ?? 0)));
    }

    /**
     * @return array<string,mixed>
     */
    private function confidencePayload(object $stock): array
    {
        $payload = $stock->confidence_payload;

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{bull:int,bear:int,risk:int}
     */
    private function payloadReasonCounts(array $payload): array
    {
        $reasons = is_array($payload['reasons'] ?? null) ? $payload['reasons'] : [];

        return [
            'bull' => count(is_array($reasons['bull'] ?? null) ? $reasons['bull'] : []),
            'bear' => count(is_array($reasons['bear'] ?? null) ? $reasons['bear'] : []),
            'risk' => count(is_array($reasons['risk'] ?? null) ? $reasons['risk'] : []),
        ];
    }

    private function metrics(object $stock): array
    {
        return [
            'themes' => $this->stockThemes((int) $stock->stock_id),
            'total_score' => (int) ($stock->total_score ?? 0),
            'theme_score' => (int) ($stock->theme_score ?? 0),
            'technical_score' => (int) ($stock->technical_score ?? 0),
            'chip_score' => $stock->chip_score === null ? null : (int) $stock->chip_score,
            'fundamental_score' => $stock->fundamental_score === null ? null : (int) $stock->fundamental_score,
            'return20' => $this->num($stock->return20),
            'return60' => $this->num($stock->return60),
            'bais20' => $this->num($stock->bais20),
            'volume_ratio20' => $this->num($stock->volume_ratio20),
            'volume_multiple' => $this->num($stock->volume_multiple),
            'per' => $this->num($stock->per),
            'mom_pct' => $this->num($stock->mom_pct),
            'yoy_pct' => $this->num($stock->yoy_pct),
            'opportunity_confidence' => (int) ($stock->confidence ?? 0),
            'risk_confidence' => (int) ($stock->risk_confidence ?? 0),
            'weak_confidence' => (int) ($stock->weak_confidence ?? 0),
            'bull_reason_count' => (int) ($stock->bull_reason_count ?? 0),
            'bear_reason_count' => (int) ($stock->bear_reason_count ?? 0),
            'risk_reason_count' => (int) ($stock->risk_reason_count ?? 0),
        ];
    }

    /**
     * @return array<int, array{name:string,slug:string,heat_score:int|null}>
     */
    private function stockThemes(int $stockId): array
    {
        return DB::table('stock_theme_map')
            ->join('themes', 'themes.id', '=', 'stock_theme_map.theme_id')
            ->leftJoin('theme_scores', function ($join) {
                $join->on('themes.id', '=', 'theme_scores.theme_id')
                    ->whereRaw('theme_scores.score_date = (select max(ts.score_date) from theme_scores ts where ts.theme_id = themes.id)');
            })
            ->where('stock_theme_map.stock_id', $stockId)
            ->where('themes.is_active', true)
            ->where(function ($query) {
                $query->whereNull('theme_scores.heat_score')
                    ->orWhere('theme_scores.heat_score', '>', 0);
            })
            ->orderByDesc('theme_scores.heat_score')
            ->orderByDesc('stock_theme_map.weight')
            ->limit(2)
            ->get(['themes.name', 'themes.slug', 'theme_scores.heat_score'])
            ->map(fn (object $theme) => [
                'name' => (string) $theme->name,
                'slug' => (string) $theme->slug,
                'heat_score' => $theme->heat_score === null ? null : (int) $theme->heat_score,
            ])
            ->values()
            ->all();
    }

    private function reason(string $label, string $tone): array
    {
        return ['label' => $label, 'tone' => $tone];
    }

    private function hasAny(array $reasons, array $labels): bool
    {
        $reasonLabels = array_map(fn (array $reason) => $reason['label'] ?? '', $reasons);

        return collect($labels)->contains(fn (string $label) => in_array($label, $reasonLabels, true));
    }

    private function isOverheated(object $stock): bool
    {
        return ((float) ($stock->bais20 ?? 0) >= 12)
            || ((float) ($stock->rsi14 ?? 0) > 78)
            || ((float) ($stock->return20 ?? 0) >= 18);
    }

    private function isHighExpectationRisk(object $stock): bool
    {
        $rsi14 = $this->num($stock->rsi14);
        $bais20 = $this->num($stock->bais20);

        $highExpectation = $this->priceExtended($stock)
            || $this->themeOverheated($stock)
            || ($rsi14 !== null && $rsi14 >= 74)
            || ($bais20 !== null && $bais20 >= 10);

        if (! $highExpectation) {
            return false;
        }

        $riskEvidence = 0;

        foreach ([
            $this->valuationStretched($stock),
            $this->fundamentalsLagPrice($stock),
            $this->fragileChipAtHighPrice($stock),
            $this->highVolumeWeakReversal($stock),
            $this->macdPositiveShrinking($stock),
            $this->marginHeavy($stock),
            $this->institutionalSelling($stock),
            $this->revenueWeak($stock),
        ] as $flag) {
            if ($flag) {
                $riskEvidence++;
            }
        }

        return $riskEvidence >= 1;
    }

    private function priceExtended(object $stock): bool
    {
        return (float) ($stock->return20 ?? 0) >= 12
            || (float) ($stock->return60 ?? 0) >= 25
            || (float) ($stock->bais20 ?? 0) >= 10
            || (
                $this->num($stock->close) !== null
                && $this->num($stock->bollinger_upper20) !== null
                && $this->num($stock->close) >= $this->num($stock->bollinger_upper20) * 0.99
            );
    }

    private function themeOverheated(object $stock): bool
    {
        return (int) ($stock->theme_score ?? 0) >= 75
            && ((float) ($stock->return20 ?? 0) >= 8 || (float) ($stock->bais20 ?? 0) >= 8);
    }

    private function valuationStretched(object $stock): bool
    {
        $per = $this->num($stock->per);
        $pb = $this->num($stock->pb_ratio);

        return ($per !== null && $per >= 35)
            || ($per !== null && $per >= 25 && $this->fundamentalsLagPrice($stock))
            || ($pb !== null && $pb >= 4.5 && ! $this->revenueStrong($stock));
    }

    private function fundamentalsLagPrice(object $stock): bool
    {
        $return20 = (float) ($stock->return20 ?? 0);
        $return60 = (float) ($stock->return60 ?? 0);
        $mom = $this->num($stock->mom_pct);
        $yoy = $this->num($stock->yoy_pct);

        if ($mom === null && $yoy === null) {
            return false;
        }

        return ($return20 >= 12 || $return60 >= 25)
            && (($mom !== null && $mom <= 0) || ($yoy !== null && $yoy <= 3));
    }

    private function fragileChipAtHighPrice(object $stock): bool
    {
        return ($this->priceExtended($stock) || $this->themeOverheated($stock))
            && ($this->institutionalSelling($stock) || $this->marginHeavy($stock));
    }

    private function movingAverageBull(object $stock): bool
    {
        return $this->num($stock->close) !== null
            && $this->num($stock->sma20) !== null
            && $this->num($stock->sma60) !== null
            && $this->num($stock->close) > $this->num($stock->sma20)
            && $this->num($stock->sma20) > $this->num($stock->sma60);
    }

    private function movingAverageBear(object $stock): bool
    {
        return $this->num($stock->sma20) !== null
            && $this->num($stock->sma60) !== null
            && $this->num($stock->sma20) < $this->num($stock->sma60);
    }

    private function macdGoldenCross(object $stock): bool
    {
        return $this->num($stock->macd_previous) !== null
            && $this->num($stock->macd_signal_previous) !== null
            && $this->num($stock->macd) !== null
            && $this->num($stock->macd_signal) !== null
            && $this->num($stock->macd_previous) <= $this->num($stock->macd_signal_previous)
            && $this->num($stock->macd) > $this->num($stock->macd_signal);
    }

    private function macdDeadCross(object $stock): bool
    {
        return $this->num($stock->macd_previous) !== null
            && $this->num($stock->macd_signal_previous) !== null
            && $this->num($stock->macd) !== null
            && $this->num($stock->macd_signal) !== null
            && $this->num($stock->macd_previous) >= $this->num($stock->macd_signal_previous)
            && $this->num($stock->macd) < $this->num($stock->macd_signal);
    }

    private function macdPositiveShrinking(object $stock): bool
    {
        return $this->num($stock->macd_histogram) !== null
            && $this->num($stock->macd_histogram_previous) !== null
            && $this->num($stock->macd_histogram) > 0
            && $this->num($stock->macd_histogram) < $this->num($stock->macd_histogram_previous);
    }

    private function kdGoldenCross(object $stock): bool
    {
        return $this->num($stock->k9_previous) !== null
            && $this->num($stock->d9_previous) !== null
            && $this->num($stock->k9) !== null
            && $this->num($stock->d9) !== null
            && $this->num($stock->k9_previous) <= $this->num($stock->d9_previous)
            && $this->num($stock->k9) > $this->num($stock->d9);
    }

    private function kdDeadCross(object $stock): bool
    {
        return $this->num($stock->k9_previous) !== null
            && $this->num($stock->d9_previous) !== null
            && $this->num($stock->k9) !== null
            && $this->num($stock->d9) !== null
            && $this->num($stock->k9_previous) >= $this->num($stock->d9_previous)
            && $this->num($stock->k9) < $this->num($stock->d9);
    }

    private function priceVolumeUp(object $stock): bool
    {
        return $this->priceUp($stock) && (float) ($stock->volume_multiple ?? 0) >= 1.2;
    }

    private function priceUp(object $stock): bool
    {
        return $this->num($stock->close) !== null
            && $this->num($stock->previous_close) !== null
            && $this->num($stock->close) > $this->num($stock->previous_close);
    }

    private function revenueStrong(object $stock): bool
    {
        return (float) ($stock->yoy_pct ?? 0) >= 5 || (float) ($stock->mom_pct ?? 0) >= 8;
    }

    private function revenueWeak(object $stock): bool
    {
        return (float) ($stock->yoy_pct ?? 0) <= -10 || (float) ($stock->mom_pct ?? 0) <= -8;
    }

    private function institutionalBuying(object $stock): bool
    {
        $volume = max(1, (float) ($stock->volume ?? 1));

        return ((float) ($stock->institutional_net_buy ?? 0) / $volume) >= 0.03;
    }

    private function institutionalSelling(object $stock): bool
    {
        $volume = max(1, (float) ($stock->volume ?? 1));

        return ((float) ($stock->institutional_net_buy ?? 0) / $volume) <= -0.03;
    }

    private function marginHeavy(object $stock): bool
    {
        $volume = max(1, (float) ($stock->volume ?? 1));

        return ((float) ($stock->margin_balance ?? 0) / $volume) >= 5;
    }

    private function volumeMultiple(object $stock): float
    {
        return (float) ($stock->volume ?? 0) / max(1, (float) ($stock->previous_volume ?? 0));
    }

    private function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
