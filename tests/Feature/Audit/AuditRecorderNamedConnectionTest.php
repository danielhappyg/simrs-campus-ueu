<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class AuditRecorderNamedConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_named_connection_persists_on_commit_and_participates_in_caller_rollback(): void
    {
        $actor = User::factory()->create();
        $connection = DB::connection();
        $recorder = app(AuditRecorder::class);

        $event = $connection->transaction(fn () => $recorder->recordOnConnection(
            $connection->getName(),
            action: 'authorization.denied',
            resourceType: 'http_route',
            resourceId: 'synthetic.named.connection',
            actor: $actor,
            outcome: 'DENIED',
            reason: 'authorization_check_failed',
            metadata: ['http_method' => 'POST', 'http_status' => 403],
            includeRequestFingerprint: false,
        ));
        $this->assertInstanceOf(AuditEvent::class, $event);
        $this->assertSame($connection->getName(), $event->getConnectionName());
        $this->assertDatabaseHas('audit_events', ['id' => $event->id]);

        try {
            $connection->transaction(function () use ($actor, $connection, $recorder): never {
                $rolledBack = $recorder->recordOnConnection(
                    $connection->getName(),
                    action: 'authorization.denied',
                    resourceType: 'http_route',
                    resourceId: 'synthetic.named.rollback',
                    actor: $actor,
                    outcome: 'DENIED',
                    reason: 'authorization_check_failed',
                    metadata: ['http_method' => 'POST', 'http_status' => 403],
                    includeRequestFingerprint: false,
                );
                $this->assertInstanceOf(AuditEvent::class, $rolledBack);

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertDatabaseMissing('audit_events', ['resource_id' => 'synthetic.named.rollback']);
        $this->assertDatabaseCount('audit_events', 1);
    }
}
