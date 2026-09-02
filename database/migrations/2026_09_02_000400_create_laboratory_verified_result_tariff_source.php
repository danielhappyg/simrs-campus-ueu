<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceLaboratoryTariffAppendOnlyGuard;
use App\Support\Finance\FinanceLaboratoryTariffMutableHeadGuard;
use App\Support\Finance\FinanceSchemaMutationScope;
use App\Support\Finance\FinanceTariffSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'finance_laboratory_source_events',
        'finance_laboratory_tariff_operation_receipts',
        'finance_laboratory_tariff_binding_versions',
        'finance_laboratory_tariff_bindings',
    ];

    public function up(): void
    {
        FinanceTariffSchemaMutationScope::run(
            fn () => FinanceSchemaMutationScope::run(fn () => $this->migrateUp()),
        );
    }

    private function migrateUp(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Laboratory tariff source migration requires synthetic-only SIMULATION mode.');
        }

        Schema::create(SchemaQualifier::table('finance_laboratory_tariff_bindings'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('laboratory_master_id')->constrained(SchemaQualifier::table('laboratory_examination_masters'), indexName: 'fltb_master_fk')->restrictOnDelete();
            $table->foreignId('laboratory_master_version_id')->constrained(SchemaQualifier::table('laboratory_examination_master_versions'), indexName: 'fltb_master_version_fk')->restrictOnDelete();
            $table->string('laboratory_master_version_public_id', 26);
            $table->unsignedInteger('laboratory_master_version');
            $table->string('laboratory_master_content_digest', 64);
            $table->string('laboratory_master_code', 64);
            $table->string('care_setting', 16);
            $table->string('state', 16);
            $table->unsignedInteger('version');
            $table->date('latest_effective_from');
            $table->string('current_content_digest', 64);
            $table->timestamps();
            $table->unique(['laboratory_master_version_id', 'care_setting'], 'fltb_master_version_care_uq');
            $table->index(['state', 'care_setting'], 'fltb_state_care_idx');
        });

        Schema::create(SchemaQualifier::table('finance_laboratory_tariff_binding_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('binding_id')->constrained(SchemaQualifier::table('finance_laboratory_tariff_bindings'), indexName: 'fltbv_binding_fk')->restrictOnDelete();
            $table->foreignId('tariff_item_id')->constrained(SchemaQualifier::table('finance_tariff_items'), indexName: 'fltbv_tariff_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fltbv_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('tariff_item_public_id', 26);
            $table->string('tariff_item_code', 64);
            $table->string('state', 16);
            $table->date('effective_from');
            $table->string('reason', 500);
            $table->string('previous_content_digest', 64)->nullable();
            $table->string('content_digest', 64);
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('created_at');
            $table->unique(['binding_id', 'version'], 'fltbv_binding_version_uq');
            $table->unique(['binding_id', 'effective_from'], 'fltbv_binding_effective_uq');
            $table->index(['effective_from', 'state'], 'fltbv_effective_state_idx');
        });

        Schema::create(SchemaQualifier::table('finance_laboratory_tariff_operation_receipts'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fltor_actor_fk')->restrictOnDelete();
            $table->string('operation', 64);
            $table->string('idempotency_key', 255);
            $table->string('payload_digest', 64);
            $table->string('result_type', 32);
            $table->string('result_public_id', 26);
            $table->unsignedInteger('result_version');
            $table->string('result_state', 16);
            $table->string('result_digest', 64);
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('completed_at');
            $table->timestamps();
            $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'fltor_actor_operation_key_uq');
        });

        Schema::create(SchemaQualifier::table('finance_laboratory_source_events'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('laboratory_result_version_id')->unique('flse_result_uq')->constrained(SchemaQualifier::table('laboratory_result_versions'), indexName: 'flse_result_fk')->restrictOnDelete();
            $table->foreignId('laboratory_order_id')->unique('flse_order_uq')->constrained(SchemaQualifier::table('laboratory_orders'), indexName: 'flse_order_fk')->restrictOnDelete();
            $table->foreignId('laboratory_specimen_attempt_id')->constrained(SchemaQualifier::table('laboratory_specimen_attempts'), indexName: 'flse_specimen_fk')->restrictOnDelete();
            $table->foreignId('laboratory_critical_communication_id')->nullable()->unique('flse_communication_uq')->constrained(SchemaQualifier::table('laboratory_critical_communications'), indexName: 'flse_communication_fk')->restrictOnDelete();
            $table->foreignId('binding_version_id')->constrained(SchemaQualifier::table('finance_laboratory_tariff_binding_versions'), indexName: 'flse_binding_version_fk')->restrictOnDelete();
            $table->foreignId('tariff_item_version_id')->constrained(SchemaQualifier::table('finance_tariff_item_versions'), indexName: 'flse_tariff_version_fk')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'flse_encounter_fk')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained(SchemaQualifier::table('patients'), indexName: 'flse_patient_fk')->restrictOnDelete();
            $table->foreignId('imported_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'flse_importer_fk')->restrictOnDelete();
            $table->string('result_public_id', 26);
            $table->unsignedInteger('result_version');
            $table->string('result_content_digest', 64);
            $table->string('result_evidence_digest', 64);
            $table->timestamp('verified_at');
            $table->string('order_public_id', 26);
            $table->string('order_snapshot_digest', 64);
            $table->string('specimen_public_id', 26);
            $table->unsignedInteger('specimen_attempt_number');
            $table->string('specimen_label_identifier', 64);
            $table->string('specimen_content_digest', 64);
            $table->string('critical_communication_public_id', 26)->nullable();
            $table->string('critical_communication_content_digest', 64)->nullable();
            $table->string('completion_evidence_digest', 64);
            $table->string('encounter_public_id', 26);
            $table->string('patient_public_id', 26);
            $table->string('care_setting', 16);
            $table->string('laboratory_master_version_public_id', 26);
            $table->unsignedInteger('laboratory_master_version');
            $table->string('laboratory_master_code', 64);
            $table->string('laboratory_master_content_digest', 64);
            $table->string('binding_public_id', 26);
            $table->string('binding_version_public_id', 26);
            $table->unsignedInteger('binding_version');
            $table->string('binding_content_digest', 64);
            $table->string('tariff_item_public_id', 26);
            $table->string('tariff_item_version_public_id', 26);
            $table->string('tariff_item_code', 64);
            $table->string('tariff_content_digest', 64);
            $table->string('component_public_id', 26);
            $table->string('component_code', 64);
            $table->string('component_content_digest', 64);
            $table->date('service_date');
            $table->string('event_type', 16);
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('unit_amount');
            $table->unsignedBigInteger('signed_amount');
            $table->string('description', 255);
            $table->string('content_digest', 64);
            $table->timestamp('imported_at');
            $table->timestamp('created_at');
            $table->index(['encounter_id', 'verified_at', 'id'], 'flse_encounter_time_idx');
        });

        $this->dropFinanceChargeCheck();
        Schema::table(SchemaQualifier::table('finance_charge_events'), function (Blueprint $table): void {
            $table->foreignId('finance_laboratory_source_event_id')->nullable()
                ->unique('fce_laboratory_source_uq')
                ->constrained(SchemaQualifier::table('finance_laboratory_source_events'), indexName: 'fce_laboratory_source_fk')
                ->restrictOnDelete();
        });
        $this->reinstallFinanceAppendOnlyGuard();

        $this->addChecks();
        $this->qualifyPostgresSequences();
        FinanceLaboratoryTariffAppendOnlyGuard::install();
        FinanceLaboratoryTariffMutableHeadGuard::install();
    }

    public function down(): void
    {
        FinanceTariffSchemaMutationScope::run(
            fn () => FinanceSchemaMutationScope::run(fn () => $this->migrateDown()),
        );
    }

    private function migrateDown(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)
            ->where('action', 'finance.tariff.mutate')
            ->where('resource_type', 'finance_tariff_record')
            ->whereJsonContains('metadata->entity_type', 'LABORATORY_TARIFF_BINDING')
            ->exists()) {
            throw new RuntimeException('Refusing to roll back laboratory tariff bindings while correlated audit evidence remains.');
        }
        if (DB::table(SchemaQualifier::table('finance_charge_events'))->whereNotNull('finance_laboratory_source_event_id')->exists()) {
            throw new RuntimeException('Refusing to roll back laboratory tariff bindings while typed finance charges remain.');
        }
        foreach (self::TABLES as $table) {
            if (Schema::hasTable(SchemaQualifier::table($table)) && DB::table(SchemaQualifier::table($table))->exists()) {
                throw new RuntimeException('Refusing to roll back laboratory tariff bindings while retained rows remain.');
            }
        }

        FinanceLaboratoryTariffMutableHeadGuard::remove();
        FinanceLaboratoryTariffAppendOnlyGuard::remove();
        $this->dropFinanceChargeCheck();
        Schema::table(SchemaQualifier::table('finance_charge_events'), function (Blueprint $table): void {
            $table->dropForeign('fce_laboratory_source_fk');
            $table->dropUnique('fce_laboratory_source_uq');
            $table->dropColumn('finance_laboratory_source_event_id');
        });
        $this->reinstallFinanceAppendOnlyGuard();
        $this->addRadiologyFinanceChargeCheck();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists(SchemaQualifier::table($table));
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS finance_laboratory_tariff_append_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS finance_laboratory_tariff_truncate_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS finance_laboratory_tariff_head_guard()');
        }
    }

    private function addChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_laboratory_tariff_bindings')." ADD CONSTRAINT fltb_contract_ck CHECK (state IN ('ACTIVE','RETIRED') AND version >= 1 AND laboratory_master_version >= 1 AND care_setting IN ('OUTPATIENT','EMERGENCY','INPATIENT'))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_laboratory_tariff_binding_versions')." ADD CONSTRAINT fltbv_contract_ck CHECK (state IN ('ACTIVE','RETIRED') AND version >= 1)");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_laboratory_tariff_operation_receipts')." ADD CONSTRAINT fltor_result_ck CHECK (result_type='BINDING' AND result_version >= 1 AND result_state IN ('ACTIVE','RETIRED'))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_laboratory_source_events')." ADD CONSTRAINT flse_value_ck CHECK (event_type='CHARGE' AND care_setting IN ('OUTPATIENT','EMERGENCY','INPATIENT') AND laboratory_master_version >= 1 AND result_version >= 1 AND specimen_attempt_number >= 1 AND binding_version >= 1 AND quantity=1 AND unit_amount>0 AND signed_amount=unit_amount AND ((laboratory_critical_communication_id IS NULL AND critical_communication_public_id IS NULL AND critical_communication_content_digest IS NULL) OR (laboratory_critical_communication_id IS NOT NULL AND critical_communication_public_id IS NOT NULL AND critical_communication_content_digest IS NOT NULL)))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_charge_events')." ADD CONSTRAINT fce_value_ck CHECK ((((source_domain='PHARMACY' AND source_table='pharmacy_financial_source_events' AND pharmacy_financial_source_event_id IS NOT NULL AND finance_radiology_source_event_id IS NULL AND finance_laboratory_source_event_id IS NULL) AND event_type IN ('CHARGE','REVERSAL')) OR ((source_domain='RADIOLOGY' AND source_table='finance_radiology_source_events' AND pharmacy_financial_source_event_id IS NULL AND finance_radiology_source_event_id IS NOT NULL AND finance_laboratory_source_event_id IS NULL) AND event_type='CHARGE') OR ((source_domain='LABORATORY' AND source_table='finance_laboratory_source_events' AND pharmacy_financial_source_event_id IS NULL AND finance_radiology_source_event_id IS NULL AND finance_laboratory_source_event_id IS NOT NULL) AND event_type='CHARGE')) AND care_setting IN ('OUTPATIENT','EMERGENCY','INPATIENT') AND quantity>0 AND unit_amount>=0 AND ((event_type='CHARGE' AND signed_amount>=0) OR (event_type='REVERSAL' AND signed_amount<=0)) AND ABS(signed_amount)=quantity*unit_amount)");
    }

    private function dropFinanceChargeCheck(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_charge_events').' DROP CONSTRAINT IF EXISTS fce_value_ck');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_charge_events').' DROP CHECK fce_value_ck');
        }
    }

    private function addRadiologyFinanceChargeCheck(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_charge_events')." ADD CONSTRAINT fce_value_ck CHECK ((((source_domain='PHARMACY' AND source_table='pharmacy_financial_source_events' AND pharmacy_financial_source_event_id IS NOT NULL AND finance_radiology_source_event_id IS NULL) AND event_type IN ('CHARGE','REVERSAL')) OR ((source_domain='RADIOLOGY' AND source_table='finance_radiology_source_events' AND pharmacy_financial_source_event_id IS NULL AND finance_radiology_source_event_id IS NOT NULL) AND event_type='CHARGE')) AND care_setting IN ('OUTPATIENT','EMERGENCY','INPATIENT') AND quantity>0 AND unit_amount>=0 AND ((event_type='CHARGE' AND signed_amount>=0) OR (event_type='REVERSAL' AND signed_amount<=0)) AND ABS(signed_amount)=quantity*unit_amount)");
    }

    private function reinstallFinanceAppendOnlyGuard(): void
    {
        FinanceAppendOnlyGuard::remove();
        FinanceAppendOnlyGuard::install();
    }

    private function qualifyPostgresSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        foreach (self::TABLES as $table) {
            $qualifiedTable = '"'.str_replace('"', '""', $schema).'"."'.str_replace('"', '""', $table).'"';
            $sequence = '"'.str_replace('"', '""', $schema).'"."'.str_replace('"', '""', $table.'_id_seq').'"';
            DB::statement(sprintf("ALTER TABLE %s ALTER COLUMN id SET DEFAULT nextval('%s'::regclass)", $qualifiedTable, str_replace("'", "''", $sequence)));
        }
    }
};
