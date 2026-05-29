<?php

namespace App\Support;

use App\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockReportPhraseComposer
{
    /**
     * @return array{summary:string,bull_case:string,bear_case:string,risk_summary:string,data_pack:array<string,mixed>}
     */
    public function compose(Stock $stock, mixed $score, mixed $chip, mixed $price, mixed $revenue): array
    {
        $technical = $this->latestTechnical($stock->id);
        $financial = $this->latestFinancial($stock->id);
        $themes = $this->themes($stock->id);
        $signals = $this->signals($score, $chip, $price, $revenue, $technical, $financial, $themes);
        $vars = $this->variables($stock, $score, $chip, $price, $revenue, $technical, $financial, $themes);

        $paragraphs = [
            '1、近期股價走勢與題材：'.$this->renderSection('price_theme', $signals['price_theme'], $vars, 3),
            '2、技術分析：'.$this->renderSection('technical', $signals['technical'], $vars, 3),
            '3、籌碼及資金走向：'.$this->renderSection('chip', $signals['chip'], $vars, 2),
            '4、營收狀況及股利政策：'.$this->renderSection('fundamental', $signals['fundamental'], $vars, 2),
            '5、總評：'.$this->renderSection('summary', $signals['summary'], $vars, 2),
        ];

        return [
            'summary' => implode("\n\n", $paragraphs),
            'bull_case' => $this->renderSection('summary', ['overall_bull', 'wait_for_confirmation'], $vars, 2),
            'bear_case' => $this->renderSection('summary', ['overall_risk', 'invalid_condition'], $vars, 2),
            'risk_summary' => $this->renderSection('summary', $signals['risk_summary'], $vars, 2),
            'data_pack' => [
                'symbol' => $stock->symbol,
                'name' => $stock->name,
                'engine' => 'phrase_library_v1',
                'signals' => $signals,
                'themes' => $themes,
                'score' => [
                    'decision' => $score?->decision,
                    'total_score' => $score?->total_score,
                    'confidence_score' => $score?->confidence_score,
                    'technical_score' => $score?->technical_score,
                    'chip_score' => $score?->chip_score,
                    'fundamental_score' => $score?->fundamental_score,
                    'theme_score' => $score?->theme_score,
                ],
            ],
        ];
    }

    /**
     * @return array{one_liner:string,condition_keys:array<string,array<int,string>>,engine:string}
     */
    public function composeQuickEvaluation(Stock $stock, mixed $score, mixed $chip, mixed $price, mixed $revenue, ?string $radarCardType = null): array
    {
        $technical = $this->latestTechnical($stock->id);
        $financial = $this->latestFinancial($stock->id);
        $themes = $this->themes($stock->id);
        $signals = $this->signals($score, $chip, $price, $revenue, $technical, $financial, $themes);
        $vars = $this->variables($stock, $score, $chip, $price, $revenue, $technical, $financial, $themes);
        $signals = $this->alignSignalsWithRadarCard($signals, $radarCardType);

        $parts = [
            $this->renderSection('price_theme', $signals['price_theme'], $vars, 1, false),
            $this->renderSection('technical', $signals['technical'], $vars, 1, false),
            $this->renderSection('chip', $signals['chip'], $vars, 1, false),
            $this->renderSection('fundamental', $signals['fundamental'], $vars, 1, false),
            $this->renderSection('summary', $signals['summary'], $vars, 1, false),
        ];

        return [
            'one_liner' => trim(implode('', array_filter($parts))),
            'condition_keys' => $signals,
            'engine' => 'phrase_library_v1',
        ];
    }

    /**
     * @param array<int, string> $conditionKeys
     */
    private function renderSection(string $section, array $conditionKeys, array $vars, int $limit, bool $trackUsage = true): string
    {
        $conditionKeys = array_values(array_unique(array_filter($conditionKeys)));

        if ($conditionKeys === []) {
            $conditionKeys = [$this->fallbackCondition($section)];
        }

        $phrases = DB::table('report_phrases')
            ->where('section', $section)
            ->where('status', 'active')
            ->whereIn('condition_key', $conditionKeys)
            ->orderByDesc('weight')
            ->orderBy('usage_count')
            ->limit(max($limit * 3, $limit))
            ->get(['id', 'template']);

        if ($phrases->isEmpty()) {
            $phrases = DB::table('report_phrases')
                ->where('section', $section)
                ->where('status', 'active')
                ->where('condition_key', $this->fallbackCondition($section))
                ->orderByDesc('weight')
                ->limit($limit)
                ->get(['id', 'template']);
        }

        $selected = $phrases->take($limit);

        if ($trackUsage && $selected->isNotEmpty()) {
            DB::table('report_phrases')
                ->whereIn('id', $selected->pluck('id')->all())
                ->increment('usage_count');
        }

        return $selected
            ->map(fn (object $phrase) => $this->replaceVars((string) $phrase->template, $vars))
            ->implode('');
    }

    private function fallbackCondition(string $section): string
    {
        return match ($section) {
            'price_theme' => 'price_sideways',
            'technical' => 'technical_mixed',
            'chip' => 'chip_neutral',
            'fundamental' => 'fundamental_stable',
            default => 'overall_watch',
        };
    }

    /**
     * @param array<string, array<int, string>> $signals
     * @return array<string, array<int, string>>
     */
    private function alignSignalsWithRadarCard(array $signals, ?string $radarCardType): array
    {
        if ($radarCardType === null) {
            return $signals;
        }

        $summary = match ($radarCardType) {
            'priority' => ['overall_bull', 'wait_for_confirmation'],
            'risk' => ['overall_risk', 'invalid_condition'],
            'potential', 'low_volume' => ['overall_watch', 'wait_for_confirmation'],
            'weak' => ['overall_bear', 'invalid_condition'],
            default => $signals['summary'],
        };

        $signals['summary'] = array_values(array_unique(array_merge($summary, $signals['summary'] ?? [])));

        if ($radarCardType === 'risk') {
            $signals['price_theme'] = array_values(array_unique(array_merge(['price_extended'], $signals['price_theme'] ?? [])));
            $signals['technical'] = array_values(array_unique(array_merge(['bais_high', 'macd_shrinking'], $signals['technical'] ?? [])));
        }

        if ($radarCardType === 'weak') {
            $signals['price_theme'] = array_values(array_unique(array_merge(['price_down_volume_up'], $signals['price_theme'] ?? [])));
            $signals['technical'] = array_values(array_unique(array_merge(['ma_bear', 'below_sma20'], $signals['technical'] ?? [])));
        }

        if ($radarCardType === 'low_volume') {
            $signals['price_theme'] = array_values(array_unique(array_merge(['low_base_breakout'], $signals['price_theme'] ?? [])));
        }

        return $signals;
    }

    private function replaceVars(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{'.$key.'}', (string) $value, $template);
        }

        return $template;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function signals(mixed $score, mixed $chip, mixed $price, mixed $revenue, mixed $technical, mixed $financial, array $themes): array
    {
        $close = $this->num($price?->close);
        $open = $this->num($price?->open);
        $changePct = $this->num($price?->change_pct);
        $volumeRatio20 = $this->num($technical?->volume_ratio20);
        $bais20 = $this->num($technical?->bais20);
        $rsi14 = $this->num($technical?->rsi14);
        $sma20 = $this->num($technical?->sma20);
        $sma60 = $this->num($technical?->sma60);
        $macd = $this->num($technical?->macd);
        $macdSignal = $this->num($technical?->macd_signal);
        $macdHist = $this->num($technical?->macd_histogram);
        $macdHistPrev = $this->num($technical?->macd_histogram_previous);
        $k9 = $this->num($technical?->k9);
        $d9 = $this->num($technical?->d9);
        $k9Prev = $this->num($technical?->k9_previous);
        $d9Prev = $this->num($technical?->d9_previous);
        $return20 = $this->num($technical?->return20) ?? 0.0;
        $return60 = $this->num($technical?->return60) ?? 0.0;
        $per = $this->num($financial?->per);
        $pb = $this->num($financial?->pb_ratio);
        $yoy = $this->num($revenue?->yoy_pct);
        $mom = $this->num($revenue?->mom_pct);
        $themeScore = (int) ($score?->theme_score ?? 0);

        $priceTheme = [];
        if ($themeScore >= 60 && ($changePct ?? 0) > 0) {
            $priceTheme[] = 'theme_hot_price_up';
        } elseif ($themes === []) {
            $priceTheme[] = 'theme_missing';
        }
        if (($changePct ?? 0) > 0 && ($volumeRatio20 ?? 0) >= 1.2) {
            $priceTheme[] = 'price_up_volume_up';
        } elseif (($changePct ?? 0) > 0) {
            $priceTheme[] = 'price_up_volume_flat';
        } elseif (($changePct ?? 0) < 0 && ($volumeRatio20 ?? 0) >= 1.2) {
            $priceTheme[] = 'price_down_volume_up';
        }
        if ($return20 >= 12 || $return60 >= 25 || ($bais20 ?? 0) >= 10) {
            $priceTheme[] = 'price_extended';
        }
        if (($changePct ?? 0) > 0 && ($volumeRatio20 ?? 0) >= 1.5 && ($close !== null && $sma60 !== null && $close <= $sma60 * 1.05)) {
            $priceTheme[] = 'low_base_breakout';
        }
        $priceTheme[] = 'price_sideways';

        $technicalSignals = [];
        if ($close !== null && $sma20 !== null && $sma60 !== null && $close > $sma20 && $sma20 > $sma60) {
            $technicalSignals[] = 'ma_bull';
        }
        if ($macd !== null && $macdSignal !== null && $macd > $macdSignal) {
            $technicalSignals[] = 'macd_bull';
        }
        if ($k9Prev !== null && $d9Prev !== null && $k9 !== null && $d9 !== null && $k9Prev <= $d9Prev && $k9 > $d9) {
            $technicalSignals[] = 'kd_golden';
        }
        if ($rsi14 !== null && $rsi14 >= 55 && $rsi14 <= 72) {
            $technicalSignals[] = 'rsi_strong';
        }
        if ((bool) ($technical?->breakout20 ?? false)) {
            $technicalSignals[] = 'breakout20';
        }
        if (($bais20 ?? 0) >= 10) {
            $technicalSignals[] = 'bais_high';
        }
        if (($rsi14 ?? 0) >= 76) {
            $technicalSignals[] = 'rsi_overheat';
        }
        if ($macdHist !== null && $macdHistPrev !== null && $macdHist > 0 && $macdHist < $macdHistPrev) {
            $technicalSignals[] = 'macd_shrinking';
        }
        if ($open !== null && $close !== null && $close < $open && ($volumeRatio20 ?? 0) >= 1.5) {
            $technicalSignals[] = 'upper_shadow';
        }
        if ($close !== null && $sma20 !== null && $close < $sma20) {
            $technicalSignals[] = 'below_sma20';
        }
        if ($sma20 !== null && $sma60 !== null && $sma20 < $sma60) {
            $technicalSignals[] = 'ma_bear';
        }
        if ($macd !== null && $macdSignal !== null && $macd < $macdSignal) {
            $technicalSignals[] = 'macd_bear';
        }
        if ($k9Prev !== null && $d9Prev !== null && $k9 !== null && $d9 !== null && $k9Prev >= $d9Prev && $k9 < $d9) {
            $technicalSignals[] = 'kd_dead';
        }
        if ($rsi14 !== null && $rsi14 < 40) {
            $technicalSignals[] = 'rsi_weak';
        }
        $technicalSignals[] = 'technical_mixed';

        $chipSignals = [];
        if ($chip && $chip->foreign_net_buy > 0 && $chip->investment_trust_net_buy > 0) {
            $chipSignals[] = 'foreign_trust_buy';
        }
        if ($chip && $chip->institutional_net_buy > 0) {
            $chipSignals[] = 'institutional_buy';
        }
        if ($chip && $chip->foreign_net_buy < 0 && $chip->investment_trust_net_buy < 0) {
            $chipSignals[] = 'foreign_trust_sell';
        }
        if ($chip && $chip->institutional_net_buy < 0) {
            $chipSignals[] = 'institutional_sell';
        }
        if ($chip && $price?->volume && $chip->margin_balance !== null && ((float) $chip->margin_balance / max(1, (float) $price->volume)) >= 5) {
            $chipSignals[] = 'margin_high';
        }
        if ($chip && $chip->short_balance !== null && $chip->margin_balance !== null && $chip->short_balance > $chip->margin_balance * 0.6) {
            $chipSignals[] = 'short_high';
        }
        if ($chip === null) {
            $chipSignals[] = 'data_missing';
        }
        $chipSignals[] = 'chip_neutral';

        $fundamentalSignals = [];
        if ($yoy !== null && $yoy >= 10) {
            $fundamentalSignals[] = 'revenue_yoy_strong';
        } elseif ($yoy !== null && $yoy < 0) {
            $fundamentalSignals[] = 'revenue_yoy_weak';
        }
        if ($mom !== null && $mom >= 8) {
            $fundamentalSignals[] = 'revenue_mom_strong';
        } elseif ($mom !== null && $mom <= -8) {
            $fundamentalSignals[] = 'revenue_mom_weak';
        }
        if ($per !== null && $per >= 30) {
            $fundamentalSignals[] = 'per_high';
        }
        if ($pb !== null && $pb >= 4.5) {
            $fundamentalSignals[] = 'pb_high';
        }
        if (($return20 >= 12 || $return60 >= 25) && (($yoy !== null && $yoy <= 3) || ($mom !== null && $mom <= 0))) {
            $fundamentalSignals[] = 'price_fundamental_gap';
        }
        if (($financial?->eps ?? null) !== null || ($financial?->roe ?? null) !== null || ($financial?->gross_margin ?? null) !== null) {
            $fundamentalSignals[] = 'profit_quality_good';
        }
        if (($financial?->dividend_yield ?? null) !== null) {
            $fundamentalSignals[] = 'dividend_available';
        }
        if ($financial === null && $revenue === null) {
            $fundamentalSignals[] = 'fundamental_missing';
        }
        $fundamentalSignals[] = 'fundamental_stable';

        $summary = match (true) {
            (int) ($score?->confidence_score ?? 0) >= 70 && (int) ($score?->technical_score ?? 0) >= 60 => ['overall_bull', 'wait_for_confirmation'],
            (int) ($score?->confidence_score ?? 0) <= 45 || (int) ($score?->technical_score ?? 0) < 45 => ['overall_bear', 'invalid_condition'],
            ($return20 >= 12 || ($per !== null && $per >= 30)) => ['overall_risk', 'invalid_condition'],
            default => ['overall_watch', 'wait_for_confirmation'],
        };

        return [
            'price_theme' => $priceTheme,
            'technical' => $technicalSignals,
            'chip' => $chipSignals,
            'fundamental' => $fundamentalSignals,
            'summary' => $summary,
            'risk_summary' => in_array('overall_risk', $summary, true) ? ['overall_risk', 'invalid_condition'] : ['invalid_condition', 'data_limited'],
        ];
    }

    private function latestTechnical(int $stockId): ?object
    {
        return DB::table('stock_technical_indicators_1d')
            ->where('stock_id', $stockId)
            ->orderByDesc('trade_date')
            ->first();
    }

    private function latestFinancial(int $stockId): ?object
    {
        return DB::table('stock_financials')
            ->where('stock_id', $stockId)
            ->orderByDesc('period')
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function themes(int $stockId): array
    {
        return DB::table('stock_theme_map')
            ->join('themes', 'themes.id', '=', 'stock_theme_map.theme_id')
            ->leftJoin('theme_scores', function ($join) {
                $join->on('themes.id', '=', 'theme_scores.theme_id')
                    ->whereRaw('theme_scores.score_date = (select max(ts.score_date) from theme_scores ts where ts.theme_id = themes.id)');
            })
            ->where('stock_theme_map.stock_id', $stockId)
            ->orderByDesc('theme_scores.heat_score')
            ->orderByDesc('stock_theme_map.weight')
            ->limit(3)
            ->pluck('themes.name')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    private function variables(Stock $stock, mixed $score, mixed $chip, mixed $price, mixed $revenue, mixed $technical, mixed $financial, array $themes): array
    {
        return [
            'stock_name' => $stock->name,
            'symbol' => $stock->symbol,
            'theme_text' => $themes === [] ? '目前未明確連動的' : implode('、', $themes),
            'close' => $price?->close === null ? '無資料' : number_format((float) $price->close, 2),
            'change_pct' => $price?->change_pct === null ? '無資料' : number_format((float) $price->change_pct, 2).'%',
            'confidence' => (string) (int) ($score?->confidence_score ?? 0),
            'revenue_yoy' => $revenue?->yoy_pct === null ? '無資料' : number_format((float) $revenue->yoy_pct, 2).'%',
            'revenue_mom' => $revenue?->mom_pct === null ? '無資料' : number_format((float) $revenue->mom_pct, 2).'%',
            'per' => $financial?->per === null ? '無資料' : number_format((float) $financial->per, 2),
        ];
    }

    private function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
