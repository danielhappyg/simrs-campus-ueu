<?php

namespace Tests\Feature;

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Audit\Services\AuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use LogicException;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_recorder_uses_request_correlation_and_hashes_ip_address(): void
    {
        config(['app.key' => 'base64:local-test-key-for-audit-hashing-only']);
        $request = Request::create('/work', 'GET', server: [
            'REMOTE_ADDR' => '192.0.2.15',
            'HTTP_USER_AGENT' => 'SIMRS Campus Test Browser',
        ]);
        $request->attributes->set('request_id', '01J00000000000000000000000');

        $event = app(AuditRecorder::class)->record(
            action: 'test.resource.viewed',
            resourceType: 'synthetic_resource',
            resourceId: 'SYN-001',
            metadata: ['synthetic' => true],
            request: $request,
        );

        $this->assertSame('01J00000000000000000000000', $event->request_correlation_id);
        $this->assertSame(64, strlen((string) $event->ip_hash));
        $this->assertNotSame('192.0.2.15', $event->ip_hash);
        $this->assertSame(['synthetic' => true], $event->metadata);
    }

    public function test_recorder_can_omit_request_fingerprints_for_minimized_security_events(): void
    {
        $request = Request::create('/protected-action', 'POST', server: [
            'REMOTE_ADDR' => '192.0.2.99',
            'HTTP_USER_AGENT' => 'Sensitive Test Browser Detail',
        ]);
        $request->attributes->set('request_id', '01J00000000000000000000001');

        $event = app(AuditRecorder::class)->record(
            action: 'authorization.denied',
            resourceType: 'http_route',
            resourceId: 'protected.action',
            outcome: 'DENIED',
            metadata: ['http_method' => 'POST', 'http_status' => 403],
            request: $request,
            includeRequestFingerprint: false,
        );

        $this->assertNull($event->ip_hash);
        $this->assertNull($event->user_agent);
        $this->assertSame('01J00000000000000000000001', $event->request_correlation_id);
    }

    public function test_audit_event_cannot_be_updated_through_model(): void
    {
        $event = AuditEvent::query()->create([
            'action' => 'test.created',
            'resource_type' => 'synthetic_resource',
        ]);

        $this->expectException(LogicException::class);
        $event->update(['outcome' => 'FAILURE']);
    }

    public function test_audit_event_cannot_be_deleted_through_model(): void
    {
        $event = AuditEvent::query()->create([
            'action' => 'test.created',
            'resource_type' => 'synthetic_resource',
        ]);

        $this->expectException(LogicException::class);
        $event->delete();
    }
}
