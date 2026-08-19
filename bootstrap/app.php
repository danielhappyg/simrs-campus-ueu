<?php

use App\Http\Middleware\AssignRequestCorrelationId;
use App\Http\Middleware\AuditAuthorizationDenial;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureSimulationSafetyMode;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        // The deployment edge terminates TLS before forwarding requests.
        // Trust only the immediate proxy so URL generation retains HTTPS
        // without accepting spoofed forwarding headers from arbitrary hops.
        $middleware->trustProxies(at: 'REMOTE_ADDR');

        $middleware->alias([
            'active.account' => EnsureAccountIsActive::class,
            'simulation' => EnsureSimulationSafetyMode::class,
        ]);

        $middleware->encryptCookies(except: ['sidebar_state']);

        $middleware->web(
            prepend: [AssignRequestCorrelationId::class],
            append: [
                AuditAuthorizationDenial::class,
                HandleInertiaRequests::class,
                AddLinkHeadersForPreloadedAssets::class,
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isClinicalDraftRecoveryRequest = static fn (Request $request): bool => $request->isMethod('post')
            && $request->header('X-Inertia') === 'true'
            && $request->header('X-SIMRS-Draft-Recovery') === 'same-tab'
            && $request->routeIs(
                'encounters.nursing-intake.versions.store',
                'encounters.medical-assessment.versions.store',
                'encounters.closure.versions.store',
            );
        $reauthenticationResponse = static fn (int $status) => response()->json([
            'code' => 'REAUTHENTICATION_REQUIRED',
            'message' => 'Authentication must be restored before this draft can be saved.',
        ], $status, [
            'Cache-Control' => 'no-store, private',
            'X-SIMRS-Draft-Recovery' => 'reauthentication-required',
        ]);

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($isClinicalDraftRecoveryRequest, $reauthenticationResponse) {
            if (! $isClinicalDraftRecoveryRequest($request)) {
                return null;
            }

            return $reauthenticationResponse(401);
        });

        $exceptions->render(function (HttpException $exception, Request $request) use ($isClinicalDraftRecoveryRequest, $reauthenticationResponse) {
            if ($exception->getStatusCode() !== 419
                || ! $exception->getPrevious() instanceof TokenMismatchException
                || ! $isClinicalDraftRecoveryRequest($request)) {
                return null;
            }

            return $reauthenticationResponse(419);
        });

        $exceptions->render(function (HttpException $exception, Request $request) {
            if ($exception->getStatusCode() !== 409
                || ! $request->isMethod('get')
                || $request->expectsJson()) {
                return null;
            }

            $reason = trim($exception->getMessage());

            return Inertia::render('errors/workflow-conflict', [
                'reason' => $reason !== ''
                    ? $reason
                    : 'Tahap ini belum tersedia pada status encounter saat ini.',
                'workQueueUrl' => route('work'),
            ])->toResponse($request)->setStatusCode(409);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
