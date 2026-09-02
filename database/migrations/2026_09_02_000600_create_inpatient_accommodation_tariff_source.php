<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Finance\FinanceAccommodationTariffAppendOnlyGuard;
use App\Support\Finance\FinanceAccommodationTariffMutableHeadGuard;
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceSchemaMutationScope;
use App\Support\Finance\FinanceTariffSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'finance_accommodation_source_events',
        'finance_accommodation_tariff_operation_receipts',
        'finance_accommodation_tariff_binding_versions',
        'finance_accommodation_tariff_bindings',
    ];

    public function up(): void
    {
        FinanceTariffSchemaMutationScope::run(fn () => FinanceSchemaMutationScope::run(fn () => $this->migrateUp()));
    }

    private function migrateUp(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Accommodation tariff source migration requires synthetic-only SIMULATION mode.');
        }

        Schema::create(SchemaQualifier::table('finance_accommodation_tariff_bindings'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('inpatient_bed_id')->constrained(SchemaQualifier::table('inpatient_beds'), indexName: 'fatb_bed_fk')->restrictOnDelete();
            $table->foreignId('inpatient_bed_version_id')->constrained(SchemaQualifier::table('inpatient_bed_versions'), indexName: 'fatb_bed_version_fk')->restrictOnDelete();
            $table->string('inpatient_bed_version_public_id', 26);
            $table->unsignedInteger('inpatient_bed_version');
            $table->string('inpatient_bed_content_digest', 64);
            $table->string('ward_public_id', 26);
            $table->string('ward_code', 64);
            $table->string('bed_public_id', 26);
            $table->string('bed_code', 64);
            $table->string('service_class', 120);
            $table->string('care_setting', 16)->default('INPATIENT');
            $table->string('pricing_unit', 32)->default('OCCUPANCY_DAY');
            $table->string('state', 16);
            $table->unsignedInteger('version');
            $table->date('latest_effective_from');
            $table->string('current_content_digest', 64);
            $table->timestamps();
            $table->unique(['inpatient_bed_version_id', 'inpatient_bed_content_digest'], 'fatb_bed_version_digest_uq');
            $table->index(['state', 'service_class'], 'fatb_state_class_idx');
        });

        Schema::create(SchemaQualifier::table('finance_accommodation_tariff_binding_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('binding_id')->constrained(SchemaQualifier::table('finance_accommodation_tariff_bindings'), indexName: 'fatbv_binding_fk')->restrictOnDelete();
            $table->foreignId('tariff_item_id')->constrained(SchemaQualifier::table('finance_tariff_items'), indexName: 'fatbv_tariff_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fatbv_actor_fk')->restrictOnDelete();
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
            $table->unique(['binding_id', 'version'], 'fatbv_binding_version_uq');
            $table->unique(['binding_id', 'effective_from'], 'fatbv_binding_effective_uq');
        });

        Schema::create(SchemaQualifier::table('finance_accommodation_tariff_operation_receipts'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fator_actor_fk')->restrictOnDelete();
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
            $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'fator_actor_operation_key_uq');
        });

        Schema::create(SchemaQualifier::table('finance_accommodation_source_events'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('inpatient_location_event_id')->constrained(SchemaQualifier::table('inpatient_location_events'), indexName: 'fase_opening_fk')->restrictOnDelete();
            $table->foreignId('inpatient_bed_version_id')->constrained(SchemaQualifier::table('inpatient_bed_versions'), indexName: 'fase_bed_version_fk')->restrictOnDelete();
            $table->foreignId('closing_location_event_id')->nullable()->constrained(SchemaQualifier::table('inpatient_location_events'), indexName: 'fase_closing_location_fk')->restrictOnDelete();
            $table->foreignId('inpatient_discharge_id')->nullable()->constrained(SchemaQualifier::table('inpatient_discharges'), indexName: 'fase_discharge_fk')->restrictOnDelete();
            $table->foreignId('binding_version_id')->constrained(SchemaQualifier::table('finance_accommodation_tariff_binding_versions'), indexName: 'fase_binding_version_fk')->restrictOnDelete();
            $table->foreignId('tariff_item_version_id')->constrained(SchemaQualifier::table('finance_tariff_item_versions'), indexName: 'fase_tariff_version_fk')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'fase_encounter_fk')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained(SchemaQualifier::table('patients'), indexName: 'fase_patient_fk')->restrictOnDelete();
            $table->foreignId('imported_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fase_importer_fk')->restrictOnDelete();
            $table->string('opening_location_event_public_id', 26);
            $table->string('opening_location_event_digest', 64);
            $table->string('closing_type', 24);
            $table->string('closing_public_id', 26);
            $table->string('closing_content_digest', 64);
            $table->timestamp('interval_start_at');
            $table->timestamp('interval_end_at');
            $table->timestamp('occupancy_anchor_at');
            $table->string('encounter_public_id', 26);
            $table->string('patient_public_id', 26);
            $table->string('care_setting', 16);
            $table->string('ward_public_id', 26);
            $table->string('ward_code', 64);
            $table->string('bed_public_id', 26);
            $table->string('bed_code', 64);
            $table->string('bed_display_name', 160);
            $table->string('room_label', 120);
            $table->string('service_class', 120);
            $table->string('inpatient_bed_version_public_id', 26);
            $table->unsignedInteger('inpatient_bed_version');
            $table->string('inpatient_bed_content_digest', 64);
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
            $table->string('pricing_unit', 32);
            $table->string('event_type', 16);
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('unit_amount');
            $table->unsignedBigInteger('signed_amount');
            $table->string('description', 255);
            $table->string('content_digest', 64);
            $table->timestamp('imported_at');
            $table->timestamp('created_at');
            $table->unique(['encounter_id', 'service_date'], 'fase_encounter_service_date_uq');
            $table->index(['encounter_id', 'occupancy_anchor_at', 'id'], 'fase_encounter_anchor_idx');
        });

        $this->dropFinanceChargeCheck();
        Schema::table(SchemaQualifier::table('finance_charge_events'), function (Blueprint $table): void {
            $table->foreignId('finance_accommodation_source_event_id')->nullable()->unique('fce_accommodation_source_uq')
                ->constrained(SchemaQualifier::table('finance_accommodation_source_events'), indexName: 'fce_accommodation_source_fk')->restrictOnDelete();
        });
        $this->reinstallFinanceAppendOnlyGuard();
        $this->addChecks(true);
        $this->qualifyPostgresSequences();
        FinanceAccommodationTariffAppendOnlyGuard::install();
        FinanceAccommodationTariffMutableHeadGuard::install();
    }

    public function down(): void
    {
        FinanceTariffSchemaMutationScope::run(fn () => FinanceSchemaMutationScope::run(fn () => $this->migrateDown()));
    }

    private function migrateDown(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)
            ->where('action', 'finance.tariff.mutate')
            ->where('resource_type', 'finance_tariff_record')
            ->whereJsonContains('metadata->entity_type', 'ACCOMMODATION_TARIFF_BINDING')
            ->exists()) {
            throw new RuntimeException('Refusing to roll back accommodation tariff bindings while correlated audit evidence remains.');
        }
        if (DB::table(SchemaQualifier::table('finance_charge_events'))->whereNotNull('finance_accommodation_source_event_id')->exists()) {
            throw new RuntimeException('Refusing to roll back accommodation tariff sources while typed finance charges remain.');
        }
        foreach (self::TABLES as $table) {
            if (Schema::hasTable(SchemaQualifier::table($table)) && DB::table(SchemaQualifier::table($table))->exists()) {
                throw new RuntimeException('Refusing to roll back accommodation tariff sources while retained rows remain.');
            }
        }
        FinanceAccommodationTariffMutableHeadGuard::remove();
        FinanceAccommodationTariffAppendOnlyGuard::remove();
        $this->dropFinanceChargeCheck();
        Schema::table(SchemaQualifier::table('finance_charge_events'), function (Blueprint $table): void {
            $table->dropForeign('fce_accommodation_source_fk');
            $table->dropUnique('fce_accommodation_source_uq');
            $table->dropColumn('finance_accommodation_source_event_id');
        });
        $this->reinstallFinanceAppendOnlyGuard();
        $this->addChecks(false);
        foreach (self::TABLES as $table) {
            Schema::dropIfExists(SchemaQualifier::table($table));
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS finance_accommodation_tariff_append_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS finance_accommodation_tariff_truncate_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS finance_accommodation_tariff_head_guard()');
        }
    }

    private function addChecks(bool $withAccommodation): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }
        if ($withAccommodation) {
            DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_accommodation_tariff_bindings')." ADD CONSTRAINT fatb_contract_ck CHECK (state IN ('ACTIVE','RETIRED') AND version>=1 AND inpatient_bed_version>=1 AND care_setting='INPATIENT' AND pricing_unit='OCCUPANCY_DAY')");
            DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_accommodation_tariff_binding_versions')." ADD CONSTRAINT fatbv_contract_ck CHECK (state IN ('ACTIVE','RETIRED') AND version>=1)");
            DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_accommodation_tariff_operation_receipts')." ADD CONSTRAINT fator_result_ck CHECK (result_type='BINDING' AND result_version>=1 AND result_state IN ('ACTIVE','RETIRED'))");
            DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_accommodation_source_events')." ADD CONSTRAINT fase_value_ck CHECK (care_setting='INPATIENT' AND pricing_unit='OCCUPANCY_DAY' AND event_type='CHARGE' AND inpatient_bed_version>=1 AND binding_version>=1 AND quantity=1 AND unit_amount>0 AND signed_amount=unit_amount AND interval_start_at<interval_end_at AND occupancy_anchor_at>=interval_start_at AND occupancy_anchor_at<interval_end_at AND ((closing_type='BED_TRANSFER' AND closing_location_event_id IS NOT NULL AND inpatient_discharge_id IS NULL) OR (closing_type='ROUTINE_DISCHARGE' AND closing_location_event_id IS NULL AND inpatient_discharge_id IS NOT NULL)))");
        }
        $a = $withAccommodation ? 'finance_accommodation_source_event_id' : null;
        $noneA = $a ? " AND {$a} IS NULL" : '';
        $branchA = $a ? " OR ((source_domain='ACCOMMODATION' AND source_table='finance_accommodation_source_events' AND pharmacy_financial_source_event_id IS NULL AND finance_radiology_source_event_id IS NULL AND finance_laboratory_source_event_id IS NULL AND {$a} IS NOT NULL) AND event_type='CHARGE')" : '';
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_charge_events')." ADD CONSTRAINT fce_value_ck CHECK ((((source_domain='PHARMACY' AND source_table='pharmacy_financial_source_events' AND pharmacy_financial_source_event_id IS NOT NULL AND finance_radiology_source_event_id IS NULL AND finance_laboratory_source_event_id IS NULL{$noneA}) AND event_type IN ('CHARGE','REVERSAL')) OR ((source_domain='RADIOLOGY' AND source_table='finance_radiology_source_events' AND pharmacy_financial_source_event_id IS NULL AND finance_radiology_source_event_id IS NOT NULL AND finance_laboratory_source_event_id IS NULL{$noneA}) AND event_type='CHARGE') OR ((source_domain='LABORATORY' AND source_table='finance_laboratory_source_events' AND pharmacy_financial_source_event_id IS NULL AND finance_radiology_source_event_id IS NULL AND finance_laboratory_source_event_id IS NOT NULL{$noneA}) AND event_type='CHARGE'){$branchA}) AND care_setting IN ('OUTPATIENT','EMERGENCY','INPATIENT') AND quantity>0 AND unit_amount>=0 AND ((event_type='CHARGE' AND signed_amount>=0) OR (event_type='REVERSAL' AND signed_amount<=0)) AND ABS(signed_amount)=quantity*unit_amount)");
    }

    private function dropFinanceChargeCheck(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_charge_events').' DROP CONSTRAINT IF EXISTS fce_value_ck');
        } elseif (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_charge_events').' DROP CHECK fce_value_ck');
        }
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
