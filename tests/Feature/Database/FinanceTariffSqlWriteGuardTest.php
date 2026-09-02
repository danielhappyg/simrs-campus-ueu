<?php

namespace Tests\Feature\Database;

use App\Support\Finance\FinanceTariffSchemaMutationScope;
use App\Support\Finance\FinanceTariffSqlWriteGuard;
use LogicException;
use Tests\TestCase;

final class FinanceTariffSqlWriteGuardTest extends TestCase
{
    public function test_provider_registers_the_tariff_guard_for_current_and_future_connections(): void
    {
        $source = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString('use App\Support\Finance\FinanceTariffSqlWriteGuard;', $source);
        $this->assertStringContainsString('$this->configureFinanceTariffSqlWriteGuard();', $source);
        $this->assertStringContainsString('app(FinanceTariffSqlWriteGuard::class)', $source);
        $this->assertStringContainsString('Event::listen(ConnectionEstablished::class', $source);
    }

    public function test_guard_refuses_direct_writes_across_the_complete_tariff_master_surface(): void
    {
        $guard = new FinanceTariffSqlWriteGuard;
        foreach ([
            'finance_cost_component_groups', 'finance_cost_component_group_versions',
            'finance_cost_components', 'finance_cost_component_versions',
            'finance_tariff_catalogues', 'finance_tariff_catalogue_versions',
            'finance_tariff_items', 'finance_tariff_item_versions',
            'finance_tariff_code_reservations', 'finance_tariff_operation_receipts',
        ] as $table) {
            try {
                $guard->assertAllowed('UPDATE "laravel"."'.$table.'" SET state = \'ACTIVE\'');
                $this->fail('Expected direct tariff SQL write refusal for '.$table.'.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('Direct SQL writes to finance tariff master tables are prohibited.', $exception->getMessage());
            }
        }
    }

    public function test_guard_allows_reads_and_explicit_schema_scope(): void
    {
        $guard = new FinanceTariffSqlWriteGuard;
        $guard->assertAllowed('SELECT * FROM finance_tariff_items');
        $guard->assertAllowed('SELECT * FROM finance_tariff_items WHERE id = ? FOR UPDATE');
        $guard->assertAllowed('SELECT * FROM finance_tariff_items WHERE id = ? FOR UPDATE SKIP LOCKED');
        $guard->assertAllowed('WITH candidate AS (SELECT id FROM finance_tariff_items) SELECT * FROM candidate FOR NO KEY UPDATE');
        FinanceTariffSchemaMutationScope::run(
            fn () => $guard->assertAllowed('DELETE FROM finance_tariff_item_versions'),
        );

        $this->addToAssertionCount(5);
    }

    public function test_guard_refuses_ddl_write_cte_executable_comments_and_multiple_statements(): void
    {
        $guard = new FinanceTariffSqlWriteGuard;
        foreach ([
            'CREATE TABLE finance_tariff_items (id bigint)',
            'ALTER TABLE laravel.finance_tariff_items ADD COLUMN bypass text',
            'DROP TABLE IF EXISTS finance_tariff_item_versions',
            'CREATE INDEX tariff_bypass_idx ON finance_tariff_items (id)',
            'CREATE TRIGGER tariff_bypass BEFORE DELETE ON finance_tariff_items BEGIN SELECT 1; END',
            'RENAME TABLE finance_tariff_items TO finance_tariff_items_archive',
            'RENAME TABLE unguarded_archive TO finance_tariff_items',
            'COPY finance_tariff_item_versions TO STDOUT',
            'GRANT UPDATE ON finance_tariff_items TO runtime_role',
            'REVOKE SELECT ON finance_tariff_operation_receipts FROM runtime_role',
            'CALL finance_tariff_items()',
            'EXECUTE finance_tariff_items',
            'VACUUM ANALYZE finance_tariff_items',
            "LOAD DATA INFILE 'rows.csv' INTO TABLE finance_tariff_items",
            'WITH removed AS (DELETE FROM finance_tariff_items RETURNING id) SELECT * FROM removed',
            'WITH changed AS (UPDATE finance_tariff_items SET state = \'ACTIVE\' RETURNING id) SELECT * FROM changed FOR UPDATE',
            'SELECT * FROM finance_tariff_items; DELETE FROM finance_tariff_items',
            '/*! DELETE FROM finance_tariff_items */',
            '/*+ INSERT INTO finance_tariff_items VALUES (1) */ SELECT 1',
            'UPSERT finance_tariff_items VALUES (1)',
        ] as $sql) {
            try {
                $guard->assertAllowed($sql);
                $this->fail('Expected guarded tariff SQL refusal for '.$sql);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_guard_allows_cross_domain_ddl_references_and_protected_names_in_values(): void
    {
        $guard = new FinanceTariffSqlWriteGuard;
        $guard->assertAllowed(
            'ALTER TABLE finance_charge_events ADD CONSTRAINT source_fk FOREIGN KEY (source_id) REFERENCES finance_tariff_item_versions (id)',
        );
        $guard->assertAllowed(
            "INSERT INTO audit_labels (label) VALUES ('finance_tariff_items')",
        );

        $this->addToAssertionCount(2);
    }
}
