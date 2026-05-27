<?php

namespace App\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('welcome', function ($view) {
            $layoutState = Cache::remember('marketx.layout_state', 30, function () {
                try {
                    $latestTaiwanJob = DB::table('system_jobs')
                        ->where('status', 'success')
                        ->whereNotNull('finished_at')
                        ->whereIn('job_name', [
                            'taiwan_stocks',
                            'taiwan_prices_fast',
                            'technical_scores_fast',
                            'taiwan_prices_aftermarket',
                            'taiwan_chips_aftermarket',
                            'official_chip_metrics_aftermarket',
                            'technical_scores_aftermarket',
                            'decision_scores_aftermarket',
                            'taiwan_chips',
                            'taiwan_margins',
                            'official_chip_metrics',
                            'taiwan_revenues',
                            'taiwan_valuations',
                            'official_financials',
                            'technical_scores',
                            'fundamental_scores',
                            'decision_scores',
                        ])
                        ->orderByDesc('finished_at')
                        ->first(['job_name', 'finished_at']);

                    $latestGlobalJob = DB::table('system_jobs')
                        ->where('status', 'success')
                        ->whereNotNull('finished_at')
                        ->whereIn('job_name', [
                            'global_market',
                            'global_market_refresh',
                            'global_influence_refresh',
                            'taifex_night',
                            'taifex_night_refresh',
                            'global_influence_night',
                            'decision_scores_night',
                            'global_events',
                            'event_clusters',
                            'global_influence',
                            'ai_event_preprocess',
                        ])
                        ->orderByDesc('finished_at')
                        ->first(['job_name', 'finished_at']);

                    return [
                        'siteStats' => [
                            'members' => DB::table('users')->count(),
                            'online' => DB::table('sessions')
                                ->where('last_activity', '>=', now()->subMinutes(5)->timestamp)
                                ->whereNotNull('user_id')
                                ->distinct('user_id')
                                ->count('user_id'),
                        ],
                        'dataFreshness' => [
                            'taiwan_date' => DB::table('stock_prices_1d')->max('trade_date'),
                            'global_date' => DB::table('global_market_data')->max('trade_date'),
                            'taiwan_updated_at' => $latestTaiwanJob?->finished_at,
                            'taiwan_updated_job' => $latestTaiwanJob?->job_name,
                            'global_updated_at' => $latestGlobalJob?->finished_at,
                            'global_updated_job' => $latestGlobalJob?->job_name,
                        ],
                    ];
                } catch (Throwable) {
                    return [
                        'siteStats' => [
                            'members' => 0,
                            'online' => 0,
                        ],
                        'dataFreshness' => [
                            'taiwan_date' => null,
                            'global_date' => null,
                            'taiwan_updated_at' => null,
                            'taiwan_updated_job' => null,
                            'global_updated_at' => null,
                            'global_updated_job' => null,
                        ],
                    ];
                }
            });

            $view->with('siteStats', $layoutState['siteStats']);
            $view->with('dataFreshness', $layoutState['dataFreshness']);
        });
    }
}
