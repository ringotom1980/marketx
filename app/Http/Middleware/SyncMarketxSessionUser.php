<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SyncMarketxSessionUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->hasSession() ? $request->session() : null;

        if ($session?->get('marketx_admin') === true && ! $session->has('marketx_user_id')) {
            $admin = User::query()
                ->where('is_admin', true)
                ->orderBy('id')
                ->first();

            if ($admin) {
                $session->put('marketx_user_id', $admin->id);
                $session->put('marketx_user_name', $admin->name);
                $session->put('marketx_is_admin', true);
            }
        }

        if ($session?->isStarted()) {
            DB::table('sessions')
                ->where('id', $session->getId())
                ->update([
                    'user_id' => $session->get('marketx_user_id'),
                    'last_activity' => time(),
                ]);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $session = $request->hasSession() ? $request->session() : null;

        if (! $session?->isStarted()) {
            return;
        }

        DB::table('sessions')
            ->where('id', $session->getId())
            ->update([
                'user_id' => $session->get('marketx_user_id'),
                'last_activity' => time(),
            ]);
    }
}
