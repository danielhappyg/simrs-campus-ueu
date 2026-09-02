<?php

use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Database\SchemaQualifier;
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const ROSTER = [
        'registrar.demo@example.invalid' => RoleCapabilityMatrix::ROLE_REGISTRAR,
        'nurse.demo@example.invalid' => RoleCapabilityMatrix::ROLE_NURSE,
        'physician.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHYSICIAN,
        'rmik.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RMIK,
        'admin.demo@example.invalid' => RoleCapabilityMatrix::ROLE_ADMIN,
        'radiology.technologist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST,
        'radiologist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RADIOLOGIST,
        'laboratory.technologist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST,
        'laboratory.verifier.demo@example.invalid' => RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER,
        'pharmacist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHARMACIST,
        'pharmacy.technician.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN,
        'pharmacy.inventory.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER,
        'cashier.demo@example.invalid' => RoleCapabilityMatrix::ROLE_CASHIER,
        'finance.steward.demo@example.invalid' => RoleCapabilityMatrix::ROLE_FINANCE_STEWARD,
        'cashier.supervisor.demo@example.invalid' => RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR,
    ];

    private const TABLES = [
        'finance_settlement_correction_operation_receipts',
        'finance_settlement_correction_events',
        'finance_settlement_correction_cases',
    ];

    public function up(): void
    {
        FinanceSchemaMutationScope::run(fn () => $this->migrateUp());
    }

    private function migrateUp(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Cash settlement correction migration requires synthetic-only SIMULATION mode.');
        }

        FinanceAppendOnlyGuard::remove();
        try {
            $this->replacePostgresRosterConstraint(self::ROSTER);
            Schema::table(SchemaQualifier::table('finance_cash_settlements'), function (Blueprint $table): void {
                $table->unsignedBigInteger('prior_net_collected_amount_snapshot')->nullable();
                $table->index('bill_version_id', 'fcs_bill_version_idx');
                $table->index(['bill_id', 'bill_version_snapshot', 'id'], 'fcs_bill_version_number_idx');
            });
            Schema::create(SchemaQualifier::table('finance_settlement_correction_cases'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26)->unique();
                $table->string('correction_number', 64)->unique();
                $table->foreignId('settlement_id')->unique('fscc_settlement_uq')->constrained(SchemaQualifier::table('finance_cash_settlements'), indexName: 'fscc_settlement_fk')->restrictOnDelete();
                $table->foreignId('bill_id')->constrained(SchemaQualifier::table('finance_bills'), indexName: 'fscc_bill_fk')->restrictOnDelete();
                $table->foreignId('bill_version_id')->constrained(SchemaQualifier::table('finance_bill_versions'), indexName: 'fscc_version_fk')->restrictOnDelete();
                $table->foreignId('requesting_cashier_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fscc_requester_fk')->restrictOnDelete();
                $table->string('requesting_cashier_name_snapshot', 255);
                $table->string('settlement_public_id_snapshot', 26);
                $table->string('receipt_number_snapshot', 64);
                $table->unsignedBigInteger('amount');
                $table->string('settlement_content_digest', 64);
                $table->string('bill_public_id_snapshot', 26);
                $table->string('bill_version_public_id_snapshot', 26);
                $table->unsignedInteger('bill_version_snapshot');
                $table->string('reason_code', 64);
                $table->string('explanation', 500);
                $table->string('content_digest', 64);
                $table->timestamp('requested_at');
                $table->timestamp('created_at');
                $table->index(['bill_id', 'bill_version_id', 'id'], 'fscc_bill_version_idx');
            });

            Schema::create(SchemaQualifier::table('finance_settlement_correction_events'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26)->unique();
                $table->foreignId('correction_case_id')->constrained(SchemaQualifier::table('finance_settlement_correction_cases'), indexName: 'fsce_case_fk')->restrictOnDelete();
                $table->unsignedInteger('sequence');
                $table->string('event_type', 32);
                $table->string('previous_event_digest', 64)->nullable();
                $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fsce_actor_fk')->restrictOnDelete();
                $table->string('actor_name_snapshot', 255);
                $table->string('explanation', 500)->nullable();
                $table->unsignedBigInteger('amount')->nullable();
                $table->string('original_settlement_content_digest', 64);
                $table->string('approval_event_digest', 64)->nullable();
                $table->string('content_digest', 64);
                $table->timestamp('occurred_at');
                $table->timestamp('created_at');
                $table->unique(['correction_case_id', 'sequence'], 'fsce_case_sequence_uq');
                $table->index(['correction_case_id', 'id'], 'fsce_case_order_idx');
            });

            Schema::create(SchemaQualifier::table('finance_settlement_correction_operation_receipts'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26);
                $table->unique('public_id', 'fscor_public_id_uq');
                $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fscor_actor_fk')->restrictOnDelete();
                $table->foreignId('correction_case_id')->constrained(SchemaQualifier::table('finance_settlement_correction_cases'), indexName: 'fscor_case_fk')->restrictOnDelete();
                $table->foreignId('correction_event_id')->nullable()->constrained(SchemaQualifier::table('finance_settlement_correction_events'), indexName: 'fscor_event_fk')->restrictOnDelete();
                $table->string('operation', 64);
                $table->string('idempotency_key', 255);
                $table->string('payload_digest', 64);
                $table->string('result_type', 16);
                $table->string('result_public_id', 26);
                $table->string('case_content_digest', 64);
                $table->string('event_content_digest', 64)->nullable();
                $table->string('result_digest', 64);
                $table->string('request_correlation_id', 26)->nullable();
                $table->timestamp('completed_at');
                $table->timestamps();
                $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'fscor_actor_operation_key_uq');
                $table->unique(['operation', 'result_public_id'], 'fscor_operation_result_uq');
            });

            $this->addChecks();
            // Keep the lifetime uniqueness barriers until every correction control is ready.
            // Laravel emits one ALTER TABLE here, so MySQL removes both barriers atomically.
            Schema::table(SchemaQualifier::table('finance_cash_settlements'), function (Blueprint $table): void {
                $table->dropUnique('fcs_bill_version_uq');
                $table->dropUnique('fcs_bill_version_number_uq');
            });
        } finally {
            FinanceAppendOnlyGuard::install();
        }
    }

    public function down(): void
    {
        FinanceSchemaMutationScope::run(fn () => $this->migrateDown());
    }

    private function migrateDown(): void
    {
        if (DB::table(SchemaQualifier::table('users'))
            ->where('email', 'cashier.supervisor.demo@example.invalid')
            ->orWhere('teaching_access_roster_key', RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR)
            ->exists()
            || DB::table(SchemaQualifier::table('teaching_role_access_leases'))
                ->where('expected_role', RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR)->exists()) {
            throw new RuntimeException('Rollback refused: preserve the cashier-supervisor identity and access-window evidence.');
        }
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)
            ->where('action', 'finance.workflow.mutate')
            ->whereIn('metadata->operation', [
                'FINANCE_SETTLEMENT_CORRECTION_REQUEST',
                'FINANCE_SETTLEMENT_CORRECTION_REVIEW',
                'FINANCE_SETTLEMENT_REFUND_COMPLETE',
            ])->exists()) {
            throw new RuntimeException('Refusing to discard settlement correction tables while correlated audit evidence remains.');
        }
        foreach (self::TABLES as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to discard populated settlement correction evidence.');
            }
        }
        if (DB::table(SchemaQualifier::table('finance_cash_settlements'))
            ->whereNotNull('prior_net_collected_amount_snapshot')->exists()) {
            throw new RuntimeException('Refusing to discard settlement calculation snapshots.');
        }

        FinanceAppendOnlyGuard::remove();
        try {
            foreach (self::TABLES as $table) {
                Schema::dropIfExists(SchemaQualifier::table($table));
            }
            Schema::table(SchemaQualifier::table('finance_cash_settlements'), function (Blueprint $table): void {
                $table->unique('bill_version_id', 'fcs_bill_version_uq');
                $table->unique(['bill_id', 'bill_version_snapshot'], 'fcs_bill_version_number_uq');
            });
            $this->dropPriorNetCheck();
            Schema::table(SchemaQualifier::table('finance_cash_settlements'), function (Blueprint $table): void {
                $table->dropIndex('fcs_bill_version_idx');
                $table->dropIndex('fcs_bill_version_number_idx');
                $table->dropColumn('prior_net_collected_amount_snapshot');
            });
            $roster = self::ROSTER;
            unset($roster['cashier.supervisor.demo@example.invalid']);
            $this->replacePostgresRosterConstraint($roster);
        } finally {
            FinanceAppendOnlyGuard::install();
        }
    }

    private function addChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }

        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_settlement_correction_cases')." ADD CONSTRAINT fscc_values_ck CHECK (amount>0 AND bill_version_snapshot>0 AND reason_code IN ('WRONG_BILL','DUPLICATE_COLLECTION','CASHIER_INPUT_CONTEXT_ERROR','OTHER_SUPERVISOR_REVIEW'))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_settlement_correction_events')." ADD CONSTRAINT fsce_transition_ck CHECK ((sequence=1 AND previous_event_digest IS NULL AND event_type='REVIEW_REJECTED' AND amount IS NULL AND approval_event_digest IS NULL AND explanation IS NOT NULL) OR (sequence=1 AND previous_event_digest IS NULL AND event_type='REFUND_APPROVED' AND amount>0 AND approval_event_digest IS NULL) OR (sequence=2 AND previous_event_digest IS NOT NULL AND event_type='REFUND_COMPLETED' AND amount>0 AND approval_event_digest IS NOT NULL))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_settlement_correction_operation_receipts')." ADD CONSTRAINT fscor_result_ck CHECK (operation IN ('FINANCE_SETTLEMENT_CORRECTION_REQUEST','FINANCE_SETTLEMENT_CORRECTION_REVIEW','FINANCE_SETTLEMENT_REFUND_COMPLETE') AND result_type IN ('CASE','EVENT'))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_cash_settlements').' ADD CONSTRAINT fcs_prior_net_snapshot_ck CHECK (prior_net_collected_amount_snapshot IS NULL OR prior_net_collected_amount_snapshot>=0)');
    }

    private function dropPriorNetCheck(): void
    {
        $table = SchemaQualifier::table('finance_cash_settlements');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS fcs_prior_net_snapshot_ck");
        } elseif (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE {$table} DROP CHECK fcs_prior_net_snapshot_ck");
        }
    }

    /** @param array<string, string> $roster */
    private function replacePostgresRosterConstraint(array $roster): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        $users = '"'.str_replace('"', '""', $schema).'"."users"';
        DB::statement("ALTER TABLE {$users} DROP CONSTRAINT IF EXISTS users_teaching_access_roster_mapping_ck");
        $emails = implode(', ', array_map(fn (string $email): string => DB::getPdo()->quote($email), array_keys($roster)));
        $mappings = implode(' OR ', array_map(
            fn (string $email, string $role): string => sprintf('(email = %s AND teaching_access_roster_key = %s)', DB::getPdo()->quote($email), DB::getPdo()->quote($role)),
            array_keys($roster), array_values($roster),
        ));
        DB::statement("ALTER TABLE {$users} ADD CONSTRAINT users_teaching_access_roster_mapping_ck CHECK ((teaching_access_roster_key IS NULL AND email NOT IN ({$emails})) OR {$mappings})");
    }
};
