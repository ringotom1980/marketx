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
            'engine' => 'research_report_v3',
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
        return implode("\n\n", array_filter([
            "一、近期股價與量能\n".$this->priceSection($pack),
            "二、支撐與壓力\n".$this->supportSection($pack),
            "三、技術結構\n".$this->technicalSection($pack),
            "四、籌碼與資金動向\n".$this->chipSection($pack),
            "五、財報、營收與評價\n".$this->fundamentalSection($pack),
            "六、新聞與題材\n".$this->newsSection($pack),
            "七、觀察重點\n".$this->directionSection($pack),
            "總結\n".$this->summarySection($pack),
        ]));
    }

    private function priceSection(array $pack): string
    {
        $price = $pack['price'];
        $trend = $pack['price_trend'];
        $lines = [
            "{$pack['name']}最新收盤 {$this->n($price['close'])} 元，單日{$this->changeWord($price['change'])} {$this->absNumber($price['change'])} 元（{$this->signedPct($price['change_pct'])}），成交量 {$this->shares($price['volume'])}。",
            "近 5 日報酬率 {$this->signedPct($trend['return5'])}，近 20 日報酬率 {$this->signedPct($trend['return20'])}，近 60 日報酬率 {$this->signedPct($trend['return60'])}。",
            "目前成交量約為 20 日平均成交量的 {$this->n($trend['volume_ratio20'])} 倍。",
            $this->priceNarrative($pack),
        ];

        return implode("\n", $lines);
    }

    private function supportSection(array $pack): string
    {
        $sr = $pack['support_resistance'];
        $lines = ['依近 90 日價量分布估算：'];

        if ($sr['support'] !== null) {
            $lines[] = '支撐區 '.$sr['support'].' 元';
            $lines[] = '支撐區累積成交量：'.$this->shares($sr['support_strength']);
        } else {
            $lines[] = '支撐區：目前資料不足';
        }

        if ($sr['pressure'] !== null) {
            $lines[] = '';
            $lines[] = '壓力區 '.$sr['pressure'].' 元';
            $lines[] = '壓力區累積成交量：'.$this->shares($sr['pressure_strength']);
        } else {
            $lines[] = '壓力區：上方暫無明確成交密集壓力';
        }

        $lines[] = '';
        $lines[] = '現價 '.$this->n($sr['current']).' 元'.$this->supportPositionText($sr);
        $lines[] = '';
        $lines[] = $this->supportNarrative($sr);

        return implode("\n", $lines);
    }

    private function technicalSection(array $pack): string
    {
        $price = $pack['price'];
        $t = $pack['technical'];
        $lines = [
            '均線位置',
            '收盤價：'.$this->n($price['close']),
            'SMA20：'.$this->n($t['sma20']),
            'SMA60：'.$this->n($t['sma60']),
            'SMA120：'.$this->n($t['sma120']),
            '',
            '目前股價：',
            $this->maPositionLine($price['close'], $t['sma20'], '20 日均線'),
            $this->maPositionLine($price['close'], $t['sma60'], '60 日均線'),
            '',
            '動能指標',
            'RSI14：'.$this->n($t['rsi14']),
            'MACD：'.$this->n($t['macd']),
            'Signal：'.$this->n($t['macd_signal']),
            'MACD柱狀體：'.$this->n($t['macd_histogram']),
            'KD：',
            'K = '.$this->n($t['k9']),
            'D = '.$this->n($t['d9']),
            '',
            '波動指標',
            '20日乖離：'.$this->signedPct($t['bais20']),
            'ATR14：'.$this->n($t['atr14']),
            '布林上緣：'.$this->n($t['bollinger_upper20']),
            '布林下緣：'.$this->n($t['bollinger_lower20']),
            '',
            $this->technicalNarrative($pack),
        ];

        return implode("\n", $lines);
    }

    private function chipSection(array $pack): string
    {
        $c = $pack['chip'];
        $lines = [
            '三大法人',
            $this->taiwanDate($c['trade_date'] ?? null),
            '外資    '.$this->signedShares($c['foreign_net_buy']),
            '投信   '.$this->signedShares($c['investment_trust_net_buy']),
            '自營商   '.$this->signedShares($c['dealer_net_buy']),
            '合計  '.$this->signedShares($c['institutional_net_buy']),
            '',
            '近 5 日',
            '法人合計 '.$this->signedShares($c['institutional_net_buy_5d']),
            '',
            '近 20 日',
            '法人合計 '.$this->signedShares($c['institutional_net_buy_20d']),
            '',
            '融資融券',
            '融資餘額：'.$this->shares($c['margin_balance']),
            '融券餘額：'.$this->shares($c['short_balance']),
            '',
            '近5日融資變化：',
            $this->signedShares($c['margin_change_5d']),
            '',
            $this->chipNarrative($pack),
        ];

        return implode("\n", $lines);
    }

    private function fundamentalSection(array $pack): string
    {
        $f = $pack['financial'];
        $r = $pack['revenue'];
        $lines = [
            '最新月營收 '.$this->money($r['revenue']),
            '月增率：'.$this->signedPct($r['mom_pct']),
            '年增率：'.$this->signedPct($r['yoy_pct']),
            '近3個月累計營收：'.$this->money($r['revenue_3m']),
            '近12個月累計營收：'.$this->money($r['revenue_12m']),
            '',
            '財報指標',
            $this->periodLabel($f['period']),
            'EPS：'.$this->n($f['eps']).' 元',
            'ROE：'.$this->pct($f['roe']),
            '毛利率：'.$this->pct($f['gross_margin']),
            '營業利益率：'.$this->pct($f['operating_margin']),
            $this->valuationLine($f),
            '',
            $this->fundamentalNarrative($pack),
        ];

        return implode("\n", array_values(array_filter($lines, fn ($line) => $line !== null)));
    }

    private function newsSection(array $pack): string
    {
        $lines = ['主要關聯題材'];

        if ($pack['themes'] === []) {
            $lines[] = '目前沒有明確題材標籤';
        } else {
            foreach (array_slice($pack['themes'], 0, 6) as $theme) {
                $lines[] = $theme;
            }
        }

        $lines[] = '';
        $lines[] = '近期市場關注事件';

        if ($pack['news'] === []) {
            $lines[] = '目前新聞資料庫尚未抓到與個股直接相關的近期新聞。';
        } else {
            foreach (array_slice($pack['news'], 0, 5) as $news) {
                $lines[] = $this->newsDate($news['date'] ?? null);
                $lines[] = $news['title'];
            }
        }

        $lines[] = $this->newsNarrative($pack);

        return trim(implode("\n", $lines));
    }

    private function directionSection(array $pack): string
    {
        $directions = [
            '觀察股價是否維持於 '.$this->keyTrendLine($pack).' 與主要支撐結構之上。',
            '觀察接近壓力區時成交量是否同步增加，以確認市場參與度是否持續。',
            '觀察三大法人買賣方向是否改變，目前'.$this->institutionalDirectionText($pack).'。',
            '觀察後續月營收與財報是否延續目前趨勢，以確認基本面與市場評價的一致性。',
        ];

        return implode("\n", array_map(fn (string $line, int $index) => ($index + 1).'、'.$line, $directions, array_keys($directions)));
    }

    private function summarySection(array $pack): string
    {
        $points = $this->summaryPoints($pack);
        $lines = [
            '當前階段判定',
            $this->stageText($pack),
            '',
            '關鍵依據',
        ];

        foreach ($points['basis'] as $basis) {
            $lines[] = $basis;
        }

        $lines[] = '';
        $lines[] = '市場關注重點';
        foreach ($points['focus'] as $focus) {
            $lines[] = $focus;
        }

        return implode("\n", $lines);
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
            ->limit(6)
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

    private function priceNarrative(array $pack): string
    {
        $trend = $pack['price_trend'];
        $price = $pack['price'];
        $volume = $trend['volume_ratio20'] ?? null;

        if (($trend['return20'] ?? 0) < -8 && ($trend['return60'] ?? 0) > 20) {
            return '短期表現與中期表現出現落差。近 20 日股價明顯回落，但近 60 日仍維持大幅上漲，代表先前累積的漲幅尚未完全被消化。'.($volume !== null && $volume > 1.2 ? '成交量維持在均量之上，顯示市場關注度仍高於一般水準，但後續仍需觀察量能是否持續維持。' : '若後續量能無法回升，反彈力道容易受到限制。');
        }
        if (($trend['return20'] ?? 0) > 10 && ($volume ?? 0) > 1.2) {
            return '近期股價與量能同步轉強，代表市場參與度仍高。若後續量能能維持在均量之上，股價較有機會維持強勢結構；但若短線漲幅過快，也要留意高檔震盪與獲利了結壓力。';
        }
        if (($trend['return20'] ?? 0) < -8) {
            return '近 20 日股價仍偏弱，短線即使出現反彈，也需要量能與均線同步改善才能確認止穩。若成交量放大但股價無法收復短均線，代表上方賣壓仍需時間消化。';
        }
        if (($price['change_pct'] ?? 0) < 0 && ($volume ?? 0) > 1.5) {
            return '單日下跌且成交量高於均量，代表市場出現較明顯換手。後續要觀察下跌是否只是短線調節，或是開始形成更持續的賣壓。';
        }

        return '近期股價尚未形成明確單邊趨勢，重點在於短均線能否逐步轉強，以及成交量是否能配合價格走勢同步擴大。';
    }

    private function supportPositionText(array $sr): string
    {
        if ($sr['pressure'] === null && $sr['support'] !== null) {
            return '位於支撐區上方，上方暫無明確歷史成交密集壓力。';
        }
        if ($sr['pressure'] !== null) {
            return '仍位於壓力區下方，後續需觀察接近壓力帶時的成交量變化。';
        }

        return '目前支撐與壓力資料仍不完整。';
    }

    private function supportNarrative(array $sr): string
    {
        $supportStrength = $sr['support_strength'] ?? null;
        $pressureStrength = $sr['pressure_strength'] ?? null;

        if ($supportStrength !== null && $pressureStrength !== null && $supportStrength > $pressureStrength) {
            return '支撐區累積成交量高於壓力區，代表過去較多交易集中於支撐區附近。後續需觀察股價是否維持於主要成交密集區之上，若跌破支撐且量能放大，結構會明顯轉弱。';
        }
        if ($supportStrength !== null && $pressureStrength !== null && $pressureStrength > $supportStrength) {
            return '壓力區累積成交量高於支撐區，代表上方可能有較多套牢或換手賣壓。若股價接近壓力區時無法放量突破，較容易出現震盪整理。';
        }

        return '支撐與壓力屬於價量結構的參考，不是固定目標價。若股價跌破支撐，應往下一個成交密集區重新尋找支撐；若放量突破壓力區，才代表上方賣壓逐步被消化。';
    }

    private function maPositionLine(?float $close, ?float $ma, string $name): string
    {
        if ($close === null || $ma === null) {
            return $name.'資料不足';
        }

        return ($close >= $ma ? '高於 ' : '低於 ').$name;
    }

    private function technicalNarrative(array $pack): string
    {
        $price = $pack['price'];
        $t = $pack['technical'];
        $parts = [];

        if (($price['close'] ?? null) !== null && ($t['sma20'] ?? null) !== null && ($t['sma60'] ?? null) !== null) {
            if ($price['close'] < $t['sma20'] && $price['close'] > $t['sma60']) {
                $parts[] = '股價已跌破 20 日均線，但仍維持於 60 日均線之上，代表短線轉弱但中期結構尚未完全破壞。';
            } elseif ($price['close'] > $t['sma20'] && $t['sma20'] > $t['sma60']) {
                $parts[] = '股價維持在 20 日均線之上，且短中期均線排列偏多，技術結構仍相對有利。';
            } elseif ($price['close'] < $t['sma20'] && $price['close'] < $t['sma60']) {
                $parts[] = '股價同時低於 20 日與 60 日均線，短中期結構偏弱，需等重新站回均線後才較能確認止穩。';
            }
        }

        if (($t['rsi14'] ?? null) !== null || ($t['k9'] ?? null) !== null || ($t['macd_histogram'] ?? null) !== null) {
            if (($t['rsi14'] ?? 50) < 45 && ($t['k9'] ?? 50) < 35) {
                $parts[] = 'RSI 與 KD 位於相對低檔區域，代表短線動能已偏弱，但也要觀察是否出現止跌訊號。';
            } elseif (($t['rsi14'] ?? 0) >= 70) {
                $parts[] = 'RSI 已接近或進入偏熱區，若股價續漲但量能無法延續，容易出現震盪或拉回。';
            }

            if (($t['macd_histogram'] ?? null) !== null && ($t['macd_histogram_previous'] ?? null) !== null) {
                $parts[] = $t['macd_histogram'] >= $t['macd_histogram_previous']
                    ? 'MACD 柱狀體較前期改善，代表短線動能有回穩跡象。'
                    : 'MACD 顯示短期動能較前期減弱，應關注股價是否能維持於中期趨勢結構之上。';
            }
        }

        if (($t['bais20'] ?? null) !== null && abs($t['bais20']) >= 10) {
            $parts[] = '20 日乖離已經偏大，代表股價與短期均線距離拉開，後續容易出現均線靠攏或股價修正。';
        }

        return implode('', $parts) ?: '技術面目前沒有出現極端訊號，後續仍以均線位置、MACD 動能與量能是否延續作為主要觀察。';
    }

    private function chipNarrative(array $pack): string
    {
        $c = $pack['chip'];
        if (($c['institutional_net_buy_5d'] ?? 0) < 0 && ($c['institutional_net_buy_20d'] ?? 0) < 0 && ($c['margin_change_5d'] ?? 0) > 0) {
            return '近期法人資金呈現持續流出狀態。同期間融資餘額增加，代表市場參與結構出現變化，後續需持續觀察法人動向是否改變，以及融資增幅是否持續擴大。';
        }
        if (($c['institutional_net_buy_5d'] ?? 0) > 0 && ($c['institutional_net_buy_20d'] ?? 0) > 0) {
            return '近 5 日與近 20 日法人買賣方向偏向同側買超，代表中短期法人資金仍有承接。若股價能維持在主要均線之上，籌碼面可視為支撐條件之一。';
        }
        if (($c['institutional_net_buy'] ?? 0) < 0 && ($c['institutional_net_buy_5d'] ?? 0) > 0) {
            return '單日法人轉為賣超，但近 5 日仍維持買超，代表短線可能出現調節，不一定代表趨勢立即反轉，後續需觀察賣超是否連續擴大。';
        }
        if (($c['margin_change_5d'] ?? 0) > 0) {
            return '融資餘額近 5 日增加，代表散戶資金參與度提高。若股價同步走強可視為人氣升溫，但若股價轉弱，融資增加會放大波動風險。';
        }

        return '籌碼面目前沒有出現非常一致的方向，應搭配價量、均線位置與題材熱度一起判斷，不宜只看單日法人買賣超。';
    }

    private function fundamentalNarrative(array $pack): string
    {
        $f = $pack['financial'];
        $r = $pack['revenue'];
        $per = $f['per'] ?? $f['per_estimated'];
        $sentences = [];

        if (($r['yoy_pct'] ?? null) !== null && ($r['mom_pct'] ?? null) !== null) {
            if ($r['yoy_pct'] > 20 && $r['mom_pct'] < 0) {
                $sentences[] = '營收年增率維持高成長，但月營收較前月下滑，代表長期成長趨勢仍在，但短期動能需要持續追蹤。';
            } elseif ($r['yoy_pct'] > 10) {
                $sentences[] = '營收年增率仍維持成長，基本面對股價具有一定支撐。';
            } elseif ($r['yoy_pct'] < 0) {
                $sentences[] = '營收年增率轉弱，基本面能否重新回到成長軌道會是後續重要觀察。';
            }
        }

        if (($f['gross_margin'] ?? null) !== null || ($f['operating_margin'] ?? null) !== null) {
            $sentences[] = '獲利能力需搭配毛利率、營業利益率與 ROE 觀察，若三者能維持穩定，代表本業獲利品質較有支撐。';
        }

        if ($per !== null && $per >= 50) {
            $sentences[] = '目前市場評價已反映相當程度的成長預期，因此後續須特別注意營收與獲利是否持續成長。';
        } elseif ($per !== null && $per < 30) {
            $sentences[] = '目前評價相對沒有過度拉高，若營收與獲利延續改善，基本面較能支撐股價表現。';
        }

        return implode('', $sentences) ?: '基本面目前較適合用營收趨勢、毛利率、ROE 與估值水準交叉確認，若題材很熱但營收沒有同步改善，評價承受度會下降。';
    }

    private function newsNarrative(array $pack): string
    {
        $themes = $pack['themes'];
        if ($themes === []) {
            return '近期新聞主軸尚未形成明確題材，後續仍需觀察是否有新的產業事件、營收公告或法人資金流向帶動市場關注。';
        }

        $themeText = implode('、', array_slice($themes, 0, 4));
        return "近期新聞主軸集中於：{$themeText}\n\n相關題材熱度仍需搭配未來營收、獲利與股價走勢確認，若只有題材熱度但沒有實際營運數據跟上，後續波動會比較大。";
    }

    private function keyTrendLine(array $pack): string
    {
        $price = $pack['price'];
        $t = $pack['technical'];
        if (($price['close'] ?? null) !== null && ($t['sma60'] ?? null) !== null && $price['close'] >= $t['sma60']) {
            return '60 日均線';
        }

        return '20 日均線';
    }

    private function institutionalDirectionText(array $pack): string
    {
        $c = $pack['chip'];
        if (($c['institutional_net_buy_5d'] ?? 0) > 0) {
            return '近 5 日法人資金偏向流入';
        }
        if (($c['institutional_net_buy_5d'] ?? 0) < 0) {
            return '近 5 日法人資金偏向流出';
        }

        return '法人資金方向尚未明確';
    }

    /**
     * @return array{basis:array<int,string>,focus:array<int,string>}
     */
    private function summaryPoints(array $pack): array
    {
        $price = $pack['price'];
        $trend = $pack['price_trend'];
        $t = $pack['technical'];
        $c = $pack['chip'];
        $r = $pack['revenue'];
        $basis = [];

        if (($trend['return60'] ?? 0) > 20) {
            $basis[] = '近 60 日仍維持明顯上漲';
        } elseif (($trend['return60'] ?? 0) < -10) {
            $basis[] = '近 60 日股價仍處於弱勢區間';
        }

        if (($price['close'] ?? null) !== null && ($t['sma20'] ?? null) !== null && ($t['sma60'] ?? null) !== null) {
            if ($price['close'] < $t['sma20'] && $price['close'] > $t['sma60']) {
                $basis[] = '股價跌破 20 日均線但仍高於 60 日均線';
            } elseif ($price['close'] > $t['sma20']) {
                $basis[] = '股價仍站在 20 日均線之上';
            }
        }

        if (($c['institutional_net_buy_5d'] ?? 0) < 0 && ($c['institutional_net_buy_20d'] ?? 0) < 0) {
            $basis[] = '法人近 5 日與近 20 日持續賣超';
        } elseif (($c['institutional_net_buy_5d'] ?? 0) > 0) {
            $basis[] = '法人近 5 日偏向買超';
        }

        if ($pack['themes'] !== []) {
            $basis[] = implode('、', array_slice($pack['themes'], 0, 3)).' 題材仍具市場關注度';
        }

        if (($r['yoy_pct'] ?? 0) > 10) {
            $basis[] = '營收年增率仍具支撐';
        }

        return [
            'basis' => array_slice($basis, 0, 6),
            'focus' => [
                '法人資金是否回流',
                '股價是否維持中期結構',
                '題材熱度是否能轉化為營收與獲利',
                '後續月營收與財報是否延續成長趨勢',
            ],
        ];
    }

    private function stageText(array $pack): string
    {
        $price = $pack['price'];
        $trend = $pack['price_trend'];
        $t = $pack['technical'];

        if (($trend['return60'] ?? 0) > 30 && ($price['close'] ?? 0) < ($t['sma20'] ?? -INF) && ($price['close'] ?? 0) > ($t['sma60'] ?? INF)) {
            return '高檔整理階段';
        }
        if (($price['close'] ?? 0) > ($t['sma20'] ?? INF) && ($t['sma20'] ?? 0) > ($t['sma60'] ?? INF)) {
            return '多方延續觀察階段';
        }
        if (($price['close'] ?? 0) < ($t['sma20'] ?? -INF) && ($price['close'] ?? 0) < ($t['sma60'] ?? -INF)) {
            return '弱勢修復觀察階段';
        }

        return '區間整理觀察階段';
    }

    private function valuationLine(array $f): ?string
    {
        if ($f['per'] !== null) {
            return '本益比：'.$this->n($f['per']).' 倍';
        }
        if ($f['per_estimated'] !== null) {
            return '推估本益比：'.$this->n($f['per_estimated']).' 倍';
        }

        return null;
    }

    private function periodLabel(mixed $period): string
    {
        return str_replace('Q', ' Q', (string) $period);
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

    private function absNumber(?float $value): string
    {
        return $value === null ? '資料不足' : rtrim(rtrim(number_format(abs($value), 2), '0'), '.');
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
        return $this->n($from).' ~ '.$this->n($to);
    }

    private function changeWord(?float $change): string
    {
        if ($change === null) {
            return '漲跌';
        }

        if ($change > 0) {
            return '上漲';
        }

        if ($change < 0) {
            return '下跌';
        }

        return '持平';
    }

    private function taiwanDate(mixed $date): string
    {
        if ($date === null || $date === '') {
            return '日期資料不足';
        }

        try {
            return now('Asia/Taipei')->parse((string) $date)->format('n 月 j 日');
        } catch (\Throwable) {
            return (string) $date;
        }
    }

    private function newsDate(mixed $date): string
    {
        if ($date === null || $date === '') {
            return '日期資料不足';
        }

        try {
            return now('Asia/Taipei')->parse((string) $date)->format('Y/m/d');
        } catch (\Throwable) {
            return (string) $date;
        }
    }
}
