<?php

namespace App\Support;

use App\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockReportPhraseComposer
{
    /**
     * @var array<int, int>
     */
    private array $usedLanguageAssetIds = [];

    /**
     * @var array<int, int>
     */
    private array $usedParagraphTemplateIds = [];

    private ?int $usedArticleTemplateId = null;

    /**
     * @return array{summary:string,bull_case:string,bear_case:string,risk_summary:string,data_pack:array<string,mixed>}
     */
    public function compose(Stock $stock, mixed $score, mixed $chip, mixed $price, mixed $revenue): array
    {
        $this->usedLanguageAssetIds = [];
        $this->usedParagraphTemplateIds = [];
        $this->usedArticleTemplateId = null;

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

        $article = $this->selectArticleTemplate($signals, $vars);
        $paragraphs = $this->renderArticleParagraphs($article, $signals, $vars);

        return [
            'summary' => implode("\n\n", $paragraphs),
            'bull_case' => $this->renderSection('summary', ['overall_bull', 'wait_for_confirmation'], $vars, 2),
            'bear_case' => $this->renderSection('summary', ['overall_risk', 'invalid_condition'], $vars, 2),
            'risk_summary' => $this->renderSection('summary', $signals['risk_summary'], $vars, 2),
            'data_pack' => [
                'symbol' => $stock->symbol,
                'name' => $stock->name,
                'engine' => 'phrase_library_v3',
                'article_template_id' => $this->usedArticleTemplateId,
                'paragraph_template_ids' => $this->usedParagraphTemplateIds,
                'language_asset_ids' => $this->usedLanguageAssetIds,
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
        $this->usedLanguageAssetIds = [];

        $technical = $this->latestTechnical($stock->id);
        $financial = $this->latestFinancial($stock->id);
        $themes = $this->themes($stock->id);
        $signals = $this->signals($score, $chip, $price, $revenue, $technical, $financial, $themes);
        $vars = $this->variables($stock, $score, $chip, $price, $revenue, $technical, $financial, $themes);
        $signals = $this->alignSignalsWithRadarCard($signals, $radarCardType);

        return [
            'one_liner' => $this->composeContextualQuickText($stock, $score, $chip, $price, $revenue, $technical, $financial, $themes, $radarCardType),
            'condition_keys' => $signals,
            'engine' => 'contextual_phrase_v2',
        ];
    }

    private function composeContextualQuickText(Stock $stock, mixed $score, mixed $chip, mixed $price, mixed $revenue, mixed $technical, mixed $financial, array $themes, ?string $radarCardType): string
    {
        $sentences = array_filter([
            $this->priceActionSentence($stock, $price, $technical, $themes, $radarCardType),
            $this->technicalSentence($price, $technical),
            $this->moneyOrFundamentalSentence($chip, $price, $revenue, $financial),
        ]);

        return implode('', array_slice($sentences, 0, 3));
    }

    private function priceActionSentence(Stock $stock, mixed $price, mixed $technical, array $themes, ?string $radarCardType): string
    {
        $close = $this->num($price?->close);
        $changePct = $this->num($price?->change_pct);
        $return5 = $this->num($technical?->return5);
        $return20 = $this->num($technical?->return20);
        $return60 = $this->num($technical?->return60);
        $volumeRatio20 = $this->num($technical?->volume_ratio20);
        $bais20 = $this->num($technical?->bais20);
        $themeText = $themes === [] ? '題材面暫時沒有明確主軸' : '目前連動題材是'.implode('、', array_slice($themes, 0, 2));

        $prefix = $stock->name.'今天收在 '.$this->formatNumber($close).'，漲跌幅 '.$this->formatPercent($changePct).'；';

        if (($changePct ?? 0) > 0 && ($return20 ?? 0) <= -6) {
            return $prefix.'近 20 日仍下跌 '.$this->formatAbsPercent($return20).'，今天轉強比較像低位階反彈剛出現，重點是量能 '.$this->formatMultiple($volumeRatio20).' 能不能延續。';
        }

        if (($changePct ?? 0) < 0 && ($return20 ?? 0) >= 8) {
            return $prefix.'近 20 日仍上漲 '.$this->formatPercent($return20).'，今天拉回比較像前波強勢後的降溫，不能只用單日下跌就判成弱勢。';
        }

        if (($return20 ?? 0) >= 12 || ($bais20 ?? 0) >= 10 || $radarCardType === 'risk') {
            return $prefix.'近 20 日報酬 '.$this->formatPercent($return20).'、20 日乖離 '.$this->formatPercent($bais20).'，股價已反映不少期待，現在更需要確認營收或籌碼能不能跟上。';
        }

        if (($return20 ?? 0) <= -8 || ($return60 ?? 0) <= -15 || $radarCardType === 'weak') {
            return $prefix.'近 20 日報酬 '.$this->formatPercent($return20).'、近 60 日 '.$this->formatPercent($return60).'，走勢還在修復期，評價重點會放在止跌與量能是否回來。';
        }

        if (($return5 ?? 0) >= 3 && ($return20 ?? 0) >= 6) {
            return $prefix.'近 5 日 '.$this->formatPercent($return5).'、近 20 日 '.$this->formatPercent($return20).'，短線動能明確轉強，'.$themeText.'。';
        }

        return $prefix.'近 5 日 '.$this->formatPercent($return5).'、近 20 日 '.$this->formatPercent($return20).'，目前比較像等待方向確認，'.$themeText.'。';
    }

    private function technicalSentence(mixed $price, mixed $technical): string
    {
        $close = $this->num($price?->close);
        $sma20 = $this->num($technical?->sma20);
        $sma60 = $this->num($technical?->sma60);
        $rsi14 = $this->num($technical?->rsi14);
        $macdHist = $this->num($technical?->macd_histogram);
        $macdHistPrev = $this->num($technical?->macd_histogram_previous);
        $volumeRatio20 = $this->num($technical?->volume_ratio20);

        $notes = [];
        if ($close !== null && $sma20 !== null) {
            $notes[] = $close >= $sma20 ? '收盤站在月線上方' : '收盤還在月線下方';
        }
        if ($sma20 !== null && $sma60 !== null) {
            $notes[] = $sma20 >= $sma60 ? '月線仍高於季線' : '月線仍低於季線';
        }
        if ($macdHist !== null && $macdHistPrev !== null) {
            $notes[] = $macdHist >= $macdHistPrev ? 'MACD 柱狀體擴大' : 'MACD 柱狀體縮小';
        }
        if ($rsi14 !== null) {
            $notes[] = match (true) {
                $rsi14 >= 75 => 'RSI 已偏熱',
                $rsi14 >= 55 => 'RSI 維持強勢區',
                $rsi14 <= 40 => 'RSI 仍偏弱',
                default => 'RSI 位在中性區',
            };
        }
        if ($volumeRatio20 !== null) {
            $notes[] = '量能約為 20 日均量的 '.$this->formatMultiple($volumeRatio20);
        }

        return $notes === [] ? '' : '技術面看，'.implode('、', array_slice($notes, 0, 4)).'。';
    }

    private function moneyOrFundamentalSentence(mixed $chip, mixed $price, mixed $revenue, mixed $financial): string
    {
        $institutional = $this->num($chip?->institutional_net_buy);
        $foreign = $this->num($chip?->foreign_net_buy);
        $trust = $this->num($chip?->investment_trust_net_buy);
        $yoy = $this->num($revenue?->yoy_pct);
        $mom = $this->num($revenue?->mom_pct);
        $per = $this->num($financial?->per);

        if ($institutional !== null && abs($institutional) > 0) {
            $direction = $institutional > 0 ? '買超' : '賣超';
            $detail = [];
            if ($foreign !== null) {
                $detail[] = '外資'.($foreign >= 0 ? '買' : '賣');
            }
            if ($trust !== null) {
                $detail[] = '投信'.($trust >= 0 ? '買' : '賣');
            }

            return '資金面三大法人合計偏'.$direction.'，'.($detail === [] ? '後續要看是否連續。' : implode('、', $detail).'的方向會影響隔日承接力道。');
        }

        if ($yoy !== null || $mom !== null || $per !== null) {
            return '基本面最新月營收年增 '.$this->formatPercent($yoy).'、月增 '.$this->formatPercent($mom).'，本益比 '.$this->formatNumber($per).'，股價能否續強要看成長是否配得上評價。';
        }

        return '';
    }

    /**
     * @param array<string, array<int, string>> $signals
     */
    private function selectArticleTemplate(array $signals, array $vars): ?object
    {
        if (! DB::getSchemaBuilder()->hasTable('article_templates')) {
            return null;
        }

        $scenarios = $this->articleScenarios($signals);

        $article = DB::table('article_templates')
            ->where('status', 'active')
            ->whereIn('scenario', $scenarios)
            ->orderByRaw($this->orderByFieldSql('scenario', $scenarios))
            ->orderByDesc('weight')
            ->orderBy('usage_count')
            ->first(['id', 'scenario', 'section_order', 'opening_template', 'closing_template', 'weight', 'usage_count']);

        if (! $article) {
            return null;
        }

        $this->usedArticleTemplateId = (int) $article->id;

        DB::table('article_templates')
            ->where('id', $article->id)
            ->increment('usage_count', 1, ['updated_at' => now()]);

        return $article;
    }

    /**
     * @param array<string, array<int, string>> $signals
     * @return array<int, string>
     */
    private function articleScenarios(array $signals): array
    {
        $flat = implode('|', array_merge(...array_values($signals)));

        return match (true) {
            Str::contains($flat, ['price_extended', 'overall_risk', 'valuation_gap', 'upper_shadow']) => ['risk', 'balanced'],
            Str::contains($flat, ['low_base_breakout', 'today_rebound_after_drop']) => ['low_volume_breakout', 'balanced'],
            Str::contains($flat, ['recent_downtrend', 'overall_bear', 'ma_bear']) => ['weak_trend', 'balanced'],
            Str::contains($flat, ['theme_hot_price_up', 'recent_momentum', 'overall_bull']) => ['priority', 'balanced'],
            default => ['potential', 'balanced'],
        };
    }

    /**
     * @param array<string, array<int, string>> $signals
     * @return array<int, string>
     */
    private function renderArticleParagraphs(?object $article, array $signals, array $vars): array
    {
        $paragraphs = [];
        $sectionOrder = $this->articleSectionOrder($article);

        foreach ($sectionOrder as $index => $section) {
            $paragraphs[] = $this->renderParagraphSection(
                (int) $index + 1,
                $section,
                $signals[$section] ?? $signals['summary'],
                $vars,
                $this->sectionLimit($section),
            );
        }

        $opening = $article?->opening_template ? $this->replaceVars((string) $article->opening_template, $vars) : '';
        $closing = $article?->closing_template ? $this->replaceVars((string) $article->closing_template, $vars) : '';

        return array_values(array_filter(array_merge([$opening], $paragraphs, [$closing])));
    }

    /**
     * @return array<int, string>
     */
    private function articleSectionOrder(?object $article): array
    {
        $default = ['price_theme', 'technical', 'chip', 'fundamental', 'summary'];

        if (! $article?->section_order) {
            return $default;
        }

        $sections = json_decode((string) $article->section_order, true);
        if (! is_array($sections)) {
            return $default;
        }

        $sections = array_values(array_filter(array_map(function (mixed $section): ?string {
            $section = (string) $section;

            return in_array($section, ['theme', 'price_theme'], true)
                ? 'price_theme'
                : (in_array($section, ['technical', 'chip', 'fundamental', 'summary'], true) ? $section : null);
        }, $sections)));

        return $sections === [] ? $default : array_values(array_unique($sections));
    }

    /**
     * @param array<int, string> $conditionKeys
     */
    private function renderParagraphSection(int $index, string $section, array $conditionKeys, array $vars, int $limit): string
    {
        $template = $this->selectParagraphTemplate($section, $conditionKeys, $vars);
        $detail = $this->renderSection($section, $conditionKeys, $vars, $limit, true, true);
        $body = $template ? $this->replaceVars((string) $template->body_template, $vars) : '';

        if ($body !== '' && $detail !== '' && ! Str::contains($body, $detail)) {
            $body .= $detail;
        }

        if ($body === '') {
            $body = $detail;
        }

        return $index.'、'.$this->sectionTitle($section).'：'.$body;
    }

    /**
     * @param array<int, string> $conditionKeys
     */
    private function selectParagraphTemplate(string $section, array $conditionKeys, array $vars): ?object
    {
        if (! DB::getSchemaBuilder()->hasTable('paragraph_templates')) {
            return null;
        }

        $scenarios = $this->paragraphScenarios($section, $conditionKeys);
        $tone = $this->toneForConditionKeys($conditionKeys);

        $template = DB::table('paragraph_templates')
            ->where('status', 'active')
            ->where('section', $section)
            ->whereIn('scenario', $scenarios)
            ->where(function ($query) use ($tone) {
                $query->where('tone', $tone)
                    ->orWhere('tone', 'neutral');
            })
            ->orderByRaw($this->orderByFieldSql('scenario', $scenarios))
            ->orderByDesc('weight')
            ->orderBy('usage_count')
            ->first(['id', 'scenario', 'body_template', 'weight', 'usage_count']);

        if (! $template) {
            return null;
        }

        $this->usedParagraphTemplateIds[] = (int) $template->id;

        DB::table('paragraph_templates')
            ->where('id', $template->id)
            ->increment('usage_count', 1, ['updated_at' => now()]);

        return $template;
    }

    /**
     * @param array<int, string> $conditionKeys
     * @return array<int, string>
     */
    private function paragraphScenarios(string $section, array $conditionKeys): array
    {
        $keys = $this->languageConditionKeys($section, $conditionKeys);

        $aliases = [
            'price_theme' => [
                'base_rebound' => 'base_rebound',
                'low_base_breakout' => 'base_rebound',
                'trend_continuation' => 'trend',
                'overextended' => 'risk',
                'price_extended' => 'risk',
                'weak_rebound' => 'weak',
            ],
            'technical' => [
                'ma_bull' => 'trend',
                'macd_turning' => 'trend',
                'below_ma' => 'failed_breakout',
                'upper_shadow' => 'failed_breakout',
            ],
            'chip' => [
                'institutional_buy' => 'institutional',
                'margin_pressure' => 'margin_risk',
            ],
            'fundamental' => [
                'revenue_growth' => 'growth',
                'valuation_gap' => 'risk',
            ],
            'summary' => [
                'balanced_bull' => 'card_alignment',
                'balanced_risk' => 'card_alignment',
                'follow_up' => 'card_alignment',
            ],
        ];

        $scenarios = [];
        foreach ($keys as $key) {
            if (isset($aliases[$section][$key])) {
                $scenarios[] = $aliases[$section][$key];
            }
        }

        $scenarios[] = 'balanced';
        $scenarios[] = $section;

        return array_values(array_unique($scenarios));
    }

    private function sectionTitle(string $section): string
    {
        return match ($section) {
            'price_theme' => '近期股價走勢與題材',
            'technical' => '技術分析',
            'chip' => '籌碼及資金走向',
            'fundamental' => '營收狀況及股利政策',
            default => '總評',
        };
    }

    private function sectionLimit(string $section): int
    {
        return match ($section) {
            'price_theme', 'technical' => 2,
            default => 1,
        };
    }

    /**
     * @param array<int, string> $values
     */
    private function orderByFieldSql(string $column, array $values): string
    {
        $cases = collect($values)
            ->values()
            ->map(fn (string $value, int $index) => "when '".$value."' then ".$index)
            ->implode(' ');

        return 'case '.$column.' '.$cases.' else 999 end';
    }

    /**
     * @param array<int, string> $conditionKeys
     */
    private function renderSection(string $section, array $conditionKeys, array $vars, int $limit, bool $trackUsage = true, bool $diversify = false): string
    {
        $conditionKeys = array_values(array_unique(array_filter($conditionKeys)));

        if ($conditionKeys === []) {
            $conditionKeys = [$this->fallbackCondition($section)];
        }

        $languageAssets = $this->selectLanguageAssets($section, $conditionKeys, $vars, $limit, $diversify);
        if ($trackUsage && $languageAssets->isNotEmpty()) {
            DB::table('language_assets')
                ->whereIn('id', $languageAssets->pluck('id')->all())
                ->increment('usage_count', 1, ['last_used_at' => now(), 'updated_at' => now()]);
        }

        if ($languageAssets->isNotEmpty()) {
            return $languageAssets
                ->map(fn (object $asset) => $this->replaceVars((string) $asset->text, $vars))
                ->implode('');
        }

        $remaining = $limit;

        $phrases = DB::table('report_phrases')
            ->where('section', $section)
            ->where('status', 'active')
            ->whereIn('condition_key', $conditionKeys)
            ->limit(max($remaining * 3, $remaining))
            ->get(['id', 'condition_key', 'template', 'weight', 'usage_count'])
            ->sort(function (object $a, object $b) use ($conditionKeys) {
                $rankA = array_search($a->condition_key, $conditionKeys, true);
                $rankB = array_search($b->condition_key, $conditionKeys, true);
                $rankA = $rankA === false ? 999 : $rankA;
                $rankB = $rankB === false ? 999 : $rankB;

                return [$rankA, -((int) $a->weight), (int) $a->usage_count]
                    <=> [$rankB, -((int) $b->weight), (int) $b->usage_count];
            })
            ->values();

        if ($phrases->isEmpty()) {
            $phrases = DB::table('report_phrases')
                ->where('section', $section)
                ->where('status', 'active')
                ->where('condition_key', $this->fallbackCondition($section))
                ->orderByDesc('weight')
                ->limit($remaining)
                ->get(['id', 'condition_key', 'template', 'weight', 'usage_count']);
        }

        $selected = $this->selectPhrases($phrases, $remaining, $diversify ? (string) ($vars['_seed'] ?? '') : '');

        if ($trackUsage && $selected->isNotEmpty()) {
            DB::table('report_phrases')
                ->whereIn('id', $selected->pluck('id')->all())
                ->increment('usage_count');
        }

        return $languageAssets
            ->map(fn (object $asset) => $this->replaceVars((string) $asset->text, $vars))
            ->merge($selected
            ->map(fn (object $phrase) => $this->replaceVars((string) $phrase->template, $vars))
            )
            ->implode('');
    }

    /**
     * @param array<int, string> $conditionKeys
     */
    private function selectLanguageAssets(string $section, array $conditionKeys, array $vars, int $limit, bool $diversify): Collection
    {
        if ($limit <= 0) {
            return collect();
        }

        if (! DB::getSchemaBuilder()->hasTable('language_assets')) {
            return collect();
        }

        $lookupKeys = $this->languageConditionKeys($section, $conditionKeys);
        $tone = $this->toneForConditionKeys($conditionKeys);

        $assets = DB::table('language_assets')
            ->where('status', 'active')
            ->whereIn('asset_type', ['phrase', 'sentence'])
            ->where(function ($query) use ($section) {
                $query->where('section', $section)
                    ->orWhereNull('section');
            })
            ->where(function ($query) use ($lookupKeys) {
                $query->whereIn('condition_key', $lookupKeys)
                    ->orWhereNull('condition_key');
            })
            ->when($this->usedLanguageAssetIds !== [], fn ($query) => $query->whereNotIn('id', $this->usedLanguageAssetIds))
            ->limit(max($limit * 6, 12))
            ->get(['id', 'condition_key', 'tone', 'text', 'weight', 'usage_count'])
            ->filter(fn (object $asset) => trim((string) $asset->text) !== '');

        $toneAlignedAssets = $assets
            ->filter(fn (object $asset) => in_array((string) $asset->tone, [$tone, 'neutral'], true))
            ->values();

        if ($toneAlignedAssets->isNotEmpty()) {
            $assets = $toneAlignedAssets;
        }

        $assets = $assets
            ->sort(function (object $a, object $b) use ($lookupKeys, $tone, $vars, $diversify) {
                $rankA = $this->assetRank($a, $lookupKeys, $tone, $vars, $diversify);
                $rankB = $this->assetRank($b, $lookupKeys, $tone, $vars, $diversify);

                return $rankA <=> $rankB;
            })
            ->take($limit)
            ->values();

        $this->usedLanguageAssetIds = array_values(array_unique(array_merge(
            $this->usedLanguageAssetIds,
            $assets->pluck('id')->map(fn ($id) => (int) $id)->all(),
        )));

        return $assets;
    }

    /**
     * @param array<int, string> $conditionKeys
     * @return array<int, string>
     */
    private function languageConditionKeys(string $section, array $conditionKeys): array
    {
        $aliases = [
            'today_rebound_after_drop' => ['base_rebound', 'weak_rebound'],
            'today_pullback_after_run' => ['overextended'],
            'recent_downtrend' => ['weak_rebound', 'below_ma'],
            'recent_momentum' => ['trend_continuation'],
            'theme_hot_price_up' => ['trend_continuation'],
            'price_up_volume_up' => ['base_rebound', 'trend_continuation'],
            'price_up_volume_flat' => ['trend_continuation'],
            'price_down_volume_up' => ['overextended', 'upper_shadow'],
            'price_extended' => ['overextended'],
            'low_base_breakout' => ['base_rebound'],
            'ma_bull' => ['trend_continuation'],
            'macd_bull' => ['macd_turning'],
            'kd_golden' => ['macd_turning'],
            'rsi_strong' => ['trend_continuation'],
            'breakout20' => ['trend_continuation'],
            'bais_high' => ['overextended'],
            'rsi_overheat' => ['overextended'],
            'macd_shrinking' => ['upper_shadow'],
            'upper_shadow' => ['upper_shadow'],
            'below_sma20' => ['below_ma'],
            'ma_bear' => ['below_ma'],
            'macd_bear' => ['below_ma'],
            'kd_dead' => ['below_ma'],
            'rsi_weak' => ['below_ma'],
            'foreign_trust_buy' => ['institutional_buy'],
            'institutional_buy' => ['institutional_buy'],
            'foreign_trust_sell' => ['margin_pressure'],
            'institutional_sell' => ['margin_pressure'],
            'margin_high' => ['margin_pressure'],
            'short_high' => ['margin_pressure'],
            'revenue_yoy_strong' => ['revenue_growth'],
            'revenue_mom_strong' => ['revenue_growth'],
            'revenue_yoy_weak' => ['valuation_gap'],
            'revenue_mom_weak' => ['valuation_gap'],
            'per_high' => ['valuation_gap'],
            'pb_high' => ['valuation_gap'],
            'price_fundamental_gap' => ['valuation_gap'],
            'overall_bull' => ['balanced_bull'],
            'overall_watch' => ['follow_up'],
            'overall_risk' => ['balanced_risk'],
            'overall_bear' => ['balanced_risk'],
            'wait_for_confirmation' => ['follow_up'],
            'invalid_condition' => ['contrast'],
        ];

        $keys = [$this->fallbackCondition($section)];

        foreach ($conditionKeys as $key) {
            $keys[] = $key;

            foreach ($aliases[$key] ?? [] as $alias) {
                $keys[] = $alias;
            }
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * @param array<int, string> $conditionKeys
     */
    private function toneForConditionKeys(array $conditionKeys): string
    {
        $joined = implode('|', $conditionKeys);

        if (Str::contains($joined, ['risk', 'extended', 'over', 'upper_shadow', 'gap', 'margin_high', 'short_high', 'high'])) {
            return 'risk';
        }

        if (Str::contains($joined, ['bear', 'weak', 'sell', 'below', 'dead', 'downtrend', 'down_volume'])) {
            return 'bear';
        }

        if (Str::contains($joined, ['bull', 'strong', 'buy', 'golden', 'rebound', 'momentum', 'breakout', 'growth', 'up_volume'])) {
            return 'bull';
        }

        return 'neutral';
    }

    /**
     * @param array<int, string> $lookupKeys
     * @return array<int, int|string>
     */
    private function assetRank(object $asset, array $lookupKeys, string $tone, array $vars, bool $diversify): array
    {
        $conditionRank = array_search($asset->condition_key, $lookupKeys, true);
        $conditionRank = $conditionRank === false ? 999 : $conditionRank;

        $toneRank = match ((string) $asset->tone) {
            $tone => 0,
            'neutral' => 1,
            default => 2,
        };

        $diversityRank = 0;
        if ($diversify) {
            $seed = (string) ($vars['_seed'] ?? '');
            $diversityRank = crc32($seed.'|language_asset|'.$asset->id) % 100;
        }

        return [
            $conditionRank,
            $toneRank,
            -((int) $asset->weight),
            (int) $asset->usage_count,
            $diversityRank,
            (int) $asset->id,
        ];
    }

    private function selectPhrases(Collection $phrases, int $limit, string $seed): Collection
    {
        if ($phrases->isEmpty() || $limit <= 0) {
            return collect();
        }

        if ($seed === '') {
            return $phrases->take($limit);
        }

        $groups = $phrases->groupBy('condition_key')->values();
        $selected = collect();

        foreach ($groups as $index => $group) {
            if ($selected->count() >= $limit) {
                break;
            }

            $offset = crc32($seed.'|'.$index) % max(1, $group->count());
            $selected->push($group->values()->get($offset));
        }

        return $selected->filter()->take($limit)->values();
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
        $return5 = $this->num($technical?->return5) ?? 0.0;
        $return20 = $this->num($technical?->return20) ?? 0.0;
        $return60 = $this->num($technical?->return60) ?? 0.0;
        $per = $this->num($financial?->per);
        $pb = $this->num($financial?->pb_ratio);
        $yoy = $this->num($revenue?->yoy_pct);
        $mom = $this->num($revenue?->mom_pct);
        $themeScore = (int) ($score?->theme_score ?? 0);

        $priceTheme = [];
        if (($changePct ?? 0) > 0 && $return20 <= -6 && ($volumeRatio20 ?? 0) >= 1.2) {
            $priceTheme[] = 'today_rebound_after_drop';
        }
        if (($changePct ?? 0) < 0 && $return20 >= 10) {
            $priceTheme[] = 'today_pullback_after_run';
        }
        if ($return20 <= -8 || $return60 <= -15) {
            $priceTheme[] = 'recent_downtrend';
        }
        if ($return5 >= 4 && $return20 >= 8) {
            $priceTheme[] = 'recent_momentum';
        }
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
            '_seed' => $stock->symbol.'|'.($price?->trade_date ?? now('Asia/Taipei')->toDateString()).'|'.($score?->score_date ?? ''),
            'close' => $price?->close === null ? '無資料' : number_format((float) $price->close, 2),
            'change_pct' => $price?->change_pct === null ? '無資料' : number_format((float) $price->change_pct, 2).'%',
            'return5' => $technical?->return5 === null ? '無資料' : number_format((float) $technical->return5, 2).'%',
            'return20' => $technical?->return20 === null ? '無資料' : number_format((float) $technical->return20, 2).'%',
            'return60' => $technical?->return60 === null ? '無資料' : number_format((float) $technical->return60, 2).'%',
            'volume_ratio20' => $technical?->volume_ratio20 === null ? '無資料' : number_format((float) $technical->volume_ratio20, 2).'倍',
            'bais20' => $technical?->bais20 === null ? '無資料' : number_format((float) $technical->bais20, 2).'%',
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

    private function formatNumber(?float $value): string
    {
        if ($value === null) {
            return '無資料';
        }

        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    private function formatPercent(?float $value): string
    {
        if ($value === null) {
            return '無資料';
        }

        return ($value > 0 ? '+' : '').number_format($value, 2).'%';
    }

    private function formatAbsPercent(?float $value): string
    {
        if ($value === null) {
            return '無資料';
        }

        return number_format(abs($value), 2).'%';
    }

    private function formatMultiple(?float $value): string
    {
        if ($value === null) {
            return '無資料';
        }

        return number_format($value, 2).'倍';
    }
}
