<?php

namespace Tests\Feature\Database;

use App\Support\Finance\FinanceRadiologyTariffSqlWriteGuard;
use App\Support\Finance\FinanceTariffSchemaMutationScope;
use LogicException;
use Tests\TestCase;

final class FinanceRadiologyTariffGuardTest extends TestCase
{
    public function test_guard_refuses_direct_writes_to_every_new_table(): void
    {
        $guard = new FinanceRadiologyTariffSqlWriteGuard;
        foreach ([
            'finance_radiology_tariff_bindings',
            'finance_radiology_tariff_binding_versions',
            'finance_radiology_tariff_operation_receipts',
            'finance_radiology_source_events',
        ] as $table) {
            try {
                $guard->assertAllowed('UPDATE '.$table.' SET state = \'ACTIVE\'');
                $this->fail('Expected direct write refusal for '.$table.'.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('Direct SQL writes', $exception->getMessage());
            }
        }
    }

    public function test_guard_rejects_ddl_comments_and_multiple_statements_but_allows_reads_and_schema_scope(): void
    {
        $guard = new FinanceRadiologyTariffSqlWriteGuard;
        $guard->assertAllowed('SELECT * FROM finance_radiology_tariff_bindings');
        FinanceTariffSchemaMutationScope::run(
            fn () => $guard->assertAllowed('DELETE FROM finance_radiology_tariff_binding_versions'),
        );
        foreach ([
            'DROP TABLE finance_radiology_source_events',
            'ALTER TABLE finance_radiology_tariff_bindings ADD COLUMN bypass text',
            'SELECT * FROM finance_radiology_tariff_bindings; DELETE FROM finance_radiology_tariff_bindings',
            '/*! DELETE FROM finance_radiology_tariff_bindings */',
        ] as $sql) {
            try {
                $guard->assertAllowed($sql);
                $this->fail('Expected guarded refusal for '.$sql);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->addToAssertionCount(2);
    }
}
