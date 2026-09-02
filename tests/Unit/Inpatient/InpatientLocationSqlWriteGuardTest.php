<?php

namespace Tests\Unit\Inpatient;

use App\Support\Inpatient\InpatientLocationSqlWriteGuard;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InpatientLocationSqlWriteGuardTest extends TestCase
{
    public function test_locking_reads_of_location_history_are_allowed(): void
    {
        $guard = new InpatientLocationSqlWriteGuard;

        $guard->assertAllowed('select * from "laravel"."inpatient_location_events" where "encounter_id" = ? order by "sequence" desc limit 1 for update');
        $guard->assertAllowed('SELECT * FROM `inpatient_location_operation_receipts` WHERE `id` = ? LOCK IN SHARE MODE');

        $this->addToAssertionCount(2);
    }

    public function test_read_prefix_cannot_hide_a_following_write_statement(): void
    {
        $this->expectException(LogicException::class);

        (new InpatientLocationSqlWriteGuard)->assertAllowed(
            'select 1; update inpatient_location_events set sequence = 2',
        );
    }

    public function test_known_cross_domain_ddl_may_reference_location_evidence_without_writing_it(): void
    {
        (new InpatientLocationSqlWriteGuard)->assertAllowed(
            'alter table pharmacy_preparations add constraint prep_location_fk foreign key (location_event_id) references inpatient_location_events (id)',
        );

        $this->addToAssertionCount(1);
    }

    #[DataProvider('writeStatements')]
    public function test_real_location_history_writes_remain_blocked(string $sql): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Direct SQL writes to inpatient location history tables are prohibited.');

        (new InpatientLocationSqlWriteGuard)->assertAllowed($sql);
    }

    /** @return array<string, array{string}> */
    public static function writeStatements(): array
    {
        return [
            'insert' => ['insert into inpatient_location_events (sequence) values (1)'],
            'update' => ['update inpatient_location_events set sequence = 2'],
            'delete' => ['delete from inpatient_location_operation_receipts'],
            'truncate' => ['truncate table inpatient_location_events'],
            'ddl' => ['alter table inpatient_location_events add column unsafe text'],
            'modifying cte' => ['with changed as (update inpatient_location_events set sequence = 2 returning id) select * from changed'],
        ];
    }
}
