<?php

namespace Tests\Feature\Database;

use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceSqlWriteGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CashSettlementCorrectionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_correction_schema_and_guards_are_installed(): void
    {
        $this->assertTrue(Schema::hasColumns('finance_settlement_correction_cases', [
            'public_id', 'settlement_id', 'amount', 'settlement_content_digest', 'reason_code', 'content_digest',
        ]));
        $this->assertTrue(Schema::hasColumns('finance_settlement_correction_events', [
            'correction_case_id', 'sequence', 'event_type', 'previous_event_digest',
            'amount', 'approval_event_digest', 'content_digest',
        ]));
        $this->assertTrue(Schema::hasColumns('finance_settlement_correction_operation_receipts', [
            'actor_user_id', 'operation', 'idempotency_key', 'payload_digest', 'result_digest',
        ]));
        $this->assertTrue(Schema::hasColumn('finance_cash_settlements', 'prior_net_collected_amount_snapshot'));
        foreach ([
            'finance_settlement_correction_cases',
            'finance_settlement_correction_events',
            'finance_settlement_correction_operation_receipts',
        ] as $table) {
            $this->assertContains($table, FinanceAppendOnlyGuard::TABLES);
            try {
                app(FinanceSqlWriteGuard::class)->assertAllowed("UPDATE {$table} SET id = id");
                $this->fail("Expected SQL write guard for {$table}.");
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('Write-capable SQL against finance tables is prohibited', $exception->getMessage());
            }
        }
    }

    public function test_append_only_reset_bypass_is_bound_to_the_installer_database_identity(): void
    {
        $source = file_get_contents(app_path('Support/Finance/FinanceAppendOnlyGuard.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString('current_user = {$installerIdentity}', $source);
        $this->assertStringContainsString('USER() = {$installerIdentity}', $source);
        $this->assertStringNotContainsString("IF current_setting('simrs.synthetic_reset', true) = '1' THEN", $source);
        $this->assertStringNotContainsString('IF COALESCE(@simrs_synthetic_reset, 0) <> 1 THEN', $source);
    }

    public function test_empty_migration_can_roll_back_and_reapply(): void
    {
        $migration = require base_path('database/migrations/2026_09_02_000800_create_append_only_cash_settlement_correction_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('finance_settlement_correction_cases'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('finance_settlement_correction_cases'));
    }
}
