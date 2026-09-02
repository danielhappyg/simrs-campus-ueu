<?php

namespace Tests\Unit\Inpatient;

use App\Support\Inpatient\InpatientDocumentationSqlWriteGuard;
use App\Support\Inpatient\InpatientRmMutationScope;
use App\Support\Inpatient\InpatientRmSchemaMutationScope;
use App\Support\Inpatient\InpatientSummaryAddendumMutationScope;
use App\Support\Inpatient\InpatientSummaryAddendumSchemaMutationScope;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InpatientDocumentationSqlWriteGuardTest extends TestCase
{
    public function test_single_read_statements_remain_allowed(): void
    {
        $guard = new InpatientDocumentationSqlWriteGuard;

        $guard->assertAllowed('select * from inpatient_discharge_summaries where encounter_id = ? for update');
        $guard->assertAllowed('select * from inpatient_discharge_summary_versions where inpatient_discharge_summary_id = ?');

        $this->addToAssertionCount(2);
    }

    #[DataProvider('bypassStatements')]
    public function test_read_prefix_and_executable_comment_write_bypasses_are_blocked(string $sql): void
    {
        $this->expectException(LogicException::class);

        (new InpatientDocumentationSqlWriteGuard)->assertAllowed($sql);
    }

    /** @return array<string, array{string}> */
    public static function bypassStatements(): array
    {
        return [
            'read-prefixed update' => ['select 1; update inpatient_discharge_summaries set version = 99'],
            'read-prefixed delete' => ['select 1; delete from inpatient_discharge_summary_versions'],
            'mysql executable comment' => ['/*! UPDATE inpatient_discharge_summaries SET version = 99 */'],
            'mariadb executable comment' => ['/*M! DELETE FROM inpatient_discharge_summary_operation_receipts */'],
        ];
    }

    public function test_inpatient_rm_schema_scope_allows_ddl_that_references_protected_source_tables(): void
    {
        InpatientRmSchemaMutationScope::run(fn () => (new InpatientDocumentationSqlWriteGuard)->assertAllowed(
            'alter table inpatient_rm_coding_versions add constraint irmcv_source_fk foreign key (source_id) references inpatient_discharge_coding_source_versions (id)',
        ));

        $this->addToAssertionCount(1);
    }

    public function test_known_cross_domain_target_may_reference_protected_evidence_without_writing_it(): void
    {
        (new InpatientDocumentationSqlWriteGuard)->assertAllowed(
            'alter table pharmacy_prescriptions add constraint pp_discharge_fk foreign key (discharge_id) references inpatient_discharges (id)',
        );

        $this->addToAssertionCount(1);
    }

    public function test_mutation_scopes_cannot_write_each_others_protected_tables(): void
    {
        $guard = new InpatientDocumentationSqlWriteGuard;

        foreach ([
            fn () => InpatientSummaryAddendumMutationScope::run(fn () => $guard->assertAllowed('update inpatient_rm_codings set version = 9')),
            fn () => InpatientRmMutationScope::run(fn () => $guard->assertAllowed("update inpatient_summary_addenda set addendum_state = 'FINAL'")),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Cross-scope DML must be refused.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        InpatientSummaryAddendumMutationScope::run(fn () => $guard->assertAllowed('update inpatient_summary_addenda set version = version + 1'));
        InpatientRmMutationScope::run(fn () => $guard->assertAllowed('update inpatient_rm_codings set version = version + 1'));
        $this->addToAssertionCount(2);
    }

    public function test_schema_scopes_cannot_alter_each_others_protected_tables(): void
    {
        $guard = new InpatientDocumentationSqlWriteGuard;

        foreach ([
            fn () => InpatientSummaryAddendumSchemaMutationScope::run(fn () => $guard->assertAllowed('alter table inpatient_rm_codings add column forbidden integer')),
            fn () => InpatientRmSchemaMutationScope::run(fn () => $guard->assertAllowed('alter table inpatient_summary_addenda add column forbidden integer')),
            fn () => InpatientSummaryAddendumSchemaMutationScope::run(fn () => $guard->assertAllowed('create trigger cross_scope before update on inpatient_rm_codings for each row begin select 1; end')),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Cross-scope DDL must be refused.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        InpatientRmSchemaMutationScope::run(fn () => $guard->assertAllowed('create trigger own_scope before update on inpatient_rm_codings for each row begin select 1; end'));
        $this->addToAssertionCount(1);
    }

    public function test_inpatient_rm_service_does_not_request_update_locks_on_immutable_evidence(): void
    {
        $source = file_get_contents(__DIR__.'/../../../app/Support/Inpatient/InpatientRmService.php');
        self::assertIsString($source);
        $normalized = preg_replace('/\s+/', '', $source);
        self::assertIsString($normalized);

        self::assertStringNotContainsString("InpatientRmCompletenessReview::query()->where('encounter_id',\$encounter->id)->orderByDesc('version')->lockForUpdate()", $normalized);
        self::assertStringNotContainsString('inpatientDischarge()->lockForUpdate()', $normalized);
        self::assertStringNotContainsString("InpatientDischargeCodingSourceVersion::query()->with('source')->whereKey(\$discharge->inpatient_discharge_coding_source_version_id)->lockForUpdate()", $normalized);
        self::assertStringNotContainsString("where('version',\$version)->lockForUpdate()->first()", $normalized);

        self::assertStringContainsString("InpatientRmCompletenessReview::query()->where('encounter_id',\$encounter->id)->orderByDesc('version')->first()", $normalized);
        self::assertStringContainsString('inpatientDischarge()->first()', $normalized);
        self::assertStringContainsString("InpatientDischargeCodingSourceVersion::query()->with('source')->whereKey(\$discharge->inpatient_discharge_coding_source_version_id)->first()", $normalized);
        self::assertStringContainsString("where('version',\$version)->first()", $normalized);
        self::assertStringContainsString("InpatientRmCoding::query()->where('encounter_id',\$encounter->id)->lockForUpdate()", $normalized);
        self::assertStringContainsString("Encounter::query()->where('public_id',\$publicId)->lockForUpdate()", $normalized);
    }
}
