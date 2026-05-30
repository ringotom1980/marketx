<?php

namespace App\Support;

use App\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StockResearchReportComposer
{
    /**
     * @return array{summary:string,bull_case:string,bear_case:string,risk_summary:string,data_pack:array<string,mixed>}
     */
    public function compose(Stock $stock, mixed $score = null): array
    {
        $pack = $this->dataPack($stock, $score);

        return [
            'summary' => $this->renderReport($pack),
            'bull_case' => $this->renderDirections($pack, 'positive'),
            'bear_case' => $this->renderDirections($pack, 'risk'),
            'risk_summary' => $this->renderRiskSummary($pack),
            'data_pack' => $pack,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function dataPack(Stock $stock, mixed $score): array
    {
        $prices = $this->prices($stock->id, 90);
        $latestPrice = $prices->first();
        $previousPrice = $prices->skip(1)->first();
        $technical = $this->latest('stock_technical_indicators_1d', 'trade_date', $stock->id);
        $chips = $this->chips($stock->id, 30);
        $latestChip = $chips->first();
        $revenues = $this->revenues($stock->id, 12);
        $latestRevenue = $revenues->first();
        $financials = $this->financials($stock->id, 12);
        $latestFinancial = $financials->first();
        $themes = $this->themes($stock->id);
        $news = $this->news($stock, $themes);
        $supportResistance = $this->supportResistance($prices, $latestPrice);

        return [
            'engine' => 'research_report_v2',
            'symbol' => $stock->symbol,
            'name' => $stock->name,
            'market' => $stock->market,
            'asof' => now('Asia/Taipei')->toDateTimeString(),
            'price' => [
                'date' => $latestPrice?->trade_date,
                'close' => $this->float($latestPrice?->close),
                'change' => $this->float($latestPrice?->change),
                'change_pct' => $this->priceChangePct($latestPrice, $previousPrice),
                'open' => $this->float($latestPrice?->open),
                'high' => $this->float($latestPrice?->high),
                'low' => $this->float($latestPrice?->low),
                'volume' => $this->int($latestPrice?->volume),
                'volume_change_pct' => $this->volumeChangePct($latestPrice, $previousPrice),
            ],
            'price_trend' => $this->priceTrend($technical),
            'support_resistance' => $supportResistance,
            'technical' => $this->technical($technical),
            'chip' => $this->chip($latestChip, $chips),
            'financial' => $this->financial($latestFinancial, $this->float($latestPrice?->close)),
            'revenue' => $this->revenue($latestRevenue, $revenues),
            'themes' => $themes,
            'news' => $news,
            'score' => [
                'confidence_score' => $this->int($score?->confidence_score),
                'classification' => $this->classification($stock->id),
            ],
        ];
    }

    private function renderReport(array $pack): string
    {
        $sections = [
            "一、近期股價與量能\n".$this->priceSection($pack),
            "二、支撐與壓力\n".$this->supportSection($pack),
            "三、技術分析\n".$this->technicalSection($pack),
            "四、籌碼與資金動向\n".$this->chipSection($pack),
            "五、財報、營收與評價\n".$this->fundamentalSection($pack),
            "六、新聞與題材\n".$this->newsSection($pack),
            "七、觀察方向\n".$this->directionSection($pack),
        ];

        return implode("\n\n", array_filter($sections));
    }

    private function priceSection(array $pack): string
    {
        $price = $pack['price'];
        $trend = $pack['price_trend'];
        $lines = [
            "{$pack['name']}最新收盤 {$this->n($price['close'])} 元，單日漲跌 {$this->signed($price['change'])} 元（{$this->signedPct($price['change_pct'])}），成交量 {$this->shares($price['volume'])}。",
            "近 5 日報酬 {$this->signedPct($trend['return5'])}，近 20 日報酬 {$this->signedPct($trend['return20'])}，近 60 日報酬 {$this->signedPct($trend['return60'])}，量能約為 20 日均量的 {$this->n($trend['volume_ratio20'])} 倍。",
        ];

        $condition = 'price_sideways';
        $tone = 'neutral';
        if (($trend['return20'] ?? 0) > 8 && ($trend['volume_ratio20'] ?? 0) >= 1.2) {
            $condition = 'price_volume_breakout';
            $tone = 'positive';
        } elseif (($trend['return20'] ?? 0) < -8 && ($price['change_pct'] ?? 0) > 0) {
            $condition = 'weak_rebound';
            $tone = 'cautious';
        } elseif (($trend['return20'] ?? 0) > 15) {
            $condition = 'overextended';
            $tone = 'risk';
        }

        $lines[] = '分析：'.($this->librarySentence('price_theme', $tone, [$condition], $this->vars($pack)) ?: $this->priceFallbackSentence($pack));

        return implode("\n", $lines);
    }

    private function supportSection(array $pack): string
    {
        $sr = $pack['support_resistance'];
        $supportText = $sr['support'] ? "支撐區約 {$sr['support']}" : '支撐區目前資料不足';
        $pressureText = $sr['pressure'] ? "壓力區約 {$sr['pressure']}" : '上方暫無明確壓力';
        $line = "以近 90 日價量分布估算，目前價 {$this->n($sr['current'])} 元，{$supportText}，{$pressureText}。";

        if ($sr['support_strength'] !== null && $sr['pressure_strength'] !== null) {
            $line .= "支撐區成交量約 {$this->shares($sr['support_strength'])}，壓力區成交量約 {$this->shares($sr['pressure_strength'])}。";
        } elseif ($sr['support_strength'] !== null) {
            $line .= "支撐區成交量約 {$this->shares($sr['support_strength'])}，上方若進入創高區，壓力需改以放量長黑、上影線與法人賣壓觀察。";
        }

        return $line."\n分析：支撐與壓力不是固定目標價，而是近期成交密集區。若股價跌破支撐且量能放大，代表原本願意承接的位置被打穿；若接近壓力區卻無法放量突破，就容易出現整理或回測。";
    }

    private function technicalSection(array $pack): string
    {
        $t = $pack['technical'];
        $items = [
            "均線：收盤價 {$this->n($pack['price']['close'])}，SMA20 {$this->n($t['sma20'])}，SMA60 {$this->n($t['sma60'])}，SMA120 {$this->n($t['sma120'])}。",
            "動能：RSI14 {$this->n($t['rsi14'])}，MACD {$this->n($t['macd'])}，Signal {$this->n($t['macd_signal'])}，柱狀體 {$this->n($t['macd_histogram'])}，KD 為 K {$this->n($t['k9'])} / D {$this->n($t['d9'])}。",
            "乖離與波動：20 日乖離 {$this->signedPct($t['bais20'])}，ATR14 {$this->n($t['atr14'])}，布林上緣 {$this->n($t['bollinger_upper20'])}，布林下緣 {$this->n($t['bollinger_lower20'])}。",
        ];

        $conditions = [];
        if (($pack['price']['close'] ?? 0) > ($t['sma20'] ?? INF) && ($t['sma20'] ?? 0) > ($t['sma60'] ?? INF)) {
            $conditions[] = 'ma_bullish_stack';
        }
        if (($t['macd_histogram'] ?? null) !== null && ($t['macd_histogram_previous'] ?? null) !== null) {
            $conditions[] = $t['macd_histogram'] >= $t['macd_histogram_previous'] ? 'macd_turning_up' : 'macd_turning_down';
        }
        if (($t['rsi14'] ?? 0) >= 75) {
            $conditions[] = 'rsi_overheated';
        } elseif (($t['rsi14'] ?? 0) <= 40) {
            $conditions[] = 'rsi_weak';
        }
        if (($t['bais20'] ?? 0) >= 10) {
            $conditions[] = 'bais_overheated';
        }

        $tone = in_array('macd_turning_down', $conditions, true) || in_array('rsi_overheated', $conditions, true) ? 'cautious' : 'positive';
        $analysis = $this->librarySentence('technical', $tone, $conditions, $this->vars($pack));
        if ($analysis === '') {
            $analysis = $this->technicalFallbackSentence($conditions);
        }

        return implode("\n", $items)."\n分析：".$analysis;
    }

    private function chipSection(array $pack): string
    {
        $c = $pack['chip'];
        $lines = [
            '三大法人買賣超',
            $this->taiwanDate($c['trade_date'] ?? null),
            '外資 '.$this->signedShares($c['foreign_net_buy']),
            '投信 '.$this->signedShares($c['investment_trust_net_buy']),
            '自營商 '.$this->signedShares($c['dealer_net_buy']),
            '合計 '.$this->signedShares($c['institutional_net_buy']),
            '',
            '近 5 日',
            '法人合計 '.$this->signedShares($c['institutional_net_buy_5d']),
            '',
            '近 20 日',
            '法人合計 '.$this->signedShares($c['institutional_net_buy_20d']),
            '',
            '融資融券',
            '融資餘額 '.$this->shares($c['margin_balance']).'、融券餘額 '.$this->shares($c['short_balance']),
            '近 5 日融資變化 '.$this->signedShares($c['margin_change_5d']).'。',
            '',
            '分析：'.$this->chipAnalysis($pack),
        ];

        return implode("\n", $lines);
    }

    private function fundamentalSection(array $pack): string
    {
        $f = $pack['financial'];
        $r = $pack['revenue'];
        $lines = [
            '月營收',
            '最新月營收 '.$this->money($r['revenue']),
            '月增率 '.$this->signedPct($r['mom_pct']).'，年增率 '.$this->signedPct($r['yoy_pct']),
            '近 3 個月累計營收 '.$this->money($r['revenue_3m']).'，近 12 個月累計營收 '.$this->money($r['revenue_12m']).'。',
            '',
            '財報指標',
            '最新財報期間 '.$f['period'],
            'EPS '.$this->n($f['eps']).' 元，ROE '.$this->pct($f['roe']).'，毛利率 '.$this->pct($f['gross_margin']).'，營業利益率 '.$this->pct($f['operating_margin']).'。',
            '',
            '評價面',
            $this->valuationLine($f),
            '',
            '分析：'.$this->fundamentalAnalysis($pack),
        ];

        return implode("\n", $lines);
    }

    private function newsSection(array $pack): string
    {
        $themeText = $pack['themes'] === [] ? '目前沒有明確題材標籤' : implode('、', array_slice($pack['themes'], 0, 4));
        $lines = ["目前關聯題材：{$themeText}。"];

        if ($pack['news'] === []) {
            $lines[] = '近期新聞資料庫尚未抓到直接關聯新聞，因此題材面先以產業分類、族群熱度與股價反應交叉觀察。';
        } else {
            $lines[] = '近期關聯新聞：';
            foreach (array_slice($pack['news'], 0, 4) as $news) {
                $source = $news['source'] ? '／'.$news['source'] : '';
                $lines[] = '- '.$news['date'].$source.'：'.$news['title'];
            }
            $lines[] = '分析：新聞只能代表市場正在討論的方向，仍要回到股價、量能與法人資金是否同步確認。';
        }

        return implode("\n", $lines);
    }

    private function directionSection(array $pack): string
    {
        $directions = [
            '觀察股價是否能站穩 20 日均線與主要支撐區，若跌破且量能放大，代表短線結構轉弱。',
            '觀察接近壓力區時是否能放量突破；若只靠題材推升但成交量沒有延續，容易轉為震盪。',
            '觀察三大法人近 5 日方向是否延續，若法人轉賣且融資同步增加，籌碼風險會提高。',
            '觀察月營收與最新財報是否能支撐目前評價，避免只看題材熱度而忽略基本面落差。',
        ];

        return implode("\n", array_map(fn (string $line) => '- '.$line, $directions));
    }

    private function renderDirections(array $pack, string $type): string
    {
        return $type === 'positive'
            ? '正向條件主要看股價是否站穩均線、量能是否延續、法人資金是否同向，以及營收或題材是否能提供後續支撐。'
            : '主要風險來自短線漲幅過大、量價背離、法人轉賣、融資升高，以及營收或財報無法支撐目前評價。';
    }

    private function renderRiskSummary(array $pack): string
    {
        return '風險摘要需同時觀察技術過熱、籌碼鬆動、財報或營收轉弱，以及題材熱度退潮；若多項條件同時出現，應提高警覺。';
    }

    private function prices(int $stockId, int $limit): Collection
    {
        return DB::table('stock_prices_1d')
            ->where('stock_id', $stockId)
            ->orderByDesc('trade_date')
            ->limit($limit)
            ->get();
    }

    private function chips(int $stockId, int $limit): Collection
    {
        return DB::table('stock_chips_1d')
            ->where('stock_id', $stockId)
            ->orderByDesc('trade_date')
            ->limit($limit)
            ->get();
    }

    private function revenues(int $stockId, int $limit): Collection
    {
        return DB::table('stock_revenues')
            ->where('stock_id', $stockId)
            ->orderByDesc('year_month')
            ->limit($limit)
            ->get();
    }

    private function financials(int $stockId, int $limit): Collection
    {
        return DB::table('stock_financials')
            ->where('stock_id', $stockId)
            ->orderByDesc('period')
            ->limit($limit)
            ->get();
    }

    private function latest(string $table, string $dateColumn, int $stockId): ?object
    {
        return DB::table($table)
            ->where('stock_id', $stockId)
            ->orderByDesc($dateColumn)
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
            ->limit(5)
            ->pluck('themes.name')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    /**
     * @param array<int, string> $themes
     * @return array<int, array<string,string|null>>
     */
    private function news(Stock $stock, array $themes): array
    {
        if (! Schema::hasTable('news_items')) {
            return [];
        }

        $terms = array_values(array_filter(array_merge([$stock->symbol, $stock->name], array_slice($themes, 0, 3))));

        return DB::table('news_items')
            ->where('status', 'active')
            ->where(function ($query) use ($terms, $stock) {
                foreach ($terms as $term) {
                    $query->orWhere('title', 'ilike', '%'.$term.'%')
                        ->orWhere('summary', 'ilike', '%'.$term.'%');
                }
                $query->orWhereRaw('symbols::text ilike ?', ['%"'.$stock->symbol.'"%']);
            })
            ->orderByDesc('published_at')
            ->orderByDesc('importance_score')
            ->limit(5)
            ->get(['news_date', 'published_at', 'source_name', 'title'])
            ->map(fn (object $row) => [
                'date' => (string) ($row->news_date ?: Str::of((string) $row->published_at)->substr(0, 10)),
                'source' => $row->source_name,
                'title' => $row->title,
            ])
            ->all();
    }

    private function priceTrend(?object $technical): array
    {
        return [
            'return5' => $this->float($technical?->return5),
            'return20' => $this->float($technical?->return20),
            'return60' => $this->float($technical?->return60),
            'volume_ratio20' => $this->float($technical?->volume_ratio20),
        ];
    }

    private function technical(?object $technical): array
    {
        $fields = ['sma5', 'sma10', 'sma20', 'sma60', 'sma120', 'sma240', 'rsi14', 'macd', 'macd_signal', 'macd_histogram', 'macd_histogram_previous', 'k9', 'd9', 'bollinger_upper20', 'bollinger_lower20', 'atr14', 'bais20'];
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $this->float($technical?->{$field});
        }

        return $data;
    }

    private function chip(?object $latestChip, Collection $chips): array
    {
        return [
            'trade_date' => $latestChip?->trade_date,
            'foreign_net_buy' => $this->int($latestChip?->foreign_net_buy),
            'investment_trust_net_buy' => $this->int($latestChip?->investment_trust_net_buy),
            'dealer_net_buy' => $this->int($latestChip?->dealer_net_buy),
            'institutional_net_buy' => $this->int($latestChip?->institutional_net_buy),
            'margin_balance' => $this->int($latestChip?->margin_balance),
            'short_balance' => $this->int($latestChip?->short_balance),
            'institutional_net_buy_5d' => $this->sum($chips->take(5), 'institutional_net_buy'),
            'institutional_net_buy_20d' => $this->sum($chips->take(20), 'institutional_net_buy'),
            'margin_change_5d' => $this->balanceChange($chips, 'margin_balance', 5),
        ];
    }

    private function financial(?object $latestFinancial, ?float $close): array
    {
        $eps = $this->float($latestFinancial?->eps);
        $per = $this->float($latestFinancial?->per);
        $estimatedPer = null;

        if ($per === null && $close !== null && $eps !== null && $eps > 0) {
            $estimatedPer = $close / ($eps * 4);
        }

        return [
            'period' => $latestFinancial?->period ?? '資料不足',
            'eps' => $eps,
            'roe' => $this->float($latestFinancial?->roe),
            'gross_margin' => $this->float($latestFinancial?->gross_margin),
            'operating_margin' => $this->float($latestFinancial?->operating_margin),
            'per' => $per,
            'per_estimated' => $estimatedPer,
            'per_source' => $per !== null ? 'official' : ($estimatedPer !== null ? 'estimated_annualized_eps' : 'missing'),
            'pb_ratio' => $this->float($latestFinancial?->pb_ratio),
            'dividend_yield' => $this->float($latestFinancial?->dividend_yield),
        ];
    }

    private function revenue(?object $latestRevenue, Collection $revenues): array
    {
        return [
            'year_month' => $latestRevenue?->year_month,
            'revenue' => $this->int($latestRevenue?->revenue),
            'mom_pct' => $this->float($latestRevenue?->mom_pct),
            'yoy_pct' => $this->float($latestRevenue?->yoy_pct),
            'revenue_3m' => $this->sum($revenues->take(3), 'revenue'),
            'revenue_12m' => $this->sum($revenues->take(12), 'revenue'),
        ];
    }

    private function supportResistance(Collection $prices, ?object $latestPrice): array
    {
        $current = $this->float($latestPrice?->close);
        if ($current === null || $prices->count() < 10) {
            return ['current' => $current, 'support' => null, 'pressure' => null, 'support_strength' => null, 'pressure_strength' => null];
        }

        $valid = $prices->filter(fn ($row) => $row->high !== null && $row->low !== null && $row->volume !== null)->values();
        $low = (float) $valid->min('low');
        $high = (float) $valid->max('high');
        $step = max(0.01, ($high - $low) / 12);
        $bins = [];

        for ($i = 0; $i < 12; $i++) {
            $from = $low + ($step * $i);
            $to = $i === 11 ? $high : $from + $step;
            $bins[$i] = ['from' => $from, 'to' => $to, 'mid' => ($from + $to) / 2, 'volume' => 0];
        }

        foreach ($valid as $row) {
            $typical = (((float) $row->high + (float) $row->low + (float) $row->close) / 3);
            $index = (int) floor(($typical - $low) / $step);
            $index = max(0, min(11, $index));
            $bins[$index]['volume'] += (int) $row->volume;
        }

        $support = collect($bins)->filter(fn ($bin) => $bin['to'] < $current)->sortByDesc('volume')->first();
        $pressure = collect($bins)->filter(fn ($bin) => $bin['from'] > $current)->sortByDesc('volume')->first();

        return [
            'current' => $current,
            'support' => $support ? $this->range($support['from'], $support['to']) : null,
            'pressure' => $pressure ? $this->range($pressure['from'], $pressure['to']) : null,
            'support_strength' => $support['volume'] ?? null,
            'pressure_strength' => $pressure['volume'] ?? null,
        ];
    }

    private function classification(int $stockId): ?string
    {
        $row = DB::table('stock_radar_cards')
            ->where('stock_id', $stockId)
            ->orderByDesc('card_date')
            ->first(['card_type']);

        return $row?->card_type;
    }

    /**
     * @param array<int, string> $conditionKeys
     * @param array<string,mixed> $vars
     */
    private function librarySentence(string $section, string $tone, array $conditionKeys, array $vars): string
    {
        if (! Schema::hasTable('language_assets')) {
            return '';
        }

        $conditionKeys = array_values(array_unique(array_filter($conditionKeys)));
        $assets = DB::table('language_assets')
            ->where('status', 'active')
            ->whereIn('asset_type', ['phrase', 'sentence'])
            ->where(function ($query) use ($section) {
                $query->where('section', $section)->orWhereNull('section');
            })
            ->where(function ($query) use ($conditionKeys) {
                if ($conditionKeys !== []) {
                    $query->whereIn('condition_key', $conditionKeys)->orWhereNull('condition_key');
                } else {
                    $query->whereNull('condition_key');
                }
            })
            ->where(function ($query) use ($tone) {
                $query->where('tone', $tone)->orWhere('tone', 'neutral');
            })
            ->limit(12)
            ->get(['id', 'condition_key', 'tone', 'text', 'weight', 'usage_count'])
            ->filter(fn (object $asset) => trim((string) $asset->text) !== '')
            ->sort(function (object $a, object $b) use ($conditionKeys, $tone) {
                return [
                    (string) $a->tone === $tone ? 0 : 1,
                    in_array((string) $a->condition_key, $conditionKeys, true) ? 0 : 1,
                    -((int) $a->weight),
                    (int) $a->usage_count,
                ] <=> [
                    (string) $b->tone === $tone ? 0 : 1,
                    in_array((string) $b->condition_key, $conditionKeys, true) ? 0 : 1,
                    -((int) $b->weight),
                    (int) $b->usage_count,
                ];
            })
            ->values();

        $asset = $assets->first();
        if (! $asset) {
            return '';
        }

        DB::table('language_assets')
            ->where('id', $asset->id)
            ->increment('usage_count', 1, ['last_used_at' => now(), 'updated_at' => now()]);

        return $this->replaceVars((string) $asset->text, $vars);
    }

    private function chipAnalysis(array $pack): string
    {
        $c = $pack['chip'];
        $conditions = [];
        $tone = 'neutral';
        if (($c['institutional_net_buy_5d'] ?? 0) > 0) {
            $conditions[] = 'institutional_buy';
            $tone = 'positive';
        }
        if (($c['institutional_net_buy_5d'] ?? 0) < 0) {
            $conditions[] = 'institutional_sell';
            $tone = 'cautious';
        }
        if (($c['margin_change_5d'] ?? 0) > 0) {
            $conditions[] = 'margin_increase';
            $tone = $tone === 'positive' ? 'neutral' : 'cautious';
        }

        $sentence = $this->librarySentence('chip', $tone, $conditions, $this->vars($pack));
        if ($sentence !== '') {
            return $sentence;
        }

        if (($c['institutional_net_buy_5d'] ?? 0) > 0 && ($c['margin_change_5d'] ?? 0) <= 0) {
            return '法人近 5 日仍偏向買超，而且融資沒有同步快速增加，籌碼結構相對乾淨，後續可觀察法人買盤是否延續。';
        }
        if (($c['margin_change_5d'] ?? 0) > 0 && ($c['institutional_net_buy_5d'] ?? 0) < 0) {
            return '近 5 日呈現法人賣超、融資增加的組合，代表籌碼有轉向散戶承接的跡象，若股價同時跌破支撐，風險會升高。';
        }

        return '籌碼目前沒有出現非常一致的方向，應搭配價量、均線位置與題材熱度一起判斷，不宜只看單日法人買賣超。';
    }

    private function fundamentalAnalysis(array $pack): string
    {
        $f = $pack['financial'];
        $r = $pack['revenue'];
        $conditions = [];
        $tone = 'neutral';

        if (($r['yoy_pct'] ?? 0) > 10) {
            $conditions[] = 'revenue_growth';
            $tone = 'positive';
        } elseif (($r['yoy_pct'] ?? 0) < 0) {
            $conditions[] = 'revenue_decline';
            $tone = 'cautious';
        }

        $per = $f['per'] ?? $f['per_estimated'];
        if ($per !== null && $per >= 35) {
            $conditions[] = 'valuation_high';
            $tone = $tone === 'positive' ? 'neutral' : 'cautious';
        }

        $sentence = $this->librarySentence('fundamental', $tone, $conditions, $this->vars($pack));
        if ($sentence !== '') {
            return $sentence;
        }

        if (($r['yoy_pct'] ?? 0) > 10 && ($per ?? 999) < 30) {
            return '營收年增仍有支撐，且評價沒有明顯脫離財報表現，基本面能提供一定支撐。';
        }
        if (($r['yoy_pct'] ?? 0) < 0 && ($per ?? 0) >= 30) {
            return '營收轉弱但評價仍偏高，代表市場期待尚未完全反映基本面壓力，後續需觀察營收是否回穩。';
        }

        return '基本面目前較適合用營收趨勢、毛利率與 ROE 交叉確認，若題材很熱但營收沒有同步改善，評價承受度會下降。';
    }

    /**
     * @return array<string,string>
     */
    private function vars(array $pack): array
    {
        return [
            'stock_name' => (string) $pack['name'],
            'symbol' => (string) $pack['symbol'],
            'theme_text' => $pack['themes'] === [] ? '目前題材不明確' : implode('、', array_slice($pack['themes'], 0, 3)),
            'close' => $this->n($pack['price']['close'] ?? null),
            'return5' => $this->signedPct($pack['price_trend']['return5'] ?? null),
            'return20' => $this->signedPct($pack['price_trend']['return20'] ?? null),
            'volume_ratio20' => $this->n($pack['price_trend']['volume_ratio20'] ?? null),
            'support' => (string) ($pack['support_resistance']['support'] ?? '支撐未明'),
            'pressure' => (string) ($pack['support_resistance']['pressure'] ?? '壓力未明'),
            'institutional_5d' => $this->signedShares($pack['chip']['institutional_net_buy_5d'] ?? null),
            'revenue_yoy' => $this->signedPct($pack['revenue']['yoy_pct'] ?? null),
        ];
    }

    /**
     * @param array<string,string> $vars
     */
    private function replaceVars(string $text, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $text = str_replace('{'.$key.'}', $value, $text);
        }

        return $text;
    }

    private function valuationLine(array $f): string
    {
        $items = [];
        if ($f['per'] !== null) {
            $items[] = '本益比 '.$this->n($f['per']).' 倍';
        } elseif ($f['per_estimated'] !== null) {
            $items[] = '本益比估算 '.$this->n($f['per_estimated']).' 倍（以最新 EPS 年化推估）';
        } else {
            $items[] = '本益比暫無可用資料';
        }

        if ($f['pb_ratio'] !== null) {
            $items[] = '股價淨值比 '.$this->n($f['pb_ratio']).' 倍';
        }

        if ($f['dividend_yield'] !== null) {
            $items[] = '殖利率 '.$this->pct($f['dividend_yield']);
        }

        $line = implode('，', $items).'。';
        if ($f['pb_ratio'] === null || $f['dividend_yield'] === null) {
            $line .= '股價淨值比與殖利率若尚未匯入，先以 EPS、ROE、毛利率、營收年增率與籌碼狀態交叉判讀。';
        }

        return $line;
    }

    private function priceFallbackSentence(array $pack): string
    {
        $trend = $pack['price_trend'];
        if (($trend['return20'] ?? 0) > 8 && ($trend['volume_ratio20'] ?? 0) >= 1.2) {
            return '近期漲幅與量能同步放大，代表市場追價意願仍在，但若接近壓力區仍需觀察是否能有效換手。';
        }
        if (($trend['return20'] ?? 0) < -8) {
            return '近期股價仍處於弱勢修正後的回彈階段，若反彈量能不足，仍可能只是短線修復。';
        }

        return '近期股價尚未形成明確單邊趨勢，重點在於能否站穩短均線並讓量能逐步回溫。';
    }

    /**
     * @param array<int,string> $conditions
     */
    private function technicalFallbackSentence(array $conditions): string
    {
        if (in_array('ma_bullish_stack', $conditions, true) && in_array('macd_turning_up', $conditions, true)) {
            return '均線與 MACD 同步偏多，短線結構相對有利，但仍要避免在乖離過大時追高。';
        }
        if (in_array('rsi_overheated', $conditions, true) || in_array('bais_overheated', $conditions, true)) {
            return '技術面已出現過熱訊號，若後續量能無法延續，短線容易轉為震盪或拉回。';
        }
        if (in_array('macd_turning_down', $conditions, true)) {
            return 'MACD 動能轉弱，代表上攻力道有放緩跡象，需要觀察股價是否守住短期均線。';
        }

        return '技術面目前沒有出現極端訊號，應以均線位置、MACD 動能與量能是否延續作為主要觀察。';
    }

    private function taiwanDate(mixed $date): string
    {
        if ($date === null || $date === '') {
            return '日期資料不足';
        }

        try {
            return now('Asia/Taipei')->parse((string) $date)->format('n月j日');
        } catch (\Throwable) {
            return (string) $date;
        }
    }

    private function sum(Collection $rows, string $field): int
    {
        return (int) $rows->sum(fn ($row) => (int) ($row->{$field} ?? 0));
    }

    private function balanceChange(Collection $rows, string $field, int $days): ?int
    {
        $latest = $rows->first();
        $base = $rows->skip($days - 1)->first();
        if ($latest?->{$field} === null || $base?->{$field} === null) {
            return null;
        }

        return (int) $latest->{$field} - (int) $base->{$field};
    }

    private function volumeChangePct(?object $latest, ?object $previous): ?float
    {
        if ($latest?->volume === null || $previous?->volume === null || (int) $previous->volume === 0) {
            return null;
        }

        return (((int) $latest->volume - (int) $previous->volume) / (int) $previous->volume) * 100;
    }

    private function priceChangePct(?object $latest, ?object $previous): ?float
    {
        if ($latest?->change_pct !== null) {
            return (float) $latest->change_pct;
        }

        if ($latest?->close === null || $previous?->close === null || (float) $previous->close == 0.0) {
            return null;
        }

        return (((float) $latest->close - (float) $previous->close) / (float) $previous->close) * 100;
    }

    private function float(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function n(?float $value): string
    {
        return $value === null ? '資料不足' : rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    private function pct(?float $value): string
    {
        return $value === null ? '資料不足' : number_format($value, 2).'%';
    }

    private function signed(?float $value): string
    {
        return $value === null ? '資料不足' : ($value > 0 ? '+' : '').rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    private function signedPct(?float $value): string
    {
        return $value === null ? '資料不足' : ($value > 0 ? '+' : '').number_format($value, 2).'%';
    }

    private function shares(?int $value): string
    {
        return $value === null ? '資料不足' : number_format($value).' 股';
    }

    private function signedShares(?int $value): string
    {
        return $value === null ? '資料不足' : ($value > 0 ? '+' : '').number_format($value).' 股';
    }

    private function money(?int $value): string
    {
        return $value === null ? '資料不足' : number_format($value).' 千元';
    }

    private function range(float $from, float $to): string
    {
        return $this->n($from).'~'.$this->n($to);
    }
}
