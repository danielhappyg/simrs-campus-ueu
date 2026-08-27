<?php

use App\Http\Middleware\AssignRequestCorrelationId;
use App\Http\Middleware\AuditAuthorizationDenial;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureCapability;
use App\Http\Middleware\EnsureSimulationSafetyMode;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ProtectTeachingRoleAuthenticationPaths;
use App\Support\Http\RequestCorrelation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
                ProtectTeachingRoleAuthenticationPaths::class,
                HandleInertiaRequests::class,
                AddLinkHeadersForPreloadedAssets::class,
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->reportable(function (Throwable $e): bool {
            if (! app()->bound('request')) {
                return true;
            }

            $request = request();
            $requestId = RequestCorrelation::existing($request);
            if (app()->runningInConsole() && $requestId === null) {
                // Artisan binds a synthetic Request during console bootstrap.
                // It has not passed through the web correlation middleware and
                // must retain Laravel's normal console exception diagnostics.
                return true;
            }

            $route = $request->route();
            Log::error('Unhandled HTTP exception.', [
                'request_id' => $requestId ?? RequestCorrelation::ensure($request),
                'exception_class' => $e::class,
                'exception_fingerprint' => hash('sha256', implode('|', [$e::class, $e->getFile(), (string) $e->getLine()])),
                'http_method' => $request->getMethod(),
                'route_name' => $route instanceof Route ? $route->getName() : null,
                'route_template' => $route instanceof Route ? $route->uri() : null,
            ]);

            // The structured event above intentionally omits exception messages,
            // stack traces and concrete URL values. Prevent a duplicate default
            // report from reintroducing them into hosted stderr logs.
            return false;
        });

        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            $response->headers->set(
                RequestCorrelation::HEADER,
                RequestCorrelation::ensure($request),
            );

            return $response;
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            $isForbidden = $e instanceof AuthorizationException
                || (
                    $e instanceof HttpExceptionInterface
                    && $e->getStatusCode() === 403
                );

            if (! $isForbidden) {
                return null;
            }

            $route = $request->route();
            Log::notice('Forbidden HTTP exception.', [
                'request_id' => RequestCorrelation::ensure($request),
                'exception_class' => $e::class,
                'http_method' => $request->getMethod(),
                'route_name' => $route instanceof Route ? $route->getName() : null,
                'route_template' => $route instanceof Route ? $route->uri() : null,
            ]);

            // Inertia navigations: flash to Beranda. Keep bare 403 for API/tests.
            if ($request->header('X-Inertia')) {
                return redirect()
                    ->route('home')
                    ->with('error', 'Anda tidak memiliki akses ke modul tersebut.');
            }

            return null;
        });
    })->create();
