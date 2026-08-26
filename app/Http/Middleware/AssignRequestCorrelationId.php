<?php

namespace App\Http\Middleware;

use App\Support\Http\RequestCorrelation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestCorrelationId
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = RequestCorrelation::ensure($request);

        $response = $next($request);
        $response->headers->set(RequestCorrelation::HEADER, $requestId);

        return $response;
    }
}
