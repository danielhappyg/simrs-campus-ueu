<?php

namespace Tests\Feature\Database;

use App\Models\User;
use App\Models\WarehouseOperationReceipt;
use App\Support\Database\SchemaQualifier;
use App\Support\Warehouse\WarehouseMutationScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WarehouseCustodyIntegrityConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)
            && ! Schema::hasTable(SchemaQualifier::table('warehouse_suppliers'))) {
            $this->markTestSkipped(
                'Exact-engine warehouse schema tests remain deferred until the governed identity/routine harness enables the migration.',
            );
        }
    }

    public function test_sqlite_check_sources_cover_pair_shape_sign_bucket_and_actor_separation(): void
    {
        $movementTrigger = DB::table('sqlite_master')
            ->where('type', 'trigger')->where('name', 'psm_state_ck_insert')->value('sql');
        $this->assertIsString($movementTrigger);
        $this->assertStringContainsString('NEW.warehouse_movement_set_id IS NOT NULL', $movementTrigger);
        $this->assertStringContainsString("NEW.movement_type='WAREHOUSE_DISPATCH_OUT'", $movementTrigger);
        $this->assertStringContainsString("NEW.pair_leg='SOURCE_OUT'", $movementTrigger);
        $this->assertStringContainsString("NEW.pair_bucket='AVAILABLE'", $movementTrigger);
        $this->assertStringContainsString('NEW.available_delta=0-NEW.pair_quantity', $movementTrigger);
        $this->assertStringContainsString('NEW.transit_delta=NEW.pair_quantity', $movementTrigger);
        $this->assertStringContainsString("NEW.pair_slot IN ('SOURCE','DESTINATION')", $movementTrigger);

        foreach ([
            'wpod_decision_ck_insert' => 'NEW.creator_user_id_snapshot<>NEW.reviewer_user_id',
            'wtd_decision_ck_insert' => 'NEW.dispatcher_user_id_snapshot<>NEW.acceptor_user_id',
            'wsrd_decision_ck_insert' => 'NEW.requester_user_id_snapshot<>NEW.approver_user_id',
            'wurd_decision_ck_insert' => 'NEW.requester_user_id_snapshot<>NEW.acceptor_user_id',
            'wcd_decision_ck_insert' => 'NEW.requester_user_id_snapshot<>NEW.supervisor_user_id',
            'wcc_values_ck_insert' => 'NEW.supervisor_user_id_snapshot<>NEW.actor_user_id',
        ] as $trigger => $inequality) {
            $sql = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger)->value('sql');
            $this->assertIsString($sql, $trigger);
            $this->assertStringContainsString($inequality, $sql, $trigger);
        }

        foreach (['wsv_values_ck_insert', 'wpov_state_ck_insert'] as $trigger) {
            $sql = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger)->value('sql');
            $this->assertIsString($sql);
            $this->assertStringContainsString('NEW.version>1 AND NEW.previous_version_id IS NOT NULL', $sql);
            $this->assertStringContainsString('NEW.previous_version_number=NEW.version-1', $sql);
        }

        $supplierVersionCheck = DB::table('sqlite_master')
            ->where('type', 'trigger')->where('name', 'wsv_values_ck_insert')->value('sql');
        $this->assertIsString($supplierVersionCheck);
        foreach ([
            'SUPPLIER_CREATED', 'DETAILS_UPDATED', 'CONTACT_UPDATED',
            'REFERENCE_UPDATED', 'DETAILS_AND_CONTACT_UPDATED', 'SUPPLIER_RETIRED',
        ] as $reason) {
            $this->assertStringContainsString("'{$reason}'", $supplierVersionCheck);
        }
    }

    public function test_pair_parent_binds_exact_set_custody_quantity_slots_and_conserved_legs(): void
    {
        [$userId, $stockLotId, $custodyLotId, $movementSetId, $pairId, $pairPublicId] = $this->createMovementContext();

        foreach ([
            [
                'available_delta' => 5, 'warehouse_movement_pair_id' => $pairId,
                'pair_public_id' => $pairPublicId, 'pair_type' => 'WAREHOUSE_DISPATCH',
                'pair_quantity' => 5, 'pair_slot' => 'SOURCE', 'pair_leg' => 'SOURCE_OUT', 'pair_bucket' => 'AVAILABLE',
            ],
            [
                'available_delta' => -5, 'warehouse_movement_pair_id' => null,
                'pair_public_id' => null, 'pair_type' => null,
                'pair_quantity' => null, 'pair_slot' => null, 'pair_leg' => null, 'pair_bucket' => null,
            ],
            [
                'available_delta' => -4, 'warehouse_movement_pair_id' => $pairId,
                'pair_public_id' => $pairPublicId, 'pair_type' => 'WAREHOUSE_DISPATCH',
                'pair_quantity' => 4, 'pair_slot' => 'SOURCE', 'pair_leg' => 'SOURCE_OUT', 'pair_bucket' => 'AVAILABLE',
            ],
        ] as $case) {
            try {
                WarehouseMutationScope::run(fn () => DB::table('pharmacy_stock_movements')->insert([
                    'public_id' => (string) Str::ulid(),
                    'stock_lot_id' => $stockLotId,
                    'actor_user_id' => $userId,
                    'handover_item_id' => null,
                    'movement_type' => 'WAREHOUSE_DISPATCH_OUT',
                    'available_delta' => $case['available_delta'],
                    'quarantined_delta' => 0,
                    'transit_delta' => 0,
                    'available_balance_after' => 5,
                    'quarantined_balance_after' => 0,
                    'transit_balance_after' => 0,
                    'reason_code' => 'TRANSFER_DISPATCH',
                    'source_type' => 'TRANSFER_ITEM',
                    'source_public_id' => (string) Str::ulid(),
                    'custody_chain_public_id' => (string) Str::ulid(),
                    'warehouse_movement_set_id' => $movementSetId,
                    'warehouse_custody_lot_id' => $custodyLotId,
                    'warehouse_movement_pair_id' => $case['warehouse_movement_pair_id'],
                    'pair_public_id' => $case['pair_public_id'],
                    'pair_type' => $case['pair_type'],
                    'pair_quantity' => $case['pair_quantity'],
                    'pair_slot' => $case['pair_slot'],
                    'pair_leg' => $case['pair_leg'],
                    'pair_bucket' => $case['pair_bucket'],
                    'content_digest' => str_repeat('9', 64),
                    'occurred_at' => now(),
                    'created_at' => now(),
                ]));
                $this->fail('Invalid paired movement must be rejected by the database.');
            } catch (QueryException $exception) {
                $this->assertTrue(
                    str_contains($exception->getMessage(), 'psm_state_ck')
                    || str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed')
                );
            }
        }

        foreach ([
            ['movement_type' => 'WAREHOUSE_DISPATCH_OUT', 'available_delta' => -5, 'transit_delta' => 0, 'pair_slot' => 'SOURCE', 'pair_leg' => 'SOURCE_OUT', 'pair_bucket' => 'AVAILABLE'],
            ['movement_type' => 'WAREHOUSE_TRANSIT_IN', 'available_delta' => 0, 'transit_delta' => 5, 'pair_slot' => 'DESTINATION', 'pair_leg' => 'TRANSIT_IN', 'pair_bucket' => 'TRANSIT'],
        ] as $leg) {
            $this->insertPairedMovement($leg + [
                'user_id' => $userId, 'stock_lot_id' => $stockLotId, 'custody_lot_id' => $custodyLotId,
                'movement_set_id' => $movementSetId, 'pair_id' => $pairId, 'pair_public_id' => $pairPublicId,
            ]);
        }

        $deltas = DB::table('pharmacy_stock_movements')->where('warehouse_movement_pair_id', $pairId)
            ->selectRaw('SUM(available_delta) AS available_total, SUM(transit_delta) AS transit_total')->first();
        $this->assertSame(-5, (int) $deltas->available_total);
        $this->assertSame(5, (int) $deltas->transit_total);

        try {
            $this->insertPairedMovement([
                'movement_type' => 'WAREHOUSE_DISPATCH_OUT', 'available_delta' => -5, 'transit_delta' => 0,
                'pair_slot' => 'SOURCE', 'pair_leg' => 'SOURCE_OUT', 'pair_bucket' => 'AVAILABLE',
                'user_id' => $userId, 'stock_lot_id' => $stockLotId, 'custody_lot_id' => $custodyLotId,
                'movement_set_id' => $movementSetId, 'pair_id' => $pairId, 'pair_public_id' => $pairPublicId,
            ]);
            $this->fail('A durable pair may have only one projection per required slot.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_operation_receipts_allow_sequential_revisions_but_retain_idempotency_uniqueness(): void
    {
        $user = User::factory()->create();
        $resultPublicId = (string) Str::ulid();
        $auditEventIds = collect(range(1, 4))->map(fn (): string => (string) Str::ulid());
        foreach ($auditEventIds as $offset => $auditEventId) {
            $version = $offset + 1;
            DB::table('audit_events')->insert([
                'id' => $auditEventId, 'recorded_at' => now(), 'actor_user_id' => $user->id,
                'action' => 'warehouse.workflow.mutate', 'resource_type' => 'warehouse_record',
                'resource_id' => $resultPublicId, 'outcome' => 'SUCCESS',
                'warehouse_operation_snapshot' => 'WAREHOUSE_SUPPLIER_REVISE',
                'warehouse_result_version_snapshot' => $version,
                'warehouse_result_digest_snapshot' => str_repeat((string) $version, 64),
                'warehouse_control_total_snapshot' => $version * 10,
            ]);
        }

        WarehouseMutationScope::run(function () use ($user, $resultPublicId, $auditEventIds): void {
            foreach ([1, 2] as $version) {
                WarehouseOperationReceipt::query()->create([
                    'actor_user_id' => $user->id,
                    'audit_event_id' => $auditEventIds[$version - 1],
                    'audit_action_snapshot' => 'warehouse.workflow.mutate',
                    'audit_resource_type_snapshot' => 'warehouse_record',
                    'operation' => 'WAREHOUSE_SUPPLIER_REVISE',
                    'idempotency_key' => 'supplier-revision-'.$version,
                    'payload_digest' => str_repeat((string) $version, 64),
                    'result_type' => 'SUPPLIER',
                    'result_public_id' => $resultPublicId,
                    'result_version' => $version,
                    'result_state' => 'ACTIVE',
                    'result_digest' => str_repeat((string) $version, 64),
                    'control_total' => $version * 10,
                    'completed_at' => now(),
                ]);
            }
        });

        $index = collect(Schema::getIndexes(SchemaQualifier::table('warehouse_operation_receipts')))
            ->firstWhere('name', 'wor_operation_result_uq');
        $this->assertIsArray($index);
        $this->assertTrue($index['unique']);
        $this->assertSame(['operation', 'result_public_id', 'result_version'], $index['columns']);
        $auditForeign = collect(Schema::getForeignKeys(SchemaQualifier::table('warehouse_operation_receipts')))
            ->firstWhere('columns', ['audit_event_id']);
        $this->assertIsArray($auditForeign);
        $this->assertSame('audit_events', $auditForeign['foreign_table']);
        $this->assertSame('SUPPLIER', WarehouseOperationReceipt::RESULT_SUPPLIER);
        $this->assertDatabaseCount('warehouse_operation_receipts', 2);

        try {
            WarehouseMutationScope::run(fn () => WarehouseOperationReceipt::query()->create([
                'actor_user_id' => $user->id,
                'audit_event_id' => $auditEventIds[2],
                'audit_action_snapshot' => 'warehouse.workflow.mutate',
                'audit_resource_type_snapshot' => 'warehouse_record',
                'operation' => 'WAREHOUSE_SUPPLIER_REVISE',
                'idempotency_key' => 'supplier-revision-2',
                'payload_digest' => str_repeat('3', 64),
                'result_type' => 'SUPPLIER',
                'result_public_id' => $resultPublicId,
                'result_version' => 3,
                'result_state' => 'ACTIVE',
                'result_digest' => str_repeat('3', 64),
                'control_total' => 30,
                'completed_at' => now(),
            ]));
            $this->fail('Actor-operation idempotency keys must remain unique.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            WarehouseMutationScope::run(fn () => WarehouseOperationReceipt::query()->create([
                'actor_user_id' => $user->id,
                'audit_event_id' => $auditEventIds[3],
                'audit_action_snapshot' => 'warehouse.workflow.mutate',
                'audit_resource_type_snapshot' => 'warehouse_record',
                'operation' => 'WAREHOUSE_SUPPLIER_REVISE',
                'idempotency_key' => 'supplier-revision-wrong-result',
                'payload_digest' => str_repeat('4', 64),
                'result_type' => 'PURCHASE_ORDER',
                'result_public_id' => $resultPublicId,
                'result_version' => 4,
                'result_state' => 'ACTIVE',
                'result_digest' => str_repeat('4', 64),
                'control_total' => 40,
                'completed_at' => now(),
            ]));
            $this->fail('Operation/result type mismatches must be rejected.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('wor_result_ck', $exception->getMessage());
        }
    }

    public function test_operation_receipt_rejects_audit_snapshot_cross_wiring(): void
    {
        $user = User::factory()->create();
        $auditId = (string) Str::ulid();
        $resultId = (string) Str::ulid();
        DB::table('audit_events')->insert([
            'id' => $auditId, 'recorded_at' => now(), 'actor_user_id' => $user->id,
            'action' => 'warehouse.workflow.mutate', 'resource_type' => 'warehouse_record',
            'resource_id' => $resultId, 'outcome' => 'SUCCESS',
            'warehouse_operation_snapshot' => 'WAREHOUSE_SUPPLIER_REVISE',
            'warehouse_result_version_snapshot' => 2,
            'warehouse_result_digest_snapshot' => str_repeat('a', 64),
            'warehouse_control_total_snapshot' => 17,
        ]);

        try {
            WarehouseMutationScope::run(fn () => WarehouseOperationReceipt::query()->create([
                'actor_user_id' => $user->id, 'audit_event_id' => $auditId,
                'audit_action_snapshot' => 'warehouse.workflow.mutate',
                'audit_resource_type_snapshot' => 'warehouse_record',
                'operation' => 'WAREHOUSE_SUPPLIER_REVISE', 'idempotency_key' => 'audit-cross-wire',
                'payload_digest' => str_repeat('b', 64), 'result_type' => 'SUPPLIER',
                'result_public_id' => $resultId, 'result_version' => 2, 'result_state' => 'ACTIVE',
                'result_digest' => str_repeat('a', 64), 'control_total' => 18, 'completed_at' => now(),
            ]));
            $this->fail('Operation receipts must match the immutable audit control total.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('FOREIGN KEY constraint failed', $exception->getMessage());
        }
    }

    public function test_supplier_version_predecessor_must_be_exact_same_entity_previous_revision(): void
    {
        $user = User::factory()->create();
        WarehouseMutationScope::run(function () use ($user): void {
            $supplierId = DB::table('warehouse_suppliers')->insertGetId([
                'public_id' => (string) Str::ulid(), 'supplier_code' => 'SYN-PRED',
                'display_name' => 'Synthetic predecessor supplier', 'state' => 'ACTIVE', 'version' => 2,
                'current_content_digest' => str_repeat('2', 64), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $firstId = DB::table('warehouse_supplier_versions')->insertGetId([
                'public_id' => (string) Str::ulid(), 'supplier_id' => $supplierId, 'actor_user_id' => $user->id,
                'version' => 1, 'supplier_code_snapshot' => 'SYN-PRED', 'display_name' => 'Revision one',
                'state' => 'ACTIVE', 'reason_code' => 'SUPPLIER_CREATED', 'previous_version_id' => null,
                'previous_version_number' => null, 'previous_content_digest' => null,
                'content_digest' => str_repeat('1', 64), 'created_at' => now(),
            ]);

            try {
                DB::table('warehouse_supplier_versions')->insert([
                    'public_id' => (string) Str::ulid(), 'supplier_id' => $supplierId, 'actor_user_id' => $user->id,
                    'version' => 2, 'supplier_code_snapshot' => 'SYN-PRED', 'display_name' => 'Revision two',
                    'state' => 'ACTIVE', 'reason_code' => 'DETAILS_UPDATED', 'previous_version_id' => $firstId,
                    'previous_version_number' => 1, 'previous_content_digest' => str_repeat('f', 64),
                    'content_digest' => str_repeat('2', 64), 'created_at' => now(),
                ]);
                $this->fail('A predecessor digest cannot be cross-wired or fabricated.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('FOREIGN KEY constraint failed', $exception->getMessage());
            }
        });
    }

    public function test_supplier_version_rejects_unregistered_or_semantically_wrong_reasons_at_the_database_boundary(): void
    {
        $user = User::factory()->create();
        WarehouseMutationScope::run(function () use ($user): void {
            $supplierId = DB::table('warehouse_suppliers')->insertGetId([
                'public_id' => (string) Str::ulid(), 'supplier_code' => 'SYN-REASON-CHECK',
                'display_name' => 'Synthetic reason check supplier', 'state' => 'ACTIVE', 'version' => 1,
                'current_content_digest' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach (['UNREGISTERED_REASON', 'DETAILS_UPDATED', 'SUPPLIER_RETIRED'] as $reason) {
                try {
                    DB::table('warehouse_supplier_versions')->insert([
                        'public_id' => (string) Str::ulid(), 'supplier_id' => $supplierId,
                        'actor_user_id' => $user->id, 'version' => 1,
                        'supplier_code_snapshot' => 'SYN-REASON-CHECK',
                        'display_name' => 'Synthetic reason check supplier',
                        'state' => 'ACTIVE', 'reason_code' => $reason,
                        'previous_version_id' => null, 'previous_version_number' => null,
                        'previous_content_digest' => null, 'content_digest' => str_repeat('a', 64),
                        'created_at' => now(),
                    ]);
                    $this->fail("Supplier version one must reject reason {$reason}.");
                } catch (QueryException $exception) {
                    $this->assertStringContainsString('wsv_values_ck', $exception->getMessage());
                }
            }

            $firstId = DB::table('warehouse_supplier_versions')->insertGetId([
                'public_id' => (string) Str::ulid(), 'supplier_id' => $supplierId,
                'actor_user_id' => $user->id, 'version' => 1,
                'supplier_code_snapshot' => 'SYN-REASON-CHECK',
                'display_name' => 'Synthetic reason check supplier',
                'state' => 'ACTIVE', 'reason_code' => 'SUPPLIER_CREATED',
                'previous_version_id' => null, 'previous_version_number' => null,
                'previous_content_digest' => null, 'content_digest' => str_repeat('a', 64),
                'created_at' => now(),
            ]);

            foreach ([
                ['state' => 'ACTIVE', 'reason' => 'SUPPLIER_RETIRED'],
                ['state' => 'RETIRED', 'reason' => 'DETAILS_UPDATED'],
            ] as $case) {
                try {
                    DB::table('warehouse_supplier_versions')->insert([
                        'public_id' => (string) Str::ulid(), 'supplier_id' => $supplierId,
                        'actor_user_id' => $user->id, 'version' => 2,
                        'supplier_code_snapshot' => 'SYN-REASON-CHECK',
                        'display_name' => 'Synthetic reason check supplier',
                        'state' => $case['state'], 'reason_code' => $case['reason'],
                        'previous_version_id' => $firstId, 'previous_version_number' => 1,
                        'previous_content_digest' => str_repeat('a', 64),
                        'content_digest' => str_repeat('b', 64), 'created_at' => now(),
                    ]);
                    $this->fail("Supplier {$case['state']} version must reject reason {$case['reason']}.");
                } catch (QueryException $exception) {
                    $this->assertStringContainsString('wsv_values_ck', $exception->getMessage());
                }
            }
        });
    }

    /** @return array{int, int, int, int, int, string} */
    private function createMovementContext(): array
    {
        $user = User::factory()->create();

        return WarehouseMutationScope::run(function () use ($user): array {
            $medicineId = DB::table('pharmacy_medicines')->insertGetId([
                'public_id' => (string) Str::ulid(), 'medicine_code' => 'SYN-PAIR-MED',
                'generic_name' => 'Synthetic Pair Medicine', 'brand_name' => null,
                'strength_text' => '1 mg', 'dosage_form' => 'TABLET', 'base_unit' => 'TABLET',
                'route_choices' => json_encode(['ORAL'], JSON_THROW_ON_ERROR),
                'acquisition_value' => 100, 'teaching_sale_value' => 150, 'state' => 'ACTIVE',
                'version' => 1, 'current_content_digest' => str_repeat('1', 64),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $depotId = DB::table('pharmacy_depots')->insertGetId([
                'public_id' => (string) Str::ulid(), 'depot_code' => 'SYN-PAIR-WH',
                'display_name' => 'Synthetic Pair Warehouse',
                'eligible_care_settings' => json_encode([], JSON_THROW_ON_ERROR),
                'state' => 'ACTIVE', 'version' => 1, 'location_kind' => 'CENTRAL_WAREHOUSE',
                'current_content_digest' => str_repeat('2', 64), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $supplierId = DB::table('warehouse_suppliers')->insertGetId([
                'public_id' => (string) Str::ulid(), 'supplier_code' => 'SYN-PAIR-SUP',
                'display_name' => 'Synthetic Pair Supplier', 'state' => 'ACTIVE', 'version' => 1,
                'current_content_digest' => str_repeat('3', 64), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $custodyLotId = DB::table('warehouse_custody_lots')->insertGetId([
                'public_id' => (string) Str::ulid(), 'supplier_id' => $supplierId,
                'medicine_id' => $medicineId, 'medicine_code_snapshot' => 'SYN-PAIR-MED',
                'base_unit_snapshot' => 'TABLET', 'lot_code' => 'PAIR-LOT-001',
                'expiry_date' => now()->addYear()->toDateString(), 'first_received_at' => now(),
                'content_digest' => str_repeat('4', 64), 'created_at' => now(),
            ]);
            $stockLotId = DB::table('pharmacy_stock_lots')->insertGetId([
                'public_id' => (string) Str::ulid(), 'medicine_id' => $medicineId, 'depot_id' => $depotId,
                'opened_by_user_id' => $user->id, 'medicine_version' => 1, 'depot_version' => 1,
                'medicine_code_snapshot' => 'SYN-PAIR-MED', 'depot_code_snapshot' => 'SYN-PAIR-WH',
                'lot_code' => 'PAIR-LOT-001', 'received_at' => now(),
                'expiry_date' => now()->addYear()->toDateString(), 'no_expiry_reason' => null,
                'available_quantity' => 10, 'quarantined_quantity' => 0, 'transit_quantity' => 0,
                'acquisition_value' => 100, 'source_reference' => 'SYN-PAIR-RECEIPT',
                'warehouse_custody_lot_id' => $custodyLotId,
                'warehouse_source_type' => 'RECEIPT_ALLOCATION',
                'warehouse_source_public_id' => (string) Str::ulid(),
                'state' => 'ACTIVE', 'version' => 1, 'content_digest' => str_repeat('5', 64),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $movementSetId = DB::table('warehouse_custody_movement_sets')->insertGetId([
                'public_id' => (string) Str::ulid(), 'custody_lot_id' => $custodyLotId,
                'actor_user_id' => $user->id, 'movement_set_type' => 'WAREHOUSE_TRANSFER_DISPATCH',
                'source_type' => 'TRANSFER_ITEM', 'source_public_id' => (string) Str::ulid(),
                'content_digest' => str_repeat('6', 64), 'occurred_at' => now(), 'created_at' => now(),
            ]);
            $pairPublicId = (string) Str::ulid();
            $pairId = DB::table('warehouse_custody_movement_pairs')->insertGetId([
                'public_id' => $pairPublicId, 'movement_set_id' => $movementSetId,
                'custody_lot_id' => $custodyLotId,
                'movement_set_type_snapshot' => 'WAREHOUSE_TRANSFER_DISPATCH',
                'pair_type' => 'WAREHOUSE_DISPATCH', 'quantity' => 5,
                'content_digest' => str_repeat('7', 64), 'created_at' => now(),
            ]);

            return [$user->id, $stockLotId, $custodyLotId, $movementSetId, $pairId, $pairPublicId];
        });
    }

    /** @param array<string, int|string> $leg */
    private function insertPairedMovement(array $leg): void
    {
        WarehouseMutationScope::run(fn () => DB::table('pharmacy_stock_movements')->insert([
            'public_id' => (string) Str::ulid(), 'stock_lot_id' => $leg['stock_lot_id'],
            'actor_user_id' => $leg['user_id'], 'handover_item_id' => null,
            'movement_type' => $leg['movement_type'], 'available_delta' => $leg['available_delta'],
            'quarantined_delta' => 0, 'transit_delta' => $leg['transit_delta'],
            'available_balance_after' => 5, 'quarantined_balance_after' => 0,
            'transit_balance_after' => $leg['transit_delta'] > 0 ? 5 : 0,
            'reason_code' => 'TRANSFER_DISPATCH', 'source_type' => 'TRANSFER_ITEM',
            'source_public_id' => (string) Str::ulid(), 'custody_chain_public_id' => (string) Str::ulid(),
            'warehouse_movement_set_id' => $leg['movement_set_id'],
            'warehouse_custody_lot_id' => $leg['custody_lot_id'],
            'warehouse_movement_pair_id' => $leg['pair_id'], 'pair_public_id' => $leg['pair_public_id'],
            'pair_type' => 'WAREHOUSE_DISPATCH', 'pair_quantity' => 5, 'pair_slot' => $leg['pair_slot'],
            'pair_leg' => $leg['pair_leg'], 'pair_bucket' => $leg['pair_bucket'],
            'content_digest' => str_repeat('8', 64), 'occurred_at' => now(), 'created_at' => now(),
        ]));
    }
}
