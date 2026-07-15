<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsureSimulationSafetyMode
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $mode = config('simulation.mode');
        $allowedModes = config('simulation.allowed_modes', []);
        $syntheticOnly = config('simulation.synthetic_only');

        if (! is_string($mode) || ! in_array($mode, $allowedModes, true) || $syntheticOnly !== true) {
            $message = 'Aplikasi dihentikan karena batas keselamatan simulasi tidak valid.';

            if ($request->expectsJson()) {
                return new JsonResponse([
                    'message' => $message,
                    'request_id' => $request->attributes->get('request_id'),
                ], 503);
            }

            throw new HttpException(503, $message);
        }

        return $next($request);
    }
}
