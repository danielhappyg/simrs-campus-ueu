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
        'finance_settlement_operation_receipts',
        'finance_cash_settlements',
    ];

    public function up(): void
    {
        FinanceSchemaMutationScope::run(fn () => $this->migrateUp());
    }

    private function migrateUp(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Cash settlement teaching migration requires synthetic-only SIMULATION mode.');
        }

        FinanceAppendOnlyGuard::remove();
        try {
            Schema::create(SchemaQualifier::table('finance_cash_settlements'), function (Blueprint $t): void {
                $t->id();
                $t->string('public_id', 26)->unique();
                $t->string('receipt_number', 64)->unique();
                $t->foreignId('bill_id')->constrained(SchemaQualifier::table('finance_bills'), indexName: 'fcs_bill_fk')->restrictOnDelete();
                $t->foreignId('bill_version_id')->unique('fcs_bill_version_uq')->constrained(SchemaQualifier::table('finance_bill_versions'), indexName: 'fcs_version_fk')->restrictOnDelete();
                $t->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'fcs_encounter_fk')->restrictOnDelete();
                $t->foreignId('patient_id')->constrained(SchemaQualifier::table('patients'), indexName: 'fcs_patient_fk')->restrictOnDelete();
                $t->foreignId('cashier_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fcs_cashier_fk')->restrictOnDelete();
                $t->string('cashier_name_snapshot', 255);
                $t->string('bill_public_id_snapshot', 26);
                $t->string('bill_number_snapshot', 64);
                $t->string('bill_version_public_id_snapshot', 26);
                $t->unsignedInteger('bill_version_snapshot');
                $t->string('encounter_public_id_snapshot', 26);
                $t->string('patient_public_id_snapshot', 26);
                $t->string('patient_name_snapshot', 160);
                $t->string('medical_record_number_snapshot', 64);
                $t->string('care_setting', 16);
                $t->string('coverage_profile_snapshot', 96);
                $t->string('coverage_label_snapshot', 500);
                $t->string('coverage_exclusion_snapshot', 500);
                $t->string('payment_method', 16);
                $t->string('state', 16);
                $t->unsignedBigInteger('amount');
                $t->string('source_set_digest', 64);
                $t->string('bill_version_content_digest', 64);
                $t->string('content_digest', 64);
                $t->timestamp('settled_at');
                $t->timestamp('created_at');
                $t->unique(['bill_id', 'bill_version_snapshot'], 'fcs_bill_version_number_uq');
                $t->index(['settled_at', 'id'], 'fcs_settled_idx');
            });

            Schema::create(SchemaQualifier::table('finance_settlement_operation_receipts'), function (Blueprint $t): void {
                $t->id();
                $t->string('public_id', 26)->unique();
                $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fsor_actor_fk')->restrictOnDelete();
                $t->string('operation', 64);
                $t->string('idempotency_key', 255);
                $t->string('payload_digest', 64);
                $t->string('settlement_public_id', 26);
                $t->string('bill_version_public_id', 26);
                $t->unsignedBigInteger('amount');
                $t->string('source_set_digest', 64);
                $t->string('bill_version_content_digest', 64);
                $t->string('settlement_content_digest', 64);
                $t->string('result_digest', 64);
                $t->string('request_correlation_id', 26)->nullable();
                $t->timestamp('completed_at');
                $t->timestamps();
                $t->unique(['actor_user_id', 'operation', 'idempotency_key'], 'fsor_actor_operation_key_uq');
                $t->unique(['operation', 'settlement_public_id'], 'fsor_operation_settlement_uq');
            });

            $this->addChecks();
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
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)
            ->where('action', 'finance.workflow.mutate')
            ->where('metadata->operation', 'FINANCE_CASH_SETTLEMENT')
            ->exists()) {
            throw new RuntimeException('Refusing to discard cash settlement tables while correlated audit evidence remains.');
        }

        foreach (self::TABLES as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to discard populated cash settlement evidence.');
            }
        }

        FinanceAppendOnlyGuard::remove();
        try {
            foreach (self::TABLES as $table) {
                Schema::dropIfExists(SchemaQualifier::table($table));
            }
        } finally {
            FinanceAppendOnlyGuard::install();
        }
    }

    private function addChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }

        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_cash_settlements')." ADD CONSTRAINT fcs_exact_cash_ck CHECK (bill_version_snapshot>0 AND care_setting IN ('OUTPATIENT','EMERGENCY','INPATIENT') AND payment_method='CASH' AND state='SETTLED' AND amount>0)");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_settlement_operation_receipts')." ADD CONSTRAINT fsor_result_ck CHECK (operation='FINANCE_CASH_SETTLEMENT' AND amount>0)");
    }
};
