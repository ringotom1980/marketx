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

            if (in_array($indicator, ['S&P 500', 'NASDAQ', 'SOX', 'TSM ADR'], true)) {
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
            ->map(fn ($row) => [
                'name' => MarketDisplay::indicatorName($row->indicator),
                'state' => MarketDisplay::stateName($row->state),
                'tone' => MarketDisplay::tone($row->state, $row->change_pct === null ? null : (float) $row->change_pct),
                'value' => number_format((float) $row->value, 2),
                'change' => $row->change_pct === null ? '無資料' : number_format((float) $row->change_pct, 2).'%',
                'note' => $this->indicatorNote($row->indicator, $row->state),
            ])
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
            default => '觀察全球資金風向',
        };
    }

    private function impactChains(Collection $markets, Collection $events): array
    {
        $chains = [];

        if (in_array($markets->get('SOX')?->state, ['strong', 'positive'], true)) {
            $chains[] = '費半偏強 → 半導體與 AI 供應鏈有支撐 → 台股電子權值股較有表現空間';
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
