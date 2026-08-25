<?php

namespace Tests\Feature\PrivilegedAccess;

use App\Models\BreakGlassActivation;
use App\Models\BreakGlassDecision;
use App\Models\BreakGlassRequest;
use App\Models\BreakGlassRevocation;
use App\Models\BreakGlassSessionBinding;
use App\Models\User;
use App\Support\Database\SchemaQualifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProtectedSecurityFactsDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string}> */
    public static function immutableFactTables(): iterable
    {
        yield 'request' => ['break_glass_requests'];
        yield 'decision' => ['break_glass_decisions'];
        yield 'activation' => ['break_glass_activations'];
        yield 'revocation' => ['break_glass_revocations'];
        yield 'session binding' => ['break_glass_session_bindings'];
    }

    #[DataProvider('immutableFactTables')]
    public function test_database_guard_rejects_direct_update_of_each_immutable_fact(string $table): void
    {
        $ids = $this->createFactGraph();

        $this->expectException(QueryException::class);

        DB::table(SchemaQualifier::table($table))
            ->where('id', $ids[$table])
            ->update(['environment' => 'attempted-direct-mutation']);
    }

    #[DataProvider('immutableFactTables')]
    public function test_database_guard_rejects_direct_delete_of_each_immutable_fact(string $table): void
    {
        $ids = $this->createFactGraph();

        $this->expectException(QueryException::class);

        DB::table(SchemaQualifier::table($table))
            ->where('id', $ids[$table])
            ->delete();
    }

    public function test_bg02_rollback_refuses_to_remove_guards_from_populated_immutable_facts(): void
    {
        $this->createFactGraph();
        $migration = require database_path('migrations/2026_08_25_000200_create_security_ledger_tables.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing BG-02 rollback because protected security evidence exists.');

        $migration->down();
    }

    /** @return array<string, int> */
    private function createFactGraph(): array
    {
        $requester = User::factory()->create();
        $subject = User::factory()->create();
        $approver = User::factory()->create();
        $revoker = User::factory()->create();
        $startedAt = CarbonImmutable::parse('2026-08-25T03:00:00Z');
        $releaseSha = str_repeat('a', 40);

        $request = BreakGlassRequest::query()->create([
            'subject_user_id' => $subject->id,
            'requester_user_id' => $requester->id,
            'subject_snapshot' => ['public_id' => $subject->public_id],
            'requester_snapshot' => ['public_id' => $requester->public_id],
            'scope_key' => 'security-containment',
            'capability_snapshot' => ['user.manage'],
            'reason' => 'Synthetic containment request.',
            'change_reference' => 'CHG-BG-DB-01',
            'requested_ttl_minutes' => 15,
            'requested_at' => $startedAt,
            'approval_deadline_at' => $startedAt->addMinutes(5),
            'environment' => 'SIMULATION',
            'release_sha' => $releaseSha,
            'canonical_digest' => $this->digest('request'),
        ]);

        $decision = BreakGlassDecision::query()->create([
            'break_glass_request_id' => $request->id,
            'approver_user_id' => $approver->id,
            'approver_snapshot' => ['public_id' => $approver->public_id],
            'decision' => BreakGlassDecision::APPROVED,
            'rationale' => 'Synthetic request approved.',
            'assurance_method' => 'TOTP',
            'assured_at' => $startedAt->addMinute(),
            'decided_at' => $startedAt->addMinutes(2),
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
            'starts_at' => $startedAt->addMinutes(3),
            'expires_at' => $startedAt->addMinutes(18),
            'nonce_version' => 1,
            'nonce_digest' => $this->digest('nonce'),
            'request_digest' => $request->canonical_digest,
            'decision_digest' => $decision->canonical_digest,
            'environment' => 'SIMULATION',
            'release_sha' => $releaseSha,
            'canonical_digest' => $this->digest('activation'),
        ]);

        $revocation = BreakGlassRevocation::query()->create([
            'break_glass_activation_id' => $activation->id,
            'revoker_user_id' => $revoker->id,
            'revoker_type' => BreakGlassRevocation::REVOKER_USER,
            'revoker_reference' => $revoker->public_id,
            'revoker_snapshot' => ['public_id' => $revoker->public_id],
            'reason' => 'Synthetic containment complete.',
            'change_reference' => 'CHG-BG-DB-01',
            'revoked_at' => $startedAt->addMinutes(10),
            'activation_digest' => $activation->canonical_digest,
            'environment' => 'SIMULATION',
            'release_sha' => $releaseSha,
            'canonical_digest' => $this->digest('revocation'),
        ]);

        $binding = BreakGlassSessionBinding::query()->create([
            'break_glass_activation_id' => $activation->id,
            'subject_user_id' => $subject->id,
            'session_reference_hmac' => $this->digest('session-reference'),
            'assurance_method' => 'TOTP',
            'assured_at' => $startedAt->addMinutes(4),
            'bound_at' => $startedAt->addMinutes(4),
            'activation_digest' => $activation->canonical_digest,
            'environment' => 'SIMULATION',
            'release_sha' => $releaseSha,
            'canonical_digest' => $this->digest('binding'),
        ]);

        return [
            'break_glass_requests' => (int) $request->id,
            'break_glass_decisions' => (int) $decision->id,
            'break_glass_activations' => (int) $activation->id,
            'break_glass_revocations' => (int) $revocation->id,
            'break_glass_session_bindings' => (int) $binding->id,
        ];
    }

    private function digest(string $value): string
    {
        return hash('sha256', 'bg-02-db-guard:'.$value);
    }
}
