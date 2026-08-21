<?php

use App\Http\Middleware\AssignRequestCorrelationId;
use App\Http\Middleware\AuditAuthorizationDenial;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureCapability;
use App\Http\Middleware\EnsureSimulationSafetyMode;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

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
            'capability' => EnsureCapability::class,
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
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->reportable(function (\Throwable $e): void {
            error_log(sprintf(
                '[simrs] %s: %s in %s:%d',
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));
        });

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            $isForbidden = $e instanceof \Illuminate\Auth\Access\AuthorizationException
                || (
                    $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                    && $e->getStatusCode() === 403
                );

            if (! $isForbidden) {
                return null;
            }

            error_log('[simrs] forbidden: '.$e->getMessage().' on '.$request->path());

            // Inertia navigations: flash to Beranda. Keep bare 403 for API/tests.
            if ($request->header('X-Inertia')) {
                return redirect()
                    ->route('home')
                    ->with('error', 'Anda tidak memiliki akses ke modul tersebut.');
            }

            return null;
        });
    })->create();
