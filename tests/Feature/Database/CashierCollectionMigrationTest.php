<?php

namespace Tests\Feature\Database;

use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceSqlWriteGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class CashierCollectionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_guard_allows_locking_reads_but_still_refuses_actual_dml(): void
    {
        $guard = new FinanceSqlWriteGuard;
        $guard->assertAllowed('SELECT * FROM finance_cashier_collection_batches WHERE id = ? FOR UPDATE SKIP LOCKED');
        $guard->assertAllowed('WITH candidate AS (SELECT id FROM finance_bills) SELECT * FROM candidate FOR NO KEY UPDATE');

        foreach ([
            'UPDATE finance_cashier_collection_batches SET id = id',
            'WITH changed AS (UPDATE finance_bills SET id = id RETURNING id) SELECT * FROM changed FOR UPDATE',
        ] as $sql) {
            try {
                $guard->assertAllowed($sql);
                $this->fail('Expected actual finance DML to remain prohibited.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('Write-capable SQL against finance tables is prohibited', $exception->getMessage());
            }
        }

        $this->addToAssertionCount(2);
    }

    public function test_schema_activation_discriminator_and_all_evidence_guards_are_installed(): void
    {
        $this->assertTrue(Schema::hasColumn('finance_cash_settlements', 'collection_binding_required'));
        $this->assertTrue(Schema::hasColumns('finance_cashier_collection_batches', ['public_id', 'batch_number', 'cashier_user_id', 'content_digest', 'opened_at']));
        $this->assertTrue(Schema::hasColumns('finance_cashier_collection_active_slots', ['cashier_user_id', 'collection_batch_id']));
        $this->assertTrue(Schema::hasColumns('finance_cashier_collection_members', ['collection_batch_id', 'settlement_id', 'amount', 'settlement_content_digest', 'content_digest']));
        $this->assertTrue(Schema::hasColumns('finance_cashier_collection_events', ['collection_batch_id', 'sequence', 'event_type', 'membership_digest', 'expected_net_amount', 'counted_amount', 'variance_amount']));
        $this->assertTrue(Schema::hasColumns('finance_cash_deposit_handoffs', ['collection_batch_id', 'verified_event_id', 'expected_net_amount', 'content_digest']));
        $this->assertTrue(Schema::hasColumns('finance_cashier_collection_operation_receipts', ['actor_user_id', 'operation', 'idempotency_key', 'payload_digest', 'result_digest']));

        $guard = app(FinanceSqlWriteGuard::class);
        foreach ([
            'finance_cashier_collection_batches', 'finance_cashier_collection_active_slots',
            'finance_cashier_collection_members', 'finance_cashier_collection_events',
            'finance_cash_deposit_handoffs', 'finance_cashier_collection_operation_receipts',
        ] as $table) {
            try {
                $guard->assertAllowed("UPDATE {$table} SET id = id");
                $this->fail("Expected SQL write guard for {$table}.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString('Write-capable SQL against finance tables is prohibited', $exception->getMessage());
            }
        }
        foreach ([
            'finance_cashier_collection_batches', 'finance_cashier_collection_members',
            'finance_cashier_collection_events', 'finance_cash_deposit_handoffs',
            'finance_cashier_collection_operation_receipts',
        ] as $table) {
            $this->assertContains($table, FinanceAppendOnlyGuard::TABLES);
        }
        $this->assertNotContains('finance_cashier_collection_active_slots', FinanceAppendOnlyGuard::TABLES);
    }

    public function test_empty_migration_rolls_back_reapplies_and_partial_empty_catalog_retries(): void
    {
        $migration = require base_path('database/migrations/2026_09_02_000900_create_cashier_collection_batch_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('finance_cashier_collection_batches'));
        $this->assertFalse(Schema::hasColumn('finance_cash_settlements', 'collection_binding_required'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('finance_cashier_collection_batches'));
        $this->assertTrue(Schema::hasColumn('finance_cash_settlements', 'collection_binding_required'));

        // A migration retry sees an empty, complete/partial catalog and rebuilds it.
        $migration->up();
        $this->assertTrue(Schema::hasTable('finance_cashier_collection_operation_receipts'));
    }
}
