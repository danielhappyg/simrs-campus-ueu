<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Finance\FinanceTariffAppendOnlyGuard;
use App\Support\Finance\FinanceTariffMutableHeadGuard;
use App\Support\Finance\FinanceTariffSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Dependency-first order for guarded rollback. */
    private const TABLES = [
        'finance_tariff_operation_receipts', 'finance_tariff_item_versions', 'finance_tariff_items',
        'finance_tariff_catalogue_versions', 'finance_tariff_catalogues',
        'finance_cost_component_versions', 'finance_cost_components',
        'finance_cost_component_group_versions', 'finance_cost_component_groups',
        'finance_tariff_code_reservations',
    ];

    public function up(): void
    {
        FinanceTariffSchemaMutationScope::run(fn () => $this->migrateUp());
    }

    private function migrateUp(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Finance tariff master migration requires synthetic-only SIMULATION mode.');
        }

        Schema::create(SchemaQualifier::table('finance_tariff_code_reservations'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ftcr_actor_fk')->restrictOnDelete();
            $table->string('reservation_type', 32);
            $table->string('normalized_code', 64);
            $table->timestamp('created_at');
            $table->unique(['reservation_type', 'normalized_code'], 'ftcr_type_code_uq');
        });

        Schema::create(SchemaQualifier::table('finance_cost_component_groups'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('group_code', 64)->unique();
            $table->string('display_name', 160);
            $table->string('state', 16);
            $table->unsignedInteger('version');
            $table->string('current_content_digest', 64);
            $table->timestamps();
            $table->index(['state', 'display_name'], 'fccg_state_name_idx');
        });
        Schema::create(SchemaQualifier::table('finance_cost_component_group_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('group_id')->constrained(SchemaQualifier::table('finance_cost_component_groups'), indexName: 'fccgv_group_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fccgv_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('display_name', 160);
            $table->string('state', 16);
            $table->string('reason', 500);
            $table->string('previous_content_digest', 64)->nullable();
            $table->string('content_digest', 64);
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('created_at');
            $table->unique(['group_id', 'version'], 'fccgv_group_version_uq');
        });

        Schema::create(SchemaQualifier::table('finance_cost_components'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('group_id')->constrained(SchemaQualifier::table('finance_cost_component_groups'), indexName: 'fcc_group_fk')->restrictOnDelete();
            $table->string('component_code', 64)->unique();
            $table->string('display_name', 160);
            $table->text('description')->nullable();
            $table->string('terminology_label', 160)->nullable();
            $table->string('state', 16);
            $table->unsignedInteger('version');
            $table->string('current_content_digest', 64);
            $table->timestamps();
            $table->index(['group_id', 'state'], 'fcc_group_state_idx');
        });
        Schema::create(SchemaQualifier::table('finance_cost_component_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('component_id')->constrained(SchemaQualifier::table('finance_cost_components'), indexName: 'fccv_component_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'fccv_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('group_public_id', 26);
            $table->string('group_code', 64);
            $table->string('display_name', 160);
            $table->text('description')->nullable();
            $table->string('terminology_label', 160)->nullable();
            $table->string('state', 16);
            $table->string('reason', 500);
            $table->string('previous_content_digest', 64)->nullable();
            $table->string('content_digest', 64);
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('created_at');
            $table->unique(['component_id', 'version'], 'fccv_component_version_uq');
        });

        Schema::create(SchemaQualifier::table('finance_tariff_catalogues'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('catalogue_code', 64)->unique();
            $table->string('display_name', 160);
            $table->string('state', 16);
            $table->unsignedInteger('version');
            $table->string('current_content_digest', 64);
            $table->timestamps();
            $table->index(['state', 'display_name'], 'ftc_state_name_idx');
        });
        Schema::create(SchemaQualifier::table('finance_tariff_catalogue_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('catalogue_id')->constrained(SchemaQualifier::table('finance_tariff_catalogues'), indexName: 'ftcv_catalogue_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ftcv_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('display_name', 160);
            $table->string('state', 16);
            $table->string('reason', 500);
            $table->string('previous_content_digest', 64)->nullable();
            $table->string('content_digest', 64);
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('created_at');
            $table->unique(['catalogue_id', 'version'], 'ftcv_catalogue_version_uq');
        });

        Schema::create(SchemaQualifier::table('finance_tariff_items'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('catalogue_id')->constrained(SchemaQualifier::table('finance_tariff_catalogues'), indexName: 'fti_catalogue_fk')->restrictOnDelete();
            $table->foreignId('component_id')->constrained(SchemaQualifier::table('finance_cost_components'), indexName: 'fti_component_fk')->restrictOnDelete();
            $table->string('tariff_code', 64)->unique();
            $table->string('state', 16);
            $table->unsignedInteger('version');
            $table->date('latest_effective_from');
            $table->string('current_content_digest', 64);
            $table->timestamps();
            $table->index(['catalogue_id', 'state'], 'fti_catalogue_state_idx');
            $table->index(['component_id', 'state'], 'fti_component_state_idx');
        });
        Schema::create(SchemaQualifier::table('finance_tariff_item_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('tariff_item_id')->constrained(SchemaQualifier::table('finance_tariff_items'), indexName: 'ftiv_item_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ftiv_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('catalogue_public_id', 26);
            $table->string('catalogue_code', 64);
            $table->string('component_public_id', 26);
            $table->string('component_code', 64);
            $table->string('component_content_digest', 64);
            $table->string('display_name', 160);
            $table->string('care_setting', 16);
            $table->string('service_domain', 32);
            $table->string('reference_label', 160)->nullable();
            $table->string('ward_class_label', 120)->nullable();
            $table->unsignedBigInteger('amount_rupiah');
            $table->string('state', 16);
            $table->date('effective_from');
            $table->string('reason', 500);
            $table->string('previous_content_digest', 64)->nullable();
            $table->string('content_digest', 64);
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('created_at');
            $table->unique(['tariff_item_id', 'version'], 'ftiv_item_version_uq');
            $table->unique(['tariff_item_id', 'effective_from'], 'ftiv_item_effective_uq');
            $table->index(['effective_from', 'state'], 'ftiv_effective_state_idx');
        });

        Schema::create(SchemaQualifier::table('finance_tariff_operation_receipts'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ftor_actor_fk')->restrictOnDelete();
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
            $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'ftor_actor_operation_key_uq');
        });

        $this->addChecks();
        $this->qualifyPostgresSequences();
        FinanceTariffAppendOnlyGuard::install();
        FinanceTariffMutableHeadGuard::install();
    }

    public function down(): void
    {
        FinanceTariffSchemaMutationScope::run(fn () => $this->migrateDown());
    }

    private function migrateDown(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)->where('action', 'finance.tariff.mutate')->exists()) {
            throw new RuntimeException('Refusing to roll back finance tariff master while correlated audit evidence remains.');
        }
        foreach (self::TABLES as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to roll back finance tariff master while retained rows remain.');
            }
        }

        FinanceTariffMutableHeadGuard::remove();
        FinanceTariffAppendOnlyGuard::remove();
        foreach (self::TABLES as $table) {
            Schema::dropIfExists(SchemaQualifier::table($table));
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS finance_tariff_append_only_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS finance_tariff_append_only_truncate_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS finance_tariff_mutable_head_guard()');
        }
    }

    private function addChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }

        foreach (['finance_cost_component_groups', 'finance_cost_components', 'finance_tariff_catalogues', 'finance_tariff_items'] as $table) {
            DB::statement('ALTER TABLE '.SchemaQualifier::table($table)." ADD CONSTRAINT {$this->checkName($table)} CHECK (state IN ('ACTIVE','RETIRED') AND version >= 1)");
        }
        foreach (['finance_cost_component_group_versions', 'finance_cost_component_versions', 'finance_tariff_catalogue_versions'] as $table) {
            DB::statement('ALTER TABLE '.SchemaQualifier::table($table)." ADD CONSTRAINT {$this->checkName($table)} CHECK (state IN ('ACTIVE','RETIRED') AND version >= 1)");
        }
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_tariff_item_versions')." ADD CONSTRAINT ftiv_contract_ck CHECK (state IN ('ACTIVE','RETIRED') AND version >= 1 AND amount_rupiah > 0 AND care_setting IN ('OUTPATIENT','EMERGENCY','INPATIENT') AND service_domain IN ('GENERAL_SERVICE','LABORATORY','RADIOLOGY','ACCOMMODATION'))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_tariff_code_reservations')." ADD CONSTRAINT ftcr_type_ck CHECK (reservation_type IN ('GROUP','COMPONENT','CATALOGUE','TARIFF_ITEM'))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('finance_tariff_operation_receipts')." ADD CONSTRAINT ftor_result_ck CHECK (result_type IN ('GROUP','COMPONENT','CATALOGUE','TARIFF_ITEM') AND result_version >= 1)");
    }

    private function checkName(string $table): string
    {
        return match ($table) {
            'finance_cost_component_groups' => 'fccg_contract_ck',
            'finance_cost_components' => 'fcc_contract_ck',
            'finance_tariff_catalogues' => 'ftc_contract_ck',
            'finance_tariff_items' => 'fti_contract_ck',
            'finance_cost_component_group_versions' => 'fccgv_contract_ck',
            'finance_cost_component_versions' => 'fccv_contract_ck',
            default => 'ftcv_contract_ck',
        };
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
