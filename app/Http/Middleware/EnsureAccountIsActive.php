<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status !== 'ACTIVE') {
            abort(403, 'Akun tidak aktif. Hubungi administrator SIMRS Campus UEU.');
        }

        return $next($request);
    }
}
