<?php

namespace Tests\Feature\Database;

use App\Support\Warehouse\WarehouseAuditEvidenceGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class WarehouseAuditEvidenceGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_and_denied_warehouse_audit_rows_are_database_immutable(): void
    {
        $this->requireInstalledWarehouseSchema();

        foreach (['SUCCESS', 'DENIED'] as $outcome) {
            $id = $this->insertAudit(WarehouseAuditEvidenceGuard::ACTION, $outcome);

            try {
                DB::table('audit_events')->where('id', $id)->update(['reason' => 'tampered']);
                $this->fail("{$outcome} warehouse audit evidence must reject raw updates.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('warehouse audit evidence is immutable', $exception->getMessage());
            }

            try {
                DB::table('audit_events')->where('id', $id)->delete();
                $this->fail("{$outcome} warehouse audit evidence must reject raw deletes.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('warehouse audit evidence is immutable', $exception->getMessage());
            }
        }
    }

    public function test_conditional_guard_does_not_make_other_audit_families_immutable(): void
    {
        $this->requireInstalledWarehouseSchema();

        $id = $this->insertAudit('test.nonwarehouse', 'SUCCESS');

        $this->assertSame(1, DB::table('audit_events')->where('id', $id)->update(['reason' => 'updated']));
        $this->assertSame('updated', DB::table('audit_events')->where('id', $id)->value('reason'));
        $this->assertSame(1, DB::table('audit_events')->where('id', $id)->delete());
    }

    public function test_guard_rejects_changing_rows_into_or_out_of_the_warehouse_audit_family(): void
    {
        $this->requireInstalledWarehouseSchema();

        $warehouseId = $this->insertAudit(WarehouseAuditEvidenceGuard::ACTION, 'SUCCESS');
        $otherId = $this->insertAudit('test.nonwarehouse', 'SUCCESS');

        foreach ([
            fn () => DB::table('audit_events')->where('id', $warehouseId)->update(['action' => 'test.nonwarehouse']),
            fn () => DB::table('audit_events')->where('id', $otherId)->update(['action' => WarehouseAuditEvidenceGuard::ACTION]),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Cross-family warehouse audit rewrites must be rejected.');
            } catch (LogicException|QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_exact_engine_guard_contract_has_no_user_settable_bypass_authority(): void
    {
        $source = file_get_contents(app_path('Support/Warehouse/WarehouseAuditEvidenceGuard.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString("current_setting('simrs", $source);
        $this->assertStringNotContainsString('@simrs_', $source);
        $this->assertStringContainsString('current_user = {$resetOwner} AND session_user = {$resetExecutor}', $source);
        $this->assertStringContainsString('USER() <> {$resetExecutor}', $source);
        $this->assertStringContainsString('OLD.action = {$action} OR NEW.action = {$action}', $source);
    }

    private function insertAudit(string $action, string $outcome): string
    {
        $id = (string) Str::ulid();
        DB::table('audit_events')->insert([
            'id' => $id,
            'recorded_at' => now(),
            'action' => $action,
            'resource_type' => $action === WarehouseAuditEvidenceGuard::ACTION ? 'warehouse_record' : 'test_record',
            'resource_id' => (string) Str::ulid(),
            'outcome' => $outcome,
            'reason' => $outcome === 'DENIED' ? 'role_not_permitted' : null,
            'metadata' => json_encode(['operation' => 'WAREHOUSE_SUPPLIER_CREATE'], JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }

    private function requireInstalledWarehouseSchema(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)
            && ! Schema::hasTable('warehouse_suppliers')) {
            $this->markTestSkipped(
                'Exact-engine warehouse schema tests remain deferred until the governed identity/routine harness enables the migration.',
            );
        }
    }
}
