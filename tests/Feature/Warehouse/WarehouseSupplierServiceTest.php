<?php

namespace Tests\Feature\Warehouse;

use App\Models\Role;
use App\Models\TeachingRoleAccessLease;
use App\Models\User;
use App\Models\WarehouseOperationReceipt;
use App\Models\WarehouseSupplier;
use App\Models\WarehouseSupplierVersion;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Authorization\TeachingRoleAccessLeaseGuard;
use App\Support\Warehouse\WarehouseActorPolicy;
use App\Support\Warehouse\WarehouseActorTransactionFence;
use App\Support\Warehouse\WarehouseAuditEvidenceGuard;
use App\Support\Warehouse\WarehouseAuditUnavailable;
use App\Support\Warehouse\WarehouseDenied;
use App\Support\Warehouse\WarehouseEvidenceFingerprint;
use App\Support\Warehouse\WarehouseMutationScope;
use App\Support\Warehouse\WarehouseOperationCoordinator;
use App\Support\Warehouse\WarehouseOperationOutcome;
use App\Support\Warehouse\WarehouseSchemaMutationScope;
use App\Support\Warehouse\WarehouseSupplierService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class WarehouseSupplierServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)
            && ! Schema::hasTable('warehouse_suppliers')) {
            $this->markTestSkipped(
                'Exact-engine warehouse service tests remain deferred until the governed identity/routine harness enables the migration.',
            );
        }

        $this->seed(RbacSeeder::class);
        config([
            'simulation.warehouse_capability_enabled' => true,
            'simulation.teaching_role_access_commitment_key' => 'warehouse-service-test-commitment-key-2026',
            'simulation.teaching_role_access_environment' => 'test-simulation',
            'simulation.teaching_role_access_release_sha' => str_repeat('a', 40),
            'simulation.teaching_role_access_deployment_url' => 'localhost',
            'simulation.teaching_role_access_canonical_host' => 'localhost',
        ]);
    }

    public function test_supplier_lifecycle_is_versioned_audited_and_historically_replayable(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $service = app(WarehouseSupplierService::class);
        $correlationId = (string) Str::ulid();
        $original = $this->supplierData();

        $created = $service->create($actor, $original, 'supplier-create-001', $correlationId);
        $this->assertFalse($created->replayed);
        $this->assertSame(WarehouseSupplier::ACTIVE, $created->record->state);
        $this->assertSame(1, $created->record->version);
        $this->assertSame('SYN-SUP-001', $created->record->supplier_code);

        $createVersion = WarehouseSupplierVersion::query()->where('supplier_id', $created->record->id)->sole();
        $this->assertNull($createVersion->previous_version_id);
        $this->assertNull($createVersion->previous_content_digest);
        $this->assertSame($correlationId, $createVersion->request_correlation_id);

        $createReceipt = WarehouseOperationReceipt::query()
            ->where('operation', WarehouseSupplierService::OP_CREATE)
            ->sole();
        $createAudit = AuditEvent::query()->whereKey($createReceipt->audit_event_id)->sole();
        $this->assertSame($createReceipt->public_id, $createAudit->metadata['operation_receipt_public_id']);
        $this->assertSame($created->record->public_id, $createAudit->metadata['result_public_id']);
        $this->assertSame($createReceipt->result_digest, $createAudit->warehouse_result_digest_snapshot);
        $this->assertSame(0, $createReceipt->control_total);
        $this->assertSame($correlationId, $createAudit->request_correlation_id);

        $revision = [
            'display_name' => 'PT Pemasok Sintetis Nusantara Baru',
            'synthetic_contact_name' => 'Kontak Demo Dua',
            'synthetic_email' => 'kontak.dua@pemasok.invalid',
        ];
        $revised = $service->revise(
            $created->record->public_id,
            $actor,
            1,
            $created->record->current_content_digest,
            $revision,
            'CONTACT_UPDATED',
            'supplier-revise-001',
            (string) Str::ulid(),
        );
        $this->assertFalse($revised->replayed);
        $this->assertSame(2, $revised->record->version);
        $this->assertSame('SYN-SUP-001', $revised->record->supplier_code);
        $this->assertSame('PT Pemasok Sintetis Nusantara Baru', $revised->record->display_name);

        $secondVersion = WarehouseSupplierVersion::query()
            ->where('supplier_id', $created->record->id)
            ->where('version', 2)
            ->sole();
        $this->assertSame($createVersion->id, $secondVersion->previous_version_id);
        $this->assertSame(1, $secondVersion->previous_version_number);
        $this->assertSame($createVersion->content_digest, $secondVersion->previous_content_digest);

        $retired = $service->retire(
            $created->record->public_id,
            $actor,
            2,
            $revised->record->current_content_digest,
            'supplier-retire-001',
            (string) Str::ulid(),
        );
        $this->assertFalse($retired->replayed);
        $this->assertSame(WarehouseSupplier::RETIRED, $retired->record->state);
        $this->assertSame(3, $retired->record->version);

        $replayed = $service->create($actor, $original, 'supplier-create-001', (string) Str::ulid());
        $this->assertTrue($replayed->replayed);
        $this->assertSame($created->record->public_id, $replayed->record->public_id);
        $this->assertSame(1, $replayed->record->version);
        $this->assertSame(WarehouseSupplier::ACTIVE, $replayed->record->state);
        $this->assertSame('PT Pemasok Sintetis Nusantara', $replayed->record->display_name);
        $this->assertSame($createVersion->content_digest, $replayed->record->current_content_digest);

        $replayedRevision = $service->revise(
            $created->record->public_id,
            $actor,
            1,
            $created->record->current_content_digest,
            $revision,
            'CONTACT_UPDATED',
            'supplier-revise-001',
            (string) Str::ulid(),
        );
        $this->assertTrue($replayedRevision->replayed);
        $this->assertSame(2, $replayedRevision->record->version);
        $this->assertSame(WarehouseSupplier::ACTIVE, $replayedRevision->record->state);
        $this->assertSame('PT Pemasok Sintetis Nusantara Baru', $replayedRevision->record->display_name);

        $this->assertDatabaseCount('warehouse_suppliers', 1);
        $this->assertDatabaseCount('warehouse_supplier_versions', 3);
        $this->assertDatabaseCount('warehouse_operation_receipts', 3);
        $this->assertSame(3, AuditEvent::query()->where('action', 'warehouse.workflow.mutate')->where('outcome', 'SUCCESS')->count());
    }

    public function test_same_key_changed_payload_stale_preconditions_stable_code_and_terminal_retirement_fail_closed(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $service = app(WarehouseSupplierService::class);
        $supplier = $service->create($actor, $this->supplierData(), 'supplier-create-002')->record;

        $this->assertDenied('idempotency_key_conflict', fn () => $service->create(
            $actor,
            [...$this->supplierData(), 'display_name' => 'Nama muatan berbeda'],
            'supplier-create-002',
        ));
        $normalizedPayload = [
            'supplier' => [
                'supplier_code' => 'SYN-SUP-001',
                'display_name' => 'PT Pemasok Sintetis Nusantara',
                'synthetic_contact_name' => 'Kontak Demo Satu',
                'synthetic_email' => 'kontak@pemasok.invalid',
                'synthetic_phone' => '000-000-000',
                'synthetic_reference' => 'REF-SYN-001',
            ],
            'reason_code' => 'SUPPLIER_CREATED',
        ];
        $this->assertDenied('idempotency_key_conflict', fn () => app(WarehouseOperationCoordinator::class)->execute(
            $actor,
            WarehouseSupplierService::OP_CREATE,
            null,
            'supplier-create-002',
            $normalizedPayload,
            WarehouseOperationReceipt::RESULT_PURCHASE_ORDER,
            app(WarehouseActorPolicy::class)->authorizeSupplierOperation($actor, WarehouseSupplierService::OP_CREATE),
            fn () => $this->fail('A conflicting result type must replay before invoking the write.'),
        ));
        $this->assertDenied('stale_version', fn () => $service->revise(
            $supplier->public_id,
            $actor,
            99,
            $supplier->current_content_digest,
            ['display_name' => 'Nama Baru'],
            'DETAILS_UPDATED',
            'supplier-revise-stale-version',
        ));
        $this->assertDenied('stale_fingerprint', fn () => $service->revise(
            $supplier->public_id,
            $actor,
            1,
            str_repeat('a', 64),
            ['display_name' => 'Nama Baru'],
            'DETAILS_UPDATED',
            'supplier-revise-stale-digest',
        ));
        $this->assertDenied('validation_failed', fn () => $service->revise(
            $supplier->public_id,
            $actor,
            1,
            $supplier->current_content_digest,
            ['supplier_code' => 'SYN-SUP-CHANGED', 'display_name' => 'Nama Baru'],
            'DETAILS_UPDATED',
            'supplier-revise-stable-code',
        ));

        $retired = $service->retire(
            $supplier->public_id,
            $actor,
            1,
            $supplier->current_content_digest,
            'supplier-retire-002',
        )->record;
        $this->assertDenied('master_retired', fn () => $service->revise(
            $retired->public_id,
            $actor,
            2,
            $retired->current_content_digest,
            ['display_name' => 'Tidak Boleh Berubah'],
            'DETAILS_UPDATED',
            'supplier-revise-retired',
        ));

        $this->assertDatabaseCount('warehouse_suppliers', 1);
        $this->assertDatabaseCount('warehouse_supplier_versions', 2);
    }

    public function test_idempotency_key_scope_is_per_actor(): void
    {
        $first = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $second = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $service = app(WarehouseSupplierService::class);

        $firstResult = $service->create($first, $this->supplierData(), 'supplier-actor-key')->record;
        $secondResult = $service->create(
            $second,
            [...$this->supplierData(), 'supplier_code' => 'SYN-SUP-002', 'display_name' => 'Pemasok Sintetis Kedua'],
            'supplier-actor-key',
        )->record;

        $this->assertNotSame($firstResult->public_id, $secondResult->public_id);
        $this->assertDatabaseCount('warehouse_suppliers', 2);
        $this->assertDatabaseCount('warehouse_operation_receipts', 2);
    }

    public function test_duplicate_code_and_non_synthetic_email_are_rejected_without_partial_supplier_rows(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $service = app(WarehouseSupplierService::class);
        $service->create($actor, $this->supplierData(), 'supplier-create-003');

        $this->assertDenied('supplier_code_conflict', fn () => $service->create(
            $actor,
            [...$this->supplierData(), 'display_name' => 'Duplikat'],
            'supplier-create-duplicate',
        ));
        $this->assertDenied('validation_failed', fn () => $service->create(
            $actor,
            [...$this->supplierData(), 'supplier_code' => 'SYN-SUP-REAL', 'synthetic_email' => 'contact@example.com'],
            'supplier-create-real-email',
        ));

        $this->assertDatabaseCount('warehouse_suppliers', 1);
        $this->assertDatabaseCount('warehouse_supplier_versions', 1);
        $this->assertDatabaseCount('warehouse_operation_receipts', 1);
    }

    public function test_supplier_marker_policy_optional_blanks_utf8_and_closed_reason_vocabulary(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $service = app(WarehouseSupplierService::class);
        $supplier = $service->create($actor, $this->supplierData(), 'supplier-policy-create')->record;

        $cleared = $service->revise(
            $supplier->public_id,
            $actor,
            1,
            $supplier->current_content_digest,
            [
                'synthetic_contact_name' => "\u{00A0}",
                'synthetic_email' => "\t",
                'synthetic_phone' => " \n ",
            ],
            'CONTACT_UPDATED',
            'supplier-policy-clear-contact',
        )->record;
        $this->assertNull($cleared->synthetic_contact_name);
        $this->assertNull($cleared->synthetic_email);
        $this->assertNull($cleared->synthetic_phone);

        $invalidCases = [
            [...$this->supplierData(), 'supplier_code' => 'REAL-SUP-001'],
            [...$this->supplierData(), 'supplier_code' => 'SYN-SUP-PHONE', 'synthetic_phone' => '0812-3456'],
            [...$this->supplierData(), 'supplier_code' => 'SYN-SUP-REF', 'synthetic_reference' => 'REF-REAL-001'],
            [...$this->supplierData(), 'supplier_code' => 'SYN-SUP-UTF8', 'display_name' => "\xB1\x31"],
        ];
        $missingReference = $this->supplierData();
        unset($missingReference['synthetic_reference']);
        $invalidCases[] = $missingReference;
        foreach ($invalidCases as $offset => $invalid) {
            $this->assertDenied('validation_failed', fn () => $service->create(
                $actor,
                $invalid,
                "supplier-policy-invalid-{$offset}",
            ));
        }

        $this->assertDenied('validation_failed', fn () => $service->revise(
            $cleared->public_id,
            $actor,
            2,
            $cleared->current_content_digest,
            ['display_name' => 'Nama Baru Tidak Sah'],
            'NAME_UPDATED',
            'supplier-policy-open-reason',
        ));
        $this->assertDatabaseCount('warehouse_suppliers', 1);
        $this->assertDatabaseCount('warehouse_supplier_versions', 2);
        $this->assertDatabaseCount('warehouse_operation_receipts', 2);
    }

    public function test_retire_enforces_the_simulation_boundary_before_lookup_or_mutation(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $service = app(WarehouseSupplierService::class);
        $supplier = $service->create($actor, $this->supplierData(), 'supplier-boundary-create')->record;
        config()->set('simulation.synthetic_only', false);

        $this->assertDenied('validation_failed', fn () => $service->retire(
            $supplier->public_id,
            $actor,
            1,
            $supplier->current_content_digest,
            'supplier-boundary-retire',
        ));

        $supplier->refresh();
        $this->assertSame(WarehouseSupplier::ACTIVE, $supplier->state);
        $this->assertSame(1, $supplier->version);
        $this->assertDatabaseCount('warehouse_supplier_versions', 1);
        $this->assertDatabaseCount('warehouse_operation_receipts', 1);
    }

    public function test_revise_validates_every_ancestor_in_the_supplier_version_chain(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $supplier = $this->supplierWithCorruptFirstOfThreeVersions($actor);

        $this->assertDenied('evidence_fingerprint_invalid', fn () => app(WarehouseSupplierService::class)->revise(
            $supplier->public_id,
            $actor,
            3,
            $supplier->current_content_digest,
            ['display_name' => 'Versi Empat'],
            'DETAILS_UPDATED',
            'supplier-corrupt-chain-revise',
        ));
        $this->assertDenied('evidence_fingerprint_invalid', fn () => app(WarehouseSupplierService::class)->retire(
            $supplier->public_id,
            $actor,
            3,
            $supplier->current_content_digest,
            'supplier-corrupt-chain-retire',
        ));

        $this->assertDatabaseCount('warehouse_supplier_versions', 3);
        $this->assertDatabaseCount('warehouse_operation_receipts', 0);
    }

    public function test_post_conflict_replay_approximation_recovers_only_the_exact_concurrent_result(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $service = app(WarehouseSupplierService::class);
        $created = $service->create($actor, $this->supplierData(), 'supplier-concurrent-create')->record;
        $revised = $service->revise(
            $created->public_id,
            $actor,
            1,
            $created->current_content_digest,
            ['display_name' => 'Versi Konkuren Dua'],
            'DETAILS_UPDATED',
            'supplier-concurrent-revise',
        )->record;
        $service->retire(
            $created->public_id,
            $actor,
            2,
            $revised->current_content_digest,
            'supplier-concurrent-retire',
        );
        $method = new \ReflectionMethod(WarehouseOperationCoordinator::class, 'replayAfterConflict');
        $method->setAccessible(true);
        $coordinator = app(WarehouseOperationCoordinator::class);

        foreach (WarehouseOperationReceipt::query()->orderBy('result_version')->get() as $receipt) {
            $authorization = app(WarehouseActorPolicy::class)->authorizeSupplierOperation($actor, $receipt->operation);
            $recovered = $method->invoke(
                $coordinator,
                $actor,
                $receipt->operation,
                $receipt->idempotency_key,
                $receipt->payload_digest,
                WarehouseOperationReceipt::RESULT_SUPPLIER,
                $authorization,
            );
            $this->assertTrue($recovered->replayed);
            $this->assertSame($receipt->result_public_id, $recovered->record->public_id);
            $this->assertSame($receipt->result_version, $recovered->record->version);
        }

        $createReceipt = WarehouseOperationReceipt::query()
            ->where('operation', WarehouseSupplierService::OP_CREATE)
            ->sole();

        $this->assertDenied('idempotency_key_conflict', fn () => $method->invoke(
            $coordinator,
            $actor,
            WarehouseSupplierService::OP_CREATE,
            'supplier-concurrent-create',
            str_repeat('f', 64),
            WarehouseOperationReceipt::RESULT_SUPPLIER,
            app(WarehouseActorPolicy::class)->authorizeSupplierOperation($actor, WarehouseSupplierService::OP_CREATE),
        ));
        $this->assertNull($method->invoke(
            $coordinator,
            $actor,
            WarehouseSupplierService::OP_CREATE,
            'supplier-concurrent-missing',
            $createReceipt->payload_digest,
            WarehouseOperationReceipt::RESULT_SUPPLIER,
            app(WarehouseActorPolicy::class)->authorizeSupplierOperation($actor, WarehouseSupplierService::OP_CREATE),
        ));
    }

    public function test_receipt_failure_after_audit_rolls_back_the_unbound_success_audit(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $supplier = app(WarehouseSupplierService::class)
            ->create($actor, $this->supplierData(), 'supplier-receipt-failure-create')
            ->record;
        $successBefore = AuditEvent::query()->where('outcome', 'SUCCESS')->count();

        $this->assertDenied('concurrent_state_conflict', fn () => app(WarehouseOperationCoordinator::class)->execute(
            $actor,
            WarehouseSupplierService::OP_CREATE,
            null,
            'supplier-receipt-failure-second-key',
            ['receipt_failure_probe' => true],
            WarehouseOperationReceipt::RESULT_SUPPLIER,
            app(WarehouseActorPolicy::class)->authorizeSupplierOperation($actor, WarehouseSupplierService::OP_CREATE),
            fn () => new WarehouseOperationOutcome(
                $supplier,
                1,
                WarehouseSupplier::ACTIVE,
                $supplier->current_content_digest,
                ['supplier_public_id' => $supplier->public_id],
            ),
        ));

        $this->assertSame($successBefore, AuditEvent::query()->where('outcome', 'SUCCESS')->count());
        $this->assertDatabaseCount('warehouse_operation_receipts', 1);
        $this->assertDatabaseHas('audit_events', [
            'outcome' => 'DENIED',
            'reason' => 'concurrent_state_conflict',
        ]);
    }

    public function test_transaction_fence_is_first_and_rejects_a_preauthorized_actor_revoked_before_lookup(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $coordinator = app(WarehouseOperationCoordinator::class);
        $authorization = $coordinator->authorize(
            $actor,
            WarehouseSupplierService::OP_CREATE,
            null,
            fn () => app(WarehouseActorPolicy::class)
                ->authorizeSupplierOperation($actor, WarehouseSupplierService::OP_CREATE),
        );
        DB::table('users')->where('id', $actor->id)->update(['status' => 'DISABLED']);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });
        $domainLookupCalled = false;

        try {
            $coordinator->execute(
                $actor,
                WarehouseSupplierService::OP_CREATE,
                null,
                'supplier-fence-revoked-before-lookup',
                ['supplier' => $this->supplierData()],
                WarehouseOperationReceipt::RESULT_SUPPLIER,
                $authorization,
                function () use (&$domainLookupCalled): never {
                    $domainLookupCalled = true;
                    throw new \LogicException('The domain callback must remain unreachable.');
                },
            );
            $this->fail('Expected the transaction fence to reject the revoked actor.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($domainLookupCalled);
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('update users set teaching_access_mutex = teaching_access_mutex', $queries[0]);
        $this->assertSame(0, (int) DB::table('users')->where('id', $actor->id)->value('teaching_access_mutex'));
        $this->assertDatabaseCount('warehouse_operation_receipts', 0);
        $this->assertDatabaseHas('audit_events', [
            'outcome' => 'DENIED',
            'reason' => 'role_not_permitted',
        ]);
    }

    public function test_roster_epoch_revocation_invalidates_a_preauthorized_claim_at_the_transaction_fence(): void
    {
        [$actor, $lease] = $this->activeRosterOfficer();
        session()->put([
            TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY => 7,
            TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY => $lease->public_id,
        ]);
        $coordinator = app(WarehouseOperationCoordinator::class);
        $authorization = $coordinator->authorize(
            $actor,
            WarehouseSupplierService::OP_CREATE,
            null,
            fn () => app(WarehouseActorPolicy::class)
                ->authorizeSupplierOperation($actor, WarehouseSupplierService::OP_CREATE),
        );
        DB::table('users')->where('id', $actor->id)->increment('teaching_access_epoch');
        $domainLookupCalled = false;

        try {
            $coordinator->execute(
                $actor,
                WarehouseSupplierService::OP_CREATE,
                null,
                'supplier-roster-epoch-revoked',
                ['supplier' => $this->supplierData()],
                WarehouseOperationReceipt::RESULT_SUPPLIER,
                $authorization,
                function () use (&$domainLookupCalled): never {
                    $domainLookupCalled = true;
                    throw new \LogicException('The domain callback must remain unreachable.');
                },
            );
            $this->fail('Expected the transaction fence to reject the stale roster epoch.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($domainLookupCalled);
        $this->assertDatabaseCount('warehouse_operation_receipts', 0);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $actor->id,
            'outcome' => 'DENIED',
            'reason' => 'role_not_permitted',
        ]);
    }

    public function test_second_transaction_fence_rolls_back_domain_audit_receipt_and_in_transaction_role_revocation(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $audit = new class($actor->id) extends AuditRecorder
        {
            private bool $revoked = false;

            public function __construct(private readonly int $actorId) {}

            public function recordOnConnection(
                string $connectionName,
                string $action,
                string $resourceType,
                ?string $resourceId = null,
                ?User $actor = null,
                string $outcome = 'SUCCESS',
                ?string $reason = null,
                array $metadata = [],
                ?Request $request = null,
                bool $includeRequestFingerprint = true,
                array $warehouseBinding = [],
            ): ?AuditEvent {
                $event = parent::recordOnConnection(
                    $connectionName,
                    $action,
                    $resourceType,
                    $resourceId,
                    $actor,
                    $outcome,
                    $reason,
                    $metadata,
                    $request,
                    $includeRequestFingerprint,
                    $warehouseBinding,
                );
                if (! $this->revoked && $outcome === 'SUCCESS' && $event instanceof AuditEvent) {
                    $this->revoked = true;
                    DB::table('role_user')->where('user_id', $this->actorId)->delete();
                }

                return $event;
            }
        };
        $this->app->instance(AuditRecorder::class, $audit);

        try {
            app(WarehouseSupplierService::class)->create(
                $actor,
                $this->supplierData(),
                'supplier-second-fence-rollback',
            );
            $this->fail('Expected the second transaction fence to reject the revoked role.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('warehouse_suppliers', 0);
        $this->assertDatabaseCount('warehouse_supplier_versions', 0);
        $this->assertDatabaseCount('warehouse_operation_receipts', 0);
        $this->assertDatabaseHas('role_user', ['user_id' => $actor->id]);
        $this->assertSame(0, AuditEvent::query()->where('outcome', 'SUCCESS')->count());
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $actor->id,
            'outcome' => 'DENIED',
            'reason' => 'role_not_permitted',
        ]);
    }

    public function test_exact_engine_actor_fence_fails_closed_without_a_configured_security_definer_routine(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $authorization = app(WarehouseActorPolicy::class)
            ->authorizeSupplierOperation($actor, WarehouseSupplierService::OP_CREATE);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('getName')->once()->andReturn('warehouse_writer');
        config()->set('database.connections.warehouse_writer.warehouse_actor_fence_routine', null);

        try {
            app(WarehouseActorTransactionFence::class)->assertAuthorized(
                $connection,
                $actor,
                WarehouseSupplierService::OP_CREATE,
                $authorization,
            );
            $this->fail('Expected an exact-engine fence without its security-definer routine to fail closed.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('security-definer actor fence routine', $exception->getMessage());
        }
    }

    public function test_authorization_denial_is_audited_before_supplier_lookup(): void
    {
        $wrongActor = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $supplierId = (string) Str::ulid();
        $supplierQueries = 0;
        DB::listen(function ($query) use (&$supplierQueries): void {
            if (str_contains(mb_strtolower($query->sql), 'warehouse_suppliers')) {
                $supplierQueries++;
            }
        });

        try {
            app(WarehouseSupplierService::class)->retire(
                $supplierId,
                $wrongActor,
                1,
                str_repeat('a', 64),
                'supplier-retire-forbidden',
            );
            $this->fail('Expected exact-role authorization denial.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $supplierQueries);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'warehouse.workflow.mutate',
            'resource_type' => 'warehouse_record',
            'resource_id' => $supplierId,
            'actor_user_id' => $wrongActor->id,
            'outcome' => 'DENIED',
            'reason' => 'role_not_permitted',
        ]);
    }

    public function test_audit_failure_rolls_back_supplier_head_version_and_receipt(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $audit = Mockery::mock(AuditRecorder::class)->makePartial();
        $audit->shouldReceive('recordOnConnection')->once()->andReturnNull();
        $this->app->instance(AuditRecorder::class, $audit);

        try {
            app(WarehouseSupplierService::class)->create($actor, $this->supplierData(), 'supplier-create-audit-failure');
            $this->fail('Expected required audit failure.');
        } catch (WarehouseAuditUnavailable) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('warehouse_suppliers', 0);
        $this->assertDatabaseCount('warehouse_supplier_versions', 0);
        $this->assertDatabaseCount('warehouse_operation_receipts', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_revise_and_retire_audit_failures_roll_back_heads_versions_and_receipts(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $service = app(WarehouseSupplierService::class);
        $revisedCandidate = $service->create($actor, $this->supplierData(), 'supplier-rollback-revise-create')->record;
        $retiredCandidate = $service->create(
            $actor,
            [...$this->supplierData(), 'supplier_code' => 'SYN-SUP-ROLLBACK-2'],
            'supplier-rollback-retire-create',
        )->record;
        $audit = Mockery::mock(AuditRecorder::class)->makePartial();
        $audit->shouldReceive('recordOnConnection')->twice()->andReturnNull();
        $this->app->instance(AuditRecorder::class, $audit);
        $failingService = app(WarehouseSupplierService::class);

        foreach ([
            fn () => $failingService->revise(
                $revisedCandidate->public_id,
                $actor,
                1,
                $revisedCandidate->current_content_digest,
                ['display_name' => 'Perubahan Dibatalkan'],
                'DETAILS_UPDATED',
                'supplier-rollback-revise',
            ),
            fn () => $failingService->retire(
                $retiredCandidate->public_id,
                $actor,
                1,
                $retiredCandidate->current_content_digest,
                'supplier-rollback-retire',
            ),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Expected required audit failure.');
            } catch (WarehouseAuditUnavailable) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, $revisedCandidate->fresh()->version);
        $this->assertSame(WarehouseSupplier::ACTIVE, $retiredCandidate->fresh()->state);
        $this->assertDatabaseCount('warehouse_supplier_versions', 2);
        $this->assertDatabaseCount('warehouse_operation_receipts', 2);
        $this->assertSame(2, AuditEvent::query()->where('outcome', 'SUCCESS')->count());
    }

    public function test_replay_recomputes_and_rejects_mismatched_audit_metadata(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $data = $this->supplierData();
        app(WarehouseSupplierService::class)->create($actor, $data, 'supplier-create-corruption');
        $receipt = WarehouseOperationReceipt::query()->sole();
        $metadata = AuditEvent::query()->whereKey($receipt->audit_event_id)->value('metadata');
        $metadata['result_content_digest'] = str_repeat('f', 64);
        $this->corruptWarehouseAuditMetadataForReplayFixture($receipt->audit_event_id, $metadata);

        $this->assertDenied('receipt_corrupt', fn () => app(WarehouseSupplierService::class)->create(
            $actor,
            $data,
            'supplier-create-corruption',
        ));

        $this->assertDatabaseCount('warehouse_suppliers', 1);
        $this->assertDatabaseCount('warehouse_supplier_versions', 1);
        $this->assertDatabaseCount('warehouse_operation_receipts', 1);
    }

    public function test_multi_version_historical_replay_rejects_a_head_that_no_longer_matches_immutable_evidence(): void
    {
        $actor = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $data = $this->supplierData();
        $service = app(WarehouseSupplierService::class);
        $created = $service->create($actor, $data, 'supplier-history-create')->record;
        $revised = $service->revise(
            $created->public_id,
            $actor,
            1,
            $created->current_content_digest,
            ['display_name' => 'Versi Dua'],
            'DETAILS_UPDATED',
            'supplier-history-revise',
        )->record;
        $service->retire(
            $created->public_id,
            $actor,
            2,
            $revised->current_content_digest,
            'supplier-history-retire',
        );
        WarehouseMutationScope::run(fn () => DB::table('warehouse_suppliers')
            ->where('public_id', $created->public_id)
            ->update(['display_name' => 'Head Tanpa Versi']));

        $this->assertDenied('receipt_corrupt', fn () => $service->create(
            $actor,
            $data,
            'supplier-history-create',
        ));
        $this->assertDatabaseCount('warehouse_supplier_versions', 3);
        $this->assertDatabaseCount('warehouse_operation_receipts', 3);
    }

    public function test_exact_engine_service_contract_keeps_all_mutations_and_audits_on_the_scoped_writer(): void
    {
        $coordinator = file_get_contents(app_path('Support/Warehouse/WarehouseOperationCoordinator.php'));
        $service = file_get_contents(app_path('Support/Warehouse/WarehouseSupplierService.php'));
        $this->assertIsString($coordinator);
        $this->assertIsString($service);
        $this->assertStringContainsString('WarehouseMutationScope::assertWriterConnection()', $coordinator);
        $this->assertStringContainsString('$connection->transaction(', $coordinator);
        $this->assertStringContainsString('$callback($scoped ?? $connection)', $coordinator);
        $this->assertStringContainsString('$this->audit->recordOnConnection(', $coordinator);
        $this->assertStringContainsString('$connection->getName()', $coordinator);
        $this->assertStringNotContainsString('DB::setDefaultConnection', $coordinator);
        $this->assertStringContainsString('WarehouseSupplier::on($connection->getName())', $service);
        $this->assertStringContainsString('WarehouseSupplierVersion::on($connection->getName())', $service);
        $this->assertStringNotContainsString('DB::setDefaultConnection', $service);
        $fence = file_get_contents(app_path('Support/Warehouse/WarehouseActorTransactionFence.php'));
        $this->assertIsString($fence);
        $this->assertStringContainsString('teaching_access_mutex = teaching_access_mutex', $fence);
        $this->assertStringContainsString('warehouse_actor_fence_routine', $fence);
        $this->assertStringContainsString('security-definer actor fence routine', $fence);
        $this->assertStringNotContainsString('DB::setDefaultConnection', $fence);
        $this->assertSame(
            ['DETAILS_UPDATED', 'CONTACT_UPDATED', 'REFERENCE_UPDATED', 'DETAILS_AND_CONTACT_UPDATED'],
            (new \ReflectionClass(WarehouseSupplierService::class))->getConstant('REVISION_REASONS'),
        );
    }

    private function supplierWithCorruptFirstOfThreeVersions(User $actor): WarehouseSupplier
    {
        $fingerprints = app(WarehouseEvidenceFingerprint::class);

        return DB::transaction(function () use ($actor, $fingerprints): WarehouseSupplier {
            return WarehouseMutationScope::run(function () use ($actor, $fingerprints): WarehouseSupplier {
                $now = now()->startOfSecond();
                $supplier = new WarehouseSupplier;
                $supplier->public_id = (string) Str::ulid();
                $supplier->forceFill([
                    'supplier_code' => 'SYN-SUP-CORRUPT',
                    'display_name' => 'Versi Tiga',
                    'synthetic_contact_name' => null,
                    'synthetic_email' => null,
                    'synthetic_phone' => null,
                    'synthetic_reference' => 'REF-SYN-CORRUPT',
                    'state' => WarehouseSupplier::ACTIVE,
                    'version' => 3,
                    'current_content_digest' => str_repeat('0', 64),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $supplier->save();

                $first = $this->makeVersion($supplier, $actor, 1, 'Versi Satu', null, 'SUPPLIER_CREATED', $now);
                $actualFirstDigest = $fingerprints->supplierVersion($first, $supplier->public_id);
                $first->content_digest = ($actualFirstDigest[0] === 'a' ? 'b' : 'a').substr($actualFirstDigest, 1);
                $first->save();

                $second = $this->makeVersion($supplier, $actor, 2, 'Versi Dua', $first, 'DETAILS_UPDATED', $now);
                $second->content_digest = $fingerprints->supplierVersion($second, $supplier->public_id);
                $second->save();

                $third = $this->makeVersion($supplier, $actor, 3, 'Versi Tiga', $second, 'DETAILS_UPDATED', $now);
                $third->content_digest = $fingerprints->supplierVersion($third, $supplier->public_id);
                $third->save();

                $supplier->forceFill([
                    'display_name' => $third->display_name,
                    'version' => 3,
                    'current_content_digest' => $third->content_digest,
                    'updated_at' => $now,
                ])->save();

                return $supplier;
            });
        });
    }

    /** @param array<string, mixed> $metadata */
    private function corruptWarehouseAuditMetadataForReplayFixture(string $auditId, array $metadata): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        DB::transaction(function () use ($auditId, $metadata): void {
            $this->assertSame(
                WarehouseAuditEvidenceGuard::ACTION,
                DB::table('audit_events')->where('id', $auditId)->value('action'),
            );

            WarehouseSchemaMutationScope::run(fn () => WarehouseAuditEvidenceGuard::remove());
            try {
                $this->assertSame(1, DB::table('audit_events')->where('id', $auditId)->update([
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                ]));
            } finally {
                WarehouseSchemaMutationScope::run(fn () => WarehouseAuditEvidenceGuard::install());
            }
        });
    }

    private function makeVersion(
        WarehouseSupplier $supplier,
        User $actor,
        int $number,
        string $displayName,
        ?WarehouseSupplierVersion $previous,
        string $reason,
        mixed $createdAt,
    ): WarehouseSupplierVersion {
        $version = new WarehouseSupplierVersion;
        $version->public_id = (string) Str::ulid();
        $version->forceFill([
            'supplier_id' => $supplier->id,
            'actor_user_id' => $actor->id,
            'version' => $number,
            'supplier_code_snapshot' => $supplier->supplier_code,
            'display_name' => $displayName,
            'synthetic_contact_name' => null,
            'synthetic_email' => null,
            'synthetic_phone' => null,
            'synthetic_reference' => 'REF-SYN-CORRUPT',
            'state' => WarehouseSupplier::ACTIVE,
            'reason_code' => $reason,
            'previous_version_id' => $previous?->id,
            'previous_version_number' => $previous?->version,
            'previous_content_digest' => $previous?->content_digest,
            'request_correlation_id' => null,
            'created_at' => $createdAt,
        ]);

        return $version;
    }

    /** @return array<string, mixed> */
    private function supplierData(): array
    {
        return [
            'supplier_code' => ' syn-sup-001 ',
            'display_name' => ' PT Pemasok Sintetis Nusantara ',
            'synthetic_contact_name' => 'Kontak Demo Satu',
            'synthetic_email' => 'KONTAK@PEMASOK.INVALID',
            'synthetic_phone' => '000-000-000',
            'synthetic_reference' => 'REF-SYN-001',
        ];
    }

    private function actor(string $roleSlug): User
    {
        $actor = User::factory()->create(['is_system_administrator' => false, 'status' => 'ACTIVE']);
        $actor->roles()->sync([Role::query()->where('slug', $roleSlug)->sole()->id]);

        return $actor->fresh();
    }

    /** @return array{User, TeachingRoleAccessLease} */
    private function activeRosterOfficer(): array
    {
        $guard = app(TeachingRoleAccessLeaseGuard::class);
        $now = $guard->databaseEpoch();
        $leasePublicId = (string) Str::ulid();
        $actor = User::factory()->create([
            'email' => 'procurement.officer.demo@example.invalid',
            'password' => 'Warehouse-Teaching-Access-2026',
            'status' => 'TEACHING_ACTIVE',
            'is_system_administrator' => false,
        ]);
        $actor->forceFill([
            'teaching_access_epoch' => 7,
            'teaching_access_mutex' => 7,
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER,
            'teaching_access_lease_public_id' => $leasePublicId,
            'teaching_access_expires_at_epoch' => $now + 900,
        ])->save();
        $actor->roles()->sync([
            Role::query()->where('slug', RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER)->sole()->id,
        ]);
        $lease = TeachingRoleAccessLease::query()->create([
            'public_id' => $leasePublicId,
            'user_id' => $actor->id,
            'expected_role' => RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER,
            'credential_commitment' => $guard->credentialCommitment('Warehouse-Teaching-Access-2026'),
            'password_state_commitment' => $guard->passwordStateCommitment($actor->password),
            'environment' => 'test-simulation',
            'release_sha' => str_repeat('a', 40),
            'deployment_url' => 'localhost',
            'canonical_host' => 'localhost',
            'status' => 'ACTIVE',
            'active_slot' => 1,
            'activated_at_epoch' => $now,
            'expires_at_epoch' => $now + 900,
            'operator' => 'warehouse-service-test',
            'reason' => 'Verify roster transaction fencing.',
        ]);

        return [$actor->fresh(), $lease];
    }

    private function assertDenied(string $reason, \Closure $callback): void
    {
        try {
            $callback();
            $this->fail("Expected warehouse denial [{$reason}].");
        } catch (WarehouseDenied $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }
}
