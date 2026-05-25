<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GlobalRadarBuilder
{
    public function build(): array
    {
        $latestDate = DB::table('global_market_data')->max('trade_date');
        $markets = $latestDate
            ? DB::table('global_market_data')->where('trade_date', $latestDate)->get()->keyBy('indicator')
            : collect();
        $events = DB::table('global_event_clusters')
            ->orderByDesc('cluster_date')
            ->orderByDesc('importance_score')
            ->limit(5)
            ->get();

        $score = $this->windScore($markets, $events);

        return [
            'wind' => [
                'title' => $this->windTitle($score),
                'score' => $score,
                'tone' => $score >= 65 ? 'red' : ($score >= 45 ? 'amber' : 'green'),
                'support' => $this->supportText($markets, $events),
                'pressure' => $this->pressureText($markets, $events),
            ],
            'indicators' => $this->indicatorCards($markets),
            'events' => $events->map(fn ($event) => [
                'title' => EventClusterDisplay::title($event),
                'body' => EventClusterDisplay::body($event),
            ])->all(),
            'chains' => $this->impactChains($markets, $events),
            'watchpoints' => $this->watchpoints($markets, $events),
        ];
    }

    private function windScore(Collection $markets, Collection $events): int
    {
        $score = 50;

        foreach ($markets as $indicator => $row) {
            $state = $row->state;

            if (in_array($indicator, ['S&P 500', 'NASDAQ', 'SOX', 'TSM ADR', 'TAIFEX TX Night'], true)) {
                $score += in_array($state, ['strong', 'positive'], true) ? 5 : (in_array($state, ['weak', 'soft'], true) ? -5 : 0);
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
            if ($event->sentiment === 'negative') {
                $score -= 3;
            }

            if ($event->sentiment === 'positive') {
                $score += 3;
            }
        }

        return max(0, min(100, $score));
    }

    private function windTitle(int $score): string
    {
        return match (true) {
            $score >= 70 => '全球環境偏順風',
            $score >= 55 => '全球環境中性偏多',
            $score >= 40 => '全球環境中性觀察',
            default => '全球風險升高',
        };
    }

    private function supportText(Collection $markets, Collection $events): string
    {
        $supports = [];

        foreach (['SOX' => '費半', 'NASDAQ' => '那斯達克', 'TSM ADR' => '台積電 ADR'] as $key => $label) {
            if (in_array($markets->get($key)?->state, ['strong', 'positive'], true)) {
                $supports[] = $label;
            }
        }

        if ($events->contains(fn ($event) => str_contains((string) $event->title, 'AI') || str_contains((string) $event->summary, 'AI'))) {
            $supports[] = 'AI 題材';
        }

        return $supports === [] ? '暫無明顯支撐' : implode('、', array_unique($supports));
    }

    private function pressureText(Collection $markets, Collection $events): string
    {
        $pressures = [];

        if (in_array($markets->get('DXY')?->state, ['pressure_up'], true)) {
            $pressures[] = '美元';
        }

        if (in_array($markets->get('US10Y')?->state, ['pressure_up'], true)) {
            $pressures[] = '美債殖利率';
        }

        if (($markets->get('VIX')?->state) === 'high_risk') {
            $pressures[] = 'VIX';
        }

        if ($events->contains(fn ($event) => $event->sentiment === 'negative')) {
            $pressures[] = '地緣或能源風險';
        }

        return $pressures === [] ? '暫無明顯壓力' : implode('、', array_unique($pressures));
    }

    private function indicatorCards(Collection $markets): array
    {
        return $markets
            ->map(function ($row) {
                $change = $row->change_pct === null ? null : (float) $row->change_pct;

                return [
                    'name' => MarketDisplay::indicatorName($row->indicator),
                    'state' => MarketDisplay::stateName($row->state),
                    'tone' => MarketDisplay::tone($row->state, $change),
                    'value' => number_format((float) $row->value, 2),
                    'change' => $change === null ? '無資料' : number_format($change, 2).'%',
                    'note' => $this->indicatorNote($row->indicator, $row->state),
                    'read' => $this->indicatorRead($row->indicator, $row->state, $change),
                    'impact' => $this->indicatorImpact($row->indicator, $row->state, $change),
                    'watch' => $this->indicatorWatch($row->indicator, $row->state, $change),
                ];
            })
            ->values()
            ->all();
    }

    private function indicatorNote(string $indicator, ?string $state): string
    {
        return match ($indicator) {
            'SOX' => in_array($state, ['strong', 'positive'], true) ? '支撐半導體與 AI 供應鏈' : '半導體族群需觀察',
            'VIX' => $state === 'low_risk' ? '市場情緒相對穩定' : '波動風險需留意',
            'DXY' => $state === 'pressure_up' ? '美元偏強，外資風險偏好受壓' : '美元壓力下降',
            'US10Y' => $state === 'pressure_up' ? '殖利率上升，高本益比股較敏感' : '利率壓力下降',
            'Crude Oil' => '油價影響通膨、能源與航運成本',
            'Gold' => '黃金反映避險需求與利率預期',
            'TSM ADR' => '觀察台積電與台股權值股風向',
            'TAIFEX TX Night' => '觀察台股盤後接軌美股後的隔日開盤風向',
            default => '觀察全球資金風向',
        };
    }

    private function indicatorRead(string $indicator, ?string $state, ?float $change): string
    {
        return match ($indicator) {
            'S&P 500' => $this->isPositive($state, $change)
                ? '美股大型股維持偏強，代表國際資金目前願意承擔風險。'
                : ($this->isNegative($state, $change) ? '美股大型股轉弱，市場資金偏保守。' : '美股大型股方向不明，市場還在等待新的催化因素。'),
            'NASDAQ' => $this->isPositive($state, $change)
                ? '科技股買盤仍在，成長股與高本益比族群的氣氛較有支撐。'
                : ($this->isNegative($state, $change) ? '科技股承壓，市場對成長股評價變得挑剔。' : '科技股沒有明顯方向，短線先看大型科技股能否重新帶量。'),
            'SOX' => $this->isPositive($state, $change)
                ? '費半偏強，半導體與 AI 硬體供應鏈仍是全球資金焦點。'
                : ($this->isNegative($state, $change) ? '費半轉弱，半導體族群短線容易出現獲利了結。' : '費半震盪整理，半導體族群需要新的基本面或財報支撐。'),
            'VIX' => $state === 'low_risk'
                ? 'VIX 偏低，市場目前沒有明顯恐慌，風險胃納較好。'
                : ($state === 'high_risk' ? 'VIX 升高，代表避險需求增加，盤面容易出現急漲急跌。' : 'VIX 處於中性區間，市場情緒沒有極端訊號。'),
            'DXY' => $state === 'pressure_up'
                ? '美元偏強，資金容易回流美元資產，新興市場承受壓力。'
                : ($state === 'pressure_down' ? '美元壓力下降，外資風險偏好通常會比較友善。' : '美元沒有明顯方向，外資流向仍需搭配美債與股市一起看。'),
            'US10Y' => $state === 'pressure_up'
                ? '美債殖利率上升，市場折現率提高，高估值股票較敏感。'
                : ($state === 'pressure_down' ? '美債殖利率回落，成長股和科技股的估值壓力較低。' : '美債殖利率變化不大，利率因素暫時不是主要壓力。'),
            'Crude Oil' => $this->isPositive($state, $change)
                ? '油價上升，市場會重新評估通膨、運輸與能源成本。'
                : ($this->isNegative($state, $change) ? '油價回落，通膨與成本壓力短線稍微降溫。' : '油價變化有限，暫時不是市場主軸，但仍會牽動通膨預期。'),
            'Gold' => $this->isPositive($state, $change)
                ? '黃金走強，通常代表避險需求或降息預期升溫。'
                : ($this->isNegative($state, $change) ? '黃金轉弱，避險需求降溫或美元利率壓力回升。' : '黃金盤整，市場避險情緒沒有明顯升溫。'),
            'TSM ADR' => $this->isPositive($state, $change)
                ? '台積電 ADR 偏強，有利隔日台股權值股與半導體情緒。'
                : ($this->isNegative($state, $change) ? '台積電 ADR 轉弱，隔日台股電子權值股容易承壓。' : '台積電 ADR 變化不大，台股仍需看本地量能與外資動向。'),
            'TAIFEX TX Night' => $this->isPositive($state, $change)
                ? '臺股期貨夜盤偏強，代表日盤收盤後的國際變化被市場偏正向反映。'
                : ($this->isNegative($state, $change) ? '臺股期貨夜盤轉弱，代表收盤後風險偏好下降，隔日開盤要更保守。' : '臺股期貨夜盤變化不大，隔日台股仍以現貨量能與外資動向為主。'),
            default => '目前作為全球資金風向參考，需搭配其他指標一起判斷。',
        };
    }

    private function indicatorImpact(string $indicator, ?string $state, ?float $change): string
    {
        return match ($indicator) {
            'S&P 500' => $this->isPositive($state, $change)
                ? '對台股是偏正向背景，尤其有助權值股與大型電子股維持人氣。'
                : '若持續轉弱，台股開盤情緒與外資買盤可能變保守。',
            'NASDAQ' => $this->isPositive($state, $change)
                ? 'AI、雲端、半導體、伺服器與高本益比電子股較容易受惠。'
                : '科技股壓力會傳到台股電子族群，短線分數要更重視技術面支撐。',
            'SOX' => $this->isPositive($state, $change)
                ? '半導體、IC 設計、設備、先進封裝與 AI 供應鏈會是優先觀察區。'
                : '半導體若失去支撐，台股高分股名單容易出現降溫或輪動。',
            'VIX' => $state === 'low_risk'
                ? '低波動環境有利題材股延續，但也要防止追高過熱。'
                : '波動升高時，系統會更重視風險旗標與減碼訊號。',
            'DXY' => $state === 'pressure_up'
                ? '美元強會壓抑外資風險偏好，台股容易偏向個股表現。'
                : '美元轉弱通常有利外資回補亞洲科技股。',
            'US10Y' => $state === 'pressure_up'
                ? '殖利率上升會壓縮評價，AI 與高本益比股要看營收能否撐住估值。'
                : '利率壓力下降時，成長股與電子權值股比較容易被重新評價。',
            'Crude Oil' => $this->isPositive($state, $change)
                ? '油價上升會影響航運、塑化、航空與高耗能產業成本。'
                : '油價回落有助成本壓力下降，對通膨與運輸成本較友善。',
            'Gold' => $this->isPositive($state, $change)
                ? '黃金偏強時，需留意市場是否正在提高避險部位。'
                : '黃金偏弱時，市場可能把資金轉回股票或美元資產。',
            'TSM ADR' => $this->isPositive($state, $change)
                ? '台積電 ADR 是台股隔日電子權值股的重要先行參考。'
                : '若 ADR 轉弱，即使題材熱，台股也可能先震盪消化。',
            'TAIFEX TX Night' => $this->isPositive($state, $change)
                ? '夜盤轉強通常有利隔日開盤情緒，尤其對指數權值股較有參考性。'
                : '夜盤轉弱時，隔日高分股也要留意開盤補跌或追價風險。',
            default => '對台股影響需與美元、美債、費半和事件熱度一起判讀。',
        };
    }

    private function indicatorWatch(string $indicator, ?string $state, ?float $change): string
    {
        return match ($indicator) {
            'S&P 500' => '觀察是否連續守在短期均線上方，以及漲勢是否由少數權值股擴散。',
            'NASDAQ' => '觀察大型科技股財報、AI 資本支出與雲端服務需求是否延續。',
            'SOX' => '觀察 NVIDIA、台積電 ADR、記憶體與設備股是否同步強勢。',
            'VIX' => '觀察是否突然跳升；若快速升高，通常代表市場風險偏好變差。',
            'DXY' => '觀察美元是否突破近期高點；美元越強，外資越可能保守。',
            'US10Y' => '觀察 10 年債殖利率是否持續走升，這會影響科技股評價。',
            'Crude Oil' => '觀察是否由供給中斷或地緣政治推升，這種油價上漲比較容易造成壓力。',
            'Gold' => '觀察黃金與美元是否同漲；若同漲，通常代表避險需求更明顯。',
            'TSM ADR' => '觀察 ADR 與台股現貨是否同步；若不同步，隔日容易修正價差。',
            'TAIFEX TX Night' => '觀察夜盤收盤與日盤開盤是否同向；若夜盤大漲大跌，隔日開盤波動通常會放大。',
            default => '觀察是否和其他全球指標形成同方向訊號。',
        };
    }

    private function isPositive(?string $state, ?float $change): bool
    {
        return in_array($state, ['strong', 'positive', 'pressure_up'], true)
            || ($change !== null && $change > 0.3);
    }

    private function isNegative(?string $state, ?float $change): bool
    {
        return in_array($state, ['weak', 'soft', 'high_risk'], true)
            || ($change !== null && $change < -0.3);
    }

    private function impactChains(Collection $markets, Collection $events): array
    {
        $chains = [];

        if (in_array($markets->get('SOX')?->state, ['strong', 'positive'], true)) {
            $chains[] = '費半偏強 → 半導體與 AI 供應鏈有支撐 → 台股電子權值股較有表現空間';
        }

        if (in_array($markets->get('TAIFEX TX Night')?->state, ['positive'], true)) {
            $chains[] = '臺股期貨夜盤偏強 → 國際盤後變化先反映 → 隔日台股開盤情緒較有支撐';
        }

        if (in_array($markets->get('TAIFEX TX Night')?->state, ['weak'], true)) {
            $chains[] = '臺股期貨夜盤轉弱 → 隔日開盤風險升高 → 高檔股需留意開盤震盪';
        }

        if ($events->contains(fn ($event) => str_contains((string) $event->title, '能源') || str_contains((string) $event->summary, '油'))) {
            $chains[] = '油價與能源事件升溫 → 通膨與成本壓力受關注 → 航運、能源與高耗能產業波動增加';
        }

        if ($events->contains(fn ($event) => str_contains((string) $event->title, '戰爭') || str_contains((string) $event->summary, '地緣'))) {
            $chains[] = '地緣風險升高 → 避險需求與運價波動上升 → 台股短線風險偏好可能下降';
        }

        if (in_array($markets->get('US10Y')?->state, ['pressure_up'], true) || in_array($markets->get('DXY')?->state, ['pressure_up'], true)) {
            $chains[] = '美元或美債殖利率上升 → 外資資金較保守 → 高本益比與權值股需留意震盪';
        }

        return $chains === [] ? ['目前全球訊號分歧，先觀察美股、費半、美元與油價是否出現同向變化。'] : $chains;
    }

    private function watchpoints(Collection $markets, Collection $events): array
    {
        return array_slice([
            in_array($markets->get('SOX')?->state, ['strong', 'positive'], true)
                ? '費半能否延續強勢，會影響半導體與 AI 供應鏈。'
                : '費半若轉弱，台股電子族群容易跟著降溫。',
            $events->contains(fn ($event) => str_contains((string) $event->summary, '油價') || str_contains((string) $event->summary, '能源'))
                ? '油價與能源供應若續升，通膨與成本壓力會升高。'
                : '能源事件目前不是主軸，但仍需觀察油價變化。',
            in_array($markets->get('US10Y')?->state, ['pressure_up'], true)
                ? '美債殖利率若續升，高本益比股票容易震盪。'
                : '美債殖利率若維持穩定，有助市場風險偏好。',
        ], 0, 3);
    }
}
