<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\InvalidAuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditRecorderSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_registered_event_is_persisted_with_ulid_correlation_and_hashed_user_agent(): void
    {
        $actor = User::factory()->create();
        $request = Request::create('/synthetic-protected-route', 'POST', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'Synthetic Browser with password=must-not-be-stored',
        ]);
        $correlationId = (string) Str::ulid();
        $request->attributes->set('request_id', $correlationId);

        $event = $this->recorder()->record(
            action: 'authorization.denied',
            resourceType: 'http_route',
            resourceId: 'synthetic.protected.route',
            actor: $actor,
            outcome: 'DENIED',
            reason: 'authorization_check_failed',
            metadata: ['http_method' => 'POST', 'http_status' => 403],
            request: $request,
        );

        $this->assertNotNull($event);
        $this->assertSame($correlationId, $event->request_correlation_id);
        $this->assertSame(64, strlen((string) $event->user_agent));
        $this->assertNotSame($request->userAgent(), $event->user_agent);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_secret_like_data_is_rejected_before_insert(): void
    {
        $event = $this->recorder()->record(
            action: 'teaching.reset.started',
            resourceType: 'simulation',
            resourceId: 'synthetic-reset',
            outcome: 'SUCCESS',
            reason: 'Bearer synthetic.secret.value',
            metadata: ['boundary' => 'synthetic_patient_graph', 'evidence_preserved' => true],
            includeRequestFingerprint: false,
        );

        $this->assertNull($event);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_unknown_event_tuple_is_rejected_before_insert(): void
    {
        $event = $this->recorder()->record(
            action: 'unregistered.action',
            resourceType: 'encounter',
            resourceId: (string) Str::ulid(),
            metadata: [],
            includeRequestFingerprint: false,
        );

        $this->assertNull($event);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_invalid_request_correlation_is_rejected_instead_of_truncated(): void
    {
        $actor = User::factory()->create();
        $request = Request::create('/synthetic-protected-route', 'GET');
        $request->attributes->set('request_id', 'not-a-ulid-but-previously-truncated');

        $event = $this->recorder()->record(
            action: 'authorization.denied',
            resourceType: 'http_route',
            resourceId: 'synthetic.protected.route',
            actor: $actor,
            outcome: 'DENIED',
            reason: 'authorization_check_failed',
            metadata: ['http_method' => 'GET', 'http_status' => 403],
            request: $request,
            includeRequestFingerprint: false,
        );

        $this->assertNull($event);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_user_agent_is_not_stored_when_application_key_is_unavailable(): void
    {
        config(['app.key' => null]);
        $actor = User::factory()->create();
        $request = Request::create('/synthetic-protected-route', 'GET', server: [
            'HTTP_USER_AGENT' => 'Synthetic Browser',
        ]);

        $event = $this->recorder()->record(
            action: 'authorization.denied',
            resourceType: 'http_route',
            resourceId: 'synthetic.protected.route',
            actor: $actor,
            outcome: 'DENIED',
            reason: 'authorization_check_failed',
            metadata: ['http_method' => 'GET', 'http_status' => 403],
            request: $request,
        );

        $this->assertNotNull($event);
        $this->assertNull($event->user_agent);
        $this->assertNull($event->ip_hash);
    }

    public function test_actor_required_event_rejects_an_unsaved_user(): void
    {
        $event = $this->recorder()->record(
            action: 'authorization.denied',
            resourceType: 'http_route',
            resourceId: 'synthetic.protected.route',
            actor: new User,
            outcome: 'DENIED',
            reason: 'authorization_check_failed',
            metadata: ['http_method' => 'GET', 'http_status' => 403],
            includeRequestFingerprint: false,
        );

        $this->assertNull($event);
        $this->assertDatabaseCount('audit_events', 0);
    }

    #[DataProvider('invalidModelBoundaryAttributes')]
    public function test_model_creating_hook_rejects_invalid_optional_security_fields(string $field, mixed $value): void
    {
        $actor = User::factory()->create();
        $attributes = [
            'actor_user_id' => $actor->id,
            'action' => 'authorization.denied',
            'resource_type' => 'http_route',
            'resource_id' => 'synthetic.protected.route',
            'outcome' => 'DENIED',
            'reason' => 'authorization_check_failed',
            'metadata' => ['http_method' => 'GET', 'http_status' => 403],
        ];
        $attributes[$field] = $value;

        $this->expectException(InvalidAuditEvent::class);
        AuditEvent::query()->create($attributes);
    }

    public function test_direct_eloquent_creation_cannot_bypass_the_validated_recording_boundary(): void
    {
        $this->expectException(InvalidAuditEvent::class);

        AuditEvent::query()->create([
            'action' => 'unregistered.direct.insert',
            'resource_type' => 'test',
            'outcome' => 'SUCCESS',
        ]);
    }

    private function recorder(): AuditRecorder
    {
        return $this->app->make(AuditRecorder::class);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidModelBoundaryAttributes(): iterable
    {
        yield 'correlation is not a ULID' => ['request_correlation_id', 'not-a-ulid'];
        yield 'IP hash is not 64 hex characters' => ['ip_hash', str_repeat('a', 63)];
        yield 'user agent is raw instead of a 64-hex digest' => ['user_agent', 'Synthetic Browser'];
        yield 'resource version is not registered' => ['resource_version', 'v1'];
    }
}
