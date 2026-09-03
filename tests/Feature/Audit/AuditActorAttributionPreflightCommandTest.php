<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Support\Audit\AuditActorAttribution;
use App\Support\Database\SchemaQualifier;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditActorAttributionPreflightCommandTest extends TestCase
{
    use DatabaseTruncation;

    protected bool $recreateExactEngineDatabaseBeforeApplicationBoot = true;

    protected function setUp(): void
    {
        // These tests must exercise the command-owned transaction rather than
        // RefreshDatabase's outer transaction on every supported engine.
        RefreshDatabaseState::$migrated = false;
        parent::setUp();

        config([
            'app.key' => 'base64:c3ludGhldGljLXByZWZsaWdodC10ZXN0LWtleQ==',
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Do not let non-transactional fixtures leak into the next test class.
        RefreshDatabaseState::$migrated = false;
    }

    public function test_empty_ledger_is_current_and_deterministic(): void
    {
        $this->assertSame(0, DB::connection()->transactionLevel());
        [$exitCode, $first] = $this->runJson();
        [, $second] = $this->runJson();

        $this->assertSame(0, $exitCode);
        $this->assertSame('CURRENT', $first['result']);
        $this->assertSame(0, $first['rows_scanned']);
        $this->assertSame([
            'CURRENT' => 0,
            'BACKFILLABLE_USER' => 0,
            'BACKFILLABLE_SERVICE' => 0,
            'BLOCKING' => 0,
        ], $first['counts']);
        $this->assertTrue($first['contract_ready']);
        $this->assertTrue($first['backfill_ready']);
        $this->assertSame(
            'e8b6b00d192cb732f8606d7146636e868c0e4842561ca76d99284e850dd0febc',
            $first['root_digest'],
        );
        $this->assertSame($first['root_digest'], $second['root_digest']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first['root_digest']);
    }

    public function test_exact_user_and_registered_service_snapshots_are_current(): void
    {
        $user = User::factory()->create();
        $this->insertAudit([
            'id' => '01J10000000000000000000000',
            'action' => 'patient.register',
            'actor_user_id' => $user->id,
            'actor_type' => AuditActorAttribution::TYPE_USER,
            'actor_reference' => $user->public_id,
        ]);
        $this->insertAudit([
            'id' => '01J20000000000000000000000',
            'action' => 'teaching.reset.started',
            'actor_type' => AuditActorAttribution::TYPE_SERVICE,
            'actor_reference' => AuditActorAttribution::SYNTHETIC_RESET_SERVICE,
        ]);
        $this->insertAudit([
            'id' => '01J30000000000000000000000',
            'action' => 'authorization.rebuild_admin.reconciled',
            'actor_type' => AuditActorAttribution::TYPE_SERVICE,
            'actor_reference' => AuditActorAttribution::REBUILD_ADMIN_SERVICE,
        ]);

        [$exitCode, $report] = $this->runJson(['--require-current' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(3, $report['counts']['CURRENT']);
        $this->assertSame('CURRENT', $report['result']);
        $this->assertTrue($report['contract_ready']);
    }

    public function test_evidenced_legacy_rows_are_backfillable_without_guessing_identity(): void
    {
        $user = User::factory()->create();
        $secretMarker = 'password=must-never-appear-in-preflight';

        $this->insertAudit([
            'id' => '01J10000000000000000000000',
            'action' => 'patient.register',
            'actor_user_id' => $user->id,
            'reason' => $secretMarker,
            'metadata' => json_encode(['secret' => $secretMarker], JSON_THROW_ON_ERROR),
            'user_agent' => $secretMarker,
        ]);
        $this->insertAudit([
            'id' => '01J20000000000000000000000',
            'action' => 'authorization.rebuild_admin.reconciled',
        ]);

        [$defaultExit, $report, $output] = $this->runJson();
        [$strictExit, $strict] = $this->runJson(['--require-current' => true]);

        $this->assertSame(0, $defaultExit);
        $this->assertSame(2, $strictExit);
        $this->assertSame('BACKFILL_REQUIRED', $report['result']);
        $this->assertSame(1, $report['counts']['BACKFILLABLE_USER']);
        $this->assertSame(1, $report['counts']['BACKFILLABLE_SERVICE']);
        $this->assertFalse($report['contract_ready']);
        $this->assertTrue($report['backfill_ready']);
        $this->assertSame($report['root_digest'], $strict['root_digest']);
        $this->assertStringNotContainsString($secretMarker, $output);
        $this->assertArrayNotHasKey('rows', $report);
        $this->assertArrayNotHasKey('findings', $report);
    }

    public function test_partial_mismatched_and_missing_actor_states_block_with_finite_counts(): void
    {
        $user = User::factory()->create();

        $this->insertAudit([
            'id' => '01J10000000000000000000000',
            'action' => 'patient.register',
            'actor_user_id' => $user->id,
            'actor_type' => AuditActorAttribution::TYPE_USER,
        ]);
        $this->insertAudit([
            'id' => '01J20000000000000000000000',
            'action' => 'patient.register',
            'actor_user_id' => $user->id,
            'actor_type' => AuditActorAttribution::TYPE_SERVICE,
            'actor_reference' => AuditActorAttribution::SYNTHETIC_RESET_SERVICE,
        ]);
        $this->insertAudit([
            'id' => '01J30000000000000000000000',
            'action' => 'teaching.reset.started',
            'actor_type' => AuditActorAttribution::TYPE_SERVICE,
            'actor_reference' => AuditActorAttribution::REBUILD_ADMIN_SERVICE,
        ]);
        $this->insertAudit([
            'id' => '01J40000000000000000000000',
            'action' => 'patient.register',
        ]);
        $this->insertAudit([
            'id' => '01J50000000000000000000000',
            'action' => 'teaching.reset.completed',
        ]);
        $this->insertAudit([
            'id' => '01J60000000000000000000000',
            'action' => 'patient.register',
            'actor_user_id' => $user->id,
            'actor_type' => AuditActorAttribution::TYPE_USER,
            'actor_reference' => '01J00000000000000000000000',
        ]);

        [$exitCode, $report] = $this->runJson();

        $this->assertSame(3, $exitCode);
        $this->assertSame('BLOCKING', $report['result']);
        $this->assertSame(6, $report['counts']['BLOCKING']);
        $this->assertSame(1, $report['blocking_reason_counts']['PARTIAL_ATTRIBUTION']);
        $this->assertSame(2, $report['blocking_reason_counts']['USER_ATTRIBUTION_MISMATCH']);
        $this->assertSame(1, $report['blocking_reason_counts']['SERVICE_ATTRIBUTION_MISMATCH']);
        $this->assertSame(2, $report['blocking_reason_counts']['ACTOR_REQUIRED_BUT_MISSING']);
        $this->assertFalse($report['contract_ready']);
        $this->assertFalse($report['backfill_ready']);
    }

    public function test_invalid_identifiers_block_without_disclosing_the_values(): void
    {
        $user = User::factory()->create();
        $invalidPublicId = '01j00000000000000000000000';
        DB::table(SchemaQualifier::table('users'))
            ->where('id', $user->id)
            ->update(['public_id' => $invalidPublicId]);

        $invalidAuditId = '01j10000000000000000000000';
        $this->insertAudit([
            'id' => $invalidAuditId,
            'action' => 'patient.register',
            'actor_user_id' => $user->id,
        ]);
        $this->insertAudit([
            'id' => '01J20000000000000000000000',
            'action' => 'patient.register',
            'actor_user_id' => $user->id,
        ]);

        [$exitCode, $report, $output] = $this->runJson();

        $this->assertSame(3, $exitCode);
        $this->assertSame(1, $report['blocking_reason_counts']['INVALID_AUDIT_ID']);
        $this->assertSame(1, $report['blocking_reason_counts']['INVALID_USER_PUBLIC_ID']);
        $this->assertStringNotContainsString($invalidAuditId, $output);
        $this->assertStringNotContainsString($invalidPublicId, $output);
    }

    public function test_scan_is_insertion_order_independent_and_does_not_mutate_rows(): void
    {
        $user = User::factory()->create();
        $rows = [
            [
                'id' => '01J10000000000000000000000',
                'action' => 'patient.register',
                'actor_user_id' => $user->id,
            ],
            [
                'id' => '01J20000000000000000000000',
                'action' => 'teaching.reset.started',
            ],
        ];

        foreach (array_reverse($rows) as $row) {
            $this->insertAudit($row);
        }
        $before = DB::table(SchemaQualifier::table('audit_events'))->orderBy('id')->get()->toArray();
        [, $first] = $this->runJson();
        $after = DB::table(SchemaQualifier::table('audit_events'))->orderBy('id')->get()->toArray();

        $this->assertEquals($before, $after);

        DB::table(SchemaQualifier::table('audit_events'))->delete();
        foreach ($rows as $row) {
            $this->insertAudit($row);
        }
        [, $second] = $this->runJson();

        $this->assertSame($first['root_digest'], $second['root_digest']);
        $this->assertSame($first['counts'], $second['counts']);
    }

    public function test_scan_crosses_the_streaming_chunk_boundary_without_skipping_rows(): void
    {
        $rows = [];
        for ($index = 0; $index < 501; $index++) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'recorded_at' => now(),
                'actor_user_id' => null,
                'actor_type' => null,
                'actor_reference' => null,
                'action' => 'authorization.rebuild_admin.reconciled',
                'resource_type' => 'user',
                'resource_id' => null,
                'resource_version' => null,
                'outcome' => 'SUCCESS',
                'reason' => null,
                'request_correlation_id' => null,
                'ip_hash' => null,
                'user_agent' => null,
                'metadata' => null,
            ];
        }
        DB::table(SchemaQualifier::table('audit_events'))->insert($rows);

        [$exitCode, $report] = $this->runJson(['--require-current' => true]);

        $this->assertSame(2, $exitCode);
        $this->assertSame(501, $report['rows_scanned']);
        $this->assertSame(501, $report['counts']['BACKFILLABLE_SERVICE']);
    }

    public function test_missing_digest_key_returns_only_a_safe_operational_code(): void
    {
        config(['app.key' => null]);

        [$exitCode, $report, $output] = $this->runJson();

        $this->assertSame(1, $exitCode);
        $this->assertSame('OPERATIONAL_FAILURE', $report['result']);
        $this->assertSame('DIGEST_KEY_UNAVAILABLE', $report['operational_code']);
        $this->assertStringNotContainsString('exception', strtolower($output));
        $this->assertStringNotContainsString('database', strtolower($output));
    }

    public function test_unsafe_runtime_is_rejected_before_scanning(): void
    {
        config(['simulation.synthetic_only' => false]);

        [$exitCode, $report] = $this->runJson();

        $this->assertSame(1, $exitCode);
        $this->assertSame('UNSAFE_RUNTIME', $report['operational_code']);
    }

    public function test_existing_transaction_is_rejected_instead_of_weakening_the_snapshot(): void
    {
        DB::beginTransaction();

        try {
            [$exitCode, $report] = $this->runJson();
        } finally {
            DB::rollBack();
        }

        $this->assertSame(1, $exitCode);
        $this->assertSame('EXISTING_TRANSACTION_UNSAFE', $report['operational_code']);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{int, array<string, mixed>, string}
     */
    private function runJson(array $options = []): array
    {
        $exitCode = Artisan::call('audit:attribution:preflight', [
            '--json' => true,
            ...$options,
        ]);
        $output = trim(Artisan::output());
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($report);
        $this->assertSame(1, substr_count($output, "\n") + 1);

        return [$exitCode, $report, $output];
    }

    /** @param array<string, mixed> $overrides */
    private function insertAudit(array $overrides): void
    {
        DB::table(SchemaQualifier::table('audit_events'))->insert([
            'id' => (string) Str::ulid(),
            'recorded_at' => now(),
            'actor_user_id' => null,
            'actor_type' => null,
            'actor_reference' => null,
            'action' => 'patient.register',
            'resource_type' => 'encounter',
            'resource_id' => null,
            'resource_version' => null,
            'outcome' => 'SUCCESS',
            'reason' => null,
            'request_correlation_id' => null,
            'ip_hash' => null,
            'user_agent' => null,
            'metadata' => null,
            ...$overrides,
        ]);
    }
}
