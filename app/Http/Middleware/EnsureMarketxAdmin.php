<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMarketxAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isPublicPath($request) || $request->session()->boolean('marketx_admin')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(401);
        }

        return redirect('/login');
    }

    private function isPublicPath(Request $request): bool
    {
        return $request->is('login')
            || $request->is('logout')
            || $request->is('up')
            || $request->is('assets/*')
            || $request->is('favicon.ico');
    }
}
