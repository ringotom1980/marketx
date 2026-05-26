<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GlobalRadarBuilder
{
    private const GROUPS = [
        'us' => [
            'title' => '美國市場',
            'lead' => '觀察美股風險偏好、科技股強弱與半導體供應鏈溫度。',
            'indicators' => ['Dow Jones', 'S&P 500', 'NASDAQ', 'Russell 2000', 'SOX', 'VIX'],
        ],
        'asia' => [
            'title' => '亞洲主要市場',
            'lead' => '觀察日股、港股、韓股與陸股是否和台股形成同向或背離。',
            'indicators' => ['Nikkei 225', 'Hang Seng', 'Hang Seng China Enterprises', 'KOSPI', 'KOSDAQ', 'Shanghai Composite'],
        ],
        'macro' => [
            'title' => '匯率、利率與商品',
            'lead' => '觀察美元、美債、油價與黃金，判斷資金壓力與避險情緒。',
            'indicators' => ['DXY', 'US10Y', 'Crude Oil', 'Gold'],
        ],
        'taiwan' => [
            'title' => '台股關聯指標',
            'lead' => '觀察台積電 ADR 與台指夜盤，補足台股開盤前的外部訊號。',
            'indicators' => ['TSM ADR', 'TAIFEX TX Night'],
        ],
    ];

    public function build(): array
    {
        $markets = $this->latestMarkets();
        $events = DB::table('global_event_clusters')
            ->orderByDesc('cluster_date')
            ->orderByDesc('importance_score')
            ->limit(5)
            ->get();

        $score = $this->windScore($markets, $events);

        return [
            'asOf' => $this->latestUpdatedAt($markets),
            'wind' => [
                'title' => $this->windTitle($score),
                'score' => $score,
                'tone' => $score >= 65 ? 'red' : ($score >= 45 ? 'amber' : 'green'),
                'support' => $this->supportText($markets),
                'pressure' => $this->pressureText($markets, $events),
            ],
            'groups' => $this->groups($markets),
            'events' => $events->map(fn ($event) => [
                'title' => EventClusterDisplay::title($event),
                'body' => EventClusterDisplay::body($event),
            ])->all(),
            'watchpoints' => $this->watchpoints($markets, $events),
        ];
    }

    private function latestMarkets(): Collection
    {
        return DB::table('global_market_data as g')
            ->joinSub(
                DB::table('global_market_data')
                    ->selectRaw('indicator, max(trade_date) as latest_date')
                    ->groupBy('indicator'),
                'latest',
                function ($join) {
                    $join->on('g.indicator', '=', 'latest.indicator')
                        ->on('g.trade_date', '=', 'latest.latest_date');
                }
            )
            ->select('g.*')
            ->get()
            ->keyBy('indicator');
    }

    private function latestUpdatedAt(Collection $markets): ?string
    {
        return $markets
            ->pluck('updated_at')
            ->filter()
            ->sort()
            ->last();
    }

    private function groups(Collection $markets): array
    {
        $groups = [];

        foreach (self::GROUPS as $key => $group) {
            $cards = collect($group['indicators'])
                ->map(fn (string $indicator) => $this->card($indicator, $markets->get($indicator)))
                ->filter()
                ->values()
                ->all();

            $groups[] = [
                'key' => $key,
                'title' => $group['title'],
                'lead' => $group['lead'],
                'cards' => $cards,
                'summary' => $this->groupSummary($key, collect($cards)),
            ];
        }

        return $groups;
    }

    private function card(string $indicator, ?object $row): ?array
    {
        if (! $row) {
            return null;
        }

        $change = $row->change_pct === null ? null : (float) $row->change_pct;
        $changeValue = $row->change === null ? null : (float) $row->change;

        return [
            'indicator' => $indicator,
            'name' => $this->displayName($indicator),
            'region' => $this->regionName($indicator),
            'value' => $this->formatValue($indicator, (float) $row->value),
            'change' => $changeValue === null ? '-' : number_format($changeValue, 2),
            'changePct' => $change === null ? '-' : number_format($change, 2).'%',
            'changeRaw' => $change,
            'tone' => $this->tone($indicator, $row->state, $change),
            'state' => $this->stateName($indicator, $row->state, $change),
            'tradeDate' => (string) $row->trade_date,
            'read' => $this->read($indicator, $row->state, $change),
            'source' => $row->source,
        ];
    }

    private function windScore(Collection $markets, Collection $events): int
    {
        $score = 50;

        foreach ($markets as $indicator => $row) {
            $state = $row->state;

            if (in_array($indicator, ['Dow Jones', 'S&P 500', 'NASDAQ', 'Russell 2000', 'SOX', 'Nikkei 225', 'Hang Seng', 'Hang Seng China Enterprises', 'KOSPI', 'KOSDAQ', 'Shanghai Composite', 'TSM ADR', 'TAIFEX TX Night'], true)) {
                $score += in_array($state, ['strong', 'positive'], true) ? 3 : (in_array($state, ['weak', 'soft'], true) ? -3 : 0);
            }

            if ($indicator === 'SOX') {
                $score += in_array($state, ['strong', 'positive'], true) ? 2 : (in_array($state, ['weak', 'soft'], true) ? -2 : 0);
            }

            if ($indicator === 'VIX') {
                $score += $state === 'low_risk' ? 6 : ($state === 'high_risk' ? -10 : 0);
            }

            if (in_array($indicator, ['DXY', 'US10Y'], true)) {
                $score += $state === 'pressure_down' ? 4 : ($state === 'pressure_up' ? -6 : 0);
            }

            if ($indicator === 'Crude Oil') {
                $score += $state === 'pressure_up' || (float) $row->change_pct > 1 ? -4 : 0;
            }
        }

        foreach ($events as $event) {
            $score += match ($event->sentiment) {
                'positive' => 2,
                'negative' => -2,
                default => 0,
            };
        }

        return max(0, min(100, $score));
    }

    private function groupSummary(string $key, Collection $cards): string
    {
        $up = $cards->whereIn('tone', ['red'])->count();
        $down = $cards->whereIn('tone', ['green'])->count();
        $mixed = $cards->count() - $up - $down;

        return match ($key) {
            'us' => $up > $down
                ? '美股主要指數偏強，代表外部風險偏好較佳。'
                : ($down > $up ? '美股主要指數偏弱，台股開盤前需先看風險收斂。' : '美股訊號分歧，需觀察科技股與半導體是否同向。'),
            'asia' => $up > $down
                ? '亞洲市場多數走強，有利台股觀察區域資金回流。'
                : ($down > $up ? '亞洲市場偏弱，需留意區域股市同步修正。' : '亞洲市場漲跌互見，台股較可能回到自身題材與籌碼表現。'),
            'macro' => $down > $up
                ? '匯率、利率或商品端壓力較高，需留意估值與成本壓力。'
                : ($up > $down ? '宏觀壓力相對降溫，市場較容易回到成長與題材定價。' : '宏觀訊號中性，短線仍看股市本身量價。'),
            'taiwan' => $up > $down
                ? '台股關聯指標偏正向，對隔日台股情緒有支撐。'
                : ($down > $up ? '台股關聯指標偏弱，隔日開盤需防守觀察。' : '台股關聯指標尚未明確表態。'),
            default => $mixed >= 0 ? '目前訊號混合。' : '',
        };
    }

    private function supportText(Collection $markets): string
    {
        $supports = [];

        foreach (['NASDAQ' => '科技股', 'SOX' => '半導體', 'Nikkei 225' => '日股', 'KOSPI' => '韓股', 'TSM ADR' => '台積電 ADR', 'TAIFEX TX Night' => '台指夜盤'] as $key => $label) {
            if (in_array($markets->get($key)?->state, ['strong', 'positive'], true)) {
                $supports[] = $label;
            }
        }

        if ($markets->get('VIX')?->state === 'low_risk') {
            $supports[] = 'VIX 低檔';
        }

        return $supports === [] ? '暫無明顯支撐訊號' : implode('、', array_unique($supports));
    }

    private function pressureText(Collection $markets, Collection $events): string
    {
        $pressures = [];

        foreach (['DXY' => '美元轉強', 'US10Y' => '美債殖利率上升', 'Crude Oil' => '油價壓力', 'Hang Seng' => '港股偏弱', 'KOSDAQ' => '韓國成長股偏弱'] as $key => $label) {
            $row = $markets->get($key);
            if ($row && in_array($row->state, ['weak', 'soft', 'pressure_up'], true)) {
                $pressures[] = $label;
            }
        }

        if ($markets->get('VIX')?->state === 'high_risk') {
            $pressures[] = 'VIX 升高';
        }

        if ($events->contains(fn ($event) => $event->sentiment === 'negative')) {
            $pressures[] = '負面事件增加';
        }

        return $pressures === [] ? '暫無明顯壓力訊號' : implode('、', array_unique($pressures));
    }

    private function watchpoints(Collection $markets, Collection $events): array
    {
        $points = [];

        if ($markets->get('SOX')) {
            $points[] = '費半與 NASDAQ 是否同向，是 AI、半導體與電子權值股的重要外部訊號。';
        }

        if ($markets->get('TAIFEX TX Night')) {
            $points[] = '台指夜盤可作為隔日台股開盤情緒參考，但仍要搭配現貨量價確認。';
        }

        if ($markets->get('DXY') || $markets->get('US10Y')) {
            $points[] = '美元與美債若同步走強，通常會提高成長股與高估值族群壓力。';
        }

        if ($markets->get('Hang Seng') || $markets->get('KOSPI')) {
            $points[] = '港股、韓股若與台股背離，要注意區域資金是否集中在少數題材。';
        }

        if ($events->isNotEmpty()) {
            $points[] = '全球事件仍以新聞聚合為輔，實際交易判斷要回到指數、期貨與台股籌碼。';
        }

        return array_slice($points, 0, 5);
    }

    private function windTitle(int $score): string
    {
        return match (true) {
            $score >= 70 => '全球順風',
            $score >= 55 => '中性偏多',
            $score >= 40 => '中性觀察',
            default => '風險升高',
        };
    }

    private function displayName(string $indicator): string
    {
        return [
            'Dow Jones' => '道瓊工業',
            'S&P 500' => '標普 500',
            'NASDAQ' => 'NASDAQ',
            'Russell 2000' => '羅素 2000',
            'SOX' => '費城半導體',
            'VIX' => 'VIX 恐慌指數',
            'Nikkei 225' => '日經 225',
            'Hang Seng' => '恆生指數',
            'Hang Seng China Enterprises' => '恆生國企',
            'KOSPI' => '韓國 KOSPI',
            'KOSDAQ' => '韓國 KOSDAQ',
            'Shanghai Composite' => '上海綜合',
            'DXY' => '美元指數',
            'US10Y' => '美國 10 年債',
            'Crude Oil' => '原油',
            'Gold' => '黃金',
            'TSM ADR' => '台積電 ADR',
            'TAIFEX TX Night' => '台指夜盤',
        ][$indicator] ?? $indicator;
    }

    private function regionName(string $indicator): string
    {
        return match ($indicator) {
            'Dow Jones', 'S&P 500', 'NASDAQ', 'Russell 2000', 'SOX', 'VIX' => '美國',
            'Nikkei 225' => '日本',
            'Hang Seng', 'Hang Seng China Enterprises' => '香港',
            'KOSPI', 'KOSDAQ' => '韓國',
            'Shanghai Composite' => '中國',
            'DXY', 'US10Y', 'Crude Oil', 'Gold' => '宏觀',
            default => '台股關聯',
        };
    }

    private function stateName(string $indicator, ?string $state, ?float $change): string
    {
        if ($indicator === 'VIX') {
            return match ($state) {
                'low_risk' => '低風險',
                'high_risk' => '風險升高',
                default => '中性',
            };
        }

        if (in_array($indicator, ['DXY', 'US10Y'], true)) {
            return $state === 'pressure_down' ? '壓力下降' : ($state === 'pressure_up' ? '壓力上升' : '中性');
        }

        if ($change === null) {
            return '待觀察';
        }

        return match (true) {
            $change >= 1 => '強勢',
            $change > 0 => '偏強',
            $change > -1 => '偏弱',
            default => '弱勢',
        };
    }

    private function tone(string $indicator, ?string $state, ?float $change): string
    {
        if ($indicator === 'VIX') {
            return $state === 'high_risk' ? 'green' : ($state === 'low_risk' ? 'red' : 'amber');
        }

        if (in_array($indicator, ['DXY', 'US10Y', 'Crude Oil'], true)) {
            return in_array($state, ['pressure_up', 'strong'], true) ? 'green' : (in_array($state, ['pressure_down', 'weak', 'soft'], true) ? 'red' : 'amber');
        }

        if ($change === null) {
            return 'amber';
        }

        return $change >= 0 ? 'red' : 'green';
    }

    private function read(string $indicator, ?string $state, ?float $change): string
    {
        $direction = $change === null ? '尚未取得漲跌幅' : ($change >= 0 ? '上漲' : '下跌');

        return match ($indicator) {
            'Dow Jones' => '大型藍籌股'.$direction.'，可觀察市場對景氣與企業獲利的整體態度。',
            'S&P 500' => '美股大盤'.$direction.'，代表全球資金風險偏好的基準訊號。',
            'NASDAQ' => '科技股'.$direction.'，會直接影響 AI、半導體與高估值族群情緒。',
            'Russell 2000' => '中小型股'.$direction.'，可用來觀察資金是否擴散到非大型權值股。',
            'SOX' => '半導體指數'.$direction.'，對台股電子權值、IC 設計與 AI 供應鏈影響最大。',
            'VIX' => $state === 'high_risk' ? 'VIX 升高代表避險情緒增加，短線需降低追價衝動。' : 'VIX 偏低代表市場風險情緒穩定，但也要留意過度樂觀。',
            'Nikkei 225' => '日股'.$direction.'，可觀察亞洲資金是否仍偏好大型出口與科技權值股。',
            'Hang Seng' => '港股'.$direction.'，反映中港資金情緒與中國政策預期。',
            'Hang Seng China Enterprises' => '國企股'.$direction.'，可觀察中國大型企業與金融地產鏈壓力。',
            'KOSPI' => '韓股'.$direction.'，與半導體、記憶體、電子出口循環高度相關。',
            'KOSDAQ' => '韓國成長股'.$direction.'，可作為亞洲成長股風險偏好的輔助訊號。',
            'Shanghai Composite' => '陸股'.$direction.'，主要觀察中國政策、內需與資金信心。',
            'DXY' => $state === 'pressure_up' ? '美元走強通常代表資金較保守，對新興市場與科技估值較不利。' : '美元壓力下降時，外資風險偏好通常較容易回升。',
            'US10Y' => $state === 'pressure_up' ? '美債殖利率上升會提高估值壓力，成長股需特別留意。' : '美債殖利率回落時，對科技與高估值族群較友善。',
            'Crude Oil' => $change !== null && $change > 0 ? '油價上漲可能推升通膨與成本壓力，航運、塑化與能源鏈需分開判讀。' : '油價走弱通常讓通膨壓力降溫，但也可能反映需求放緩。',
            'Gold' => $change !== null && $change > 0 ? '黃金上漲代表避險或降息預期升溫，需搭配美元與美債一起看。' : '黃金回落時，通常代表避險需求下降或美元壓力轉強。',
            'TSM ADR' => '台積電 ADR '.$direction.'，可作為隔日台股權值股與半導體情緒參考。',
            'TAIFEX TX Night' => '台指夜盤'.$direction.'，反映台股收盤後到隔日開盤前的期貨情緒。',
            default => '此指標作為全球市場輔助觀察。',
        };
    }

    private function formatValue(string $indicator, float $value): string
    {
        if ($indicator === 'US10Y') {
            return number_format($value, 3).'%';
        }

        if (in_array($indicator, ['Crude Oil', 'Gold', 'TSM ADR'], true)) {
            return number_format($value, 2);
        }

        return number_format($value, 2);
    }
}
