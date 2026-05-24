<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class MarketxAuth
{
    public static function userId(): ?int
    {
        return session('marketx_user_id');
    }

    public static function isAdmin(): bool
    {
        return session('marketx_admin') === true
            || (bool) session('marketx_is_admin', false);
    }

    public static function requireAdmin(): void
    {
        if (! self::isAdmin()) {
            abort(403);
        }
    }

    public static function watchlistQuery()
    {
        $query = DB::table('watchlist');
        $userId = self::userId();

        return $userId === null
            ? $query->whereNull('user_id')
            : $query->where('user_id', $userId);
    }
}
