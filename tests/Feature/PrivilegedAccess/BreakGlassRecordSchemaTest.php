<?php

namespace Tests\Feature\PrivilegedAccess;

use App\Models\BreakGlassActivation;
use App\Models\BreakGlassDecision;
use App\Models\BreakGlassRequest;
use App\Models\BreakGlassRevocation;
use App\Models\BreakGlassSessionBinding;
use App\Models\BreakGlassSubjectLease;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class BreakGlassRecordSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_contains_portable_fact_and_projection_contracts(): void
    {
        $immutableColumns = [
            'break_glass_requests' => [
                'id', 'public_id', 'subject_user_id', 'requester_user_id',
                'subject_snapshot', 'requester_snapshot', 'scope_key',
                'capability_snapshot', 'reason', 'change_reference',
                'requested_ttl_minutes', 'requested_at', 'approval_deadline_at',
                'environment', 'release_sha', 'canonical_digest', 'created_at',
            ],
            'break_glass_decisions' => [
                'id', 'public_id', 'break_glass_request_id', 'approver_user_id',
                'approver_snapshot', 'decision', 'rationale', 'assurance_method',
                'assured_at', 'decided_at', 'request_digest', 'environment',
                'release_sha', 'canonical_digest', 'created_at',
            ],
            'break_glass_activations' => [
                'id', 'public_id', 'break_glass_request_id', 'break_glass_decision_id',
                'subject_user_id', 'approved_by_user_id', 'subject_snapshot',
                'approver_snapshot', 'scope_key', 'capability_snapshot', 'starts_at',
                'expires_at', 'nonce_version', 'nonce_digest', 'request_digest',
                'decision_digest', 'environment', 'release_sha', 'canonical_digest',
                'created_at',
            ],
            'break_glass_revocations' => [
                'id', 'public_id', 'break_glass_activation_id', 'revoker_user_id',
                'revoker_type', 'revoker_reference', 'revoker_snapshot', 'reason',
                'change_reference', 'revoked_at', 'activation_digest', 'environment',
                'release_sha', 'canonical_digest', 'created_at',
            ],
            'break_glass_session_bindings' => [
                'id', 'public_id', 'break_glass_activation_id', 'subject_user_id',
                'session_reference_hmac', 'assurance_method', 'assured_at', 'bound_at',
                'activation_digest', 'environment', 'release_sha', 'canonical_digest',
                'created_at',
            ],
        ];

        foreach ($immutableColumns as $table => $columns) {
            $this->assertTrue(Schema::hasColumns($table, $columns), "{$table} is missing required immutable fact columns.");
            $this->assertFalse(Schema::hasColumn($table, 'updated_at'), "{$table} must not expose mutable timestamps.");
        }

        $this->assertTrue(Schema::hasColumns('break_glass_subject_leases', [
            'id', 'public_id', 'subject_user_id', 'break_glass_activation_id',
            'expires_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_schema_enforces_one_final_fact_and_one_current_lease_without_nullable_unique_keys(): void
    {
        $uniqueContracts = [
            ['break_glass_decisions', ['break_glass_request_id']],
            ['break_glass_activations', ['break_glass_request_id']],
            ['break_glass_activations', ['break_glass_decision_id']],
            ['break_glass_revocations', ['break_glass_activation_id']],
            ['break_glass_session_bindings', ['break_glass_activation_id']],
            ['break_glass_session_bindings', ['session_reference_hmac']],
            ['break_glass_subject_leases', ['subject_user_id']],
            ['break_glass_subject_leases', ['break_glass_activation_id']],
        ];

        foreach ($uniqueContracts as [$table, $columns]) {
            $this->assertTrue(
                Schema::hasIndex($table, $columns, 'unique'),
                sprintf('%s must have a unique index on %s.', $table, implode(', ', $columns)),
            );
        }
    }

    public function test_schema_preserves_user_and_fact_attribution_with_foreign_keys(): void
    {
        $foreignKeys = [
            ['break_glass_requests', ['subject_user_id']],
            ['break_glass_requests', ['requester_user_id']],
            ['break_glass_decisions', ['break_glass_request_id']],
            ['break_glass_decisions', ['approver_user_id']],
            ['break_glass_activations', ['break_glass_request_id']],
            ['break_glass_activations', ['break_glass_decision_id']],
            ['break_glass_activations', ['subject_user_id']],
            ['break_glass_activations', ['approved_by_user_id']],
            ['break_glass_revocations', ['break_glass_activation_id']],
            ['break_glass_revocations', ['revoker_user_id']],
            ['break_glass_session_bindings', ['break_glass_activation_id']],
            ['break_glass_session_bindings', ['subject_user_id']],
            ['break_glass_subject_leases', ['subject_user_id']],
            ['break_glass_subject_leases', ['break_glass_activation_id']],
        ];

        foreach ($foreignKeys as [$table, $columns]) {
            $this->assertTrue(
                Schema::hasForeignKey($table, $columns),
                sprintf('%s must have a foreign key on %s.', $table, implode(', ', $columns)),
            );
        }
    }

    public function test_models_persist_an_attributable_break_glass_fact_graph_with_public_ulids(): void
    {
        $graph = $this->createGraph();

        foreach ($graph as $record) {
            $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $record->public_id);
            $this->assertSame('public_id', $record->getRouteKeyName());
        }

        $this->assertTrue($graph['request']->subject->is($graph['subject']));
        $this->assertTrue($graph['request']->requester->is($graph['requester']));
        $this->assertTrue($graph['decision']->request->is($graph['request']));
        $this->assertTrue($graph['decision']->approver->is($graph['approver']));
        $this->assertTrue($graph['activation']->decision->is($graph['decision']));
        $this->assertTrue($graph['activation']->approvedBy->is($graph['approver']));
        $this->assertTrue($graph['binding']->activation->is($graph['activation']));
        $this->assertTrue($graph['revocation']->revoker->is($graph['revoker']));
        $this->assertTrue($graph['lease']->activation->is($graph['activation']));

        $this->assertSame(['user.manage', 'audit.view'], $graph['request']->capability_snapshot);
        $this->assertSame(['public_id' => $graph['subject']->public_id], $graph['activation']->subject_snapshot);
        $this->assertInstanceOf(CarbonImmutable::class, $graph['activation']->expires_at);
        $this->assertSame(15, $graph['request']->requested_ttl_minutes);
        $this->assertArrayNotHasKey('nonce_digest', $graph['activation']->toArray());
        $this->assertArrayNotHasKey('session_reference_hmac', $graph['binding']->toArray());
    }

    public function test_fact_models_are_append_only_while_subject_lease_is_explicitly_mutable(): void
    {
        $graph = $this->createGraph();
        $facts = [
            $graph['request'],
            $graph['decision'],
            $graph['activation'],
            $graph['binding'],
            $graph['revocation'],
        ];

        foreach ($facts as $fact) {
            try {
                $fact->update(['environment' => 'other-environment']);
                $this->fail($fact::class.' must reject updates.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('append-only break-glass fact', $exception->getMessage());
            }

            try {
                $fact->delete();
                $this->fail($fact::class.' must reject deletion.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('append-only break-glass fact', $exception->getMessage());
            }

            $this->assertSame('SIMULATION', $fact->fresh()->environment);
        }

        $newExpiry = CarbonImmutable::parse('2026-08-25T03:25:00Z');
        $graph['lease']->update(['expires_at' => $newExpiry]);
        $persistedExpiry = $graph['lease']->fresh()->expires_at;

        $this->assertInstanceOf(CarbonImmutable::class, $persistedExpiry);
        $this->assertSame(
            $newExpiry->format('Y-m-d H:i:s.u'),
            $persistedExpiry->format('Y-m-d H:i:s.u'),
        );
    }

    /**
     * @return array{
     *     requester: User,
     *     subject: User,
     *     approver: User,
     *     revoker: User,
     *     request: BreakGlassRequest,
     *     decision: BreakGlassDecision,
     *     activation: BreakGlassActivation,
     *     binding: BreakGlassSessionBinding,
     *     revocation: BreakGlassRevocation,
     *     lease: BreakGlassSubjectLease
     * }
     */
    private function createGraph(): array
    {
        $requester = User::factory()->create();
        $subject = User::factory()->create();
        $approver = User::factory()->create();
        $revoker = User::factory()->create();
        $requestedAt = CarbonImmutable::parse('2026-08-25T03:00:00Z');
        $releaseSha = str_repeat('a', 40);

        $request = BreakGlassRequest::query()->create([
            'subject_user_id' => $subject->id,
            'requester_user_id' => $requester->id,
            'subject_snapshot' => ['public_id' => $subject->public_id],
            'requester_snapshot' => ['public_id' => $requester->public_id],
            'scope_key' => 'security-containment',
            'capability_snapshot' => ['user.manage', 'audit.view'],
            'reason' => 'Contain a synthetic teaching account after an access anomaly.',
            'change_reference' => 'CHG-BG-0001',
            'requested_ttl_minutes' => 15,
            'requested_at' => $requestedAt,
            'approval_deadline_at' => $requestedAt->addMinutes(5),
            'environment' => 'SIMULATION',
            'release_sha' => $releaseSha,
            'canonical_digest' => $this->digest('request'),
        ]);

        $decision = BreakGlassDecision::query()->create([
            'break_glass_request_id' => $request->id,
            'approver_user_id' => $approver->id,
            'approver_snapshot' => ['public_id' => $approver->public_id],
            'decision' => BreakGlassDecision::APPROVED,
            'rationale' => 'Approved for the exact synthetic containment task and scope.',
            'assurance_method' => 'TOTP',
            'assured_at' => $requestedAt->addMinute(),
            'decided_at' => $requestedAt->addMinutes(2),
            'request_digest' => $request->canonical_digest,
            'environment' => 'SIMULATION',
            'release_sha' => $releaseSha,
            'canonical_digest' => $this->digest('decision'),
        ]);

        $activation = BreakGlassActivation::query()->create([
            'break_glass_request_id' => $request->id,
            'break_glass_decision_id' => $decision->id,
            'subject_user_id' => $subject->id,
            'approved_by_user_id' => $approver->id,
            'subject_snapshot' => ['public_id' => $subject->public_id],
            'approver_snapshot' => ['public_id' => $approver->public_id],
            'scope_key' => $request->scope_key,
            'capability_snapshot' => $request->capability_snapshot,
            'starts_at' => $requestedAt->addMinutes(3),
            'expires_at' => $requestedAt->addMinutes(18),
            'nonce_version' => 1,
            'nonce_digest' => $this->digest('nonce'),
            'request_digest' => $request->canonical_digest,
            'decision_digest' => $decision->canonical_digest,
            'environment' => 'SIMULATION',
            'release_sha' => $releaseSha,
            'canonical_digest' => $this->digest('activation'),
        ]);

        $binding = BreakGlassSessionBinding::query()->create([
            'break_glass_activation_id' => $activation->id,
            'subject_user_id' => $subject->id,
            'session_reference_hmac' => $this->digest('session-reference'),
            'assurance_method' => 'TOTP',
            'assured_at' => $requestedAt->addMinutes(4),
            'bound_at' => $requestedAt->addMinutes(4),
            'activation_digest' => $activation->canonical_digest,
            'environment' => 'SIMULATION',
            'release_sha' => $releaseSha,
            'canonical_digest' => $this->digest('binding'),
        ]);

        $revocation = BreakGlassRevocation::query()->create([
            'break_glass_activation_id' => $activation->id,
            'revoker_user_id' => $revoker->id,
            'revoker_type' => BreakGlassRevocation::REVOKER_USER,
            'revoker_reference' => $revoker->public_id,
            'revoker_snapshot' => ['public_id' => $revoker->public_id],
            'reason' => 'Synthetic containment task completed.',
            'change_reference' => 'CHG-BG-0001',
            'revoked_at' => $requestedAt->addMinutes(10),
            'activation_digest' => $activation->canonical_digest,
            'environment' => 'SIMULATION',
            'release_sha' => $releaseSha,
            'canonical_digest' => $this->digest('revocation'),
        ]);

        $lease = BreakGlassSubjectLease::query()->create([
            'subject_user_id' => $subject->id,
            'break_glass_activation_id' => $activation->id,
            'expires_at' => $activation->expires_at,
        ]);

        return compact(
            'requester',
            'subject',
            'approver',
            'revoker',
            'request',
            'decision',
            'activation',
            'binding',
            'revocation',
            'lease',
        );
    }

    private function digest(string $value): string
    {
        return hash('sha256', 'bg-01-test:'.$value);
    }
}
