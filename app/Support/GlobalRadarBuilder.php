<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GlobalRadarBuilder
{
    private const GROUPS = [
        [
            'title' => '美國市場',
            'indicators' => ['Dow Jones', 'S&P 500', 'NASDAQ', 'Russell 2000', 'SOX', 'VIX', 'TSM ADR', 'UMC ADR'],
        ],
        [
            'title' => '亞洲主要市場',
            'indicators' => ['Nikkei 225', 'Hang Seng', 'Hang Seng China Enterprises', 'KOSPI', 'KOSDAQ', 'Shanghai Composite'],
        ],
        [
            'title' => '匯率、利率與商品',
            'indicators' => ['DXY', 'US10Y', 'Crude Oil', 'Gold'],
        ],
    ];

    public function build(): array
    {
        $markets = $this->latestMarkets();

        return [
            'asOf' => $this->latestUpdatedAt($markets),
            'groups' => collect(self::GROUPS)->map(function (array $group) use ($markets) {
                return [
                    'title' => $group['title'],
                    'cards' => collect($group['indicators'])
                        ->map(fn (string $indicator) => $this->card($indicator, $markets->get($indicator)))
                        ->filter()
                        ->values()
                        ->all(),
                ];
            })->all(),
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

    private function card(string $indicator, ?object $row): ?array
    {
        if (! $row) {
            return null;
        }

        $change = $row->change_pct === null ? null : (float) $row->change_pct;

        return [
            'indicator' => $indicator,
            'name' => $this->displayName($indicator),
            'region' => $this->regionName($indicator),
            'value' => $this->formatValue($indicator, (float) $row->value),
            'changePct' => $this->formatChangePct($change),
            'changeRaw' => $change,
            'tone' => $this->tone($indicator, $row->state, $change),
            'state' => $this->stateName($indicator, $row->state, $change),
            'tradeDate' => (string) $row->trade_date,
        ];
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
            'TSM ADR' => '台積電 ADR',
            'UMC ADR' => '聯電 ADR',
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
        ][$indicator] ?? $indicator;
    }

    private function regionName(string $indicator): string
    {
        return match ($indicator) {
            'Dow Jones', 'S&P 500', 'NASDAQ', 'Russell 2000', 'SOX', 'VIX', 'TSM ADR', 'UMC ADR' => '美國',
            'Nikkei 225' => '日本',
            'Hang Seng', 'Hang Seng China Enterprises' => '香港',
            'KOSPI', 'KOSDAQ' => '韓國',
            'Shanghai Composite' => '中國',
            default => '宏觀',
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

        if ($change === null || $change == 0.0) {
            return 'amber';
        }

        return $change > 0 ? 'red' : 'green';
    }

    private function formatChangePct(?float $change): string
    {
        if ($change === null) {
            return '-';
        }

        $symbol = $change > 0 ? '▲' : ($change < 0 ? '▼' : '•');

        return $symbol.' '.number_format(abs($change), 2).'%';
    }

    private function formatValue(string $indicator, float $value): string
    {
        if ($indicator === 'US10Y') {
            return number_format($value, 3).'%';
        }

        return number_format($value, 2);
    }
}
