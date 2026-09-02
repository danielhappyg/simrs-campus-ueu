<?php

namespace Tests\Feature\Database;

use App\Models\User;
use App\Models\WarehouseCustodyMovementSet;
use App\Models\WarehouseCustodyReceiptAllocation;
use App\Models\WarehousePurchaseOrder;
use App\Models\WarehouseSupplier;
use App\Models\WarehouseSupplierVersion;
use App\Support\Database\SchemaQualifier;
use App\Support\Warehouse\WarehouseAppendOnlyGuard;
use App\Support\Warehouse\WarehouseMutationScope;
use App\Support\Warehouse\WarehouseSchemaMutationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class WarehouseCustodyMigrationTest extends TestCase
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

        // Some provider-guard integration tests intentionally create a minimal
        // same-named table without RefreshDatabase. Repair that empty fixture
        // before exercising the real warehouse migration contract.
        if (Schema::hasTable('warehouse_suppliers') && ! Schema::hasColumn('warehouse_suppliers', 'state')) {
            $migration = require database_path('migrations/2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables.php');
            $migration->up();
        }
    }

    public function test_sqlite_migration_remains_enabled_without_the_exact_engine_cutover_flag(): void
    {
        config()->set('database.warehouse_schema_migration_enabled', false);

        $migration = require database_path('migrations/2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables.php');

        $this->assertTrue($migration->shouldRun());
    }

    public function test_exact_engine_migration_gate_requires_both_explicit_flag_and_named_migrator_default(): void
    {
        $originalDefault = config('database.default');
        $originalGate = config('database.warehouse_schema_migration_enabled');
        try {
            config()->set('database.connections.release_default.driver', 'pgsql');
            config()->set('database.default', 'release_default');
            config()->set('database.warehouse_schema_migration_enabled', false);

            $migration = require database_path('migrations/2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables.php');
            $this->assertFalse($migration->shouldRun());

            config()->set('database.warehouse_schema_migration_enabled', true);
            try {
                $migration->shouldRun();
                $this->fail('The exact-engine cutover flag must not authorize another default connection.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('warehouse_migrator default connection', $exception->getMessage());
            }

            config()->set('database.connections.warehouse_migrator.driver', null);
            config()->set('database.default', 'warehouse_migrator');
            config()->set('database.warehouse_schema_migration_enabled', false);
            $this->assertFalse($migration->shouldRun());

            config()->set('database.warehouse_schema_migration_enabled', true);
            try {
                $migration->shouldRun();
                $this->fail('The cutover must not infer an exact engine from incomplete connection configuration.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('supports only PostgreSQL or MySQL', $exception->getMessage());
            }

            config()->set('database.connections.warehouse_migrator.driver', 'pgsql');
            $this->assertTrue($migration->shouldRun());
        } finally {
            config()->set('database.default', $originalDefault);
            config()->set('database.warehouse_schema_migration_enabled', $originalGate);
        }
    }

    public function test_schema_preserves_opening_stock_and_installs_the_complete_custody_surface(): void
    {
        foreach ([
            'warehouse_suppliers', 'warehouse_supplier_versions', 'warehouse_purchase_orders',
            'warehouse_purchase_order_versions', 'warehouse_purchase_order_lines', 'warehouse_purchase_order_decisions',
            'warehouse_receipts', 'warehouse_receipt_lines', 'warehouse_custody_lots',
            'warehouse_custody_receipt_allocations', 'warehouse_custody_movement_sets',
            'warehouse_custody_movement_pairs',
            'warehouse_transfers', 'warehouse_transfer_items', 'warehouse_transfer_decisions',
            'warehouse_supplier_returns', 'warehouse_supplier_return_items', 'warehouse_supplier_return_decisions',
            'warehouse_unit_returns', 'warehouse_unit_return_items', 'warehouse_unit_return_decisions',
            'warehouse_correction_requests', 'warehouse_correction_decisions',
            'warehouse_correction_compensations', 'warehouse_operation_receipts',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertTrue(Schema::hasColumns('pharmacy_depots', ['location_kind']));
        $this->assertTrue(Schema::hasColumns('pharmacy_depot_versions', ['location_kind']));
        $this->assertTrue(Schema::hasColumns('pharmacy_stock_lots', [
            'warehouse_custody_lot_id', 'warehouse_source_type',
            'warehouse_source_public_id', 'transit_quantity',
        ]));
        $this->assertTrue(Schema::hasColumns('pharmacy_stock_movements', [
            'transit_delta', 'transit_balance_after', 'custody_chain_public_id',
            'warehouse_movement_set_id', 'warehouse_custody_lot_id',
            'warehouse_movement_pair_id', 'pair_public_id', 'pair_type', 'pair_quantity',
            'pair_slot', 'pair_leg', 'pair_bucket',
        ]));
        $this->assertTrue(Schema::hasColumns('audit_events', [
            'warehouse_operation_snapshot', 'warehouse_result_version_snapshot',
            'warehouse_result_digest_snapshot', 'warehouse_control_total_snapshot',
        ]));
        $this->assertSame(2, DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->whereIn('name', ['wh_audit_immutable_update', 'wh_audit_immutable_delete'])
            ->count());
        $this->assertFalse(Schema::hasColumn('warehouse_custody_lots', 'receipt_line_id'));
        $this->assertFalse(Schema::hasColumn('warehouse_custody_lots', 'received_available_quantity'));
        $this->assertTrue(Schema::hasColumns('warehouse_custody_receipt_allocations', [
            'receipt_id', 'receipt_line_id', 'custody_lot_id', 'supplier_id',
            'medicine_id', 'medicine_version_id', 'available_quantity', 'quarantined_quantity',
        ]));

        $lotColumns = collect(Schema::getColumns(SchemaQualifier::table('pharmacy_stock_lots')))->keyBy('name');
        foreach (['warehouse_custody_lot_id', 'warehouse_source_type', 'warehouse_source_public_id'] as $column) {
            $this->assertTrue($lotColumns[$column]['nullable'], $column.' must preserve null-provenance opening lots.');
        }
        $this->assertContains((string) $lotColumns['transit_quantity']['default'], ['0', "'0'", '0::bigint']);

        $foreign = collect(Schema::getForeignKeys(SchemaQualifier::table('pharmacy_stock_lots')))
            ->firstWhere('columns', ['warehouse_custody_lot_id']);
        $this->assertIsArray($foreign);
        $this->assertSame('warehouse_custody_lots', $foreign['foreign_table']);
        $this->assertSame('restrict', $foreign['on_delete']);

        $movementSetForeign = collect(Schema::getForeignKeys(SchemaQualifier::table('pharmacy_stock_movements')))
            ->firstWhere('columns', ['warehouse_movement_set_id', 'warehouse_custody_lot_id']);
        $this->assertIsArray($movementSetForeign);
        $this->assertSame('warehouse_custody_movement_sets', $movementSetForeign['foreign_table']);

        $this->assertCompositeForeign(
            'pharmacy_stock_movements',
            ['warehouse_movement_pair_id', 'warehouse_movement_set_id', 'warehouse_custody_lot_id', 'pair_public_id', 'pair_type', 'pair_quantity'],
            'warehouse_custody_movement_pairs',
            ['id', 'movement_set_id', 'custody_lot_id', 'public_id', 'pair_type', 'quantity'],
        );

        $receiptDecisionForeign = collect(Schema::getForeignKeys(SchemaQualifier::table('warehouse_receipts')))
            ->firstWhere('columns', [
                'purchase_order_decision_id', 'purchase_order_id', 'purchase_order_version_id',
                'supplier_id', 'supplier_version_id', 'approval_decision_snapshot',
            ]);
        $this->assertIsArray($receiptDecisionForeign);

        $allocationForeign = collect(Schema::getForeignKeys(SchemaQualifier::table('warehouse_custody_receipt_allocations')))
            ->firstWhere('columns', [
                'receipt_line_id', 'receipt_id', 'supplier_id', 'medicine_id', 'medicine_version_id',
                'lot_code_snapshot', 'expiry_date_snapshot', 'available_quantity',
                'quarantined_quantity', 'unit_acquisition_value_snapshot',
            ]);
        $this->assertIsArray($allocationForeign);

        foreach ([
            ['warehouse_transfer_items', ['transfer_id', 'source_depot_id'], 'warehouse_transfers', ['id', 'source_depot_id']],
            ['warehouse_transfer_items', ['source_stock_lot_id', 'custody_lot_id', 'source_depot_id'], 'pharmacy_stock_lots', ['id', 'warehouse_custody_lot_id', 'depot_id']],
            ['warehouse_supplier_return_items', ['supplier_return_id', 'supplier_id'], 'warehouse_supplier_returns', ['id', 'supplier_id']],
            ['warehouse_supplier_return_items', ['receipt_line_id', 'receipt_id', 'custody_lot_id', 'supplier_id'], 'warehouse_custody_receipt_allocations', ['receipt_line_id', 'receipt_id', 'custody_lot_id', 'supplier_id']],
            ['warehouse_unit_return_items', ['unit_return_id', 'original_transfer_id'], 'warehouse_unit_returns', ['id', 'original_transfer_id']],
            ['warehouse_unit_return_items', ['original_transfer_item_id', 'original_transfer_id', 'custody_lot_id'], 'warehouse_transfer_items', ['id', 'transfer_id', 'custody_lot_id']],
            ['warehouse_correction_compensations', ['movement_set_id', 'custody_lot_id'], 'warehouse_custody_movement_sets', ['id', 'custody_lot_id']],
            ['warehouse_supplier_versions', ['previous_version_id', 'supplier_id', 'previous_version_number', 'previous_content_digest'], 'warehouse_supplier_versions', ['id', 'supplier_id', 'version', 'content_digest']],
            ['warehouse_purchase_order_versions', ['previous_version_id', 'purchase_order_id', 'previous_version_number', 'previous_content_digest'], 'warehouse_purchase_order_versions', ['id', 'purchase_order_id', 'version', 'content_digest']],
            ['warehouse_operation_receipts', ['audit_event_id', 'actor_user_id'], 'audit_events', ['id', 'actor_user_id']],
            ['warehouse_operation_receipts', ['audit_event_id', 'result_public_id', 'result_version'], 'audit_events', ['id', 'resource_id', 'warehouse_result_version_snapshot']],
            ['warehouse_operation_receipts', ['audit_event_id', 'operation', 'result_digest', 'control_total'], 'audit_events', ['id', 'warehouse_operation_snapshot', 'warehouse_result_digest_snapshot', 'warehouse_control_total_snapshot']],
        ] as [$table, $columns, $foreignTable, $foreignColumns]) {
            $this->assertCompositeForeign($table, $columns, $foreignTable, $foreignColumns);
        }

        $this->assertSame(SchemaQualifier::table('warehouse_suppliers'), (new WarehouseSupplier)->getTable());
        $this->assertSame(SchemaQualifier::table('warehouse_purchase_orders'), (new WarehousePurchaseOrder)->getTable());
        $this->assertSame(SchemaQualifier::table('warehouse_custody_receipt_allocations'), (new WarehouseCustodyReceiptAllocation)->getTable());
        $this->assertSame(SchemaQualifier::table('warehouse_custody_movement_sets'), (new WarehouseCustodyMovementSet)->getTable());
    }

    public function test_empty_migration_rolls_back_reapplies_and_retries_a_complete_empty_catalog(): void
    {
        $migration = require database_path('migrations/2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('warehouse_suppliers'));
        $this->assertFalse(Schema::hasColumn('pharmacy_depots', 'location_kind'));
        $this->assertFalse(Schema::hasColumn('pharmacy_stock_lots', 'transit_quantity'));
        $this->assertFalse(Schema::hasColumn('audit_events', 'warehouse_operation_snapshot'));
        $this->assertSame(0, DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->whereIn('name', ['wh_audit_immutable_update', 'wh_audit_immutable_delete'])
            ->count());

        WarehouseSchemaMutationScope::run(function (): void {
            Schema::create('warehouse_suppliers', fn ($table) => $table->id());
            Schema::create('warehouse_supplier_versions', fn ($table) => $table->id());
            Schema::table('pharmacy_stock_lots', fn ($table) => $table->unsignedBigInteger('warehouse_custody_lot_id')->nullable());
            Schema::table('pharmacy_stock_movements', fn ($table) => $table->bigInteger('transit_delta')->default(0));
            Schema::table('audit_events', fn ($table) => $table->string('warehouse_operation_snapshot', 64)->nullable());
        });

        $method = new \ReflectionMethod($migration, 'reinstallWarehouseGuards');
        try {
            WarehouseSchemaMutationScope::run(fn () => $method->invoke($migration));
            $this->fail('A partial warehouse catalog must fail closed instead of silently skipping guard installation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Partial warehouse custody catalog', $exception->getMessage());
        }
        $this->assertSame(2, DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('tbl_name', 'warehouse_supplier_versions')
            ->count());

        $auditGuardMethod = new \ReflectionMethod($migration, 'reinstallWarehouseAuditEvidenceGuard');
        try {
            WarehouseSchemaMutationScope::run(fn () => $auditGuardMethod->invoke($migration));
            $this->fail('A partial warehouse audit extension must fail closed after restoring its evidence guard.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Partial warehouse audit extension', $exception->getMessage());
        }
        $this->assertSame(2, DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->whereIn('name', ['wh_audit_immutable_update', 'wh_audit_immutable_delete'])
            ->count());
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasTable('warehouse_operation_receipts'));
        $this->assertTrue(Schema::hasColumns('pharmacy_stock_lots', [
            'warehouse_custody_lot_id', 'warehouse_source_type', 'warehouse_source_public_id', 'transit_quantity',
        ]));
        $this->assertTrue(Schema::hasColumns('pharmacy_stock_movements', [
            'transit_delta', 'transit_balance_after', 'custody_chain_public_id',
            'warehouse_movement_set_id', 'warehouse_custody_lot_id', 'warehouse_movement_pair_id',
            'pair_public_id', 'pair_type', 'pair_quantity', 'pair_slot', 'pair_leg', 'pair_bucket',
        ]));
        $this->assertTrue(Schema::hasColumns('audit_events', [
            'warehouse_operation_snapshot', 'warehouse_result_version_snapshot',
            'warehouse_result_digest_snapshot', 'warehouse_control_total_snapshot',
        ]));
        $this->assertSame(2, DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->whereIn('name', ['wh_audit_immutable_update', 'wh_audit_immutable_delete'])
            ->count());
    }

    public function test_populated_warehouse_catalog_refuses_rollback_and_preserves_the_row(): void
    {
        $migration = require database_path('migrations/2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables.php');
        $migration->up();
        $supplier = WarehouseMutationScope::run(fn (): WarehouseSupplier => WarehouseSupplier::query()->create([
            'supplier_code' => 'SYN-GUARD',
            'display_name' => 'Pemasok Sintetis Guard',
            'state' => WarehouseSupplier::ACTIVE,
            'version' => 1,
            'current_content_digest' => str_repeat('a', 64),
        ]));
        try {
            $migration->down();
            $this->fail('Rollback should preserve populated warehouse evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('populated warehouse custody evidence', $exception->getMessage());
        }

        $this->assertDatabaseHas('warehouse_suppliers', ['id' => $supplier->id, 'supplier_code' => 'SYN-GUARD']);
    }

    public function test_model_scope_and_database_append_only_guard_fail_closed(): void
    {
        $migration = require database_path('migrations/2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables.php');
        $migration->up();
        try {
            WarehouseSupplier::query()->create([
                'supplier_code' => 'SYN-OUTSIDE', 'display_name' => 'Tidak Sah',
                'state' => WarehouseSupplier::ACTIVE, 'version' => 1,
                'current_content_digest' => str_repeat('b', 64),
            ]);
            $this->fail('Expected governed model scope refusal.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('governed warehouse service', $exception->getMessage());
        }

        $user = User::factory()->create();
        [$supplier, $version] = WarehouseMutationScope::run(function () use ($user): array {
            $supplier = WarehouseSupplier::query()->create([
                'supplier_code' => 'SYN-VERSION', 'display_name' => 'Pemasok Versi',
                'state' => WarehouseSupplier::ACTIVE, 'version' => 1,
                'current_content_digest' => str_repeat('c', 64),
            ]);
            $version = WarehouseSupplierVersion::query()->create([
                'supplier_id' => $supplier->id, 'actor_user_id' => $user->id, 'version' => 1,
                'supplier_code_snapshot' => 'SYN-VERSION', 'display_name' => 'Pemasok Versi',
                'state' => WarehouseSupplier::ACTIVE, 'reason_code' => 'SUPPLIER_CREATED',
                'previous_content_digest' => null, 'content_digest' => str_repeat('c', 64),
                'created_at' => now(),
            ]);

            return [$supplier, $version];
        });

        $this->assertContains('warehouse_supplier_versions', WarehouseAppendOnlyGuard::TABLES);
        try {
            WarehouseMutationScope::run(fn () => DB::table((new WarehouseSupplierVersion)->getTable())
                ->where('id', $version->id)->update(['display_name' => 'Tampered']));
            $this->fail('Expected append-only SQL guard refusal.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('permits only closed DML', $exception->getMessage());
        }

        try {
            DB::connection()->getPdo()->exec("UPDATE warehouse_supplier_versions SET display_name = 'Tampered' WHERE id = {$version->id}");
            $this->fail('Expected append-only database trigger refusal.');
        } catch (\PDOException $exception) {
            $this->assertStringContainsString('warehouse append-only evidence is immutable', $exception->getMessage());
        }

        $this->assertSame('Pemasok Versi', $version->fresh()->display_name);
        $this->assertSame('Pemasok Versi', $supplier->fresh()->display_name);
    }

    public function test_finalizer_attempts_guard_reinstall_even_when_base_check_restoration_throws(): void
    {
        $source = file_get_contents(database_path('migrations/2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables.php'));
        $this->assertIsString($source);
        $start = strpos($source, 'private function restoreChecksAndWarehouseGuards');
        $end = strpos($source, 'private function reinstallWarehouseGuards', $start);
        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $finalizer = substr($source, $start, $end - $start);

        $baseRestore = strpos($finalizer, '$this->restoreBasePharmacyChecks()');
        $firstCatch = strpos($finalizer, '} catch (Throwable $exception)', $baseRestore);
        $guardRestore = strpos($finalizer, '$this->reinstallWarehouseGuards()', $firstCatch);
        $secondCatch = strpos($finalizer, '} catch (Throwable $exception)', $guardRestore);
        $this->assertIsInt($baseRestore);
        $this->assertIsInt($firstCatch);
        $this->assertIsInt($guardRestore);
        $this->assertIsInt($secondCatch);
        $this->assertGreaterThan($firstCatch, $guardRestore);
        $this->assertStringContainsString('Warehouse migration finalization failed closed', $finalizer);
    }

    /** @param list<string> $columns @param list<string> $foreignColumns */
    private function assertCompositeForeign(
        string $table,
        array $columns,
        string $foreignTable,
        array $foreignColumns,
    ): void {
        $foreign = collect(Schema::getForeignKeys(SchemaQualifier::table($table)))
            ->firstWhere('columns', $columns);
        $this->assertIsArray($foreign, $table.' '.implode(',', $columns));
        $this->assertSame($foreignTable, $foreign['foreign_table']);
        $this->assertSame($foreignColumns, $foreign['foreign_columns']);
    }
}
