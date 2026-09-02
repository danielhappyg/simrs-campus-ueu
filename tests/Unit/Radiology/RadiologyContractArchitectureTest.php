<?php

namespace Tests\Unit\Radiology;

use App\Support\Audit\AuditEventSchemaRegistry;
use App\Support\Audit\InvalidAuditEvent;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RadiologyContractArchitectureTest extends TestCase
{
    public function test_engine_schema_uses_safe_widths_closed_receipts_and_reapplicable_pg_helpers(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_31_001100_create_cross_setting_radiology_tables.php'));
        $this->assertIsString($migration);
        $this->assertStringContainsString("\$t->string('status', 24);", $migration);
        $this->assertStringContainsString("\$t->string('result_type', 24);", $migration);
        $this->assertStringContainsString("\$t->string('result_digest', 64);", $migration);
        $this->assertStringContainsString("\$t->unsignedInteger('result_version');", $migration);
        $this->assertStringContainsString('ADD CONSTRAINT ror_result_ck', $migration);
        $this->assertStringContainsString('result_version>=1', $migration);
        $this->assertStringContainsString('DROP FUNCTION IF EXISTS radiology_append_only_guard()', $migration);
        $this->assertStringContainsString('NEW.master_preparation_instruction IS DISTINCT FROM OLD.master_preparation_instruction', $migration);
        $this->assertStringContainsString('NEW.public_id<>OLD.public_id OR NEW.created_at<>OLD.created_at OR NEW.examination_code', $migration);
        $this->assertStringContainsString('NEW.ordered_at<>OLD.ordered_at', $migration);
        $this->assertStringContainsString('radiology_order_no_truncate', $migration);
    }

    public function test_replay_current_reads_are_mysql_only_and_immutable_tables_are_not_for_update(): void
    {
        foreach ([
            app_path('Support/Radiology/RadiologyWorkflowService.php'),
            app_path('Support/Radiology/RadiologyMasterService.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $this->assertStringContainsString("getDriverName() === 'mysql'", $source);
            $this->assertStringContainsString('->sharedLock()', $source);
            $this->assertDoesNotMatchRegularExpression('/RadiologyOperationReceipt::query\(\)[\s\S]{0,300}->lockForUpdate\(\)/', $source);
        }

        $workflow = file_get_contents(app_path('Support/Radiology/RadiologyWorkflowService.php'));
        $this->assertIsString($workflow);
        $this->assertDoesNotMatchRegularExpression('/RadiologyReportVersion::query\(\)[\s\S]{0,250}->lockForUpdate\(\)/', $workflow);
    }

    public function test_audit_registry_rejects_an_unknown_success_operation_state_pair(): void
    {
        $this->expectException(InvalidAuditEvent::class);
        app(AuditEventSchemaRegistry::class)->assertAllows(
            'radiology.workflow.mutate',
            'radiology_record',
            (string) Str::ulid(),
            true,
            'SUCCESS',
            null,
            ['operation' => 'RADIOLOGY_REPORT_VERIFY', 'state' => 'DRAFT', 'version' => 1],
        );
    }
}
