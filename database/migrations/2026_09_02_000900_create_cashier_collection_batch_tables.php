<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'finance_cashier_collection_operation_receipts',
        'finance_cash_deposit_handoffs',
        'finance_cashier_collection_events',
        'finance_cashier_collection_members',
        'finance_cashier_collection_active_slots',
        'finance_cashier_collection_batches',
    ];

    public function up(): void
    {
        FinanceSchemaMutationScope::run(fn () => $this->migrateUp());
    }

    private function migrateUp(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Cashier collection migration requires synthetic-only SIMULATION mode.');
        }

        FinanceAppendOnlyGuard::remove();
        try {
            if (! Schema::hasColumn(SchemaQualifier::table('finance_cash_settlements'), 'collection_binding_required')) {
                Schema::table(SchemaQualifier::table('finance_cash_settlements'), function (Blueprint $table): void {
                    $table->boolean('collection_binding_required')->default(false)->index('fcs_collection_binding_idx');
                });
            }
            // MySQL DDL auto-commits. A retry after a partial, empty installation
            // removes only this migration's catalog objects, then rebuilds them.
            // Any retained row makes the retry fail closed instead of guessing.
            $existing = collect(self::TABLES)->filter(fn (string $table): bool => Schema::hasTable(SchemaQualifier::table($table)));
            if ($existing->isNotEmpty()) {
                foreach ($existing as $table) {
                    if (DB::table(SchemaQualifier::table($table))->exists()) {
                        throw new RuntimeException('Cashier collection migration retry refused: partial catalog contains retained rows.');
                    }
                }
                foreach (self::TABLES as $table) {
                    Schema::dropIfExists(SchemaQualifier::table($table));
                }
            }
            Schema::create(SchemaQualifier::table('finance_cashier_collection_batches'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26)->unique('fccb_public_id_uq');
                $table->string('batch_number', 64)->unique('fccb_number_uq');
                $table->foreignId('cashier_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fccb_cashier_fk')->restrictOnDelete();
                $table->string('cashier_name_snapshot', 255);
                $table->string('content_digest', 64);
                $table->timestamp('opened_at');
                $table->timestamp('created_at');
                $table->index(['cashier_user_id', 'opened_at', 'id'], 'fccb_cashier_open_idx');
            });

            Schema::create(SchemaQualifier::table('finance_cashier_collection_active_slots'), function (Blueprint $table): void {
                $table->foreignId('cashier_user_id')->primary()->constrained(SchemaQualifier::table('users'), indexName: 'fccas_cashier_fk')->restrictOnDelete();
                $table->foreignId('collection_batch_id')->unique('fccas_batch_uq')->constrained(SchemaQualifier::table('finance_cashier_collection_batches'), indexName: 'fccas_batch_fk')->restrictOnDelete();
                $table->timestamp('created_at');
            });

            Schema::create(SchemaQualifier::table('finance_cashier_collection_members'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26)->unique('fccm_public_id_uq');
                $table->foreignId('collection_batch_id')->constrained(SchemaQualifier::table('finance_cashier_collection_batches'), indexName: 'fccm_batch_fk')->restrictOnDelete();
                $table->foreignId('settlement_id')->unique('fccm_settlement_uq')->constrained(SchemaQualifier::table('finance_cash_settlements'), indexName: 'fccm_settlement_fk')->restrictOnDelete();
                $table->foreignId('cashier_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fccm_cashier_fk')->restrictOnDelete();
                $table->string('cashier_name_snapshot', 255);
                $table->string('settlement_public_id_snapshot', 26);
                $table->string('receipt_number_snapshot', 64);
                $table->unsignedBigInteger('amount');
                $table->string('settlement_content_digest', 64);
                $table->string('content_digest', 64);
                $table->timestamp('collected_at');
                $table->timestamp('created_at');
                $table->unique(['collection_batch_id', 'settlement_id'], 'fccm_batch_settlement_uq');
                $table->index(['collection_batch_id', 'id'], 'fccm_batch_order_idx');
            });

            Schema::create(SchemaQualifier::table('finance_cashier_collection_events'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26)->unique('fcce_public_id_uq');
                $table->foreignId('collection_batch_id')->constrained(SchemaQualifier::table('finance_cashier_collection_batches'), indexName: 'fcce_batch_fk')->restrictOnDelete();
                $table->unsignedInteger('sequence');
                $table->string('event_type', 32);
                $table->string('previous_event_digest', 64)->nullable();
                $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fcce_actor_fk')->restrictOnDelete();
                $table->string('actor_name_snapshot', 255);
                $table->unsignedInteger('membership_count');
                $table->unsignedBigInteger('gross_amount');
                $table->unsignedBigInteger('completed_refund_amount');
                $table->unsignedBigInteger('expected_net_amount');
                $table->unsignedBigInteger('counted_amount');
                $table->bigInteger('variance_amount');
                $table->string('membership_digest', 64);
                $table->string('explanation', 500)->nullable();
                $table->string('content_digest', 64);
                $table->timestamp('occurred_at');
                $table->timestamp('created_at');
                $table->unique(['collection_batch_id', 'sequence'], 'fcce_batch_sequence_uq');
                $table->index(['collection_batch_id', 'id'], 'fcce_batch_order_idx');
            });

            Schema::create(SchemaQualifier::table('finance_cash_deposit_handoffs'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26)->unique('fcdh_public_id_uq');
                $table->string('handoff_number', 64)->unique('fcdh_number_uq');
                $table->foreignId('collection_batch_id')->unique('fcdh_batch_uq')->constrained(SchemaQualifier::table('finance_cashier_collection_batches'), indexName: 'fcdh_batch_fk')->restrictOnDelete();
                $table->foreignId('verified_event_id')->unique('fcdh_verified_event_uq')->constrained(SchemaQualifier::table('finance_cashier_collection_events'), indexName: 'fcdh_event_fk')->restrictOnDelete();
                $table->foreignId('cashier_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fcdh_cashier_fk')->restrictOnDelete();
                $table->foreignId('supervisor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fcdh_supervisor_fk')->restrictOnDelete();
                $table->string('cashier_name_snapshot', 255);
                $table->string('supervisor_name_snapshot', 255);
                $table->string('batch_public_id_snapshot', 26);
                $table->string('batch_number_snapshot', 64);
                $table->unsignedInteger('membership_count');
                $table->unsignedBigInteger('gross_amount');
                $table->unsignedBigInteger('completed_refund_amount');
                $table->unsignedBigInteger('expected_net_amount');
                $table->unsignedBigInteger('counted_amount');
                $table->string('batch_content_digest', 64);
                $table->string('verified_event_digest', 64);
                $table->string('content_digest', 64);
                $table->timestamp('handed_off_at');
                $table->timestamp('created_at');
            });

            Schema::create(SchemaQualifier::table('finance_cashier_collection_operation_receipts'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26)->unique('fccor_public_id_uq');
                $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fccor_actor_fk')->restrictOnDelete();
                $table->foreignId('collection_batch_id')->constrained(SchemaQualifier::table('finance_cashier_collection_batches'), indexName: 'fccor_batch_fk')->restrictOnDelete();
                $table->foreignId('collection_event_id')->nullable()->constrained(SchemaQualifier::table('finance_cashier_collection_events'), indexName: 'fccor_event_fk')->restrictOnDelete();
                $table->foreignId('deposit_handoff_id')->nullable()->constrained(SchemaQualifier::table('finance_cash_deposit_handoffs'), indexName: 'fccor_handoff_fk')->restrictOnDelete();
                $table->string('operation', 64);
                $table->string('idempotency_key', 255);
                $table->string('payload_digest', 64);
                $table->string('result_type', 16);
                $table->string('result_public_id', 26);
                $table->string('batch_content_digest', 64);
                $table->string('event_content_digest', 64)->nullable();
                $table->string('handoff_content_digest', 64)->nullable();
                $table->string('result_digest', 64);
                $table->string('request_correlation_id', 26)->nullable();
                $table->timestamp('completed_at');
                $table->timestamps();
                $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'fccor_actor_operation_key_uq');
                $table->unique(['operation', 'result_public_id'], 'fccor_operation_result_uq');
            });

            $this->addChecks();
        } finally {
            FinanceAppendOnlyGuard::install();
        }
    }

    public function down(): void
    {
        FinanceSchemaMutationScope::run(function (): void {
            foreach (self::TABLES as $table) {
                $qualified = SchemaQualifier::table($table);
                if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                    throw new RuntimeException('Refusing to discard populated cashier collection evidence.');
                }
            }
            if (DB::table(SchemaQualifier::table('finance_cash_settlements'))->where('collection_binding_required', true)->exists()) {
                throw new RuntimeException('Refusing to discard the durable cashier-collection activation discriminator.');
            }
            $audit = SchemaQualifier::table('audit_events');
            if (Schema::hasTable($audit) && DB::table($audit)->where('action', 'finance.workflow.mutate')
                ->whereIn('metadata->operation', [
                    'FINANCE_CASHIER_COLLECTION_OPEN', 'FINANCE_CASHIER_COLLECTION_CLOSE_REQUEST',
                    'FINANCE_CASHIER_COLLECTION_RECOUNT', 'FINANCE_CASHIER_COLLECTION_VERIFY',
                    'FINANCE_CASH_DEPOSIT_HANDOFF_CREATE',
                ])->exists()) {
                throw new RuntimeException('Refusing to discard cashier collection tables while correlated audit evidence remains.');
            }
            FinanceAppendOnlyGuard::remove();
            try {
                foreach (self::TABLES as $table) {
                    Schema::dropIfExists(SchemaQualifier::table($table));
                }
                Schema::table(SchemaQualifier::table('finance_cash_settlements'), function (Blueprint $table): void {
                    $table->dropIndex('fcs_collection_binding_idx');
                    $table->dropColumn('collection_binding_required');
                });
            } finally {
                FinanceAppendOnlyGuard::install();
            }
        });
    }

    private function addChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_cashier_collection_members').' ADD CONSTRAINT fccm_values_ck CHECK (amount>0)');
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_cashier_collection_events')." ADD CONSTRAINT fcce_values_ck CHECK (gross_amount>=completed_refund_amount AND expected_net_amount=gross_amount-completed_refund_amount AND variance_amount=counted_amount-expected_net_amount AND ((event_type='CLOSE_REQUESTED' AND sequence=1 AND previous_event_digest IS NULL) OR (event_type='RECOUNT_SUBMITTED' AND sequence>1 AND previous_event_digest IS NOT NULL) OR (event_type='CLOSE_VERIFIED' AND sequence>1 AND previous_event_digest IS NOT NULL AND variance_amount=0)))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_cash_deposit_handoffs').' ADD CONSTRAINT fcdh_values_ck CHECK (gross_amount>=completed_refund_amount AND expected_net_amount=gross_amount-completed_refund_amount AND counted_amount=expected_net_amount)');
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_cashier_collection_operation_receipts')." ADD CONSTRAINT fccor_result_ck CHECK ((operation='FINANCE_CASHIER_COLLECTION_OPEN' AND result_type='BATCH' AND collection_event_id IS NULL AND deposit_handoff_id IS NULL) OR (operation IN ('FINANCE_CASHIER_COLLECTION_CLOSE_REQUEST','FINANCE_CASHIER_COLLECTION_RECOUNT','FINANCE_CASHIER_COLLECTION_VERIFY') AND result_type='EVENT' AND collection_event_id IS NOT NULL AND deposit_handoff_id IS NULL) OR (operation='FINANCE_CASH_DEPOSIT_HANDOFF_CREATE' AND result_type='HANDOFF' AND collection_event_id IS NOT NULL AND deposit_handoff_id IS NOT NULL))");
    }
};
