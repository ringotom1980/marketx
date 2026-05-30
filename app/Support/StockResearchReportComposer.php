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
            'engine' => 'research_report_v1',
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
            'price_trend' => $this->priceTrend($prices, $technical),
            'support_resistance' => $supportResistance,
            'technical' => $this->technical($technical),
            'chip' => $this->chip($latestChip, $chips),
            'financial' => $this->financial($latestFinancial, $financials),
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
            '一、近期股價與量能',
            $this->priceSection($pack),
            '二、支撐與壓力',
            $this->supportSection($pack),
            '三、技術指標',
            $this->technicalSection($pack),
            '四、籌碼與資金動向',
            $this->chipSection($pack),
            '五、財報、營收與評價',
            $this->fundamentalSection($pack),
            '六、題材與近期新聞',
            $this->newsSection($pack),
            '七、後續觀察方向',
            $this->directionSection($pack),
        ];

        return implode("\n\n", array_filter($sections));
    }

    private function priceSection(array $pack): string
    {
        $price = $pack['price'];
        $trend = $pack['price_trend'];
        $lines = [];
        $lines[] = "{$pack['name']} 最新收盤 {$this->n($price['close'])} 元，單日漲跌 {$this->signed($price['change'])} 元（{$this->signedPct($price['change_pct'])}），成交量 {$this->shares($price['volume'])}。";
        $lines[] = "近期股價表現：近 5 日 {$this->signedPct($trend['return5'])}、近 20 日 {$this->signedPct($trend['return20'])}、近 60 日 {$this->signedPct($trend['return60'])}；目前量能約為 20 日均量的 {$this->n($trend['volume_ratio20'])} 倍。";

        if (($trend['return20'] ?? 0) > 8 && ($trend['volume_ratio20'] ?? 0) >= 1.2) {
            $lines[] = '價格與量能同時放大，代表短線資金仍願意追蹤這檔股票，但也要同步檢查是否已經離短均線太遠。';
        } elseif (($trend['return20'] ?? 0) < -8 && ($price['change_pct'] ?? 0) > 0) {
            $lines[] = '股價仍在修復過程中，今日反彈比較像跌深後資金回補，後續要觀察量能能否連續放大。';
        } else {
            $lines[] = '目前價格不是只看單日漲跌，重點在近 20 日趨勢與量能是否同向，這會影響後續延續性。';
        }

        return implode('', $lines);
    }

    private function supportSection(array $pack): string
    {
        $sr = $pack['support_resistance'];
        $support = $sr['support'] ?? '資料不足';
        $pressure = $sr['pressure'] ?? '尚未形成明確壓力';
        $line = "以近 90 日價量分布估算，目前價位約 {$this->n($sr['current'])} 元，較明顯支撐區在 {$support}，上方壓力區在 {$pressure}。";

        if ($sr['support_strength'] !== null && $sr['pressure_strength'] !== null) {
            $line .= "支撐區累積量約 {$this->shares($sr['support_strength'])}，壓力區累積量約 {$this->shares($sr['pressure_strength'])}。";
        } elseif ($sr['support_strength'] !== null) {
            $line .= "支撐區累積量約 {$this->shares($sr['support_strength'])}；目前若已接近波段新高，上方壓力會改以整數關卡、前高與放量換手情況觀察。";
        }

        $line .= '若股價跌破支撐區，代表原本承接位置失守，需要重新往下一個成交密集區找支撐；若放量突破壓力區，才比較能確認上方賣壓被消化。';

        return $line;
    }

    private function technicalSection(array $pack): string
    {
        $t = $pack['technical'];
        $items = [];
        $items[] = "均線：收盤價 {$this->n($pack['price']['close'])}，SMA20 {$this->n($t['sma20'])}、SMA60 {$this->n($t['sma60'])}、SMA120 {$this->n($t['sma120'])}。";
        $items[] = "動能：RSI14 {$this->n($t['rsi14'])}，MACD {$this->n($t['macd'])}、Signal {$this->n($t['macd_signal'])}、柱狀體 {$this->n($t['macd_histogram'])}，KD 為 K {$this->n($t['k9'])} / D {$this->n($t['d9'])}。";
        $items[] = "乖離與波動：20 日乖離 {$this->signedPct($t['bais20'])}，ATR14 {$this->n($t['atr14'])}，布林上緣 {$this->n($t['bollinger_upper20'])}、下緣 {$this->n($t['bollinger_lower20'])}。";

        $judgement = [];
        if (($pack['price']['close'] ?? 0) > ($t['sma20'] ?? INF) && ($t['sma20'] ?? 0) > ($t['sma60'] ?? INF)) {
            $judgement[] = '短中期均線偏多';
        }
        if (($t['macd_histogram'] ?? null) !== null && ($t['macd_histogram_previous'] ?? null) !== null) {
            $judgement[] = $t['macd_histogram'] >= $t['macd_histogram_previous'] ? 'MACD 柱狀體改善' : 'MACD 柱狀體縮減';
        }
        if (($t['rsi14'] ?? 0) >= 75) {
            $judgement[] = 'RSI 進入偏熱區';
        } elseif (($t['rsi14'] ?? 0) <= 40) {
            $judgement[] = 'RSI 偏弱';
        }
        if (($t['bais20'] ?? 0) >= 10) {
            $judgement[] = '20 日乖離偏大';
        }

        return implode('', $items).'整體來看，'.($judgement === [] ? '技術訊號偏混合，需要等待量價與均線方向更一致。' : implode('、', $judgement).'，這些是目前技術面的主要觀察點。');
    }

    private function chipSection(array $pack): string
    {
        $c = $pack['chip'];
        $lines = [];
        $lines[] = "最新三大法人合計 {$this->signedShares($c['institutional_net_buy'])}，其中外資 {$this->signedShares($c['foreign_net_buy'])}、投信 {$this->signedShares($c['investment_trust_net_buy'])}、自營商 {$this->signedShares($c['dealer_net_buy'])}。";
        $lines[] = "近 5 日法人合計 {$this->signedShares($c['institutional_net_buy_5d'])}，近 20 日法人合計 {$this->signedShares($c['institutional_net_buy_20d'])}。";
        $lines[] = "融資餘額 {$this->shares($c['margin_balance'])}、融券餘額 {$this->shares($c['short_balance'])}，近 5 日融資變化 {$this->signedShares($c['margin_change_5d'])}。";

        if (($c['institutional_net_buy_5d'] ?? 0) > 0 && ($c['margin_change_5d'] ?? 0) <= 0) {
            $lines[] = '法人偏買且融資沒有同步快速膨脹，籌碼結構相對健康。';
        } elseif (($c['margin_change_5d'] ?? 0) > 0 && ($c['institutional_net_buy_5d'] ?? 0) < 0) {
            $lines[] = '法人偏賣但融資增加，代表籌碼浮動風險升高，股價轉弱時容易放大波動。';
        } else {
            $lines[] = '籌碼面目前需要看法人買賣是否連續，以及融資餘額是否跟著價格過度升高。';
        }

        return implode('', $lines);
    }

    private function fundamentalSection(array $pack): string
    {
        $f = $pack['financial'];
        $r = $pack['revenue'];
        $lines = [];
        $lines[] = "最新月營收 {$this->money($r['revenue'])}，月增率 {$this->signedPct($r['mom_pct'])}、年增率 {$this->signedPct($r['yoy_pct'])}；近 3 個月營收合計 {$this->money($r['revenue_3m'])}，近 12 個月合計 {$this->money($r['revenue_12m'])}。";
        $lines[] = "最新財務資料期間 {$f['period']}，EPS {$this->n($f['eps'])}、ROE {$this->pct($f['roe'])}、毛利率 {$this->pct($f['gross_margin'])}、營業利益率 {$this->pct($f['operating_margin'])}。";
        $lines[] = "評價面：本益比 {$this->n($f['per'])}、股價淨值比 {$this->n($f['pb_ratio'])}、殖利率 {$this->pct($f['dividend_yield'])}。";

        if (($r['yoy_pct'] ?? 0) > 10 && ($f['per'] ?? 0) < 30) {
            $lines[] = '營收成長與評價沒有明顯脫節，基本面對股價仍有支撐。';
        } elseif (($r['yoy_pct'] ?? 0) < 0 && ($f['per'] ?? 0) >= 30) {
            $lines[] = '營收年增率偏弱但評價不低，股價若續強就需要後續財報或題材給出更明確支撐。';
        } else {
            $lines[] = '基本面要看營收能否延續，若股價漲幅領先營收，後續容易回到評價檢驗。';
        }

        return implode('', $lines);
    }

    private function newsSection(array $pack): string
    {
        $themeText = $pack['themes'] === [] ? '目前沒有明確題材標籤' : implode('、', array_slice($pack['themes'], 0, 4));
        $lines = ["目前關聯題材：{$themeText}。"];

        if ($pack['news'] === []) {
            $lines[] = '近期新聞資料庫沒有抓到明確對應此股的重大新聞，因此這份報告以價格、量能、籌碼與財報資料為主要依據。';
        } else {
            $lines[] = '近期可參考新聞：';
            foreach (array_slice($pack['news'], 0, 4) as $news) {
                $lines[] = '・'.$news['date'].'｜'.$news['title'];
            }
            $lines[] = '新聞只能作為題材背景，仍需要回到股價是否反映、量能是否跟上，以及法人是否延續。';
        }

        return implode("\n", $lines);
    }

    private function directionSection(array $pack): string
    {
        $directions = [];
        $directions[] = '觀察量能是否維持在 20 日均量附近或以上，若價漲量縮，延續性要打折。';
        $directions[] = '觀察股價是否守住支撐區 '.$pack['support_resistance']['support'].'，跌破後要重新檢查下一個成交密集區。';
        $directions[] = '觀察法人近 5 日是否延續同方向，若法人轉賣且融資上升，籌碼風險會提高。';
        $directions[] = '觀察最新月營收與下一季財報是否能支撐目前評價，避免股價只靠題材推動。';

        return implode("\n", array_map(fn (string $line) => '・'.$line, $directions));
    }

    private function renderDirections(array $pack, string $type): string
    {
        return $type === 'positive'
            ? '可觀察價格是否站穩短中期均線、量能是否延續、法人是否維持買盤，以及營收能否支撐目前評價。'
            : '需留意跌破支撐、量增價跌、法人轉賣、融資升高，以及題材熱度退潮後評價被重新檢驗。';
    }

    private function renderRiskSummary(array $pack): string
    {
        return '主要風險在於支撐區失守、量能與價格背離、法人買盤不連續、融資籌碼升高，以及營收或財報無法支撐目前評價。';
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

    private function priceTrend(Collection $prices, ?object $technical): array
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

    private function financial(?object $latestFinancial, Collection $financials): array
    {
        return [
            'period' => $latestFinancial?->period ?? '資料不足',
            'eps' => $this->float($latestFinancial?->eps),
            'roe' => $this->float($latestFinancial?->roe),
            'gross_margin' => $this->float($latestFinancial?->gross_margin),
            'operating_margin' => $this->float($latestFinancial?->operating_margin),
            'per' => $this->float($latestFinancial?->per),
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
