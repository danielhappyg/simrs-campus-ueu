<?php

namespace Tests\Feature\Database;

use App\Models\FinanceLaboratorySourceEvent;
use App\Support\Finance\FinanceLaboratoryTariffSqlWriteGuard;
use App\Support\Finance\FinanceMutationScope;
use App\Support\Finance\FinanceTariffSchemaMutationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class FinanceLaboratoryTariffGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guard_refuses_direct_writes_to_every_new_table(): void
    {
        $guard = new FinanceLaboratoryTariffSqlWriteGuard;
        foreach ([
            'finance_laboratory_tariff_bindings',
            'finance_laboratory_tariff_binding_versions',
            'finance_laboratory_tariff_operation_receipts',
            'finance_laboratory_source_events',
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
        $guard = new FinanceLaboratoryTariffSqlWriteGuard;
        $guard->assertAllowed('SELECT * FROM finance_laboratory_tariff_bindings');
        FinanceTariffSchemaMutationScope::run(
            fn () => $guard->assertAllowed('DELETE FROM finance_laboratory_tariff_binding_versions'),
        );
        foreach ([
            'DROP TABLE finance_laboratory_source_events',
            'ALTER TABLE finance_laboratory_tariff_bindings ADD COLUMN bypass text',
            'SELECT * FROM finance_laboratory_tariff_bindings; DELETE FROM finance_laboratory_tariff_bindings',
            '/*! DELETE FROM finance_laboratory_tariff_bindings */',
            'CALL mutate(finance_laboratory_source_events)',
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

    public function test_finance_mutation_scope_is_bounded_to_source_rows_not_binding_evidence(): void
    {
        $guard = new FinanceLaboratoryTariffSqlWriteGuard;
        FinanceMutationScope::run(
            fn () => $guard->assertAllowed('INSERT INTO finance_laboratory_source_events (public_id) VALUES (\'x\')'),
        );

        try {
            FinanceMutationScope::run(
                fn () => $guard->assertAllowed('UPDATE finance_laboratory_tariff_bindings SET state=\'RETIRED\''),
            );
            $this->fail('General finance mutation scope must not mutate laboratory tariff bindings.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Direct SQL writes', $exception->getMessage());
        }
    }

    public function test_source_model_requires_governed_finance_scope_before_database_access(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('governed finance mutation scope');
        FinanceLaboratorySourceEvent::query()->create([
            'event_type' => 'CHARGE', 'quantity' => 1, 'unit_amount' => 1, 'signed_amount' => 1,
        ]);
    }
}
