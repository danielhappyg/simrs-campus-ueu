<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceRadiologyTariffAppendOnlyGuard;
use App\Support\Finance\FinanceRadiologyTariffMutableHeadGuard;
use App\Support\Finance\FinanceSchemaMutationScope;
use App\Support\Finance\FinanceTariffSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'finance_radiology_source_events',
        'finance_radiology_tariff_operation_receipts',
        'finance_radiology_tariff_binding_versions',
        'finance_radiology_tariff_bindings',
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
            throw new RuntimeException('Radiology tariff source migration requires synthetic-only SIMULATION mode.');
        }

        Schema::create(SchemaQualifier::table('finance_radiology_tariff_bindings'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('radiology_master_id')->constrained(SchemaQualifier::table('radiology_examination_masters'), indexName: 'frtb_master_fk')->restrictOnDelete();
            $table->foreignId('radiology_master_version_id')->constrained(SchemaQualifier::table('radiology_examination_master_versions'), indexName: 'frtb_master_version_fk')->restrictOnDelete();
            $table->string('radiology_master_version_public_id', 26);
            $table->unsignedInteger('radiology_master_version');
            $table->string('radiology_master_content_digest', 64);
            $table->string('radiology_master_code', 64);
            $table->string('care_setting', 16);
            $table->string('state', 16);
            $table->unsignedInteger('version');
            $table->date('latest_effective_from');
            $table->string('current_content_digest', 64);
            $table->timestamps();
            $table->unique(['radiology_master_version_id', 'care_setting'], 'frtb_master_version_care_uq');
            $table->index(['state', 'care_setting'], 'frtb_state_care_idx');
        });

        Schema::create(SchemaQualifier::table('finance_radiology_tariff_binding_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('binding_id')->constrained(SchemaQualifier::table('finance_radiology_tariff_bindings'), indexName: 'frtbv_binding_fk')->restrictOnDelete();
            $table->foreignId('tariff_item_id')->constrained(SchemaQualifier::table('finance_tariff_items'), indexName: 'frtbv_tariff_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'frtbv_actor_fk')->restrictOnDelete();
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
            $table->unique(['binding_id', 'version'], 'frtbv_binding_version_uq');
            $table->unique(['binding_id', 'effective_from'], 'frtbv_binding_effective_uq');
            $table->index(['effective_from', 'state'], 'frtbv_effective_state_idx');
        });

        Schema::create(SchemaQualifier::table('finance_radiology_tariff_operation_receipts'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'frtor_actor_fk')->restrictOnDelete();
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
            $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'frtor_actor_operation_key_uq');
        });

        Schema::create(SchemaQualifier::table('finance_radiology_source_events'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('radiology_performance_id')->unique('frse_performance_uq')->constrained(SchemaQualifier::table('radiology_performances'), indexName: 'frse_performance_fk')->restrictOnDelete();
            $table->foreignId('radiology_order_id')->constrained(SchemaQualifier::table('radiology_orders'), indexName: 'frse_order_fk')->restrictOnDelete();
            $table->foreignId('binding_version_id')->constrained(SchemaQualifier::table('finance_radiology_tariff_binding_versions'), indexName: 'frse_binding_version_fk')->restrictOnDelete();
            $table->foreignId('tariff_item_version_id')->constrained(SchemaQualifier::table('finance_tariff_item_versions'), indexName: 'frse_tariff_version_fk')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'frse_encounter_fk')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained(SchemaQualifier::table('patients'), indexName: 'frse_patient_fk')->restrictOnDelete();
            $table->foreignId('imported_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'frse_importer_fk')->restrictOnDelete();
            $table->string('performance_public_id', 26);
            $table->timestamp('performed_at');
            $table->string('order_public_id', 26);
            $table->string('encounter_public_id', 26);
            $table->string('patient_public_id', 26);
            $table->string('care_setting', 16);
            $table->string('radiology_master_version_public_id', 26);
            $table->unsignedInteger('radiology_master_version');
            $table->string('radiology_master_code', 64);
            $table->string('radiology_master_content_digest', 64);
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
            $table->index(['encounter_id', 'performed_at', 'id'], 'frse_encounter_time_idx');
        });

        $this->dropFinanceChargeCheck();
        Schema::table(SchemaQualifier::table('finance_charge_events'), function (Blueprint $table): void {
            $table->unsignedBigInteger('pharmacy_financial_source_event_id')->nullable()->change();
            $table->foreignId('finance_radiology_source_event_id')->nullable()
                ->unique('fce_radiology_source_uq')
                ->constrained(SchemaQualifier::table('finance_radiology_source_events'), indexName: 'fce_radiology_source_fk')
                ->restrictOnDelete();
        });
        $this->reinstallFinanceAppendOnlyGuard();

        $this->addChecks();
        $this->qualifyPostgresSequences();
        FinanceRadiologyTariffAppendOnlyGuard::install();
        FinanceRadiologyTariffMutableHeadGuard::install();
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
            ->whereJsonContains('metadata->entity_type', 'RADIOLOGY_TARIFF_BINDING')
            ->exists()) {
            throw new RuntimeException('Refusing to roll back radiology tariff bindings while correlated audit evidence remains.');
        }
        if (DB::table(SchemaQualifier::table('finance_charge_events'))->whereNotNull('finance_radiology_source_event_id')->exists()) {
            throw new RuntimeException('Refusing to roll back radiology tariff bindings while typed finance charges remain.');
        }
        foreach (self::TABLES as $table) {
            if (Schema::hasTable(SchemaQualifier::table($table)) && DB::table(SchemaQualifier::table($table))->exists()) {
                throw new RuntimeException('Refusing to roll back radiology tariff bindings while retained rows remain.');
            }
        }

        FinanceRadiologyTariffMutableHeadGuard::remove();
        FinanceRadiologyTariffAppendOnlyGuard::remove();
        $this->dropFinanceChargeCheck();
        Schema::table(SchemaQualifier::table('finance_charge_events'), function (Blueprint $table): void {
            $table->dropForeign('fce_radiology_source_fk');
            $table->dropUnique('fce_radiology_source_uq');
            $table->dropColumn('finance_radiology_source_event_id');
            $table->unsignedBigInteger('pharmacy_financial_source_event_id')->nullable(false)->change();
        });
        $this->reinstallFinanceAppendOnlyGuard();
        $this->addOriginalFinanceChargeCheck();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists(SchemaQualifier::table($table));
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS finance_radiology_tariff_append_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS finance_radiology_tariff_truncate_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS finance_radiology_tariff_head_guard()');
        }
    }

    private function addChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_radiology_tariff_bindings')." ADD CONSTRAINT frtb_contract_ck CHECK (state IN ('ACTIVE','RETIRED') AND version >= 1 AND radiology_master_version >= 1 AND care_setting IN ('OUTPATIENT','EMERGENCY','INPATIENT'))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_radiology_tariff_binding_versions')." ADD CONSTRAINT frtbv_contract_ck CHECK (state IN ('ACTIVE','RETIRED') AND version >= 1)");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_radiology_tariff_operation_receipts')." ADD CONSTRAINT frtor_result_ck CHECK (result_type='BINDING' AND result_version >= 1 AND result_state IN ('ACTIVE','RETIRED'))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_radiology_source_events')." ADD CONSTRAINT frse_value_ck CHECK (event_type='CHARGE' AND care_setting IN ('OUTPATIENT','EMERGENCY','INPATIENT') AND radiology_master_version >= 1 AND binding_version >= 1 AND quantity=1 AND unit_amount>0 AND signed_amount=unit_amount)");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_charge_events')." ADD CONSTRAINT fce_value_ck CHECK ((((source_domain='PHARMACY' AND source_table='pharmacy_financial_source_events' AND pharmacy_financial_source_event_id IS NOT NULL AND finance_radiology_source_event_id IS NULL) AND event_type IN ('CHARGE','REVERSAL')) OR ((source_domain='RADIOLOGY' AND source_table='finance_radiology_source_events' AND pharmacy_financial_source_event_id IS NULL AND finance_radiology_source_event_id IS NOT NULL) AND event_type='CHARGE')) AND care_setting IN ('OUTPATIENT','EMERGENCY','INPATIENT') AND quantity>0 AND unit_amount>=0 AND ((event_type='CHARGE' AND signed_amount>=0) OR (event_type='REVERSAL' AND signed_amount<=0)) AND ABS(signed_amount)=quantity*unit_amount)");
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

    private function addOriginalFinanceChargeCheck(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_charge_events')." ADD CONSTRAINT fce_value_ck CHECK (source_domain='PHARMACY' AND source_table='pharmacy_financial_source_events' AND event_type IN ('CHARGE','REVERSAL') AND care_setting IN ('OUTPATIENT','EMERGENCY','INPATIENT') AND quantity>0 AND unit_amount>=0 AND ((event_type='CHARGE' AND signed_amount>=0) OR (event_type='REVERSAL' AND signed_amount<=0)) AND ABS(signed_amount)=quantity*unit_amount)");
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
