<?php

namespace Tests\Feature\Database;

use App\Support\Pharmacy\PharmacyMutationScope;
use App\Support\Pharmacy\PharmacySchemaMutationScope;
use App\Support\Warehouse\WarehouseMutationScope;
use App\Support\Warehouse\WarehouseSchemaMutationScope;
use App\Support\Warehouse\WarehouseSqlWriteGuard;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class WarehouseCustodySqlWriteGuardTest extends TestCase
{
    public function test_guard_refuses_direct_writes_across_warehouse_and_extended_pharmacy_tables(): void
    {
        $guard = new WarehouseSqlWriteGuard;
        foreach ([
            'pharmacy_depots', 'pharmacy_depot_versions', 'pharmacy_stock_lots', 'pharmacy_stock_movements',
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
            try {
                $guard->assertAllowed('UPDATE "laravel"."'.$table.'" SET id = id');
                $this->fail('Expected direct SQL write refusal for '.$table.'.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('Write-capable SQL against warehouse custody tables is prohibited.', $exception->getMessage());
            }
        }
    }

    public function test_guard_allows_reads_locking_reads_and_explicit_scopes(): void
    {
        $this->requireSqliteWarehouseHarness();

        $guard = new WarehouseSqlWriteGuard;
        $guard->assertAllowed('SELECT * FROM warehouse_custody_lots');
        $guard->assertAllowed('SELECT * FROM warehouse_custody_lots WHERE id = ? FOR UPDATE SKIP LOCKED');
        $guard->assertAllowed('WITH candidate AS (SELECT id FROM warehouse_transfers) SELECT * FROM candidate FOR NO KEY UPDATE');
        WarehouseMutationScope::run(fn () => $guard->assertAllowed('UPDATE warehouse_transfers SET state = state'));
        WarehouseSchemaMutationScope::run(fn () => $guard->assertAllowed('DROP TABLE warehouse_operation_receipts'));

        $this->addToAssertionCount(5);
    }

    public function test_mutation_scope_allows_only_closed_warehouse_dml_and_never_schema_ddl(): void
    {
        $this->requireSqliteWarehouseHarness();

        $guard = new WarehouseSqlWriteGuard;

        WarehouseMutationScope::run(fn () => $guard->assertAllowed(
            'INSERT INTO warehouse_supplier_versions (id) VALUES (?)',
            DB::connection(),
        ));
        WarehouseMutationScope::run(fn () => $guard->assertAllowed(
            'INSERT INTO warehouse_operation_receipts (id) VALUES (?)',
            DB::connection(),
        ));
        WarehouseMutationScope::run(fn () => $guard->assertAllowed(
            'UPDATE warehouse_suppliers SET state = state',
            DB::connection(),
        ));

        foreach ([
            'DROP TABLE warehouse_suppliers',
            'ALTER TABLE warehouse_suppliers ADD COLUMN bypass TEXT',
            "CREATE TRIGGER bypass BEFORE UPDATE ON warehouse_suppliers BEGIN SELECT RAISE(ABORT, 'bypass'); END",
            'UPDATE warehouse_supplier_versions SET reason_code = reason_code',
            'DELETE FROM warehouse_supplier_versions',
            'INSERT OR REPLACE INTO warehouse_supplier_versions (id) VALUES (1)',
            'INSERT INTO warehouse_supplier_versions (id) VALUES (1) ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id',
            'INSERT INTO warehouse_supplier_versions (id) VALUES (1) ON DUPLICATE KEY UPDATE id = VALUES(id)',
        ] as $sql) {
            try {
                WarehouseMutationScope::run(fn () => $guard->assertAllowed($sql, DB::connection()));
                $this->fail('Warehouse mutation scope must reject '.$sql);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        WarehouseSchemaMutationScope::run(fn () => $guard->assertAllowed(
            'ALTER TABLE warehouse_suppliers ADD COLUMN governed_extension TEXT',
            DB::connection(),
        ));
        $this->addToAssertionCount(4);
    }

    public function test_guard_rejects_explicit_warehouse_audit_mutations_and_truncation(): void
    {
        $guard = new WarehouseSqlWriteGuard;

        foreach ([
            ['UPDATE audit_events SET metadata = ? WHERE action = ?', ['{}', 'warehouse.workflow.mutate']],
            ["DELETE FROM audit_events WHERE action = 'warehouse.workflow.mutate'", []],
            ['TRUNCATE TABLE audit_events', []],
        ] as [$sql, $bindings]) {
            try {
                $guard->assertAllowed($sql, DB::connection(), $bindings);
                $this->fail('Warehouse audit mutation must be rejected.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        // The SQL interceptor cannot determine the action for an ID-only
        // predicate. The conditional database trigger remains authoritative.
        $guard->assertAllowed('UPDATE audit_events SET metadata = ? WHERE id = ?', DB::connection(), ['{}', '01TEST']);
        $this->addToAssertionCount(1);
    }

    public function test_guard_allows_pharmacy_scopes_only_for_the_extended_pharmacy_tables(): void
    {
        $guard = new WarehouseSqlWriteGuard;
        PharmacyMutationScope::run(
            fn () => $guard->assertAllowed('UPDATE pharmacy_stock_lots SET available_quantity = available_quantity'),
        );
        PharmacySchemaMutationScope::run(
            fn () => $guard->assertAllowed('ALTER TABLE pharmacy_depots ADD COLUMN safe_extension text'),
        );
        PharmacySchemaMutationScope::run(
            fn () => $guard->assertAllowed("CREATE TRIGGER pharmacy_stock_lots_guard BEFORE UPDATE ON pharmacy_stock_lots BEGIN SELECT RAISE(ABORT, 'immutable'); END"),
        );

        try {
            PharmacyMutationScope::run(
                fn () => $guard->assertAllowed("CREATE TRIGGER pharmacy_stock_lots_guard BEFORE UPDATE ON pharmacy_stock_lots BEGIN SELECT RAISE(ABORT, 'immutable'); END"),
            );
            $this->fail('Pharmacy mutation scope must not authorize trigger DDL.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        foreach ([PharmacyMutationScope::class, PharmacySchemaMutationScope::class] as $scope) {
            try {
                $scope::run(fn () => $guard->assertAllowed('UPDATE warehouse_suppliers SET state = state'));
                $this->fail('Pharmacy scope must not authorize warehouse table writes.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('Write-capable SQL against warehouse custody tables is prohibited.', $exception->getMessage());
            }
        }

        $this->addToAssertionCount(3);
    }

    public function test_guard_refuses_direct_session_variable_bypasses_without_a_table_reference(): void
    {
        foreach ([
            "SET LOCAL simrs.synthetic_reset = '1'",
            "SET SESSION simrs.warehouse_mutation TO '1'",
            'SET @simrs_synthetic_reset = 1',
            'SET /* disguise */ @simrs_warehouse_mutation := 1',
        ] as $sql) {
            try {
                (new WarehouseSqlWriteGuard)->assertAllowed($sql);
                $this->fail('Expected warehouse session-variable bypass refusal.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('session-variable changes are prohibited', $exception->getMessage());
            }
        }
    }

    public function test_warehouse_scope_does_not_authorize_synthetic_reset_session_bypasses(): void
    {
        $this->requireSqliteWarehouseHarness();

        foreach ([WarehouseMutationScope::class, WarehouseSchemaMutationScope::class] as $scope) {
            foreach (["SET LOCAL simrs.synthetic_reset = '1'", "SET LOCAL simrs.warehouse_mutation = '1'", 'SET @simrs_warehouse_mutation = 1'] as $sql) {
                try {
                    $scope::run(
                        fn () => (new WarehouseSqlWriteGuard)->assertAllowed($sql),
                    );
                    $this->fail('Warehouse scope must not authorize a session-variable bypass.');
                } catch (LogicException $exception) {
                    $this->assertStringContainsString('session-variable changes are prohibited', $exception->getMessage());
                }
            }
        }
    }

    public function test_guard_refuses_database_role_switching_without_a_table_reference(): void
    {
        foreach (['SET ROLE warehouse_writer', 'SET LOCAL ROLE warehouse_writer', 'SET SESSION ROLE warehouse_writer', 'SET DEFAULT ROLE ALL', 'SET SESSION AUTHORIZATION warehouse_writer'] as $sql) {
            try {
                (new WarehouseSqlWriteGuard)->assertAllowed($sql);
                $this->fail('Expected warehouse database identity switch refusal.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('database identity changes are prohibited', $exception->getMessage());
            }
        }
    }

    public function test_schema_scope_allows_only_the_exact_single_statement_sqlite_rebuild_copy(): void
    {
        $this->requireSqliteWarehouseHarness();

        $guard = new WarehouseSqlWriteGuard;
        WarehouseSchemaMutationScope::run(
            fn () => $guard->assertAllowed(
                'INSERT INTO __temp__warehouse_suppliers (id) SELECT id FROM warehouse_suppliers',
                DB::connection(),
            ),
        );
        $this->addToAssertionCount(1);

        try {
            WarehouseSchemaMutationScope::run(
                fn () => $guard->assertAllowed(
                    'INSERT INTO __temp__warehouse_suppliers (id) SELECT id FROM warehouse_suppliers; DELETE FROM warehouse_suppliers',
                    DB::connection(),
                ),
            );
            $this->fail('Stacked DML must not use the SQLite schema-rebuild exception.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Ambiguous or executable-comment SQL', $exception->getMessage());
        }
    }

    public function test_guard_refuses_ddl_write_ctes_comments_and_multiple_statements(): void
    {
        $guard = new WarehouseSqlWriteGuard;
        foreach ([
            'CREATE TABLE warehouse_suppliers (id bigint)',
            'ALTER TABLE laravel.warehouse_custody_lots ADD COLUMN bypass text',
            'DROP TABLE IF EXISTS warehouse_operation_receipts',
            'CREATE INDEX bypass_idx ON warehouse_transfers (id)',
            'CREATE TRIGGER bypass BEFORE DELETE ON warehouse_receipts BEGIN SELECT 1; END',
            'RENAME TABLE warehouse_suppliers TO warehouse_suppliers_archive',
            'RENAME TABLE unguarded_archive TO warehouse_suppliers',
            'COPY warehouse_receipts TO STDOUT',
            'GRANT UPDATE ON warehouse_transfers TO runtime_role',
            'REVOKE SELECT ON warehouse_operation_receipts FROM runtime_role',
            'CALL warehouse_transfers()',
            'EXECUTE warehouse_receipts',
            'VACUUM ANALYZE warehouse_custody_lots',
            "LOAD DATA INFILE 'rows.csv' INTO TABLE warehouse_suppliers",
            'WITH removed AS (DELETE FROM warehouse_receipts RETURNING id) SELECT * FROM removed',
            'SELECT * FROM warehouse_transfers; DELETE FROM warehouse_transfers',
            '/*! DELETE FROM warehouse_receipts */',
            '/*+ INSERT INTO warehouse_receipts VALUES (1) */ SELECT 1',
            'UPSERT warehouse_transfers VALUES (1)',
        ] as $sql) {
            try {
                $guard->assertAllowed($sql);
                $this->fail('Expected warehouse SQL refusal for '.$sql);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_guard_allows_cross_domain_ddl_references_and_protected_names_in_values(): void
    {
        $guard = new WarehouseSqlWriteGuard;
        $guard->assertAllowed('ALTER TABLE audit_labels ADD CONSTRAINT source_fk FOREIGN KEY (source_id) REFERENCES warehouse_receipts (id)');
        $guard->assertAllowed("INSERT INTO audit_labels (label) VALUES ('warehouse_receipts')");

        $this->addToAssertionCount(2);
    }

    private function requireSqliteWarehouseHarness(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped(
                'Scoped warehouse SQL behavior awaits the governed exact-engine identity/routine harness.',
            );
        }
    }
}
