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
                    $latestJob = DB::table('system_jobs')
                        ->where('status', 'success')
                        ->whereNotNull('finished_at')
                        ->orderByDesc('finished_at')
                        ->first(['job_name', 'finished_at']);

                    return [
                        'siteStats' => [
                            'members' => DB::table('users')->count(),
                            'online' => DB::table('sessions')
                                ->where('last_activity', '>=', now()->subMinutes(5)->timestamp)
                                ->count(),
                        ],
                        'dataFreshness' => [
                            'taiwan_date' => DB::table('stock_prices_1d')->max('trade_date'),
                            'global_date' => DB::table('global_market_data')->max('trade_date'),
                            'last_success_at' => $latestJob?->finished_at,
                            'last_success_job' => $latestJob?->job_name,
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
                            'last_success_at' => null,
                            'last_success_job' => null,
                        ],
                    ];
                }
            });

            $view->with('siteStats', $layoutState['siteStats']);
            $view->with('dataFreshness', $layoutState['dataFreshness']);
        });
    }
}
