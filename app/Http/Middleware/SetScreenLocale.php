<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetScreenLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $previous = app()->getLocale();
        // Documents are a separate language surface, not an English UI screen.
        $isDocument = $request->routeIs(
            'pendaftaran.kunjungan.cetak',
            'finance.settlements.receipt',
            'finance.settlement-corrections.refund-receipt',
            'finance.cashier-collections.handoff-receipt',
        );
        app()->setLocale($isDocument ? 'id' : 'en');

        try {
            return $next($request);
        } finally {
            app()->setLocale($previous);
        }
    }
}
