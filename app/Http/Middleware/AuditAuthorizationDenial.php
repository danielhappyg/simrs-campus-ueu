<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditAuthorizationDenial
{
    private const AUDITED_ATTRIBUTE = 'authorization_denial_audited';

    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            if ($this->isAuthorizationDenial($exception)) {
                $this->recordOnce($request);
            }

            throw $exception;
        }

        if ($response->getStatusCode() === Response::HTTP_FORBIDDEN) {
            $this->recordOnce($request);
        }

        return $response;
    }

    private function isAuthorizationDenial(Throwable $exception): bool
    {
        if ($exception instanceof AuthorizationException) {
            return ! $exception->hasStatus()
                || $exception->status() === Response::HTTP_FORBIDDEN;
        }

        return $exception instanceof HttpExceptionInterface
            && $exception->getStatusCode() === Response::HTTP_FORBIDDEN;
    }

    private function recordOnce(Request $request): void
    {
        if ($request->attributes->getBoolean(self::AUDITED_ATTRIBUTE)) {
            return;
        }

        $actor = $request->user();

        if (! $actor instanceof User) {
            return;
        }

        $route = $request->route();
        $routeName = $route instanceof Route ? $route->getName() : null;

        try {
            $event = $this->auditRecorder->record(
                action: 'authorization.denied',
                resourceType: 'http_route',
                resourceId: is_string($routeName) && $routeName !== ''
                    ? $routeName
                    : 'unnamed-protected-route',
                actor: $actor,
                outcome: 'DENIED',
                reason: 'authorization_check_failed',
                metadata: [
                    'http_method' => $request->getMethod(),
                    'http_status' => Response::HTTP_FORBIDDEN,
                ],
                request: $request,
                includeRequestFingerprint: false,
            );

            if ($event !== null) {
                $request->attributes->set(self::AUDITED_ATTRIBUTE, true);

                return;
            }

            Log::critical('Authorization denial audit recording failed.', [
                'failure_code' => 'AUDIT_EVENT_NOT_RECORDED',
            ]);
        } catch (Throwable $exception) {
            Log::critical('Authorization denial audit recording failed.', [
                'failure_code' => 'AUDIT_RECORDER_THROWN',
                'exception_class' => $exception::class,
            ]);
        }
    }
}
