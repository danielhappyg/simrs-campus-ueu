<?php

namespace Tests\Unit\Radiology;

use App\Support\Radiology\RadiologySqlWriteGuard;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RadiologySqlWriteGuardTest extends TestCase
{
    public function test_normal_reads_are_allowed(): void
    {
        $guard = new RadiologySqlWriteGuard;
        $guard->assertAllowed('SELECT * FROM radiology_operation_receipts WHERE public_id = ?');
        $guard->assertAllowed('WITH reports AS (SELECT * FROM radiology_report_versions) SELECT * FROM reports');
        $this->addToAssertionCount(2);
    }

    public function test_protected_table_names_inside_unrelated_values_do_not_become_write_targets(): void
    {
        (new RadiologySqlWriteGuard)->assertAllowed(
            "INSERT INTO role_capabilities (capability) VALUES ('radiology_orders')",
        );

        $this->addToAssertionCount(1);
    }

    public function test_cross_domain_ddl_may_reference_radiology_without_writing_it(): void
    {
        (new RadiologySqlWriteGuard)->assertAllowed(
            'ALTER TABLE emergency_result_follow_up_proposals ADD CONSTRAINT erfup_order_fk FOREIGN KEY (order_id) REFERENCES radiology_orders (id)',
        );

        $this->addToAssertionCount(1);
    }

    #[DataProvider('nonReadStatements')]
    public function test_non_read_prefixes_are_rejected(string $sql): void
    {
        $this->expectException(LogicException::class);
        (new RadiologySqlWriteGuard)->assertAllowed($sql);
    }

    /** @return array<string,array{string}> */
    public static function nonReadStatements(): array
    {
        return [
            'copy' => ['COPY radiology_report_versions TO STDOUT'],
            'load data' => ["LOAD DATA INFILE 'rows.csv' INTO TABLE radiology_orders"],
            'grant' => ['GRANT UPDATE ON radiology_orders TO runtime_role'],
            'revoke' => ['REVOKE SELECT ON radiology_operation_receipts FROM runtime_role'],
            'call' => ['CALL radiology_orders()'],
            'writable CTE' => ['WITH removed AS (DELETE FROM radiology_orders RETURNING id) SELECT * FROM removed'],
            'explain analyze delete' => ['EXPLAIN ANALYZE DELETE FROM radiology_operation_receipts'],
        ];
    }
}
