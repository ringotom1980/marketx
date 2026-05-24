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
            $siteStats = Cache::remember('marketx.site_stats', 30, function () {
                try {
                    return [
                        'members' => DB::table('users')->count(),
                        'online' => DB::table('sessions')
                            ->where('last_activity', '>=', now()->subMinutes(5)->timestamp)
                            ->count(),
                    ];
                } catch (Throwable) {
                    return [
                        'members' => 0,
                        'online' => 0,
                    ];
                }
            });

            $view->with('siteStats', $siteStats);
        });
    }
}
