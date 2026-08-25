<?php

namespace Tests\Feature\Audit;

use App\Http\Middleware\AuditAuthorizationDenial;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AuditAuthorizationDenialTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_recording_is_not_marked_audited_and_is_retried(): void
    {
        $recorder = new AuthorizationDenialProbeRecorder(null);
        $middleware = new AuditAuthorizationDenial($recorder);
        $request = $this->request(User::factory()->create());

        $middleware->handle($request, static fn (): Response => new Response(status: 403));
        $middleware->handle($request, static fn (): Response => new Response(status: 403));

        $this->assertSame(2, $recorder->calls);
    }

    public function test_successful_recording_is_marked_and_not_duplicated(): void
    {
        $recorder = new AuthorizationDenialProbeRecorder(new AuditEvent);
        $middleware = new AuditAuthorizationDenial($recorder);
        $request = $this->request(User::factory()->create());

        $middleware->handle($request, static fn (): Response => new Response(status: 403));
        $middleware->handle($request, static fn (): Response => new Response(status: 403));

        $this->assertSame(1, $recorder->calls);
    }

    private function request(User $user): Request
    {
        $request = Request::create('/synthetic-protected-route', 'POST');
        $request->setUserResolver(static fn (): User => $user);
        $route = new Route(['POST'], '/synthetic-protected-route', static fn (): null => null);
        $route->name('synthetic.protected.route');
        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    }
}

class AuthorizationDenialProbeRecorder extends AuditRecorder
{
    public int $calls = 0;

    public function __construct(private readonly ?AuditEvent $result) {}

    /** @param array<string, mixed> $metadata */
    public function record(
        string $action,
        string $resourceType,
        ?string $resourceId = null,
        ?User $actor = null,
        string $outcome = 'SUCCESS',
        ?string $reason = null,
        array $metadata = [],
        ?Request $request = null,
        bool $includeRequestFingerprint = true,
    ): ?AuditEvent {
        $this->calls++;

        return $this->result;
    }
}
