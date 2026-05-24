<?php

namespace App\Support\Ai;

use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class StockDataPackBuilder
{
    public function build(Stock $stock): array
    {
        $score = $stock->latestScore;
        $price = $stock->dailyPrices()->latest('trade_date')->first();
        $chip = $stock->latestChip;
        $financial = DB::table('stock_financials')->where('stock_id', $stock->id)->orderByDesc('period')->first();
        $revenue = DB::table('stock_revenues')->where('stock_id', $stock->id)->orderByDesc('year_month')->first();
        $themes = DB::table('stock_theme_map')
            ->join('themes', 'themes.id', '=', 'stock_theme_map.theme_id')
            ->leftJoin('theme_scores', function ($join) {
                $join->on('themes.id', '=', 'theme_scores.theme_id')
                    ->whereRaw('theme_scores.score_date = (select max(ts.score_date) from theme_scores ts where ts.theme_id = themes.id)');
            })
            ->where('stock_theme_map.stock_id', $stock->id)
            ->orderByDesc('theme_scores.heat_score')
            ->limit(5)
            ->get(['themes.name', 'theme_scores.heat_score'])
            ->map(fn ($theme) => [
                'name' => $theme->name,
                'heat_score' => $theme->heat_score,
            ])
            ->values()
            ->all();

        return [
            'symbol' => $stock->symbol,
            'name' => $stock->name,
            'market' => $stock->market,
            'asof_date' => CarbonImmutable::now('Asia/Taipei')->toDateString(),
            'base_scores' => [
                'macro_score' => $score?->macro_score,
                'event_score' => $score?->event_score,
                'topic_score' => $score?->theme_score,
                'technical_score' => $score?->technical_score,
                'chip_score' => $score?->chip_score,
                'fundamental_score' => $score?->fundamental_score,
                'total_score' => $score?->total_score,
                'confidence_score' => $score?->confidence_score,
                'decision' => $score?->decision,
            ],
            'technical_module' => [
                'latest_close' => $price?->close,
                'latest_volume' => $price?->volume,
                'signals' => $score?->technical_payload['signals'] ?? [],
            ],
            'chip_module' => [
                'foreign_net_buy' => $chip?->foreign_net_buy,
                'investment_trust_net_buy' => $chip?->investment_trust_net_buy,
                'dealer_net_buy' => $chip?->dealer_net_buy,
                'institutional_net_buy' => $chip?->institutional_net_buy,
                'margin_balance' => $chip?->margin_balance,
                'short_balance' => $chip?->short_balance,
            ],
            'topic_module' => [
                'main_topics' => $themes,
            ],
            'fundamental_module' => [
                'period' => $financial?->period,
                'eps' => $financial?->eps,
                'roe' => $financial?->roe,
                'gross_margin' => $financial?->gross_margin,
                'per' => $financial?->per,
                'revenue_month' => $revenue?->year_month,
                'revenue_yoy_pct' => $revenue?->yoy_pct,
            ],
            'risk_module' => [
                'risk_flags' => $score?->risk_flags ?? [],
            ],
        ];
    }
}
