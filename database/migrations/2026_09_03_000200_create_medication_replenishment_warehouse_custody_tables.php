<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Warehouse\WarehouseAppendOnlyGuard;
use App\Support\Warehouse\WarehouseAuditEvidenceGuard;
use App\Support\Warehouse\WarehouseMutableHeadGuard;
use App\Support\Warehouse\WarehouseMutationScope;
use App\Support\Warehouse\WarehouseSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Child-first order for guarded retry and rollback. */
    private const TABLES = [
        'warehouse_operation_receipts',
        'warehouse_correction_compensations', 'warehouse_correction_decisions', 'warehouse_correction_requests',
        'warehouse_unit_return_decisions', 'warehouse_unit_return_items', 'warehouse_unit_returns',
        'warehouse_supplier_return_decisions', 'warehouse_supplier_return_items', 'warehouse_supplier_returns',
        'warehouse_transfer_decisions', 'warehouse_transfer_items', 'warehouse_transfers',
        'warehouse_custody_movement_pairs', 'warehouse_custody_movement_sets',
        'warehouse_custody_receipt_allocations',
        'warehouse_custody_lots', 'warehouse_receipt_lines', 'warehouse_receipts',
        'warehouse_purchase_order_decisions', 'warehouse_purchase_order_lines',
        'warehouse_purchase_order_versions', 'warehouse_purchase_orders',
        'warehouse_supplier_versions', 'warehouse_suppliers',
    ];

    private const AUDIT_OPERATIONS = [
        'WAREHOUSE_SUPPLIER_CREATE', 'WAREHOUSE_SUPPLIER_REVISE', 'WAREHOUSE_SUPPLIER_RETIRE',
        'WAREHOUSE_PURCHASE_ORDER_CREATE', 'WAREHOUSE_PURCHASE_ORDER_REVISE',
        'WAREHOUSE_PURCHASE_ORDER_SUBMIT', 'WAREHOUSE_PURCHASE_ORDER_REVIEW', 'WAREHOUSE_RECEIPT_RECORD',
        'WAREHOUSE_TRANSFER_DISPATCH', 'WAREHOUSE_TRANSFER_REVIEW', 'WAREHOUSE_SUPPLIER_RETURN_REQUEST',
        'WAREHOUSE_SUPPLIER_RETURN_REVIEW', 'WAREHOUSE_UNIT_RETURN_REQUEST', 'WAREHOUSE_UNIT_RETURN_REVIEW',
        'WAREHOUSE_CORRECTION_REQUEST', 'WAREHOUSE_CORRECTION_REVIEW', 'WAREHOUSE_CORRECTION_COMPENSATE',
    ];

    private const AUDIT_EXTENSION_COLUMNS = [
        'warehouse_operation_snapshot',
        'warehouse_result_version_snapshot',
        'warehouse_result_digest_snapshot',
        'warehouse_control_total_snapshot',
    ];

    public function shouldRun(): bool
    {
        $defaultConnection = config('database.default');
        if (! is_string($defaultConnection) || $defaultConnection === '') {
            throw new RuntimeException('Warehouse custody migration requires an explicit default database connection.');
        }

        $driver = config("database.connections.{$defaultConnection}.driver");
        if ($driver === 'sqlite') {
            return true;
        }

        if (config('database.warehouse_schema_migration_enabled') !== true) {
            return false;
        }

        if ($defaultConnection !== WarehouseMutationScope::DEFAULT_MIGRATOR_CONNECTION) {
            throw new RuntimeException(
                'WAREHOUSE_SCHEMA_MIGRATION_ENABLED requires the warehouse_migrator default connection.',
            );
        }

        if (! in_array($driver, ['pgsql', 'mysql'], true)) {
            throw new RuntimeException(
                'The governed warehouse schema cutover supports only PostgreSQL or MySQL.',
            );
        }

        return true;
    }

    public function up(): void
    {
        WarehouseSchemaMutationScope::run(fn () => $this->migrateUp());
    }

    private function migrateUp(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Warehouse custody migration requires synthetic-only SIMULATION mode.');
        }

        $this->assertPrerequisites();
        $existing = collect(self::TABLES)
            ->filter(fn (string $table): bool => Schema::hasTable(SchemaQualifier::table($table)));
        foreach ($existing as $table) {
            if (DB::table(SchemaQualifier::table($table))->exists()) {
                throw new RuntimeException('Warehouse custody migration retry refused: partial catalog contains retained rows.');
            }
        }

        try {
            WarehouseAuditEvidenceGuard::remove();
            WarehouseMutableHeadGuard::remove();
            WarehouseAppendOnlyGuard::remove();
            $this->dropMovementSetForeignIfPresent();
            $this->dropCustodyLotForeignIfPresent();
            $this->dropExtendedChecks();
            foreach (self::TABLES as $table) {
                Schema::dropIfExists(SchemaQualifier::table($table));
            }

            $this->extendPharmacyTables();
            $this->extendAuditTable();
            $this->createSupplierTables();
            $this->createPurchaseOrderTables();
            $this->createReceiptAndCustodyTables();
            $this->createTransferTables();
            $this->createReturnTables();
            $this->createCorrectionAndOperationTables();
            $this->addCustodyLotForeign();
            $this->addMovementSetForeign();
            $this->addChecks();
        } finally {
            $this->restoreChecksAndWarehouseGuards();
        }
    }

    public function down(): void
    {
        WarehouseSchemaMutationScope::run(fn () => $this->migrateDown());
    }

    private function migrateDown(): void
    {
        foreach (self::TABLES as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to discard populated warehouse custody evidence.');
            }
        }
        if ($this->extendedPharmacyEvidenceExists() || $this->extendedAuditEvidenceExists()) {
            throw new RuntimeException('Refusing to discard pharmacy or audit rows bound to warehouse custody evidence.');
        }

        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)
            ->where('action', 'warehouse.workflow.mutate')
            ->whereIn('metadata->operation', self::AUDIT_OPERATIONS)
            ->exists()) {
            throw new RuntimeException('Refusing to discard warehouse custody tables while correlated audit evidence remains.');
        }

        try {
            WarehouseAuditEvidenceGuard::remove();
            WarehouseMutableHeadGuard::remove();
            WarehouseAppendOnlyGuard::remove();
            $this->dropMovementSetForeignIfPresent();
            $this->dropCustodyLotForeignIfPresent();
            $this->dropExtendedChecks();
            // SQLite rebuilds altered tables. Remove extension columns while
            // their referenced warehouse parents still exist, otherwise the
            // rebuild can fail while copying the empty base table.
            $this->removePharmacyExtensions();
            foreach (self::TABLES as $table) {
                Schema::dropIfExists(SchemaQualifier::table($table));
            }
            $this->removeAuditExtensions();

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::unprepared('DROP FUNCTION IF EXISTS warehouse_append_only_guard()');
                DB::unprepared('DROP FUNCTION IF EXISTS warehouse_append_only_truncate_guard()');
                DB::unprepared('DROP FUNCTION IF EXISTS warehouse_mutable_head_guard()');
            }
        } finally {
            $this->restoreChecksAndWarehouseGuards();
        }
    }

    private function assertPrerequisites(): void
    {
        foreach (['users', 'audit_events', 'pharmacy_medicines', 'pharmacy_medicine_versions', 'pharmacy_depots', 'pharmacy_depot_versions', 'pharmacy_stock_lots', 'pharmacy_stock_movements'] as $table) {
            if (! Schema::hasTable(SchemaQualifier::table($table))) {
                throw new RuntimeException("Warehouse custody migration prerequisite missing: {$table}.");
            }
        }
    }

    private function extendPharmacyTables(): void
    {
        $depots = SchemaQualifier::table('pharmacy_depots');
        if (! Schema::hasColumn($depots, 'location_kind')) {
            Schema::table($depots, function (Blueprint $table): void {
                $table->string('location_kind', 32)->default('DISPENSING_DEPOT');
            });
        }
        $this->ensureIndex('pharmacy_depots', ['location_kind'], 'pd_location_kind_idx');

        $versions = SchemaQualifier::table('pharmacy_depot_versions');
        if (! Schema::hasColumn($versions, 'location_kind')) {
            Schema::table($versions, function (Blueprint $table): void {
                $table->string('location_kind', 32)->default('DISPENSING_DEPOT');
            });
        }
        $this->ensureIndex('pharmacy_depot_versions', ['location_kind'], 'pdv_location_kind_idx');
        $this->ensureUnique('pharmacy_depot_versions', ['id', 'depot_id'], 'pdv_id_depot_uq');
        $this->ensureUnique('pharmacy_medicine_versions', ['id', 'medicine_id'], 'pmv_id_medicine_uq');

        $lots = SchemaQualifier::table('pharmacy_stock_lots');
        if (! Schema::hasColumn($lots, 'warehouse_custody_lot_id')) {
            Schema::table($lots, function (Blueprint $table): void {
                $table->unsignedBigInteger('warehouse_custody_lot_id')->nullable();
            });
        }
        if (! Schema::hasColumn($lots, 'warehouse_source_type')) {
            Schema::table($lots, function (Blueprint $table): void {
                $table->string('warehouse_source_type', 32)->nullable();
            });
        }
        if (! Schema::hasColumn($lots, 'warehouse_source_public_id')) {
            Schema::table($lots, function (Blueprint $table): void {
                $table->string('warehouse_source_public_id', 26)->nullable();
            });
        }
        if (! Schema::hasColumn($lots, 'transit_quantity')) {
            Schema::table($lots, function (Blueprint $table): void {
                $table->unsignedBigInteger('transit_quantity')->default(0);
            });
        }
        $this->ensureIndex('pharmacy_stock_lots', ['warehouse_custody_lot_id', 'depot_id'], 'psl_wc_depot_idx');
        $this->ensureUnique('pharmacy_stock_lots', ['id', 'warehouse_custody_lot_id'], 'psl_id_wc_lot_uq');
        $this->ensureUnique('pharmacy_stock_lots', ['id', 'warehouse_custody_lot_id', 'depot_id'], 'psl_id_wc_depot_uq');

        $movements = SchemaQualifier::table('pharmacy_stock_movements');
        if (! Schema::hasColumn($movements, 'transit_delta')) {
            Schema::table($movements, function (Blueprint $table): void {
                $table->bigInteger('transit_delta')->default(0);
            });
        }
        if (! Schema::hasColumn($movements, 'transit_balance_after')) {
            Schema::table($movements, function (Blueprint $table): void {
                $table->unsignedBigInteger('transit_balance_after')->default(0);
            });
        }
        if (! Schema::hasColumn($movements, 'custody_chain_public_id')) {
            Schema::table($movements, function (Blueprint $table): void {
                $table->string('custody_chain_public_id', 26)->nullable();
            });
        }
        if (! Schema::hasColumn($movements, 'warehouse_movement_set_id')) {
            Schema::table($movements, function (Blueprint $table): void {
                $table->unsignedBigInteger('warehouse_movement_set_id')->nullable();
            });
        }
        if (! Schema::hasColumn($movements, 'warehouse_custody_lot_id')) {
            Schema::table($movements, function (Blueprint $table): void {
                $table->unsignedBigInteger('warehouse_custody_lot_id')->nullable();
            });
        }
        if (! Schema::hasColumn($movements, 'warehouse_movement_pair_id')) {
            Schema::table($movements, function (Blueprint $table): void {
                $table->unsignedBigInteger('warehouse_movement_pair_id')->nullable();
            });
        }
        if (! Schema::hasColumn($movements, 'pair_type')) {
            Schema::table($movements, function (Blueprint $table): void {
                $table->string('pair_type', 40)->nullable();
            });
        }
        if (! Schema::hasColumn($movements, 'pair_quantity')) {
            Schema::table($movements, function (Blueprint $table): void {
                $table->unsignedBigInteger('pair_quantity')->nullable();
            });
        }
        if (! Schema::hasColumn($movements, 'pair_slot')) {
            Schema::table($movements, function (Blueprint $table): void {
                $table->string('pair_slot', 16)->nullable();
            });
        }
        if (! Schema::hasColumn($movements, 'pair_public_id')) {
            Schema::table($movements, function (Blueprint $table): void {
                $table->string('pair_public_id', 26)->nullable();
            });
        }
        if (! Schema::hasColumn($movements, 'pair_leg')) {
            Schema::table($movements, function (Blueprint $table): void {
                $table->string('pair_leg', 32)->nullable();
            });
        }
        if (! Schema::hasColumn($movements, 'pair_bucket')) {
            Schema::table($movements, function (Blueprint $table): void {
                $table->string('pair_bucket', 16)->nullable();
            });
        }
        $this->ensureUnique('pharmacy_stock_movements', ['pair_public_id', 'pair_leg'], 'psm_pair_leg_uq');
        $this->ensureUnique('pharmacy_stock_movements', ['warehouse_movement_pair_id', 'pair_slot'], 'psm_pair_slot_uq');
        $this->ensureIndex('pharmacy_stock_movements', ['custody_chain_public_id', 'id'], 'psm_custody_order_idx');
        $this->ensureIndex('pharmacy_stock_movements', ['warehouse_movement_set_id', 'id'], 'psm_movement_set_idx');
    }

    private function extendAuditTable(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        foreach ([
            'warehouse_operation_snapshot' => fn (Blueprint $table) => $table->string('warehouse_operation_snapshot', 64)->nullable(),
            'warehouse_result_version_snapshot' => fn (Blueprint $table) => $table->unsignedInteger('warehouse_result_version_snapshot')->nullable(),
            'warehouse_result_digest_snapshot' => fn (Blueprint $table) => $table->string('warehouse_result_digest_snapshot', 64)->nullable(),
            'warehouse_control_total_snapshot' => fn (Blueprint $table) => $table->unsignedBigInteger('warehouse_control_total_snapshot')->nullable(),
        ] as $column => $definition) {
            if (! Schema::hasColumn($audit, $column)) {
                Schema::table($audit, fn (Blueprint $table) => $definition($table));
            }
        }

        $this->ensureUnique('audit_events', ['id', 'actor_user_id'], 'ae_warehouse_actor_uq');
        $this->ensureUnique('audit_events', ['id', 'action', 'resource_type'], 'ae_warehouse_action_resource_uq');
        $this->ensureUnique('audit_events', ['id', 'resource_id', 'warehouse_result_version_snapshot'], 'ae_warehouse_result_identity_uq');
        $this->ensureUnique(
            'audit_events',
            ['id', 'warehouse_operation_snapshot', 'warehouse_result_digest_snapshot', 'warehouse_control_total_snapshot'],
            'ae_warehouse_control_uq'
        );
    }

    private function createSupplierTables(): void
    {
        Schema::create(SchemaQualifier::table('warehouse_suppliers'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('ws_public_id_uq');
            $table->string('supplier_code', 64)->unique('ws_code_uq');
            $table->string('display_name', 160);
            $table->string('synthetic_contact_name', 160)->nullable();
            $table->string('synthetic_email', 255)->nullable();
            $table->string('synthetic_phone', 64)->nullable();
            $table->string('synthetic_reference', 120)->nullable();
            $table->string('state', 16)->default('ACTIVE');
            $table->unsignedInteger('version')->default(1);
            $table->string('current_content_digest', 64);
            $table->timestamps();
            $table->index(['state', 'display_name'], 'ws_state_name_idx');
        });

        Schema::create(SchemaQualifier::table('warehouse_supplier_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wsv_public_id_uq');
            $table->foreignId('supplier_id')->constrained(SchemaQualifier::table('warehouse_suppliers'), indexName: 'wsv_supplier_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wsv_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('supplier_code_snapshot', 64);
            $table->string('display_name', 160);
            $table->string('synthetic_contact_name', 160)->nullable();
            $table->string('synthetic_email', 255)->nullable();
            $table->string('synthetic_phone', 64)->nullable();
            $table->string('synthetic_reference', 120)->nullable();
            $table->string('state', 16);
            $table->string('reason_code', 64);
            $table->unsignedBigInteger('previous_version_id')->nullable();
            $table->unsignedInteger('previous_version_number')->nullable();
            $table->string('previous_content_digest', 64)->nullable();
            $table->string('content_digest', 64);
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('created_at');
            $table->unique(['supplier_id', 'version'], 'wsv_supplier_version_uq');
            $table->unique(['id', 'supplier_id'], 'wsv_id_supplier_uq');
            $table->unique(['id', 'supplier_id', 'version', 'content_digest'], 'wsv_predecessor_identity_uq');
            $table->foreign(
                ['previous_version_id', 'supplier_id', 'previous_version_number', 'previous_content_digest'],
                'wsv_predecessor_fk'
            )->references(['id', 'supplier_id', 'version', 'content_digest'])
                ->on(SchemaQualifier::table('warehouse_supplier_versions'))->restrictOnDelete();
        });
    }

    private function createPurchaseOrderTables(): void
    {
        Schema::create(SchemaQualifier::table('warehouse_purchase_orders'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wpo_public_id_uq');
            $table->string('purchase_order_number', 64)->unique('wpo_number_uq');
            $table->foreignId('supplier_id')->constrained(SchemaQualifier::table('warehouse_suppliers'), indexName: 'wpo_supplier_fk')->restrictOnDelete();
            $table->foreignId('supplier_version_id')->constrained(SchemaQualifier::table('warehouse_supplier_versions'), indexName: 'wpo_supplier_version_fk')->restrictOnDelete();
            $table->foreignId('creator_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wpo_creator_fk')->restrictOnDelete();
            $table->string('state', 32)->default('DRAFT');
            $table->unsignedInteger('version')->default(1);
            $table->string('current_content_digest', 64);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'created_at'], 'wpo_state_created_idx');
            $table->unique(['id', 'supplier_id', 'supplier_version_id'], 'wpo_id_supplier_version_uq');
            $table->unique(['id', 'creator_user_id'], 'wpo_id_creator_uq');
            $table->foreign(['supplier_version_id', 'supplier_id'], 'wpo_supplier_identity_fk')
                ->references(['id', 'supplier_id'])->on(SchemaQualifier::table('warehouse_supplier_versions'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_purchase_order_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wpov_public_id_uq');
            $table->foreignId('purchase_order_id')->constrained(SchemaQualifier::table('warehouse_purchase_orders'), indexName: 'wpov_order_fk')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained(SchemaQualifier::table('warehouse_suppliers'), indexName: 'wpov_supplier_fk')->restrictOnDelete();
            $table->foreignId('supplier_version_id')->constrained(SchemaQualifier::table('warehouse_supplier_versions'), indexName: 'wpov_supplier_version_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wpov_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('state', 16);
            $table->string('supplier_code_snapshot', 64);
            $table->string('supplier_name_snapshot', 160);
            $table->unsignedBigInteger('previous_version_id')->nullable();
            $table->unsignedInteger('previous_version_number')->nullable();
            $table->string('previous_content_digest', 64)->nullable();
            $table->string('content_digest', 64);
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['purchase_order_id', 'version'], 'wpov_order_version_uq');
            $table->unique(['id', 'purchase_order_id', 'supplier_id', 'supplier_version_id'], 'wpov_id_order_supplier_uq');
            $table->unique(['id', 'purchase_order_id', 'version', 'content_digest'], 'wpov_predecessor_identity_uq');
            $table->foreign(['purchase_order_id', 'supplier_id', 'supplier_version_id'], 'wpov_order_supplier_fk')
                ->references(['id', 'supplier_id', 'supplier_version_id'])->on(SchemaQualifier::table('warehouse_purchase_orders'))->restrictOnDelete();
            $table->foreign(['supplier_version_id', 'supplier_id'], 'wpov_supplier_identity_fk')
                ->references(['id', 'supplier_id'])->on(SchemaQualifier::table('warehouse_supplier_versions'))->restrictOnDelete();
            $table->foreign(
                ['previous_version_id', 'purchase_order_id', 'previous_version_number', 'previous_content_digest'],
                'wpov_predecessor_fk'
            )->references(['id', 'purchase_order_id', 'version', 'content_digest'])
                ->on(SchemaQualifier::table('warehouse_purchase_order_versions'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_purchase_order_lines'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wpol_public_id_uq');
            $table->foreignId('purchase_order_version_id')->constrained(SchemaQualifier::table('warehouse_purchase_order_versions'), indexName: 'wpol_order_version_fk')->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained(SchemaQualifier::table('pharmacy_medicines'), indexName: 'wpol_medicine_fk')->restrictOnDelete();
            $table->foreignId('medicine_version_id')->constrained(SchemaQualifier::table('pharmacy_medicine_versions'), indexName: 'wpol_medicine_version_fk')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('medicine_code_snapshot', 64);
            $table->string('medicine_name_snapshot', 160);
            $table->string('base_unit_snapshot', 32);
            $table->unsignedBigInteger('ordered_quantity');
            $table->unsignedBigInteger('unit_acquisition_value');
            $table->string('content_digest', 64);
            $table->timestamp('created_at');
            $table->unique(['purchase_order_version_id', 'line_number'], 'wpol_version_line_uq');
            $table->unique(['purchase_order_version_id', 'medicine_version_id'], 'wpol_version_medicine_uq');
            $table->unique(['id', 'purchase_order_version_id', 'medicine_id', 'medicine_version_id'], 'wpol_id_version_medicine_uq');
            $table->foreign(['medicine_version_id', 'medicine_id'], 'wpol_medicine_identity_fk')
                ->references(['id', 'medicine_id'])->on(SchemaQualifier::table('pharmacy_medicine_versions'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_purchase_order_decisions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wpod_public_id_uq');
            $table->foreignId('purchase_order_id')->constrained(SchemaQualifier::table('warehouse_purchase_orders'), indexName: 'wpod_order_fk')->restrictOnDelete();
            $table->foreignId('purchase_order_version_id')->unique('wpod_version_uq')->constrained(SchemaQualifier::table('warehouse_purchase_order_versions'), indexName: 'wpod_version_fk')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained(SchemaQualifier::table('warehouse_suppliers'), indexName: 'wpod_supplier_fk')->restrictOnDelete();
            $table->foreignId('supplier_version_id')->constrained(SchemaQualifier::table('warehouse_supplier_versions'), indexName: 'wpod_supplier_version_fk')->restrictOnDelete();
            $table->foreignId('creator_user_id_snapshot')->constrained(SchemaQualifier::table('users'), indexName: 'wpod_creator_snapshot_fk')->restrictOnDelete();
            $table->foreignId('reviewer_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wpod_reviewer_fk')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('purchase_order_fingerprint', 64);
            $table->string('reason_code', 64);
            $table->string('note', 500)->nullable();
            $table->string('content_digest', 64);
            $table->timestamp('decided_at');
            $table->timestamp('created_at');
            $table->unique(
                ['id', 'purchase_order_id', 'purchase_order_version_id', 'supplier_id', 'supplier_version_id', 'decision'],
                'wpod_approved_receipt_identity_uq'
            );
            $table->foreign(
                ['purchase_order_version_id', 'purchase_order_id', 'supplier_id', 'supplier_version_id'],
                'wpod_order_version_identity_fk'
            )->references(['id', 'purchase_order_id', 'supplier_id', 'supplier_version_id'])
                ->on(SchemaQualifier::table('warehouse_purchase_order_versions'))->restrictOnDelete();
            $table->foreign(['purchase_order_id', 'creator_user_id_snapshot'], 'wpod_creator_identity_fk')
                ->references(['id', 'creator_user_id'])->on(SchemaQualifier::table('warehouse_purchase_orders'))->restrictOnDelete();
        });
    }

    private function createReceiptAndCustodyTables(): void
    {
        Schema::create(SchemaQualifier::table('warehouse_receipts'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wr_public_id_uq');
            $table->string('receipt_number', 64)->unique('wr_number_uq');
            $table->foreignId('purchase_order_id')->constrained(SchemaQualifier::table('warehouse_purchase_orders'), indexName: 'wr_order_fk')->restrictOnDelete();
            $table->foreignId('purchase_order_version_id')->constrained(SchemaQualifier::table('warehouse_purchase_order_versions'), indexName: 'wr_order_version_fk')->restrictOnDelete();
            $table->foreignId('purchase_order_decision_id')->constrained(SchemaQualifier::table('warehouse_purchase_order_decisions'), indexName: 'wr_order_decision_fk')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained(SchemaQualifier::table('warehouse_suppliers'), indexName: 'wr_supplier_fk')->restrictOnDelete();
            $table->foreignId('supplier_version_id')->constrained(SchemaQualifier::table('warehouse_supplier_versions'), indexName: 'wr_supplier_version_fk')->restrictOnDelete();
            $table->foreignId('receiver_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wr_receiver_fk')->restrictOnDelete();
            $table->string('approval_decision_snapshot', 16);
            $table->string('supplier_reference', 120);
            $table->unsignedBigInteger('presented_quantity');
            $table->unsignedBigInteger('accepted_quantity');
            $table->unsignedBigInteger('rejected_quantity');
            $table->unsignedBigInteger('quarantined_quantity');
            $table->string('variance_reason_code', 64)->nullable();
            $table->string('variance_note', 500)->nullable();
            $table->string('purchase_order_fingerprint', 64);
            $table->string('content_digest', 64);
            $table->timestamp('received_at');
            $table->timestamp('created_at');
            $table->unique(['supplier_version_id', 'supplier_reference'], 'wr_supplier_reference_uq');
            $table->unique(['id', 'purchase_order_version_id', 'supplier_id'], 'wr_id_version_supplier_uq');
            $table->foreign(
                ['purchase_order_decision_id', 'purchase_order_id', 'purchase_order_version_id', 'supplier_id', 'supplier_version_id', 'approval_decision_snapshot'],
                'wr_approved_order_identity_fk'
            )->references(['id', 'purchase_order_id', 'purchase_order_version_id', 'supplier_id', 'supplier_version_id', 'decision'])
                ->on(SchemaQualifier::table('warehouse_purchase_order_decisions'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_receipt_lines'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wrl_public_id_uq');
            $table->foreignId('receipt_id')->constrained(SchemaQualifier::table('warehouse_receipts'), indexName: 'wrl_receipt_fk')->restrictOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained(SchemaQualifier::table('warehouse_purchase_order_lines'), indexName: 'wrl_order_line_fk')->restrictOnDelete();
            $table->foreignId('purchase_order_version_id')->constrained(SchemaQualifier::table('warehouse_purchase_order_versions'), indexName: 'wrl_order_version_fk')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained(SchemaQualifier::table('warehouse_suppliers'), indexName: 'wrl_supplier_fk')->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained(SchemaQualifier::table('pharmacy_medicines'), indexName: 'wrl_medicine_fk')->restrictOnDelete();
            $table->foreignId('medicine_version_id')->constrained(SchemaQualifier::table('pharmacy_medicine_versions'), indexName: 'wrl_medicine_version_fk')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('medicine_code_snapshot', 64);
            $table->string('medicine_name_snapshot', 160);
            $table->string('base_unit_snapshot', 32);
            $table->string('lot_code', 80);
            $table->date('expiry_date');
            $table->unsignedBigInteger('ordered_quantity_snapshot');
            $table->unsignedBigInteger('presented_quantity');
            $table->unsignedBigInteger('accepted_quantity');
            $table->unsignedBigInteger('rejected_quantity');
            $table->unsignedBigInteger('quarantined_quantity');
            $table->unsignedBigInteger('unit_acquisition_value');
            $table->string('variance_reason_code', 64)->nullable();
            $table->string('variance_note', 500)->nullable();
            $table->string('content_digest', 64);
            $table->timestamp('created_at');
            $table->unique(['receipt_id', 'line_number'], 'wrl_receipt_line_uq');
            $table->unique(['receipt_id', 'purchase_order_line_id', 'lot_code'], 'wrl_order_lot_uq');
            $table->unique(
                ['id', 'receipt_id', 'supplier_id', 'medicine_id', 'medicine_version_id', 'lot_code', 'expiry_date', 'accepted_quantity', 'quarantined_quantity', 'unit_acquisition_value'],
                'wrl_allocation_identity_uq'
            );
            $table->foreign(['receipt_id', 'purchase_order_version_id', 'supplier_id'], 'wrl_receipt_identity_fk')
                ->references(['id', 'purchase_order_version_id', 'supplier_id'])
                ->on(SchemaQualifier::table('warehouse_receipts'))->restrictOnDelete();
            $table->foreign(
                ['purchase_order_line_id', 'purchase_order_version_id', 'medicine_id', 'medicine_version_id'],
                'wrl_order_line_identity_fk'
            )->references(['id', 'purchase_order_version_id', 'medicine_id', 'medicine_version_id'])
                ->on(SchemaQualifier::table('warehouse_purchase_order_lines'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_custody_lots'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wcl_public_id_uq');
            $table->foreignId('supplier_id')->constrained(SchemaQualifier::table('warehouse_suppliers'), indexName: 'wcl_supplier_fk')->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained(SchemaQualifier::table('pharmacy_medicines'), indexName: 'wcl_medicine_fk')->restrictOnDelete();
            $table->string('medicine_code_snapshot', 64);
            $table->string('base_unit_snapshot', 32);
            $table->string('lot_code', 80);
            $table->date('expiry_date');
            $table->timestamp('first_received_at');
            $table->string('content_digest', 64);
            $table->timestamp('created_at');
            $table->unique(['supplier_id', 'medicine_id', 'lot_code'], 'wcl_supplier_medicine_lot_uq');
            $table->unique(['id', 'supplier_id', 'medicine_id', 'lot_code', 'expiry_date'], 'wcl_allocation_identity_uq');
        });

        Schema::create(SchemaQualifier::table('warehouse_custody_receipt_allocations'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wcra_public_id_uq');
            $table->foreignId('receipt_id')->constrained(SchemaQualifier::table('warehouse_receipts'), indexName: 'wcra_receipt_fk')->restrictOnDelete();
            $table->foreignId('receipt_line_id')->unique('wcra_receipt_line_uq')->constrained(SchemaQualifier::table('warehouse_receipt_lines'), indexName: 'wcra_receipt_line_fk')->restrictOnDelete();
            $table->foreignId('custody_lot_id')->constrained(SchemaQualifier::table('warehouse_custody_lots'), indexName: 'wcra_custody_lot_fk')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained(SchemaQualifier::table('warehouse_suppliers'), indexName: 'wcra_supplier_fk')->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained(SchemaQualifier::table('pharmacy_medicines'), indexName: 'wcra_medicine_fk')->restrictOnDelete();
            $table->foreignId('medicine_version_id')->constrained(SchemaQualifier::table('pharmacy_medicine_versions'), indexName: 'wcra_medicine_version_fk')->restrictOnDelete();
            $table->string('lot_code_snapshot', 80);
            $table->date('expiry_date_snapshot');
            $table->unsignedBigInteger('available_quantity');
            $table->unsignedBigInteger('quarantined_quantity');
            $table->unsignedBigInteger('unit_acquisition_value_snapshot');
            $table->string('content_digest', 64);
            $table->timestamp('created_at');
            $table->unique(['receipt_line_id', 'receipt_id', 'custody_lot_id'], 'wcra_line_receipt_custody_uq');
            $table->unique(['receipt_line_id', 'receipt_id', 'custody_lot_id', 'supplier_id'], 'wcra_return_supplier_uq');
            $table->foreign(
                ['receipt_line_id', 'receipt_id', 'supplier_id', 'medicine_id', 'medicine_version_id', 'lot_code_snapshot', 'expiry_date_snapshot', 'available_quantity', 'quarantined_quantity', 'unit_acquisition_value_snapshot'],
                'wcra_receipt_line_identity_fk'
            )->references(['id', 'receipt_id', 'supplier_id', 'medicine_id', 'medicine_version_id', 'lot_code', 'expiry_date', 'accepted_quantity', 'quarantined_quantity', 'unit_acquisition_value'])
                ->on(SchemaQualifier::table('warehouse_receipt_lines'))->restrictOnDelete();
            $table->foreign(
                ['custody_lot_id', 'supplier_id', 'medicine_id', 'lot_code_snapshot', 'expiry_date_snapshot'],
                'wcra_custody_identity_fk'
            )->references(['id', 'supplier_id', 'medicine_id', 'lot_code', 'expiry_date'])
                ->on(SchemaQualifier::table('warehouse_custody_lots'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_custody_movement_sets'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wcms_public_id_uq');
            $table->foreignId('custody_lot_id')->constrained(SchemaQualifier::table('warehouse_custody_lots'), indexName: 'wcms_custody_lot_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wcms_actor_fk')->restrictOnDelete();
            $table->string('movement_set_type', 40);
            $table->string('source_type', 32);
            $table->string('source_public_id', 26);
            $table->string('content_digest', 64);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
            $table->unique(['movement_set_type', 'source_type', 'source_public_id', 'custody_lot_id'], 'wcms_source_custody_uq');
            $table->unique(['id', 'custody_lot_id'], 'wcms_id_custody_uq');
            $table->unique(['id', 'custody_lot_id', 'movement_set_type'], 'wcms_pair_parent_uq');
        });

        Schema::create(SchemaQualifier::table('warehouse_custody_movement_pairs'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wcmp_public_id_uq');
            $table->foreignId('movement_set_id')->constrained(SchemaQualifier::table('warehouse_custody_movement_sets'), indexName: 'wcmp_movement_set_fk')->restrictOnDelete();
            $table->foreignId('custody_lot_id')->constrained(SchemaQualifier::table('warehouse_custody_lots'), indexName: 'wcmp_custody_lot_fk')->restrictOnDelete();
            $table->string('movement_set_type_snapshot', 40);
            $table->string('pair_type', 40);
            $table->unsignedBigInteger('quantity');
            $table->string('content_digest', 64);
            $table->timestamp('created_at');
            $table->unique(
                ['id', 'movement_set_id', 'custody_lot_id', 'public_id', 'pair_type', 'quantity'],
                'wcmp_movement_identity_uq'
            );
            $table->foreign(
                ['movement_set_id', 'custody_lot_id', 'movement_set_type_snapshot'],
                'wcmp_movement_set_identity_fk'
            )->references(['id', 'custody_lot_id', 'movement_set_type'])
                ->on(SchemaQualifier::table('warehouse_custody_movement_sets'))->restrictOnDelete();
        });
    }

    private function createTransferTables(): void
    {
        Schema::create(SchemaQualifier::table('warehouse_transfers'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wt_public_id_uq');
            $table->string('transfer_number', 64)->unique('wt_number_uq');
            $table->foreignId('source_depot_id')->constrained(SchemaQualifier::table('pharmacy_depots'), indexName: 'wt_source_depot_fk')->restrictOnDelete();
            $table->foreignId('source_depot_version_id')->constrained(SchemaQualifier::table('pharmacy_depot_versions'), indexName: 'wt_source_version_fk')->restrictOnDelete();
            $table->foreignId('destination_depot_id')->constrained(SchemaQualifier::table('pharmacy_depots'), indexName: 'wt_destination_depot_fk')->restrictOnDelete();
            $table->foreignId('destination_depot_version_id')->constrained(SchemaQualifier::table('pharmacy_depot_versions'), indexName: 'wt_destination_version_fk')->restrictOnDelete();
            $table->foreignId('dispatcher_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wt_dispatcher_fk')->restrictOnDelete();
            $table->string('state', 16)->default('DISPATCHED');
            $table->unsignedInteger('version')->default(1);
            $table->string('current_content_digest', 64);
            $table->timestamp('dispatched_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['destination_depot_id', 'state', 'dispatched_at'], 'wt_destination_state_idx');
            $table->unique(['id', 'dispatcher_user_id'], 'wt_id_dispatcher_uq');
            $table->unique(['id', 'source_depot_id'], 'wt_id_source_depot_uq');
            $table->foreign(['source_depot_version_id', 'source_depot_id'], 'wt_source_depot_identity_fk')
                ->references(['id', 'depot_id'])->on(SchemaQualifier::table('pharmacy_depot_versions'))->restrictOnDelete();
            $table->foreign(['destination_depot_version_id', 'destination_depot_id'], 'wt_destination_depot_identity_fk')
                ->references(['id', 'depot_id'])->on(SchemaQualifier::table('pharmacy_depot_versions'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_transfer_items'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wti_public_id_uq');
            $table->foreignId('transfer_id')->constrained(SchemaQualifier::table('warehouse_transfers'), indexName: 'wti_transfer_fk')->restrictOnDelete();
            $table->foreignId('custody_lot_id')->constrained(SchemaQualifier::table('warehouse_custody_lots'), indexName: 'wti_custody_lot_fk')->restrictOnDelete();
            $table->foreignId('source_stock_lot_id')->constrained(SchemaQualifier::table('pharmacy_stock_lots'), indexName: 'wti_source_lot_fk')->restrictOnDelete();
            $table->foreignId('source_depot_id')->constrained(SchemaQualifier::table('pharmacy_depots'), indexName: 'wti_source_depot_fk')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('medicine_code_snapshot', 64);
            $table->string('base_unit_snapshot', 32);
            $table->string('lot_code_snapshot', 80);
            $table->date('expiry_date_snapshot');
            $table->unsignedBigInteger('quantity');
            $table->string('pair_public_id', 26)->unique('wti_pair_uq');
            $table->string('source_lot_fingerprint', 64);
            $table->string('content_digest', 64);
            $table->timestamp('created_at');
            $table->unique(['transfer_id', 'line_number'], 'wti_transfer_line_uq');
            $table->unique(['transfer_id', 'custody_lot_id'], 'wti_transfer_lot_uq');
            $table->unique(['id', 'custody_lot_id'], 'wti_id_custody_uq');
            $table->unique(['id', 'transfer_id', 'custody_lot_id'], 'wti_return_identity_uq');
            $table->foreign(['transfer_id', 'source_depot_id'], 'wti_transfer_source_fk')
                ->references(['id', 'source_depot_id'])->on(SchemaQualifier::table('warehouse_transfers'))->restrictOnDelete();
            $table->foreign(['source_stock_lot_id', 'custody_lot_id', 'source_depot_id'], 'wti_source_custody_fk')
                ->references(['id', 'warehouse_custody_lot_id', 'depot_id'])->on(SchemaQualifier::table('pharmacy_stock_lots'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_transfer_decisions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wtd_public_id_uq');
            $table->foreignId('transfer_id')->unique('wtd_transfer_uq')->constrained(SchemaQualifier::table('warehouse_transfers'), indexName: 'wtd_transfer_fk')->restrictOnDelete();
            $table->foreignId('dispatcher_user_id_snapshot')->constrained(SchemaQualifier::table('users'), indexName: 'wtd_dispatcher_snapshot_fk')->restrictOnDelete();
            $table->foreignId('acceptor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wtd_acceptor_fk')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('rejection_disposition', 32)->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('transfer_fingerprint', 64);
            $table->string('content_digest', 64);
            $table->timestamp('decided_at');
            $table->timestamp('created_at');
            $table->foreign(['transfer_id', 'dispatcher_user_id_snapshot'], 'wtd_dispatcher_identity_fk')
                ->references(['id', 'dispatcher_user_id'])->on(SchemaQualifier::table('warehouse_transfers'))->restrictOnDelete();
        });
    }

    private function createReturnTables(): void
    {
        Schema::create(SchemaQualifier::table('warehouse_supplier_returns'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wsr_public_id_uq');
            $table->string('return_number', 64)->unique('wsr_number_uq');
            $table->foreignId('supplier_id')->constrained(SchemaQualifier::table('warehouse_suppliers'), indexName: 'wsr_supplier_fk')->restrictOnDelete();
            $table->foreignId('supplier_version_id')->constrained(SchemaQualifier::table('warehouse_supplier_versions'), indexName: 'wsr_supplier_version_fk')->restrictOnDelete();
            $table->foreignId('requester_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wsr_requester_fk')->restrictOnDelete();
            $table->string('state', 16)->default('REQUESTED');
            $table->unsignedInteger('version')->default(1);
            $table->string('current_content_digest', 64);
            $table->timestamp('requested_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'requester_user_id'], 'wsr_id_requester_uq');
            $table->unique(['id', 'supplier_id'], 'wsr_id_supplier_uq');
            $table->foreign(['supplier_version_id', 'supplier_id'], 'wsr_supplier_identity_fk')
                ->references(['id', 'supplier_id'])->on(SchemaQualifier::table('warehouse_supplier_versions'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_supplier_return_items'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wsri_public_id_uq');
            $table->foreignId('supplier_return_id')->constrained(SchemaQualifier::table('warehouse_supplier_returns'), indexName: 'wsri_return_fk')->restrictOnDelete();
            $table->foreignId('receipt_id')->constrained(SchemaQualifier::table('warehouse_receipts'), indexName: 'wsri_receipt_fk')->restrictOnDelete();
            $table->foreignId('receipt_line_id')->constrained(SchemaQualifier::table('warehouse_receipt_lines'), indexName: 'wsri_receipt_line_fk')->restrictOnDelete();
            $table->foreignId('custody_lot_id')->constrained(SchemaQualifier::table('warehouse_custody_lots'), indexName: 'wsri_custody_lot_fk')->restrictOnDelete();
            $table->foreignId('source_stock_lot_id')->constrained(SchemaQualifier::table('pharmacy_stock_lots'), indexName: 'wsri_source_lot_fk')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained(SchemaQualifier::table('warehouse_suppliers'), indexName: 'wsri_supplier_fk')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->unsignedBigInteger('quantity');
            $table->string('reason_code', 64);
            $table->string('source_fingerprint', 64);
            $table->string('content_digest', 64);
            $table->timestamp('created_at');
            $table->unique(['supplier_return_id', 'line_number'], 'wsri_return_line_uq');
            $table->unique(['supplier_return_id', 'receipt_line_id'], 'wsri_return_receipt_line_uq');
            $table->foreign(['supplier_return_id', 'supplier_id'], 'wsri_return_supplier_fk')
                ->references(['id', 'supplier_id'])->on(SchemaQualifier::table('warehouse_supplier_returns'))->restrictOnDelete();
            $table->foreign(['receipt_line_id', 'receipt_id', 'custody_lot_id', 'supplier_id'], 'wsri_allocation_identity_fk')
                ->references(['receipt_line_id', 'receipt_id', 'custody_lot_id', 'supplier_id'])
                ->on(SchemaQualifier::table('warehouse_custody_receipt_allocations'))->restrictOnDelete();
            $table->foreign(['source_stock_lot_id', 'custody_lot_id'], 'wsri_source_custody_fk')
                ->references(['id', 'warehouse_custody_lot_id'])->on(SchemaQualifier::table('pharmacy_stock_lots'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_supplier_return_decisions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wsrd_public_id_uq');
            $table->foreignId('supplier_return_id')->unique('wsrd_return_uq')->constrained(SchemaQualifier::table('warehouse_supplier_returns'), indexName: 'wsrd_return_fk')->restrictOnDelete();
            $table->foreignId('requester_user_id_snapshot')->constrained(SchemaQualifier::table('users'), indexName: 'wsrd_requester_snapshot_fk')->restrictOnDelete();
            $table->foreignId('approver_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wsrd_approver_fk')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('return_fingerprint', 64);
            $table->string('reason_code', 64);
            $table->string('note', 500)->nullable();
            $table->string('content_digest', 64);
            $table->timestamp('decided_at');
            $table->timestamp('created_at');
            $table->foreign(['supplier_return_id', 'requester_user_id_snapshot'], 'wsrd_requester_identity_fk')
                ->references(['id', 'requester_user_id'])->on(SchemaQualifier::table('warehouse_supplier_returns'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_unit_returns'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wur_public_id_uq');
            $table->string('return_number', 64)->unique('wur_number_uq');
            $table->foreignId('original_transfer_id')->constrained(SchemaQualifier::table('warehouse_transfers'), indexName: 'wur_transfer_fk')->restrictOnDelete();
            $table->foreignId('source_depot_id')->constrained(SchemaQualifier::table('pharmacy_depots'), indexName: 'wur_source_depot_fk')->restrictOnDelete();
            $table->foreignId('source_depot_version_id')->constrained(SchemaQualifier::table('pharmacy_depot_versions'), indexName: 'wur_source_version_fk')->restrictOnDelete();
            $table->foreignId('destination_depot_id')->constrained(SchemaQualifier::table('pharmacy_depots'), indexName: 'wur_destination_depot_fk')->restrictOnDelete();
            $table->foreignId('destination_depot_version_id')->constrained(SchemaQualifier::table('pharmacy_depot_versions'), indexName: 'wur_destination_version_fk')->restrictOnDelete();
            $table->foreignId('requester_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wur_requester_fk')->restrictOnDelete();
            $table->string('state', 16)->default('DISPATCHED');
            $table->unsignedInteger('version')->default(1);
            $table->string('current_content_digest', 64);
            $table->timestamp('dispatched_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'requester_user_id'], 'wur_id_requester_uq');
            $table->unique(['id', 'original_transfer_id'], 'wur_id_original_transfer_uq');
            $table->foreign(['source_depot_version_id', 'source_depot_id'], 'wur_source_depot_identity_fk')
                ->references(['id', 'depot_id'])->on(SchemaQualifier::table('pharmacy_depot_versions'))->restrictOnDelete();
            $table->foreign(['destination_depot_version_id', 'destination_depot_id'], 'wur_destination_depot_identity_fk')
                ->references(['id', 'depot_id'])->on(SchemaQualifier::table('pharmacy_depot_versions'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_unit_return_items'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wuri_public_id_uq');
            $table->foreignId('unit_return_id')->constrained(SchemaQualifier::table('warehouse_unit_returns'), indexName: 'wuri_return_fk')->restrictOnDelete();
            $table->foreignId('original_transfer_item_id')->constrained(SchemaQualifier::table('warehouse_transfer_items'), indexName: 'wuri_transfer_item_fk')->restrictOnDelete();
            $table->foreignId('original_transfer_id')->constrained(SchemaQualifier::table('warehouse_transfers'), indexName: 'wuri_transfer_fk')->restrictOnDelete();
            $table->foreignId('custody_lot_id')->constrained(SchemaQualifier::table('warehouse_custody_lots'), indexName: 'wuri_custody_lot_fk')->restrictOnDelete();
            $table->foreignId('source_stock_lot_id')->constrained(SchemaQualifier::table('pharmacy_stock_lots'), indexName: 'wuri_source_lot_fk')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->unsignedBigInteger('quantity');
            $table->boolean('intact_custody');
            $table->string('reason_code', 64);
            $table->string('pair_public_id', 26)->unique('wuri_pair_uq');
            $table->string('source_fingerprint', 64);
            $table->string('content_digest', 64);
            $table->timestamp('created_at');
            $table->unique(['unit_return_id', 'line_number'], 'wuri_return_line_uq');
            $table->unique(['unit_return_id', 'original_transfer_item_id'], 'wuri_return_transfer_item_uq');
            $table->foreign(['unit_return_id', 'original_transfer_id'], 'wuri_return_transfer_fk')
                ->references(['id', 'original_transfer_id'])->on(SchemaQualifier::table('warehouse_unit_returns'))->restrictOnDelete();
            $table->foreign(['original_transfer_item_id', 'original_transfer_id', 'custody_lot_id'], 'wuri_transfer_custody_fk')
                ->references(['id', 'transfer_id', 'custody_lot_id'])->on(SchemaQualifier::table('warehouse_transfer_items'))->restrictOnDelete();
            $table->foreign(['source_stock_lot_id', 'custody_lot_id'], 'wuri_source_custody_fk')
                ->references(['id', 'warehouse_custody_lot_id'])->on(SchemaQualifier::table('pharmacy_stock_lots'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_unit_return_decisions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wurd_public_id_uq');
            $table->foreignId('unit_return_id')->unique('wurd_return_uq')->constrained(SchemaQualifier::table('warehouse_unit_returns'), indexName: 'wurd_return_fk')->restrictOnDelete();
            $table->foreignId('requester_user_id_snapshot')->constrained(SchemaQualifier::table('users'), indexName: 'wurd_requester_snapshot_fk')->restrictOnDelete();
            $table->foreignId('acceptor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wurd_acceptor_fk')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('destination_disposition', 32)->nullable();
            $table->string('return_fingerprint', 64);
            $table->string('reason_code', 64)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('content_digest', 64);
            $table->timestamp('decided_at');
            $table->timestamp('created_at');
            $table->foreign(['unit_return_id', 'requester_user_id_snapshot'], 'wurd_requester_identity_fk')
                ->references(['id', 'requester_user_id'])->on(SchemaQualifier::table('warehouse_unit_returns'))->restrictOnDelete();
        });
    }

    private function createCorrectionAndOperationTables(): void
    {
        Schema::create(SchemaQualifier::table('warehouse_correction_requests'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wcr_public_id_uq');
            $table->string('correction_number', 64)->unique('wcr_number_uq');
            $table->foreignId('requester_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wcr_requester_fk')->restrictOnDelete();
            $table->foreignId('custody_lot_id')->constrained(SchemaQualifier::table('warehouse_custody_lots'), indexName: 'wcr_custody_lot_fk')->restrictOnDelete();
            $table->foreignId('stock_lot_id')->constrained(SchemaQualifier::table('pharmacy_stock_lots'), indexName: 'wcr_stock_lot_fk')->restrictOnDelete();
            $table->string('source_type', 32);
            $table->string('source_public_id', 26);
            $table->bigInteger('available_delta');
            $table->bigInteger('quarantined_delta');
            $table->bigInteger('transit_delta');
            $table->string('reason_code', 64);
            $table->string('explanation', 500);
            $table->string('source_fingerprint', 64);
            $table->string('content_digest', 64);
            $table->timestamp('requested_at');
            $table->timestamp('created_at');
            $table->unique(
                ['id', 'custody_lot_id', 'stock_lot_id', 'requester_user_id', 'available_delta', 'quarantined_delta', 'transit_delta'],
                'wcr_compensation_identity_uq'
            );
            $table->foreign(['stock_lot_id', 'custody_lot_id'], 'wcr_stock_custody_fk')
                ->references(['id', 'warehouse_custody_lot_id'])->on(SchemaQualifier::table('pharmacy_stock_lots'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_correction_decisions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wcd_public_id_uq');
            $table->foreignId('correction_request_id')->unique('wcd_request_uq')->constrained(SchemaQualifier::table('warehouse_correction_requests'), indexName: 'wcd_request_fk')->restrictOnDelete();
            $table->foreignId('custody_lot_id')->constrained(SchemaQualifier::table('warehouse_custody_lots'), indexName: 'wcd_custody_lot_fk')->restrictOnDelete();
            $table->foreignId('stock_lot_id')->constrained(SchemaQualifier::table('pharmacy_stock_lots'), indexName: 'wcd_stock_lot_fk')->restrictOnDelete();
            $table->foreignId('requester_user_id_snapshot')->constrained(SchemaQualifier::table('users'), indexName: 'wcd_requester_snapshot_fk')->restrictOnDelete();
            $table->foreignId('supervisor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wcd_supervisor_fk')->restrictOnDelete();
            $table->bigInteger('requested_available_delta_snapshot');
            $table->bigInteger('requested_quarantined_delta_snapshot');
            $table->bigInteger('requested_transit_delta_snapshot');
            $table->string('decision', 16);
            $table->string('request_fingerprint', 64);
            $table->string('reason_code', 64);
            $table->string('note', 500)->nullable();
            $table->string('content_digest', 64);
            $table->timestamp('decided_at');
            $table->timestamp('created_at');
            $table->unique(
                ['id', 'correction_request_id', 'custody_lot_id', 'stock_lot_id', 'requester_user_id_snapshot', 'supervisor_user_id', 'decision', 'requested_available_delta_snapshot', 'requested_quarantined_delta_snapshot', 'requested_transit_delta_snapshot'],
                'wcd_approved_compensation_identity_uq'
            );
            $table->foreign(
                ['correction_request_id', 'custody_lot_id', 'stock_lot_id', 'requester_user_id_snapshot', 'requested_available_delta_snapshot', 'requested_quarantined_delta_snapshot', 'requested_transit_delta_snapshot'],
                'wcd_request_identity_fk'
            )->references(['id', 'custody_lot_id', 'stock_lot_id', 'requester_user_id', 'available_delta', 'quarantined_delta', 'transit_delta'])
                ->on(SchemaQualifier::table('warehouse_correction_requests'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_correction_compensations'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wcc_public_id_uq');
            $table->foreignId('correction_request_id')->unique('wcc_request_uq')->constrained(SchemaQualifier::table('warehouse_correction_requests'), indexName: 'wcc_request_fk')->restrictOnDelete();
            $table->foreignId('correction_decision_id')->unique('wcc_decision_uq')->constrained(SchemaQualifier::table('warehouse_correction_decisions'), indexName: 'wcc_decision_fk')->restrictOnDelete();
            $table->foreignId('custody_lot_id')->constrained(SchemaQualifier::table('warehouse_custody_lots'), indexName: 'wcc_custody_lot_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wcc_actor_fk')->restrictOnDelete();
            $table->foreignId('stock_lot_id')->constrained(SchemaQualifier::table('pharmacy_stock_lots'), indexName: 'wcc_stock_lot_fk')->restrictOnDelete();
            $table->foreignId('supervisor_user_id_snapshot')->constrained(SchemaQualifier::table('users'), indexName: 'wcc_supervisor_snapshot_fk')->restrictOnDelete();
            $table->foreignId('movement_set_id')->unique('wcc_movement_set_uq')->constrained(SchemaQualifier::table('warehouse_custody_movement_sets'), indexName: 'wcc_movement_set_fk')->restrictOnDelete();
            $table->string('approval_decision_snapshot', 16);
            $table->bigInteger('available_delta');
            $table->bigInteger('quarantined_delta');
            $table->bigInteger('transit_delta');
            $table->unsignedBigInteger('available_balance_after');
            $table->unsignedBigInteger('quarantined_balance_after');
            $table->unsignedBigInteger('transit_balance_after');
            $table->string('movement_set_digest', 64);
            $table->string('content_digest', 64);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
            $table->foreign(
                ['correction_decision_id', 'correction_request_id', 'custody_lot_id', 'stock_lot_id', 'supervisor_user_id_snapshot', 'approval_decision_snapshot', 'available_delta', 'quarantined_delta', 'transit_delta'],
                'wcc_approved_decision_identity_fk'
            )->references(['id', 'correction_request_id', 'custody_lot_id', 'stock_lot_id', 'supervisor_user_id', 'decision', 'requested_available_delta_snapshot', 'requested_quarantined_delta_snapshot', 'requested_transit_delta_snapshot'])
                ->on(SchemaQualifier::table('warehouse_correction_decisions'))->restrictOnDelete();
            $table->foreign(['movement_set_id', 'custody_lot_id'], 'wcc_movement_set_custody_fk')
                ->references(['id', 'custody_lot_id'])
                ->on(SchemaQualifier::table('warehouse_custody_movement_sets'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('warehouse_operation_receipts'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('wor_public_id_uq');
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'wor_actor_fk')->restrictOnDelete();
            $table->ulid('audit_event_id')->unique('wor_audit_event_uq');
            $table->string('audit_action_snapshot', 64);
            $table->string('audit_resource_type_snapshot', 64);
            $table->string('operation', 64);
            $table->string('idempotency_key', 255);
            $table->string('payload_digest', 64);
            $table->string('result_type', 32);
            $table->string('result_public_id', 26);
            $table->unsignedInteger('result_version');
            $table->string('result_state', 32);
            $table->string('result_digest', 64);
            $table->unsignedBigInteger('control_total');
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('completed_at');
            $table->timestamps();
            $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'wor_actor_operation_key_uq');
            $table->unique(['operation', 'result_public_id', 'result_version'], 'wor_operation_result_uq');
            $table->foreign('audit_event_id', 'wor_audit_event_fk')
                ->references('id')->on(SchemaQualifier::table('audit_events'))->restrictOnDelete();
            $table->foreign(['audit_event_id', 'actor_user_id'], 'wor_audit_actor_fk')
                ->references(['id', 'actor_user_id'])->on(SchemaQualifier::table('audit_events'))->restrictOnDelete();
            $table->foreign(['audit_event_id', 'audit_action_snapshot', 'audit_resource_type_snapshot'], 'wor_audit_action_fk')
                ->references(['id', 'action', 'resource_type'])->on(SchemaQualifier::table('audit_events'))->restrictOnDelete();
            $table->foreign(['audit_event_id', 'result_public_id', 'result_version'], 'wor_audit_result_fk')
                ->references(['id', 'resource_id', 'warehouse_result_version_snapshot'])
                ->on(SchemaQualifier::table('audit_events'))->restrictOnDelete();
            $table->foreign(['audit_event_id', 'operation', 'result_digest', 'control_total'], 'wor_audit_control_fk')
                ->references(['id', 'warehouse_operation_snapshot', 'warehouse_result_digest_snapshot', 'warehouse_control_total_snapshot'])
                ->on(SchemaQualifier::table('audit_events'))->restrictOnDelete();
        });
    }

    private function addCustodyLotForeign(): void
    {
        if (! $this->foreignExists('pharmacy_stock_lots', 'warehouse_custody_lot_id')) {
            Schema::table(SchemaQualifier::table('pharmacy_stock_lots'), function (Blueprint $table): void {
                $table->foreign('warehouse_custody_lot_id', 'psl_wc_lot_fk')
                    ->references('id')->on(SchemaQualifier::table('warehouse_custody_lots'))->restrictOnDelete();
            });
        }
    }

    private function addMovementSetForeign(): void
    {
        if (! $this->foreignColumnsExist('pharmacy_stock_movements', ['warehouse_movement_set_id', 'warehouse_custody_lot_id'])) {
            Schema::table(SchemaQualifier::table('pharmacy_stock_movements'), function (Blueprint $table): void {
                $table->foreign(
                    ['warehouse_movement_set_id', 'warehouse_custody_lot_id'],
                    'psm_movement_set_custody_fk'
                )->references(['id', 'custody_lot_id'])
                    ->on(SchemaQualifier::table('warehouse_custody_movement_sets'))->restrictOnDelete();
            });
        }
        if (! $this->foreignColumnsExist('pharmacy_stock_movements', ['stock_lot_id', 'warehouse_custody_lot_id'])) {
            Schema::table(SchemaQualifier::table('pharmacy_stock_movements'), function (Blueprint $table): void {
                $table->foreign(
                    ['stock_lot_id', 'warehouse_custody_lot_id'],
                    'psm_stock_custody_fk'
                )->references(['id', 'warehouse_custody_lot_id'])
                    ->on(SchemaQualifier::table('pharmacy_stock_lots'))->restrictOnDelete();
            });
        }
        if (! $this->foreignColumnsExist('pharmacy_stock_movements', [
            'warehouse_movement_pair_id', 'warehouse_movement_set_id', 'warehouse_custody_lot_id',
            'pair_public_id', 'pair_type', 'pair_quantity',
        ])) {
            Schema::table(SchemaQualifier::table('pharmacy_stock_movements'), function (Blueprint $table): void {
                $table->foreign(
                    [
                        'warehouse_movement_pair_id', 'warehouse_movement_set_id', 'warehouse_custody_lot_id',
                        'pair_public_id', 'pair_type', 'pair_quantity',
                    ],
                    'psm_pair_identity_fk'
                )->references(['id', 'movement_set_id', 'custody_lot_id', 'public_id', 'pair_type', 'quantity'])
                    ->on(SchemaQualifier::table('warehouse_custody_movement_pairs'))->restrictOnDelete();
            });
        }
    }

    private function dropMovementSetForeignIfPresent(): void
    {
        $table = SchemaQualifier::table('pharmacy_stock_movements');
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'warehouse_movement_set_id')) {
            return;
        }
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }
        foreach (Schema::getForeignKeys($table) as $foreign) {
            $columns = $foreign['columns'] ?? [];
            $isWarehouseForeign = in_array($columns, [
                ['warehouse_movement_set_id', 'warehouse_custody_lot_id'],
                ['stock_lot_id', 'warehouse_custody_lot_id'],
                [
                    'warehouse_movement_pair_id', 'warehouse_movement_set_id', 'warehouse_custody_lot_id',
                    'pair_public_id', 'pair_type', 'pair_quantity',
                ],
            ], true);
            if ($isWarehouseForeign && is_array($foreign) && is_string($foreign['name'] ?? null)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($foreign['name']));
            }
        }
    }

    private function dropCustodyLotForeignIfPresent(): void
    {
        if (! Schema::hasTable(SchemaQualifier::table('pharmacy_stock_lots'))
            || ! Schema::hasColumn(SchemaQualifier::table('pharmacy_stock_lots'), 'warehouse_custody_lot_id')) {
            return;
        }
        // SQLite retains the declared foreign across a drop/recreate of the
        // empty referenced table. Remove it together with the column in down().
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }
        $foreign = collect(Schema::getForeignKeys(SchemaQualifier::table('pharmacy_stock_lots')))
            ->first(fn (array $key): bool => ($key['columns'] ?? null) === ['warehouse_custody_lot_id']);
        if (is_array($foreign) && is_string($foreign['name'] ?? null)) {
            Schema::table(SchemaQualifier::table('pharmacy_stock_lots'), fn (Blueprint $table) => $table->dropForeign($foreign['name']));
        }
    }

    private function foreignExists(string $table, string $column): bool
    {
        return $this->foreignColumnsExist($table, [$column]);
    }

    /** @param list<string> $columns */
    private function foreignColumnsExist(string $table, array $columns): bool
    {
        return collect(Schema::getForeignKeys(SchemaQualifier::table($table)))
            ->contains(fn (array $key): bool => ($key['columns'] ?? null) === $columns);
    }

    private function extendedPharmacyEvidenceExists(): bool
    {
        if (Schema::hasColumn(SchemaQualifier::table('pharmacy_depots'), 'location_kind')
            && DB::table(SchemaQualifier::table('pharmacy_depots'))->where('location_kind', '!=', 'DISPENSING_DEPOT')->exists()) {
            return true;
        }
        if (Schema::hasColumn(SchemaQualifier::table('pharmacy_depot_versions'), 'location_kind')
            && DB::table(SchemaQualifier::table('pharmacy_depot_versions'))->where('location_kind', '!=', 'DISPENSING_DEPOT')->exists()) {
            return true;
        }
        $lots = SchemaQualifier::table('pharmacy_stock_lots');
        foreach (['warehouse_custody_lot_id', 'warehouse_source_type', 'warehouse_source_public_id'] as $column) {
            if (Schema::hasColumn($lots, $column) && DB::table($lots)->whereNotNull($column)->exists()) {
                return true;
            }
        }
        if (Schema::hasColumn($lots, 'transit_quantity') && DB::table($lots)->where('transit_quantity', '!=', 0)->exists()) {
            return true;
        }

        $movements = SchemaQualifier::table('pharmacy_stock_movements');
        foreach (['transit_delta', 'transit_balance_after'] as $column) {
            if (Schema::hasColumn($movements, $column) && DB::table($movements)->where($column, '!=', 0)->exists()) {
                return true;
            }
        }
        foreach (['custody_chain_public_id', 'warehouse_movement_set_id', 'warehouse_custody_lot_id', 'warehouse_movement_pair_id', 'pair_public_id', 'pair_type', 'pair_quantity', 'pair_slot', 'pair_leg', 'pair_bucket'] as $column) {
            if (Schema::hasColumn($movements, $column) && DB::table($movements)->whereNotNull($column)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function extendedAuditEvidenceExists(): bool
    {
        $audit = SchemaQualifier::table('audit_events');
        foreach ([
            'warehouse_operation_snapshot', 'warehouse_result_version_snapshot',
            'warehouse_result_digest_snapshot', 'warehouse_control_total_snapshot',
        ] as $column) {
            if (Schema::hasColumn($audit, $column) && DB::table($audit)->whereNotNull($column)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function removePharmacyExtensions(): void
    {
        $movements = SchemaQualifier::table('pharmacy_stock_movements');
        if (DB::connection()->getDriverName() === 'sqlite'
            && Schema::hasColumn($movements, 'warehouse_movement_set_id')) {
            if ($this->foreignColumnsExist('pharmacy_stock_movements', ['warehouse_movement_set_id', 'warehouse_custody_lot_id'])) {
                Schema::table($movements, fn (Blueprint $table) => $table->dropForeign(['warehouse_movement_set_id', 'warehouse_custody_lot_id']));
            }
            if ($this->foreignColumnsExist('pharmacy_stock_movements', ['stock_lot_id', 'warehouse_custody_lot_id'])) {
                Schema::table($movements, fn (Blueprint $table) => $table->dropForeign(['stock_lot_id', 'warehouse_custody_lot_id']));
            }
            $pairColumns = [
                'warehouse_movement_pair_id', 'warehouse_movement_set_id', 'warehouse_custody_lot_id',
                'pair_public_id', 'pair_type', 'pair_quantity',
            ];
            if ($this->foreignColumnsExist('pharmacy_stock_movements', $pairColumns)) {
                Schema::table($movements, fn (Blueprint $table) => $table->dropForeign($pairColumns));
            }
        }
        $this->dropIndexIfPresent('pharmacy_stock_movements', 'psm_pair_leg_uq', true);
        $this->dropIndexIfPresent('pharmacy_stock_movements', 'psm_pair_slot_uq', true);
        $this->dropIndexIfPresent('pharmacy_stock_movements', 'psm_custody_order_idx');
        $this->dropIndexIfPresent('pharmacy_stock_movements', 'psm_movement_set_idx');
        $movementColumns = collect([
            'transit_delta', 'transit_balance_after', 'custody_chain_public_id',
            'warehouse_movement_set_id', 'warehouse_custody_lot_id', 'warehouse_movement_pair_id',
            'pair_public_id', 'pair_type', 'pair_quantity', 'pair_slot', 'pair_leg', 'pair_bucket',
        ])->filter(fn (string $column): bool => Schema::hasColumn($movements, $column))->values()->all();
        if ($movementColumns !== []) {
            Schema::table($movements, fn (Blueprint $table) => $table->dropColumn($movementColumns));
        }

        $lots = SchemaQualifier::table('pharmacy_stock_lots');
        if (Schema::hasColumn($lots, 'warehouse_custody_lot_id')
            && DB::connection()->getDriverName() === 'sqlite'
            && $this->foreignExists('pharmacy_stock_lots', 'warehouse_custody_lot_id')) {
            Schema::table($lots, function (Blueprint $table): void {
                $table->dropForeign(['warehouse_custody_lot_id']);
            });
        }
        $this->dropIndexIfPresent('pharmacy_stock_lots', 'psl_wc_depot_idx');
        $this->dropIndexIfPresent('pharmacy_stock_lots', 'psl_id_wc_lot_uq', true);
        $this->dropIndexIfPresent('pharmacy_stock_lots', 'psl_id_wc_depot_uq', true);
        $lotColumns = collect(['warehouse_custody_lot_id', 'warehouse_source_type', 'warehouse_source_public_id', 'transit_quantity'])
            ->filter(fn (string $column): bool => Schema::hasColumn($lots, $column))->values()->all();
        if ($lotColumns !== []) {
            Schema::table($lots, fn (Blueprint $table) => $table->dropColumn($lotColumns));
        }

        $this->dropIndexIfPresent('pharmacy_depot_versions', 'pdv_id_depot_uq', true);
        $this->dropIndexIfPresent('pharmacy_medicine_versions', 'pmv_id_medicine_uq', true);
        foreach ([
            'pharmacy_depot_versions' => 'pdv_location_kind_idx',
            'pharmacy_depots' => 'pd_location_kind_idx',
        ] as $table => $index) {
            $this->dropIndexIfPresent($table, $index);
            if (Schema::hasColumn(SchemaQualifier::table($table), 'location_kind')) {
                Schema::table(SchemaQualifier::table($table), fn (Blueprint $blueprint) => $blueprint->dropColumn('location_kind'));
            }
        }
    }

    private function removeAuditExtensions(): void
    {
        foreach ([
            'ae_warehouse_actor_uq', 'ae_warehouse_action_resource_uq',
            'ae_warehouse_result_identity_uq', 'ae_warehouse_control_uq',
        ] as $index) {
            $this->dropIndexIfPresent('audit_events', $index, true);
        }

        $audit = SchemaQualifier::table('audit_events');
        $columns = collect([
            'warehouse_operation_snapshot', 'warehouse_result_version_snapshot',
            'warehouse_result_digest_snapshot', 'warehouse_control_total_snapshot',
        ])->filter(fn (string $column): bool => Schema::hasColumn($audit, $column))->values()->all();
        if ($columns !== []) {
            Schema::table($audit, fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }

    /** @param list<string> $columns */
    private function ensureIndex(string $table, array $columns, string $name): void
    {
        if (! $this->indexExists($table, $name)) {
            Schema::table(SchemaQualifier::table($table), fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        }
    }

    /** @param list<string> $columns */
    private function ensureUnique(string $table, array $columns, string $name): void
    {
        if (! $this->indexExists($table, $name)) {
            Schema::table(SchemaQualifier::table($table), fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        return collect(Schema::getIndexes(SchemaQualifier::table($table)))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }

    private function dropIndexIfPresent(string $table, string $name, bool $unique = false): void
    {
        if (! $this->indexExists($table, $name)) {
            return;
        }
        Schema::table(SchemaQualifier::table($table), function (Blueprint $blueprint) use ($name, $unique): void {
            $unique ? $blueprint->dropUnique($name) : $blueprint->dropIndex($name);
        });
    }

    private function addChecks(): void
    {
        foreach ($this->checkDefinitions() as $table => [$constraint, $expression]) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $sqliteExpression = $this->sqliteNewRowExpression($table, $expression);
                foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $verb) {
                    DB::statement("CREATE TRIGGER {$constraint}_{$suffix} BEFORE {$verb} ON ".SchemaQualifier::table($table)
                        ." WHEN NOT ({$sqliteExpression}) BEGIN SELECT RAISE(ABORT, 'warehouse integrity check failed: {$constraint}'); END");
                }
            } elseif (in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
                DB::statement('ALTER TABLE '.SchemaQualifier::table($table)." ADD CONSTRAINT {$constraint} CHECK ({$expression})");
            }
        }
    }

    private function sqliteNewRowExpression(string $table, string $expression): string
    {
        $columns = collect(Schema::getColumnListing(SchemaQualifier::table($table)))
            ->sortByDesc(fn (string $column): int => strlen($column));
        foreach ($columns as $column) {
            $expression = preg_replace(
                '/(?<![A-Za-z0-9_.])'.preg_quote($column, '/').'(?![A-Za-z0-9_])/',
                'NEW.'.$column,
                $expression,
            ) ?? $expression;
        }

        return $expression;
    }

    /** @return array<string, array{string, string}> */
    private function checkDefinitions(): array
    {
        $movementTypes = "('OPENING','CORRECTION','QUARANTINE','HANDOVER','RETURN','SUPPLIER_RECEIPT_AVAILABLE','SUPPLIER_RECEIPT_QUARANTINED','WAREHOUSE_DISPATCH_OUT','WAREHOUSE_TRANSIT_IN','DEPOT_ACCEPT_TRANSIT_OUT','DEPOT_ACCEPT_AVAILABLE','DEPOT_REJECT_TRANSIT_OUT','DEPOT_REJECT_SOURCE_AVAILABLE','DEPOT_REJECT_SOURCE_QUARANTINED','SUPPLIER_RETURN_OUT','UNIT_RETURN_SOURCE_OUT','UNIT_RETURN_TRANSIT_IN','UNIT_RETURN_TRANSIT_OUT','UNIT_RETURN_DEST_AVAILABLE','UNIT_RETURN_DEST_QUARANTINED','CORRECTION_COMPENSATION')";
        $pairedTypes = "('WAREHOUSE_DISPATCH_OUT','WAREHOUSE_TRANSIT_IN','DEPOT_ACCEPT_TRANSIT_OUT','DEPOT_ACCEPT_AVAILABLE','DEPOT_REJECT_TRANSIT_OUT','DEPOT_REJECT_SOURCE_AVAILABLE','DEPOT_REJECT_SOURCE_QUARANTINED','UNIT_RETURN_SOURCE_OUT','UNIT_RETURN_TRANSIT_IN','UNIT_RETURN_TRANSIT_OUT','UNIT_RETURN_DEST_AVAILABLE','UNIT_RETURN_DEST_QUARANTINED')";
        $warehouseTypes = "('SUPPLIER_RECEIPT_AVAILABLE','SUPPLIER_RECEIPT_QUARANTINED','WAREHOUSE_DISPATCH_OUT','WAREHOUSE_TRANSIT_IN','DEPOT_ACCEPT_TRANSIT_OUT','DEPOT_ACCEPT_AVAILABLE','DEPOT_REJECT_TRANSIT_OUT','DEPOT_REJECT_SOURCE_AVAILABLE','DEPOT_REJECT_SOURCE_QUARANTINED','SUPPLIER_RETURN_OUT','UNIT_RETURN_SOURCE_OUT','UNIT_RETURN_TRANSIT_IN','UNIT_RETURN_TRANSIT_OUT','UNIT_RETURN_DEST_AVAILABLE','UNIT_RETURN_DEST_QUARANTINED','CORRECTION_COMPENSATION')";
        $pairShape = "((pair_type='WAREHOUSE_DISPATCH' AND ((pair_slot='SOURCE' AND movement_type='WAREHOUSE_DISPATCH_OUT' AND pair_leg='SOURCE_OUT' AND pair_bucket='AVAILABLE' AND available_delta=0-pair_quantity AND quarantined_delta=0 AND transit_delta=0) OR (pair_slot='DESTINATION' AND movement_type='WAREHOUSE_TRANSIT_IN' AND pair_leg='TRANSIT_IN' AND pair_bucket='TRANSIT' AND available_delta=0 AND quarantined_delta=0 AND transit_delta=pair_quantity))) OR (pair_type='DEPOT_ACCEPT' AND ((pair_slot='SOURCE' AND movement_type='DEPOT_ACCEPT_TRANSIT_OUT' AND pair_leg='TRANSIT_OUT' AND pair_bucket='TRANSIT' AND available_delta=0 AND quarantined_delta=0 AND transit_delta=0-pair_quantity) OR (pair_slot='DESTINATION' AND movement_type='DEPOT_ACCEPT_AVAILABLE' AND pair_leg='DESTINATION_IN' AND pair_bucket='AVAILABLE' AND available_delta=pair_quantity AND quarantined_delta=0 AND transit_delta=0))) OR (pair_type='DEPOT_REJECT_AVAILABLE' AND ((pair_slot='SOURCE' AND movement_type='DEPOT_REJECT_TRANSIT_OUT' AND pair_leg='TRANSIT_OUT' AND pair_bucket='TRANSIT' AND available_delta=0 AND quarantined_delta=0 AND transit_delta=0-pair_quantity) OR (pair_slot='DESTINATION' AND movement_type='DEPOT_REJECT_SOURCE_AVAILABLE' AND pair_leg='DESTINATION_IN' AND pair_bucket='AVAILABLE' AND available_delta=pair_quantity AND quarantined_delta=0 AND transit_delta=0))) OR (pair_type='DEPOT_REJECT_QUARANTINED' AND ((pair_slot='SOURCE' AND movement_type='DEPOT_REJECT_TRANSIT_OUT' AND pair_leg='TRANSIT_OUT' AND pair_bucket='TRANSIT' AND available_delta=0 AND quarantined_delta=0 AND transit_delta=0-pair_quantity) OR (pair_slot='DESTINATION' AND movement_type='DEPOT_REJECT_SOURCE_QUARANTINED' AND pair_leg='DESTINATION_IN' AND pair_bucket='QUARANTINED' AND available_delta=0 AND quarantined_delta=pair_quantity AND transit_delta=0))) OR (pair_type='UNIT_RETURN_DISPATCH' AND ((pair_slot='SOURCE' AND movement_type='UNIT_RETURN_SOURCE_OUT' AND pair_leg='SOURCE_OUT' AND pair_bucket='AVAILABLE' AND available_delta=0-pair_quantity AND quarantined_delta=0 AND transit_delta=0) OR (pair_slot='DESTINATION' AND movement_type='UNIT_RETURN_TRANSIT_IN' AND pair_leg='TRANSIT_IN' AND pair_bucket='TRANSIT' AND available_delta=0 AND quarantined_delta=0 AND transit_delta=pair_quantity))) OR (pair_type='UNIT_RETURN_ACCEPT_AVAILABLE' AND ((pair_slot='SOURCE' AND movement_type='UNIT_RETURN_TRANSIT_OUT' AND pair_leg='TRANSIT_OUT' AND pair_bucket='TRANSIT' AND available_delta=0 AND quarantined_delta=0 AND transit_delta=0-pair_quantity) OR (pair_slot='DESTINATION' AND movement_type='UNIT_RETURN_DEST_AVAILABLE' AND pair_leg='DESTINATION_IN' AND pair_bucket='AVAILABLE' AND available_delta=pair_quantity AND quarantined_delta=0 AND transit_delta=0))) OR (pair_type='UNIT_RETURN_ACCEPT_QUARANTINED' AND ((pair_slot='SOURCE' AND movement_type='UNIT_RETURN_TRANSIT_OUT' AND pair_leg='TRANSIT_OUT' AND pair_bucket='TRANSIT' AND available_delta=0 AND quarantined_delta=0 AND transit_delta=0-pair_quantity) OR (pair_slot='DESTINATION' AND movement_type='UNIT_RETURN_DEST_QUARANTINED' AND pair_leg='DESTINATION_IN' AND pair_bucket='QUARANTINED' AND available_delta=0 AND quarantined_delta=pair_quantity AND transit_delta=0))))";

        return [
            'pharmacy_depots' => ['pd_state_ck', "state IN ('ACTIVE','RETIRED') AND version>=1 AND location_kind IN ('CENTRAL_WAREHOUSE','DISPENSING_DEPOT')"],
            'pharmacy_depot_versions' => ['pdv_location_kind_ck', "location_kind IN ('CENTRAL_WAREHOUSE','DISPENSING_DEPOT')"],
            'pharmacy_stock_lots' => ['psl_state_ck', "state IN ('ACTIVE','QUARANTINED','RETIRED') AND available_quantity>=0 AND quarantined_quantity>=0 AND transit_quantity>=0 AND ((expiry_date IS NULL AND no_expiry_reason='NO_EXPIRY_ASSIGNED') OR expiry_date IS NOT NULL) AND ((warehouse_custody_lot_id IS NULL AND warehouse_source_type IS NULL AND warehouse_source_public_id IS NULL) OR (warehouse_custody_lot_id IS NOT NULL AND warehouse_source_type IN ('RECEIPT_ALLOCATION','TRANSFER_ITEM','UNIT_RETURN_ITEM','CORRECTION') AND warehouse_source_public_id IS NOT NULL))"],
            'pharmacy_stock_movements' => ['psm_state_ck', "movement_type IN {$movementTypes} AND ((available_delta<>0 OR quarantined_delta<>0 OR transit_delta<>0) OR (movement_type='QUARANTINE' AND source_type='LOT' AND handover_item_id IS NULL) OR (movement_type='RETURN' AND source_type='RETURN_ITEM' AND reason_code='DESTROYED_OR_NOT_RETURNABLE' AND handover_item_id IS NOT NULL)) AND available_balance_after>=0 AND quarantined_balance_after>=0 AND transit_balance_after>=0 AND ((movement_type IN {$warehouseTypes} AND warehouse_movement_set_id IS NOT NULL AND warehouse_custody_lot_id IS NOT NULL AND custody_chain_public_id IS NOT NULL) OR (movement_type NOT IN {$warehouseTypes} AND warehouse_movement_set_id IS NULL AND warehouse_custody_lot_id IS NULL AND custody_chain_public_id IS NULL)) AND ((movement_type IN {$pairedTypes} AND warehouse_movement_pair_id IS NOT NULL AND pair_public_id IS NOT NULL AND pair_type IS NOT NULL AND pair_quantity>0 AND pair_slot IN ('SOURCE','DESTINATION') AND pair_leg IS NOT NULL AND pair_bucket IS NOT NULL AND {$pairShape}) OR (movement_type NOT IN {$pairedTypes} AND warehouse_movement_pair_id IS NULL AND pair_public_id IS NULL AND pair_type IS NULL AND pair_quantity IS NULL AND pair_slot IS NULL AND pair_leg IS NULL AND pair_bucket IS NULL))"],
            'warehouse_suppliers' => ['ws_state_ck', "state IN ('ACTIVE','RETIRED') AND version>=1"],
            'warehouse_supplier_versions' => ['wsv_values_ck', "((version=1 AND state='ACTIVE' AND reason_code='SUPPLIER_CREATED' AND previous_version_id IS NULL AND previous_version_number IS NULL AND previous_content_digest IS NULL) OR (version>1 AND previous_version_id IS NOT NULL AND previous_version_number=version-1 AND previous_content_digest IS NOT NULL AND ((state='ACTIVE' AND reason_code IN ('DETAILS_UPDATED','CONTACT_UPDATED','REFERENCE_UPDATED','DETAILS_AND_CONTACT_UPDATED')) OR (state='RETIRED' AND reason_code='SUPPLIER_RETIRED'))))"],
            'warehouse_purchase_orders' => ['wpo_state_ck', "state IN ('DRAFT','SUBMITTED','APPROVED','REJECTED','PARTIALLY_RECEIVED','FULLY_RECEIVED','CLOSED','CANCELLED') AND version>=1"],
            'warehouse_purchase_order_versions' => ['wpov_state_ck', "state IN ('DRAFT','SUBMITTED') AND version>=1 AND ((version=1 AND previous_version_id IS NULL AND previous_version_number IS NULL AND previous_content_digest IS NULL) OR (version>1 AND previous_version_id IS NOT NULL AND previous_version_number=version-1 AND previous_content_digest IS NOT NULL)) AND ((state='DRAFT' AND submitted_at IS NULL) OR (state='SUBMITTED' AND submitted_at IS NOT NULL))"],
            'warehouse_purchase_order_lines' => ['wpol_values_ck', 'line_number>0 AND ordered_quantity>0 AND unit_acquisition_value>=0'],
            'warehouse_purchase_order_decisions' => ['wpod_decision_ck', "decision IN ('APPROVED','REJECTED') AND creator_user_id_snapshot<>reviewer_user_id"],
            'warehouse_receipts' => ['wr_values_ck', "approval_decision_snapshot='APPROVED' AND presented_quantity>0 AND accepted_quantity>=0 AND rejected_quantity>=0 AND quarantined_quantity>=0 AND presented_quantity=accepted_quantity+rejected_quantity+quarantined_quantity AND ((rejected_quantity+quarantined_quantity=0 AND variance_reason_code IS NULL) OR (rejected_quantity+quarantined_quantity>0 AND variance_reason_code IN ('DAMAGED','EXPIRED','QUANTITY_MISMATCH','LOT_MISMATCH','DOCUMENT_MISMATCH','QUALITY_REVIEW')))"],
            'warehouse_receipt_lines' => ['wrl_values_ck', "line_number>0 AND ordered_quantity_snapshot>0 AND presented_quantity>0 AND accepted_quantity>=0 AND rejected_quantity>=0 AND quarantined_quantity>=0 AND accepted_quantity+quarantined_quantity>0 AND presented_quantity=accepted_quantity+rejected_quantity+quarantined_quantity AND unit_acquisition_value>=0 AND ((presented_quantity=ordered_quantity_snapshot AND rejected_quantity=0 AND quarantined_quantity=0 AND variance_reason_code IS NULL) OR ((presented_quantity<>ordered_quantity_snapshot OR rejected_quantity>0 OR quarantined_quantity>0) AND variance_reason_code IN ('DAMAGED','EXPIRED','QUANTITY_MISMATCH','LOT_MISMATCH','DOCUMENT_MISMATCH','QUALITY_REVIEW') AND variance_note IS NOT NULL))"],
            'warehouse_custody_lots' => ['wcl_values_ck', 'expiry_date>=DATE(first_received_at)'],
            'warehouse_custody_receipt_allocations' => ['wcra_values_ck', 'available_quantity>=0 AND quarantined_quantity>=0 AND available_quantity+quarantined_quantity>0 AND unit_acquisition_value_snapshot>=0'],
            'warehouse_custody_movement_sets' => ['wcms_type_ck', "movement_set_type IN ('SUPPLIER_RECEIPT','WAREHOUSE_TRANSFER_DISPATCH','WAREHOUSE_TRANSFER_ACCEPTANCE','WAREHOUSE_TRANSFER_REJECTION','SUPPLIER_RETURN','UNIT_RETURN_DISPATCH','UNIT_RETURN_ACCEPTANCE','CORRECTION_COMPENSATION')"],
            'warehouse_custody_movement_pairs' => ['wcmp_values_ck', "quantity>0 AND ((pair_type='WAREHOUSE_DISPATCH' AND movement_set_type_snapshot='WAREHOUSE_TRANSFER_DISPATCH') OR (pair_type='DEPOT_ACCEPT' AND movement_set_type_snapshot='WAREHOUSE_TRANSFER_ACCEPTANCE') OR (pair_type IN ('DEPOT_REJECT_AVAILABLE','DEPOT_REJECT_QUARANTINED') AND movement_set_type_snapshot='WAREHOUSE_TRANSFER_REJECTION') OR (pair_type='UNIT_RETURN_DISPATCH' AND movement_set_type_snapshot='UNIT_RETURN_DISPATCH') OR (pair_type IN ('UNIT_RETURN_ACCEPT_AVAILABLE','UNIT_RETURN_ACCEPT_QUARANTINED') AND movement_set_type_snapshot='UNIT_RETURN_ACCEPTANCE'))"],
            'warehouse_transfers' => ['wt_state_ck', "state IN ('DISPATCHED','ACCEPTED','REJECTED') AND version>=1 AND source_depot_id<>destination_depot_id AND ((state='DISPATCHED' AND resolved_at IS NULL) OR (state IN ('ACCEPTED','REJECTED') AND resolved_at IS NOT NULL))"],
            'warehouse_transfer_items' => ['wti_values_ck', 'line_number>0 AND quantity>0'],
            'warehouse_transfer_decisions' => ['wtd_decision_ck', "dispatcher_user_id_snapshot<>acceptor_user_id AND ((decision='ACCEPTED' AND rejection_disposition IS NULL AND reason_code IS NULL) OR (decision='REJECTED' AND rejection_disposition IN ('SOURCE_AVAILABLE','SOURCE_QUARANTINED') AND reason_code IS NOT NULL))"],
            'warehouse_supplier_returns' => ['wsr_state_ck', "state IN ('REQUESTED','APPROVED','REJECTED') AND version>=1 AND ((state='REQUESTED' AND resolved_at IS NULL) OR (state IN ('APPROVED','REJECTED') AND resolved_at IS NOT NULL))"],
            'warehouse_supplier_return_items' => ['wsri_values_ck', 'line_number>0 AND quantity>0'],
            'warehouse_supplier_return_decisions' => ['wsrd_decision_ck', "decision IN ('APPROVED','REJECTED') AND requester_user_id_snapshot<>approver_user_id"],
            'warehouse_unit_returns' => ['wur_state_ck', "state IN ('DISPATCHED','ACCEPTED','REJECTED') AND version>=1 AND source_depot_id<>destination_depot_id AND ((state='DISPATCHED' AND resolved_at IS NULL) OR (state IN ('ACCEPTED','REJECTED') AND resolved_at IS NOT NULL))"],
            'warehouse_unit_return_items' => ['wuri_values_ck', 'line_number>0 AND quantity>0'],
            'warehouse_unit_return_decisions' => ['wurd_decision_ck', "requester_user_id_snapshot<>acceptor_user_id AND ((decision='ACCEPTED' AND destination_disposition IN ('DESTINATION_AVAILABLE','DESTINATION_QUARANTINED')) OR (decision='REJECTED' AND destination_disposition IS NULL AND reason_code IS NOT NULL))"],
            'warehouse_correction_requests' => ['wcr_values_ck', "(available_delta<>0 OR quarantined_delta<>0 OR transit_delta<>0) AND reason_code IN ('QUANTITY_ENTRY_ERROR','CUSTODY_STATE_ERROR','DUPLICATE_POSTING','OTHER_SUPERVISOR_REVIEW')"],
            'warehouse_correction_decisions' => ['wcd_decision_ck', "decision IN ('APPROVED','REJECTED') AND requester_user_id_snapshot<>supervisor_user_id"],
            'warehouse_correction_compensations' => ['wcc_values_ck', "approval_decision_snapshot='APPROVED' AND supervisor_user_id_snapshot<>actor_user_id AND (available_delta<>0 OR quarantined_delta<>0 OR transit_delta<>0) AND available_balance_after>=0 AND quarantined_balance_after>=0 AND transit_balance_after>=0"],
            'warehouse_operation_receipts' => ['wor_result_ck', "audit_action_snapshot='warehouse.workflow.mutate' AND audit_resource_type_snapshot='warehouse_record' AND result_version>0 AND control_total>=0 AND ((operation IN ('WAREHOUSE_SUPPLIER_CREATE','WAREHOUSE_SUPPLIER_REVISE','WAREHOUSE_SUPPLIER_RETIRE') AND result_type='SUPPLIER') OR (operation IN ('WAREHOUSE_PURCHASE_ORDER_CREATE','WAREHOUSE_PURCHASE_ORDER_REVISE','WAREHOUSE_PURCHASE_ORDER_SUBMIT') AND result_type='PURCHASE_ORDER') OR (operation='WAREHOUSE_PURCHASE_ORDER_REVIEW' AND result_type='PURCHASE_ORDER_DECISION') OR (operation='WAREHOUSE_RECEIPT_RECORD' AND result_type='RECEIPT') OR (operation='WAREHOUSE_TRANSFER_DISPATCH' AND result_type='TRANSFER') OR (operation='WAREHOUSE_TRANSFER_REVIEW' AND result_type='TRANSFER_DECISION') OR (operation='WAREHOUSE_SUPPLIER_RETURN_REQUEST' AND result_type='SUPPLIER_RETURN') OR (operation='WAREHOUSE_SUPPLIER_RETURN_REVIEW' AND result_type='SUPPLIER_RETURN_DECISION') OR (operation='WAREHOUSE_UNIT_RETURN_REQUEST' AND result_type='UNIT_RETURN') OR (operation='WAREHOUSE_UNIT_RETURN_REVIEW' AND result_type='UNIT_RETURN_DECISION') OR (operation='WAREHOUSE_CORRECTION_REQUEST' AND result_type='CORRECTION_REQUEST') OR (operation='WAREHOUSE_CORRECTION_REVIEW' AND result_type='CORRECTION_DECISION') OR (operation='WAREHOUSE_CORRECTION_COMPENSATE' AND result_type='CORRECTION_COMPENSATION'))"],
        ];
    }

    private function dropExtendedChecks(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $grammar = DB::connection()->getQueryGrammar();
            foreach ($this->checkDefinitions() as [$constraint]) {
                DB::statement('DROP TRIGGER IF EXISTS '.$grammar->wrap($constraint.'_insert'));
                DB::statement('DROP TRIGGER IF EXISTS '.$grammar->wrap($constraint.'_update'));
            }

            return;
        }
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }
        foreach ([
            'pharmacy_depots' => ['pd_state_ck'],
            'pharmacy_depot_versions' => ['pdv_location_kind_ck'],
            'pharmacy_stock_lots' => ['psl_state_ck'],
            'pharmacy_stock_movements' => ['psm_state_ck'],
        ] as $table => $constraints) {
            if (! Schema::hasTable(SchemaQualifier::table($table))) {
                continue;
            }
            foreach ($constraints as $constraint) {
                $this->dropCheck($table, $constraint);
            }
        }
    }

    private function dropCheck(string $table, string $constraint): void
    {
        $qualified = SchemaQualifier::table($table);
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$qualified} DROP CONSTRAINT IF EXISTS {$constraint}");
        } elseif (DB::connection()->getDriverName() === 'mysql') {
            $schema = DB::connection()->getDatabaseName();
            $exists = DB::table('information_schema.table_constraints')
                ->where('constraint_schema', $schema)->where('table_name', $table)
                ->where('constraint_name', $constraint)->where('constraint_type', 'CHECK')->exists();
            if ($exists) {
                DB::statement("ALTER TABLE {$qualified} DROP CHECK {$constraint}");
            }
        }
    }

    private function restoreBasePharmacyChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }
        foreach ([
            'pharmacy_depots' => ['pd_state_ck', "state IN ('ACTIVE','RETIRED') AND version>=1"],
            'pharmacy_stock_lots' => ['psl_state_ck', "state IN ('ACTIVE','QUARANTINED','RETIRED') AND available_quantity>=0 AND quarantined_quantity>=0 AND ((expiry_date IS NULL AND no_expiry_reason='NO_EXPIRY_ASSIGNED') OR expiry_date IS NOT NULL)"],
            'pharmacy_stock_movements' => ['psm_state_ck', "movement_type IN ('OPENING','CORRECTION','QUARANTINE','HANDOVER','RETURN') AND ((available_delta<>0 OR quarantined_delta<>0) OR (movement_type='QUARANTINE' AND source_type='LOT' AND handover_item_id IS NULL) OR (movement_type='RETURN' AND source_type='RETURN_ITEM' AND reason_code='DESTROYED_OR_NOT_RETURNABLE' AND handover_item_id IS NOT NULL)) AND available_balance_after>=0 AND quarantined_balance_after>=0"],
        ] as $table => [$constraint, $expression]) {
            if (Schema::hasTable(SchemaQualifier::table($table)) && ! $this->checkExists($table, $constraint)) {
                DB::statement('ALTER TABLE '.SchemaQualifier::table($table)." ADD CONSTRAINT {$constraint} CHECK ({$expression})");
            }
        }
    }

    private function checkExists(string $table, string $constraint): bool
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return DB::table('pg_constraint as c')
                ->join('pg_class as t', 't.oid', '=', 'c.conrelid')
                ->where('t.relname', $table)->where('c.conname', $constraint)->exists();
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            return DB::table('information_schema.table_constraints')
                ->where('constraint_schema', DB::connection()->getDatabaseName())
                ->where('table_name', $table)->where('constraint_name', $constraint)
                ->where('constraint_type', 'CHECK')->exists();
        }

        return false;
    }

    private function restoreChecksAndWarehouseGuards(): void
    {
        $failures = [];
        try {
            $this->restoreBasePharmacyChecks();
        } catch (Throwable $exception) {
            $failures[] = 'base pharmacy check restoration failed: '.$exception->getMessage();
        }
        try {
            $this->reinstallWarehouseAuditEvidenceGuard();
        } catch (Throwable $exception) {
            $failures[] = 'warehouse audit evidence guard restoration failed: '.$exception->getMessage();
        }
        try {
            $this->reinstallWarehouseGuards();
        } catch (Throwable $exception) {
            $failures[] = 'warehouse guard restoration failed: '.$exception->getMessage();
        }
        if ($failures !== []) {
            throw new RuntimeException('Warehouse migration finalization failed closed: '.implode('; ', $failures));
        }
    }

    private function reinstallWarehouseGuards(): void
    {
        $existing = collect(self::TABLES)
            ->filter(fn (string $table): bool => Schema::hasTable(SchemaQualifier::table($table)));
        if ($existing->isEmpty()) {
            return;
        }

        $failures = [];
        foreach ([WarehouseAppendOnlyGuard::class, WarehouseMutableHeadGuard::class] as $guard) {
            try {
                $guard::remove();
            } catch (Throwable $exception) {
                $failures[] = class_basename($guard).' removal failed: '.$exception->getMessage();
            }
            try {
                $guard::install();
            } catch (Throwable $exception) {
                $failures[] = class_basename($guard).' installation failed: '.$exception->getMessage();
            }
        }
        if ($existing->count() !== count(self::TABLES)) {
            $failures[] = 'Partial warehouse custody catalog cannot be left without complete database guards.';
        }
        if ($failures !== []) {
            throw new RuntimeException(implode('; ', $failures));
        }
    }

    private function reinstallWarehouseAuditEvidenceGuard(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (! Schema::hasTable($audit) || ! Schema::hasColumn($audit, 'action')) {
            return;
        }

        $existing = collect(self::AUDIT_EXTENSION_COLUMNS)
            ->filter(fn (string $column): bool => Schema::hasColumn($audit, $column));
        if ($existing->isEmpty()) {
            return;
        }

        // Even a partially evolved audit table must not be left with mutable
        // warehouse evidence while the migration reports the incompatible state.
        $failures = [];
        try {
            WarehouseAuditEvidenceGuard::remove();
        } catch (Throwable $exception) {
            $failures[] = 'WarehouseAuditEvidenceGuard removal failed: '.$exception->getMessage();
        }
        try {
            WarehouseAuditEvidenceGuard::install();
        } catch (Throwable $exception) {
            $failures[] = 'WarehouseAuditEvidenceGuard installation failed: '.$exception->getMessage();
        }
        if ($existing->count() !== count(self::AUDIT_EXTENSION_COLUMNS)) {
            $failures[] = 'Partial warehouse audit extension cannot be left without database evidence guards.';
        }
        if ($failures !== []) {
            throw new RuntimeException(implode('; ', $failures));
        }
    }
};
