<?php

namespace Tests\Feature\PrivilegedAccess;

use App\Models\SecurityLedgerEntry;
use App\Models\SecurityLedgerOutbox;
use App\Models\User;
use App\Support\Database\SchemaQualifier;
use App\Support\PrivilegedAccess\SecurityLedgerActorGuard;
use App\Support\PrivilegedAccess\SecurityLedgerEventSchemaRegistry;
use App\Support\PrivilegedAccess\SecurityLedgerIntegrity;
use App\Support\PrivilegedAccess\SecurityLedgerOutboxPersistence;
use App\Support\PrivilegedAccess\SecurityLedgerPayloadGuard;
use App\Support\PrivilegedAccess\SecurityLedgerRecord;
use App\Support\PrivilegedAccess\SecurityLedgerSemanticConflict;
use App\Support\PrivilegedAccess\SecurityLedgerWriter;
use App\Support\PrivilegedAccess\UnsafeSecurityLedgerPayload;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class SecurityLedgerWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_strict_writer_atomically_appends_entry_and_pending_outbox_with_deterministic_digests(): void
    {
        $actor = User::factory()->create();
        $record = $this->record($actor);
        $entry = $this->writer()->append($record);
        $outbox = $entry->outbox;
        $integrity = new SecurityLedgerIntegrity;

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $entry->public_id);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $outbox->public_id);
        $this->assertSame('public_id', $entry->getRouteKeyName());
        $this->assertTrue($entry->actor->is($actor));
        $this->assertTrue($outbox->entry->is($entry));
        $this->assertSame(SecurityLedgerOutbox::STATE_PENDING, $outbox->delivery_state);
        $this->assertSame(0, $outbox->attempts);
        $this->assertSame(SecurityLedgerOutboxPersistence::DEFAULT_DESTINATION, $outbox->destination);
        $this->assertSame($integrity->payloadDigest($record->payload), $entry->payload_digest);
        $this->assertSame($this->activationPayload(), $entry->payload);
        $semanticKey = hash('sha256', $record->eventType."\0".$record->idempotencyKey);
        $this->assertSame($semanticKey, $entry->semantic_key);

        $expectedIntegrity = $integrity->integrityDigest([
            'actor_reference' => $record->actorReference,
            'actor_type' => $record->actorType,
            'environment' => $record->environment,
            'event_type' => $record->eventType,
            'outcome' => $record->outcome,
            'payload_digest' => $entry->payload_digest,
            'public_id' => $entry->public_id,
            'reason' => $record->reason,
            'recorded_at' => $record->recordedAt?->format('Y-m-d\TH:i:s.u\Z'),
            'release_sha' => $record->releaseSha,
            'request_correlation_id' => $record->requestCorrelationId,
            'resource_public_id' => $record->resourcePublicId,
            'resource_type' => $record->resourceType,
            'schema_version' => $record->schemaVersion,
            'semantic_key' => $semanticKey,
        ]);

        $this->assertSame($expectedIntegrity, $entry->integrity_digest);
        $this->assertDatabaseCount('security_ledger_entries', 1);
        $this->assertDatabaseCount('security_ledger_outboxes', 1);

        $serializedEntry = $entry->toArray();
        $serializedOutbox = $outbox->toArray();
        $this->assertArrayNotHasKey('payload_digest', $serializedEntry);
        $this->assertArrayNotHasKey('semantic_key', $serializedEntry);
        $this->assertArrayNotHasKey('integrity_digest', $serializedEntry);
        $this->assertArrayNotHasKey('last_error_digest', $serializedOutbox);
    }

    public function test_canonical_digests_ignore_associative_key_order_but_preserve_list_order(): void
    {
        $integrity = new SecurityLedgerIntegrity;
        $first = [
            'subject' => ['name' => 'Synthetic Admin', 'public_id' => str_repeat('S', 26)],
            'capabilities' => ['user.manage', 'audit.view'],
        ];
        $reordered = [
            'capabilities' => ['user.manage', 'audit.view'],
            'subject' => ['public_id' => str_repeat('S', 26), 'name' => 'Synthetic Admin'],
        ];
        $reversedList = [
            'subject' => ['public_id' => str_repeat('S', 26), 'name' => 'Synthetic Admin'],
            'capabilities' => ['audit.view', 'user.manage'],
        ];

        $this->assertSame($integrity->payloadDigest($first), $integrity->payloadDigest($reordered));
        $this->assertNotSame($integrity->payloadDigest($first), $integrity->payloadDigest($reversedList));
        $this->assertSame($integrity->integrityDigest($first), $integrity->integrityDigest($reordered));
    }

    public function test_unsafe_or_secret_like_metadata_fails_before_any_persistence(): void
    {
        $actor = User::factory()->create();
        $unsafePayloads = [
            ['password' => 'do-not-store-this'],
            ['password_hash' => hash('sha256', 'still-prohibited')],
            ['passwordHash' => hash('sha256', 'still-prohibited')],
            ['nested' => ['session_id' => 'raw-session-id']],
            ['session_id_hmac' => hash('sha256', 'still-prohibited')],
            ['token_digest' => hash('sha256', 'still-prohibited')],
            ['tokenDigest' => hash('sha256', 'still-prohibited')],
            ['private_key' => 'not-a-private-key-but-still-prohibited'],
            ['note' => 'Authorization: Bearer synthetic-token'],
            ['floating_value' => 1.5],
            ['api_key' => 'synthetic-api-key'],
            ['passphrase' => 'synthetic-passphrase'],
            ['webauthn_assertion' => 'synthetic-assertion'],
            ['recovery_material' => 'synthetic-recovery'],
            ['unknown_safe_field' => 'not-registered'],
        ];

        foreach ($unsafePayloads as $payload) {
            try {
                $this->writer()->append($this->record(
                    $actor,
                    payload: array_merge($this->activationPayload(), $payload),
                ));
                $this->fail('Unsafe security-ledger metadata must fail closed.');
            } catch (UnsafeSecurityLedgerPayload) {
                $this->assertDatabaseCount('security_ledger_entries', 0);
                $this->assertDatabaseCount('security_ledger_outboxes', 0);
            }
        }
    }

    public function test_outbox_failure_rolls_back_the_ledger_entry_without_partial_state(): void
    {
        $outboxPersistence = new class extends SecurityLedgerOutboxPersistence
        {
            public function persist(SecurityLedgerEntry $entry): SecurityLedgerOutbox
            {
                throw new RuntimeException('Synthetic protected-outbox failure.');
            }
        };
        $writer = new SecurityLedgerWriter(
            new SecurityLedgerPayloadGuard,
            new SecurityLedgerIntegrity,
            $outboxPersistence,
            new SecurityLedgerActorGuard,
            new SecurityLedgerEventSchemaRegistry,
        );

        try {
            $writer->append($this->record(User::factory()->create()));
            $this->fail('Outbox persistence failure must abort the strict ledger transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic protected-outbox failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('security_ledger_entries', 0);
        $this->assertDatabaseCount('security_ledger_outboxes', 0);
    }

    public function test_outbox_projection_allows_one_attributable_terminal_delivery_and_is_unique_per_entry(): void
    {
        $entry = $this->writer()->append($this->record(User::factory()->create()));
        $outbox = $entry->outbox;
        $deliveredAt = CarbonImmutable::parse('2026-08-25T04:06:00Z');

        $outbox->update([
            'delivery_state' => SecurityLedgerOutbox::STATE_DELIVERED,
            'attempts' => 1,
            'last_attempted_at' => $deliveredAt,
            'delivered_at' => $deliveredAt,
        ]);

        $this->assertSame(SecurityLedgerOutbox::STATE_DELIVERED, $outbox->fresh()->delivery_state);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $this->assertTrue(Schema::hasIndex(
            'security_ledger_outboxes',
            ['security_ledger_entry_id'],
            'unique',
        ));

        try {
            $outbox->update(['destination' => 'unreviewed-sink']);
            $this->fail('Outbox destination must be immutable through Eloquent.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('identity, link, and destination are immutable', $exception->getMessage());
        }

        try {
            $outbox->delete();
            $this->fail('Outbox rows must not be deleted through Eloquent.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
        }
    }

    public function test_eloquent_rejects_update_and_delete_on_ledger_facts(): void
    {
        $entry = $this->writer()->append($this->record(User::factory()->create()));

        foreach ([$entry] as $fact) {
            try {
                $fact->update(['reason' => 'attempted mutation']);
                $this->fail($fact::class.' must reject Eloquent updates.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }

            try {
                $fact->delete();
                $this->fail($fact::class.' must reject Eloquent deletes.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
        }
    }

    public function test_database_trigger_rejects_raw_ledger_update(): void
    {
        $entry = $this->writer()->append($this->record(User::factory()->create()));

        $this->expectException(QueryException::class);

        DB::table(SchemaQualifier::table('security_ledger_entries'))
            ->where('id', $entry->id)
            ->update(['reason' => 'raw update attempt']);
    }

    public function test_database_trigger_rejects_raw_ledger_delete(): void
    {
        $entry = $this->writer()->append($this->record(User::factory()->create()));

        $this->expectException(QueryException::class);

        DB::table(SchemaQualifier::table('security_ledger_entries'))
            ->where('id', $entry->id)
            ->delete();
    }

    public function test_database_trigger_rejects_raw_outbox_identity_update(): void
    {
        $outbox = $this->writer()->append($this->record(User::factory()->create()))->outbox;

        $this->expectException(QueryException::class);

        DB::table(SchemaQualifier::table('security_ledger_outboxes'))
            ->where('id', $outbox->id)
            ->update(['destination' => 'unreviewed-sink']);
    }

    public function test_database_trigger_rejects_raw_outbox_delete(): void
    {
        $outbox = $this->writer()->append($this->record(User::factory()->create()))->outbox;

        $this->expectException(QueryException::class);

        DB::table(SchemaQualifier::table('security_ledger_outboxes'))
            ->where('id', $outbox->id)
            ->delete();
    }

    public function test_database_trigger_rejects_outbox_available_at_movement(): void
    {
        $outbox = $this->writer()->append($this->record(User::factory()->create()))->outbox;

        $this->expectException(QueryException::class);

        DB::table(SchemaQualifier::table('security_ledger_outboxes'))
            ->where('id', $outbox->id)
            ->update(['available_at' => CarbonImmutable::parse('2026-08-25T05:00:00Z')]);
    }

    public function test_database_trigger_rejects_outbox_attempt_decrease(): void
    {
        $outbox = $this->writer()->append($this->record(User::factory()->create()))->outbox;
        $attemptedAt = CarbonImmutable::parse('2026-08-25T04:06:00Z');
        $outbox->update(['attempts' => 1, 'last_attempted_at' => $attemptedAt]);

        $this->expectException(QueryException::class);

        DB::table(SchemaQualifier::table('security_ledger_outboxes'))
            ->where('id', $outbox->id)
            ->update(['attempts' => 0]);
    }

    public function test_database_trigger_rejects_outbox_attempt_time_moving_backward(): void
    {
        $outbox = $this->writer()->append($this->record(User::factory()->create()))->outbox;
        $outbox->update([
            'attempts' => 1,
            'last_attempted_at' => CarbonImmutable::parse('2026-08-25T04:06:00Z'),
        ]);

        $this->expectException(QueryException::class);

        DB::table(SchemaQualifier::table('security_ledger_outboxes'))
            ->where('id', $outbox->id)
            ->update([
                'attempts' => 2,
                'last_attempted_at' => CarbonImmutable::parse('2026-08-25T04:05:30Z'),
            ]);
    }

    public function test_database_trigger_rejects_unregistered_outbox_state(): void
    {
        $outbox = $this->writer()->append($this->record(User::factory()->create()))->outbox;

        $this->expectException(QueryException::class);

        DB::table(SchemaQualifier::table('security_ledger_outboxes'))
            ->where('id', $outbox->id)
            ->update(['delivery_state' => 'RETRYING']);
    }

    public function test_database_trigger_rejects_delivered_outbox_regression(): void
    {
        $outbox = $this->writer()->append($this->record(User::factory()->create()))->outbox;
        $attemptedAt = CarbonImmutable::parse('2026-08-25T04:06:00Z');
        $outbox->update([
            'delivery_state' => SecurityLedgerOutbox::STATE_DELIVERED,
            'attempts' => 1,
            'last_attempted_at' => $attemptedAt,
            'delivered_at' => $attemptedAt,
        ]);

        $this->expectException(QueryException::class);

        DB::table(SchemaQualifier::table('security_ledger_outboxes'))
            ->where('id', $outbox->id)
            ->update(['delivery_state' => SecurityLedgerOutbox::STATE_PENDING]);
    }

    public function test_eloquent_rejects_failed_outbox_without_attributable_attempt_evidence(): void
    {
        $outbox = $this->writer()->append($this->record(User::factory()->create()))->outbox;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('require attributable attempt time and error digest');

        $outbox->update(['delivery_state' => SecurityLedgerOutbox::STATE_FAILED]);
    }

    public function test_database_trigger_rejects_failed_outbox_without_attributable_attempt_evidence(): void
    {
        $outbox = $this->writer()->append($this->record(User::factory()->create()))->outbox;

        $this->expectException(QueryException::class);

        DB::table(SchemaQualifier::table('security_ledger_outboxes'))
            ->where('id', $outbox->id)
            ->update(['delivery_state' => SecurityLedgerOutbox::STATE_FAILED]);
    }

    public function test_database_trigger_rejects_failed_outbox_with_non_hex_error_evidence(): void
    {
        $outbox = $this->writer()->append($this->record(User::factory()->create()))->outbox;

        $this->expectException(QueryException::class);

        DB::table(SchemaQualifier::table('security_ledger_outboxes'))
            ->where('id', $outbox->id)
            ->update([
                'delivery_state' => SecurityLedgerOutbox::STATE_FAILED,
                'attempts' => 1,
                'last_attempted_at' => CarbonImmutable::parse('2026-08-25T04:06:00Z'),
                'last_error_digest' => str_repeat('z', 64),
            ]);
    }

    public function test_attributable_failed_outbox_transition_is_accepted(): void
    {
        $outbox = $this->writer()->append($this->record(User::factory()->create()))->outbox;
        $attemptedAt = CarbonImmutable::parse('2026-08-25T04:06:00Z');
        $errorDigest = strtoupper(hash('sha256', 'synthetic-delivery-failure'));

        $outbox->update([
            'delivery_state' => SecurityLedgerOutbox::STATE_FAILED,
            'attempts' => 1,
            'last_attempted_at' => $attemptedAt,
            'last_error_digest' => $errorDigest,
        ]);

        $persisted = $outbox->fresh();
        $this->assertSame(SecurityLedgerOutbox::STATE_FAILED, $persisted->delivery_state);
        $this->assertSame(1, $persisted->attempts);
        $this->assertNotNull($persisted->last_attempted_at);
        $this->assertSame($errorDigest, $persisted->getRawOriginal('last_error_digest'));
    }

    public function test_raw_outbox_insert_requires_exact_initial_state(): void
    {
        $entry = $this->writer()->append($this->record(User::factory()->create()));

        $this->expectException(QueryException::class);

        DB::table(SchemaQualifier::table('security_ledger_outboxes'))->insert([
            'public_id' => (string) Str::ulid(),
            'security_ledger_entry_id' => $entry->id,
            'destination' => 'protected-security-sink',
            'delivery_state' => 'UNKNOWN',
            'attempts' => 0,
            'available_at' => CarbonImmutable::now('UTC'),
            'created_at' => CarbonImmutable::now('UTC'),
            'updated_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    public function test_identical_semantic_replay_returns_the_existing_entry_and_outbox(): void
    {
        $record = $this->record(User::factory()->create());
        $first = $this->writer()->append($record);
        $replayed = $this->writer()->append($record);

        $this->assertSame($first->id, $replayed->id);
        $this->assertSame($first->outbox->id, $replayed->outbox->id);
        $this->assertDatabaseCount('security_ledger_entries', 1);
        $this->assertDatabaseCount('security_ledger_outboxes', 1);
    }

    public function test_idempotency_key_reuse_for_different_content_fails_closed(): void
    {
        $actor = User::factory()->create();
        $this->writer()->append($this->record($actor));
        $changed = $this->activationPayload();
        $changed['sessions_revoked'] = 2;

        try {
            $this->writer()->append($this->record($actor, payload: $changed));
            $this->fail('A semantic idempotency key must not accept different content.');
        } catch (SecurityLedgerSemanticConflict) {
            $this->assertDatabaseCount('security_ledger_entries', 1);
            $this->assertDatabaseCount('security_ledger_outboxes', 1);
        }
    }

    public function test_actor_attribution_requires_exact_user_or_registered_null_user_service(): void
    {
        $actor = User::factory()->create();

        try {
            $this->writer()->append($this->record($actor, actorReference: (string) Str::ulid()));
            $this->fail('Mismatched USER attribution must fail closed.');
        } catch (UnsafeSecurityLedgerPayload $exception) {
            $this->assertStringContainsString('exact persisted actor', $exception->getMessage());
        }

        $service = $this->writer()->append($this->serviceRecord());
        $this->assertNull($service->actor_user_id);
        $this->assertSame('break-glass-expiry-sweeper', $service->actor_reference);
    }

    public function test_unregistered_service_actor_fails_before_persistence(): void
    {
        $record = $this->serviceRecord(actorReference: 'unregistered-worker');

        $this->expectException(UnsafeSecurityLedgerPayload::class);
        $this->expectExceptionMessage('registered service reference');

        $this->writer()->append($record);
    }

    public function test_migration_rollback_refuses_to_destroy_existing_security_evidence(): void
    {
        $this->writer()->append($this->record(User::factory()->create()));
        $migration = require database_path('migrations/2026_08_25_000200_create_security_ledger_tables.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing BG-02 rollback because protected security evidence exists.');

        $migration->down();
    }

    private function writer(): SecurityLedgerWriter
    {
        return new SecurityLedgerWriter(
            new SecurityLedgerPayloadGuard,
            new SecurityLedgerIntegrity,
            new SecurityLedgerOutboxPersistence,
            new SecurityLedgerActorGuard,
            new SecurityLedgerEventSchemaRegistry,
        );
    }

    /** @param array<string, mixed>|null $payload */
    private function record(User $actor, ?array $payload = null, ?string $actorReference = null): SecurityLedgerRecord
    {
        return new SecurityLedgerRecord(
            eventType: 'break_glass.activation.created',
            resourceType: 'break_glass_activation',
            resourcePublicId: str_repeat('A', 26),
            actorType: 'USER',
            actorReference: $actorReference ?? $actor->public_id,
            outcome: 'SUCCESS',
            reason: 'Approved synthetic containment activation.',
            payload: $payload ?? $this->activationPayload(),
            environment: 'SIMULATION',
            releaseSha: str_repeat('a', 40),
            idempotencyKey: 'activation-created-'.str_repeat('A', 26),
            actor: $actor,
            requestCorrelationId: (string) Str::ulid(),
            schemaVersion: 1,
            recordedAt: CarbonImmutable::parse('2026-08-25T04:05:06.123456Z'),
        );
    }

    /** @return array<string, mixed> */
    private function activationPayload(): array
    {
        return [
            'request_public_id' => str_repeat('R', 26),
            'decision_public_id' => str_repeat('D', 26),
            'activation_public_id' => str_repeat('A', 26),
            'requester_public_id' => str_repeat('Q', 26),
            'approver_public_id' => str_repeat('P', 26),
            'subject_public_id' => str_repeat('S', 26),
            'scope_key' => 'security-containment',
            'capability_snapshot' => ['user.manage', 'audit.view'],
            'request_digest' => hash('sha256', 'request'),
            'decision_digest' => hash('sha256', 'decision'),
            'assurance_method' => 'TOTP',
            'assured_at' => '2026-08-25T04:03:00.000000Z',
            'change_reference' => 'CHG-BG-0001',
            'sessions_revoked' => 1,
            'starts_at' => '2026-08-25T04:05:00.000000Z',
            'expires_at' => '2026-08-25T04:20:00.000000Z',
        ];
    }

    private function serviceRecord(string $actorReference = 'break-glass-expiry-sweeper'): SecurityLedgerRecord
    {
        return new SecurityLedgerRecord(
            eventType: 'break_glass.activation.expired',
            resourceType: 'break_glass_activation',
            resourcePublicId: str_repeat('E', 26),
            actorType: 'SERVICE',
            actorReference: $actorReference,
            outcome: 'SUCCESS',
            reason: 'Synthetic activation reached its approved expiry.',
            payload: [
                'activation_public_id' => str_repeat('E', 26),
                'subject_public_id' => str_repeat('S', 26),
                'activation_digest' => hash('sha256', 'activation-expiry'),
                'expired_at' => '2026-08-25T04:20:00.000000Z',
                'sessions_revoked' => 1,
            ],
            environment: 'SIMULATION',
            releaseSha: str_repeat('a', 40),
            idempotencyKey: 'activation-expired-'.str_repeat('E', 26),
            recordedAt: CarbonImmutable::parse('2026-08-25T04:20:01.000000Z'),
        );
    }
}
