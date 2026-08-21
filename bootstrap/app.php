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
    })->create();
