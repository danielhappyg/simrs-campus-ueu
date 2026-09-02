<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceMutableHeadGuard;
use App\Support\Finance\FinanceSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'finance_bill_lines', 'finance_operation_receipts', 'finance_bill_versions',
        'finance_bills', 'finance_charge_events',
    ];

    public function up(): void
    {
        FinanceSchemaMutationScope::run(fn () => $this->migrateUp());
    }

    private function migrateUp(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Finance teaching migration requires synthetic-only SIMULATION mode.');
        }

        Schema::create(SchemaQualifier::table('finance_charge_events'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('pharmacy_financial_source_event_id')->unique('fce_pharmacy_source_uq')->constrained(SchemaQualifier::table('pharmacy_financial_source_events'), indexName: 'fce_pharmacy_source_fk')->restrictOnDelete();
            $t->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'fce_encounter_fk')->restrictOnDelete();
            $t->foreignId('patient_id')->constrained(SchemaQualifier::table('patients'), indexName: 'fce_patient_fk')->restrictOnDelete();
            $t->foreignId('imported_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fce_importer_fk')->restrictOnDelete();
            $t->string('source_domain', 24);
            $t->string('source_table', 64);
            $t->string('source_public_id', 26);
            $t->string('source_content_digest', 64);
            $t->string('event_type', 16);
            $t->string('care_setting', 16);
            $t->unsignedBigInteger('quantity');
            $t->unsignedBigInteger('unit_amount');
            $t->bigInteger('signed_amount');
            $t->string('description', 255);
            $t->string('content_digest', 64);
            $t->timestamp('occurred_at');
            $t->timestamp('imported_at');
            $t->timestamp('created_at');
            $t->unique(['source_domain', 'source_table', 'source_public_id'], 'fce_source_binding_uq');
            $t->index(['encounter_id', 'occurred_at', 'id'], 'fce_encounter_time_idx');
        });

        Schema::create(SchemaQualifier::table('finance_bills'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->string('bill_number', 64)->unique();
            $t->foreignId('encounter_id')->unique('fb_encounter_uq')->constrained(SchemaQualifier::table('encounters'), indexName: 'fb_encounter_fk')->restrictOnDelete();
            $t->foreignId('patient_id')->constrained(SchemaQualifier::table('patients'), indexName: 'fb_patient_fk')->restrictOnDelete();
            $t->string('care_setting', 16);
            $t->string('state', 32);
            $t->unsignedInteger('current_version')->default(0);
            $t->unsignedInteger('current_source_event_count')->default(0);
            $t->string('current_source_set_digest', 64);
            $t->string('current_issued_source_set_digest', 64)->nullable();
            $t->string('current_content_digest', 64);
            $t->timestamps();
            $t->index(['state', 'updated_at'], 'fb_state_updated_idx');
        });

        Schema::create(SchemaQualifier::table('finance_bill_versions'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('bill_id')->constrained(SchemaQualifier::table('finance_bills'), indexName: 'fbv_bill_fk')->restrictOnDelete();
            $t->foreignId('previous_version_id')->nullable()->constrained(SchemaQualifier::table('finance_bill_versions'), indexName: 'fbv_previous_fk')->restrictOnDelete();
            $t->foreignId('issued_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fbv_issuer_fk')->restrictOnDelete();
            $t->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'fbv_encounter_fk')->restrictOnDelete();
            $t->foreignId('patient_id')->constrained(SchemaQualifier::table('patients'), indexName: 'fbv_patient_fk')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('encounter_public_id_snapshot', 26);
            $t->string('encounter_number_snapshot', 26);
            $t->string('patient_public_id_snapshot', 26);
            $t->string('patient_name_snapshot', 160);
            $t->string('medical_record_number_snapshot', 64);
            $t->string('care_setting', 16);
            $t->string('payer_snapshot', 32);
            $t->string('service_location_snapshot', 160);
            $t->string('coverage_profile', 64);
            $t->string('source_set_digest', 64);
            $t->unsignedInteger('source_event_count');
            $t->timestamp('source_cutoff_at');
            $t->bigInteger('gross_amount');
            $t->bigInteger('reversal_amount');
            $t->bigInteger('net_amount');
            $t->text('issue_reason');
            $t->string('content_digest', 64);
            $t->timestamp('issued_at');
            $t->timestamp('created_at');
            $t->unique(['bill_id', 'version'], 'fbv_bill_version_uq');
            $t->unique(['bill_id', 'source_set_digest'], 'fbv_bill_source_set_uq');
        });

        Schema::create(SchemaQualifier::table('finance_bill_lines'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('bill_version_id')->constrained(SchemaQualifier::table('finance_bill_versions'), indexName: 'fbl_version_fk')->restrictOnDelete();
            $t->foreignId('charge_event_id')->constrained(SchemaQualifier::table('finance_charge_events'), indexName: 'fbl_charge_fk')->restrictOnDelete();
            $t->unsignedInteger('line_number');
            $t->string('source_domain', 24);
            $t->string('source_public_id', 26);
            $t->string('source_content_digest', 64);
            $t->string('event_type', 16);
            $t->unsignedBigInteger('quantity');
            $t->unsignedBigInteger('unit_amount');
            $t->bigInteger('signed_amount');
            $t->string('description', 255);
            $t->string('charge_event_content_digest', 64);
            $t->string('content_digest', 64);
            $t->timestamp('occurred_at');
            $t->timestamp('created_at');
            $t->unique(['bill_version_id', 'line_number'], 'fbl_version_line_uq');
            $t->unique(['bill_version_id', 'charge_event_id'], 'fbl_version_charge_uq');
        });

        Schema::create(SchemaQualifier::table('finance_operation_receipts'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'for_actor_fk')->restrictOnDelete();
            $t->string('operation', 64);
            $t->string('idempotency_key', 255);
            $t->string('payload_digest', 64);
            $t->string('result_type', 32);
            $t->string('result_public_id', 26);
            $t->unsignedInteger('result_version');
            $t->string('result_state', 32);
            $t->unsignedInteger('result_source_event_count');
            $t->string('result_source_set_digest', 64);
            $t->string('result_digest', 64);
            $t->string('request_correlation_id', 26)->nullable();
            $t->timestamp('completed_at');
            $t->timestamps();
            $t->unique(['actor_user_id', 'operation', 'idempotency_key'], 'for_actor_operation_key_uq');
        });

        $this->addChecks();
        FinanceAppendOnlyGuard::install();
        FinanceMutableHeadGuard::install();
    }

    public function down(): void
    {
        FinanceSchemaMutationScope::run(fn () => $this->migrateDown());
    }

    private function migrateDown(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)->where('action', 'finance.workflow.mutate')->exists()) {
            throw new RuntimeException('Refusing to discard finance tables while correlated audit evidence exists.');
        }
        foreach (self::TABLES as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to discard populated finance evidence.');
            }
        }
        FinanceMutableHeadGuard::remove();
        FinanceAppendOnlyGuard::remove();
        foreach (self::TABLES as $table) {
            Schema::dropIfExists(SchemaQualifier::table($table));
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS finance_append_only_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS finance_append_only_truncate_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS finance_mutable_head_guard()');
        }
    }

    private function addChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_charge_events')." ADD CONSTRAINT fce_value_ck CHECK (source_domain='PHARMACY' AND source_table='pharmacy_financial_source_events' AND event_type IN ('CHARGE','REVERSAL') AND care_setting IN ('OUTPATIENT','EMERGENCY','INPATIENT') AND quantity>0 AND unit_amount>=0 AND ((event_type='CHARGE' AND signed_amount>=0) OR (event_type='REVERSAL' AND signed_amount<=0)) AND ABS(signed_amount)=quantity*unit_amount)");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_bills')." ADD CONSTRAINT fb_state_ck CHECK (state IN ('OPEN_NO_VERSION','ISSUED_CURRENT','NEW_SOURCE_PENDING') AND ((current_version=0 AND state='OPEN_NO_VERSION' AND current_issued_source_set_digest IS NULL) OR (current_version>0 AND current_issued_source_set_digest IS NOT NULL AND state IN ('ISSUED_CURRENT','NEW_SOURCE_PENDING'))))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_bill_versions').' ADD CONSTRAINT fbv_value_ck CHECK (version>0 AND source_event_count>0 AND reversal_amount<=0 AND gross_amount+reversal_amount=net_amount AND net_amount>=0)');
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_bill_lines')." ADD CONSTRAINT fbl_value_ck CHECK (line_number>0 AND event_type IN ('CHARGE','REVERSAL') AND quantity>0 AND unit_amount>=0 AND ((event_type='CHARGE' AND signed_amount>=0) OR (event_type='REVERSAL' AND signed_amount<=0)) AND ABS(signed_amount)=quantity*unit_amount)");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_operation_receipts')." ADD CONSTRAINT for_result_ck CHECK (result_type IN ('BILL','BILL_VERSION') AND result_source_event_count>0)");
    }
};
