<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Pharmacy\PharmacyAppendOnlyGuard;
use App\Support\Pharmacy\PharmacyMutableHeadGuard;
use App\Support\Pharmacy\PharmacySchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'pharmacy_operation_receipts', 'pharmacy_return_items', 'pharmacy_returns',
        'pharmacy_financial_source_events', 'pharmacy_stock_movements',
        'pharmacy_handover_items', 'pharmacy_handovers', 'pharmacy_preparation_allocations',
        'pharmacy_preparations', 'pharmacy_verifications', 'pharmacy_prescription_items',
        'pharmacy_prescription_versions', 'pharmacy_prescriptions', 'pharmacy_stock_lots',
        'pharmacy_inventory_mutexes', 'pharmacy_depot_versions', 'pharmacy_depots',
        'pharmacy_depot_code_reservations', 'pharmacy_medicine_versions',
        'pharmacy_medicines', 'pharmacy_medicine_code_reservations',
    ];

    public function up(): void
    {
        PharmacySchemaMutationScope::run(fn () => $this->migrateUp());
    }

    private function migrateUp(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Pharmacy teaching migration requires synthetic-only SIMULATION mode.');
        }

        Schema::create(SchemaQualifier::table('pharmacy_medicine_code_reservations'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'pmcr_actor_fk')->restrictOnDelete();
            $t->string('normalized_code', 64)->unique();
            $t->timestamp('created_at');
        });
        Schema::create(SchemaQualifier::table('pharmacy_medicines'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->string('medicine_code', 64)->unique();
            $t->string('generic_name', 160);
            $t->string('brand_name', 160)->nullable();
            $t->string('strength_text', 120);
            $t->string('dosage_form', 64);
            $t->string('base_unit', 32);
            $t->json('route_choices');
            $t->unsignedBigInteger('acquisition_value');
            $t->unsignedBigInteger('teaching_sale_value');
            $t->string('state', 16);
            $t->unsignedInteger('version');
            $t->string('current_content_digest', 64);
            $t->timestamps();
            $t->index(['state', 'generic_name'], 'pm_state_name_idx');
        });
        Schema::create(SchemaQualifier::table('pharmacy_medicine_versions'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('medicine_id')->constrained(SchemaQualifier::table('pharmacy_medicines'), indexName: 'pmv_medicine_fk')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'pmv_actor_fk')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('generic_name', 160);
            $t->string('brand_name', 160)->nullable();
            $t->string('strength_text', 120);
            $t->string('dosage_form', 64);
            $t->string('base_unit', 32);
            $t->json('route_choices');
            $t->unsignedBigInteger('acquisition_value');
            $t->unsignedBigInteger('teaching_sale_value');
            $t->string('state', 16);
            $t->string('content_digest', 64);
            $t->timestamp('created_at');
            $t->unique(['medicine_id', 'version'], 'pmv_medicine_version_uq');
        });
        Schema::create(SchemaQualifier::table('pharmacy_depot_code_reservations'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'pdcr_actor_fk')->restrictOnDelete();
            $t->string('normalized_code', 64)->unique();
            $t->timestamp('created_at');
        });
        Schema::create(SchemaQualifier::table('pharmacy_depots'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->string('depot_code', 64)->unique();
            $t->string('display_name', 160);
            $t->json('eligible_care_settings');
            $t->string('state', 16);
            $t->unsignedInteger('version');
            $t->string('current_content_digest', 64);
            $t->timestamps();
        });
        Schema::create(SchemaQualifier::table('pharmacy_depot_versions'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('depot_id')->constrained(SchemaQualifier::table('pharmacy_depots'), indexName: 'pdv_depot_fk')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'pdv_actor_fk')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('display_name', 160);
            $t->json('eligible_care_settings');
            $t->string('state', 16);
            $t->string('content_digest', 64);
            $t->timestamp('created_at');
            $t->unique(['depot_id', 'version'], 'pdv_depot_version_uq');
        });
        Schema::create(SchemaQualifier::table('pharmacy_inventory_mutexes'), function (Blueprint $t): void {
            $t->id();
            $t->foreignId('depot_id')->constrained(SchemaQualifier::table('pharmacy_depots'), indexName: 'pim_depot_fk')->restrictOnDelete();
            $t->foreignId('medicine_id')->constrained(SchemaQualifier::table('pharmacy_medicines'), indexName: 'pim_medicine_fk')->restrictOnDelete();
            $t->unsignedBigInteger('lock_version')->default(0);
            $t->timestamps();
            $t->unique(['depot_id', 'medicine_id'], 'pim_depot_medicine_uq');
        });
        Schema::create(SchemaQualifier::table('pharmacy_stock_lots'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('medicine_id')->constrained(SchemaQualifier::table('pharmacy_medicines'), indexName: 'psl_medicine_fk')->restrictOnDelete();
            $t->foreignId('depot_id')->constrained(SchemaQualifier::table('pharmacy_depots'), indexName: 'psl_depot_fk')->restrictOnDelete();
            $t->foreignId('opened_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'psl_actor_fk')->restrictOnDelete();
            $t->unsignedInteger('medicine_version');
            $t->unsignedInteger('depot_version');
            $t->string('medicine_code_snapshot', 64);
            $t->string('depot_code_snapshot', 64);
            $t->string('lot_code', 80);
            $t->timestamp('received_at');
            $t->date('expiry_date')->nullable();
            $t->string('no_expiry_reason', 64)->nullable();
            $t->unsignedBigInteger('available_quantity')->default(0);
            $t->unsignedBigInteger('quarantined_quantity')->default(0);
            $t->unsignedBigInteger('acquisition_value');
            $t->string('source_reference', 120);
            $t->string('state', 16)->default('ACTIVE');
            $t->unsignedInteger('version')->default(1);
            $t->string('content_digest', 64);
            $t->timestamps();
            $t->unique(['depot_id', 'medicine_id', 'lot_code'], 'psl_depot_medicine_lot_uq');
            $t->index(['depot_id', 'medicine_id', 'expiry_date', 'received_at'], 'psl_fefo_idx');
        });
        Schema::create(SchemaQualifier::table('pharmacy_prescriptions'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'pp_encounter_fk')->restrictOnDelete();
            $t->foreignId('patient_id')->constrained(SchemaQualifier::table('patients'), indexName: 'pp_patient_fk')->restrictOnDelete();
            $t->foreignId('ordering_physician_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'pp_physician_fk')->restrictOnDelete();
            $t->foreignId('depot_id')->constrained(SchemaQualifier::table('pharmacy_depots'), indexName: 'pp_depot_fk')->restrictOnDelete();
            $t->foreignId('replaces_prescription_id')->nullable()->constrained(SchemaQualifier::table('pharmacy_prescriptions'), indexName: 'pp_replaces_fk')->restrictOnDelete();
            $t->string('care_setting', 16);
            $t->string('encounter_number_snapshot', 26);
            $t->string('location_snapshot', 160);
            $t->string('location_fingerprint', 64)->nullable();
            $t->unsignedInteger('depot_version');
            $t->string('depot_code_snapshot', 64);
            $t->text('clinical_note')->nullable();
            $t->string('status', 32);
            $t->unsignedInteger('version');
            $t->string('current_content_digest', 64);
            $t->timestamp('ordered_at')->nullable();
            $t->timestamps();
            $t->index(['encounter_id', 'status'], 'pp_encounter_status_idx');
        });
        Schema::create(SchemaQualifier::table('pharmacy_prescription_versions'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('prescription_id')->constrained(SchemaQualifier::table('pharmacy_prescriptions'), indexName: 'ppv_prescription_fk')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ppv_actor_fk')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('state', 32);
            $t->json('content_snapshot');
            $t->string('reason_code', 64)->nullable();
            $t->text('reason_note')->nullable();
            $t->string('content_digest', 64);
            $t->timestamp('created_at');
            $t->unique(['prescription_id', 'version'], 'ppv_prescription_version_uq');
        });
        Schema::create(SchemaQualifier::table('pharmacy_prescription_items'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('prescription_id')->constrained(SchemaQualifier::table('pharmacy_prescriptions'), indexName: 'ppi_prescription_fk')->restrictOnDelete();
            $t->foreignId('medicine_id')->constrained(SchemaQualifier::table('pharmacy_medicines'), indexName: 'ppi_medicine_fk')->restrictOnDelete();
            $t->unsignedInteger('line_number');
            $t->unsignedInteger('medicine_version');
            $t->string('medicine_version_public_id', 26);
            $t->string('medicine_content_digest', 64);
            $t->string('medicine_code', 64);
            $t->string('medicine_name', 160);
            $t->string('strength_text', 120);
            $t->string('dosage_form', 64);
            $t->string('base_unit', 32);
            $t->text('dose_text');
            $t->string('route', 64);
            $t->text('frequency_text');
            $t->text('duration_text');
            $t->unsignedBigInteger('requested_quantity');
            $t->unsignedBigInteger('verified_quantity')->nullable();
            $t->unsignedBigInteger('sale_value_snapshot');
            $t->text('instruction');
            $t->string('content_digest', 64);
            $t->timestamp('created_at');
            $t->unique(['prescription_id', 'line_number'], 'ppi_prescription_line_uq');
        });
        Schema::create(SchemaQualifier::table('pharmacy_verifications'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('prescription_id')->unique('pv_prescription_uq')->constrained(SchemaQualifier::table('pharmacy_prescriptions'), indexName: 'pv_prescription_fk')->restrictOnDelete();
            $t->foreignId('pharmacist_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'pv_pharmacist_fk')->restrictOnDelete();
            $t->string('decision', 16);
            $t->string('manual_allergy_review', 32);
            $t->json('checklist');
            $t->json('item_decisions');
            $t->string('reason_code', 64)->nullable();
            $t->text('note')->nullable();
            $t->string('prescription_fingerprint', 64);
            $t->string('content_digest', 64);
            $t->timestamp('verified_at');
            $t->timestamp('created_at');
        });
        Schema::create(SchemaQualifier::table('pharmacy_preparations'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('prescription_id')->constrained(SchemaQualifier::table('pharmacy_prescriptions'), indexName: 'prep_prescription_fk')->restrictOnDelete();
            $t->foreignId('technician_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'prep_technician_fk')->restrictOnDelete();
            $t->foreignId('replaces_preparation_id')->nullable()->constrained(SchemaQualifier::table('pharmacy_preparations'), indexName: 'prep_replaces_fk')->restrictOnDelete();
            $t->unsignedInteger('sequence');
            $t->string('state', 16);
            $t->string('prescription_fingerprint', 64);
            $t->string('stock_fingerprint', 64);
            $t->text('replacement_reason')->nullable();
            $t->string('content_digest', 64);
            $t->timestamp('prepared_at');
            $t->timestamp('created_at');
            $t->unique(['prescription_id', 'sequence'], 'prep_prescription_sequence_uq');
        });
        Schema::create(SchemaQualifier::table('pharmacy_preparation_allocations'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('preparation_id')->constrained(SchemaQualifier::table('pharmacy_preparations'), indexName: 'ppa_preparation_fk')->restrictOnDelete();
            $t->foreignId('prescription_item_id')->constrained(SchemaQualifier::table('pharmacy_prescription_items'), indexName: 'ppa_item_fk')->restrictOnDelete();
            $t->foreignId('stock_lot_id')->constrained(SchemaQualifier::table('pharmacy_stock_lots'), indexName: 'ppa_lot_fk')->restrictOnDelete();
            $t->unsignedBigInteger('quantity');
            $t->unsignedInteger('fefo_sequence');
            $t->string('lot_fingerprint', 64);
            $t->string('content_digest', 64);
            $t->timestamp('created_at');
            $t->unique(['preparation_id', 'prescription_item_id', 'stock_lot_id'], 'ppa_prep_item_lot_uq');
        });
        Schema::create(SchemaQualifier::table('pharmacy_handovers'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('prescription_id')->constrained(SchemaQualifier::table('pharmacy_prescriptions'), indexName: 'ph_prescription_fk')->restrictOnDelete();
            $t->foreignId('preparation_id')->unique('ph_preparation_uq')->constrained(SchemaQualifier::table('pharmacy_preparations'), indexName: 'ph_preparation_fk')->restrictOnDelete();
            $t->foreignId('pharmacist_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ph_pharmacist_fk')->restrictOnDelete();
            $t->unsignedInteger('sequence');
            $t->string('state', 24);
            $t->text('partial_reason')->nullable();
            $t->string('preparation_fingerprint', 64);
            $t->string('content_digest', 64);
            $t->timestamp('handed_over_at');
            $t->timestamp('created_at');
            $t->unique(['prescription_id', 'sequence'], 'ph_prescription_sequence_uq');
        });
        Schema::create(SchemaQualifier::table('pharmacy_handover_items'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('handover_id')->constrained(SchemaQualifier::table('pharmacy_handovers'), indexName: 'phi_handover_fk')->restrictOnDelete();
            $t->foreignId('prescription_item_id')->constrained(SchemaQualifier::table('pharmacy_prescription_items'), indexName: 'phi_item_fk')->restrictOnDelete();
            $t->foreignId('stock_lot_id')->constrained(SchemaQualifier::table('pharmacy_stock_lots'), indexName: 'phi_lot_fk')->restrictOnDelete();
            $t->unsignedBigInteger('quantity');
            $t->unsignedBigInteger('sale_value_snapshot');
            $t->string('content_digest', 64);
            $t->timestamp('created_at');
        });
        Schema::create(SchemaQualifier::table('pharmacy_stock_movements'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('stock_lot_id')->constrained(SchemaQualifier::table('pharmacy_stock_lots'), indexName: 'psm_lot_fk')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'psm_actor_fk')->restrictOnDelete();
            $t->foreignId('handover_item_id')->nullable()->constrained(SchemaQualifier::table('pharmacy_handover_items'), indexName: 'psm_handover_fk')->restrictOnDelete();
            $t->string('movement_type', 32);
            $t->bigInteger('available_delta');
            $t->bigInteger('quarantined_delta');
            $t->unsignedBigInteger('available_balance_after');
            $t->unsignedBigInteger('quarantined_balance_after');
            $t->string('reason_code', 64);
            $t->string('source_type', 32);
            $t->string('source_public_id', 26);
            $t->string('content_digest', 64);
            $t->timestamp('occurred_at');
            $t->timestamp('created_at');
            $t->index(['stock_lot_id', 'id'], 'psm_lot_id_idx');
        });
        Schema::create(SchemaQualifier::table('pharmacy_financial_source_events'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('prescription_id')->constrained(SchemaQualifier::table('pharmacy_prescriptions'), indexName: 'pfse_prescription_fk')->restrictOnDelete();
            $t->foreignId('prescription_item_id')->constrained(SchemaQualifier::table('pharmacy_prescription_items'), indexName: 'pfse_item_fk')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'pfse_actor_fk')->restrictOnDelete();
            $t->string('event_type', 16);
            $t->unsignedBigInteger('quantity');
            $t->bigInteger('amount');
            $t->string('source_type', 32);
            $t->string('source_public_id', 26);
            $t->string('content_digest', 64);
            $t->timestamp('occurred_at');
            $t->timestamp('created_at');
            $t->unique(['event_type', 'source_type', 'source_public_id', 'prescription_item_id'], 'pfse_source_item_uq');
        });
        Schema::create(SchemaQualifier::table('pharmacy_returns'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('handover_id')->constrained(SchemaQualifier::table('pharmacy_handovers'), indexName: 'pr_handover_fk')->restrictOnDelete();
            $t->foreignId('pharmacist_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'pr_pharmacist_fk')->restrictOnDelete();
            $t->string('reason_code', 64);
            $t->text('note')->nullable();
            $t->string('handover_fingerprint', 64);
            $t->string('content_digest', 64);
            $t->timestamp('returned_at');
            $t->timestamp('created_at');
        });
        Schema::create(SchemaQualifier::table('pharmacy_return_items'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('return_id')->constrained(SchemaQualifier::table('pharmacy_returns'), indexName: 'pri_return_fk')->restrictOnDelete();
            $t->foreignId('handover_item_id')->constrained(SchemaQualifier::table('pharmacy_handover_items'), indexName: 'pri_handover_item_fk')->restrictOnDelete();
            $t->string('condition', 32);
            $t->unsignedBigInteger('quantity');
            $t->string('content_digest', 64);
            $t->timestamp('created_at');
            $t->unique(['return_id', 'handover_item_id'], 'pri_return_handover_item_uq');
        });
        Schema::create(SchemaQualifier::table('pharmacy_operation_receipts'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'por_actor_fk')->restrictOnDelete();
            $t->string('operation', 64);
            $t->string('idempotency_key', 255);
            $t->string('payload_digest', 64);
            $t->string('result_type', 32);
            $t->string('result_public_id', 26);
            $t->unsignedInteger('result_version');
            $t->string('result_state', 32);
            $t->string('result_digest', 64);
            $t->string('request_correlation_id', 26)->nullable();
            $t->timestamp('completed_at');
            $t->timestamps();
            $t->unique(['actor_user_id', 'operation', 'idempotency_key'], 'por_actor_operation_key_uq');
        });

        $this->addChecks();
        PharmacyAppendOnlyGuard::install();
        PharmacyMutableHeadGuard::install();
    }

    public function down(): void
    {
        PharmacySchemaMutationScope::run(fn () => $this->migrateDown());
    }

    private function migrateDown(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)->where('action', 'pharmacy.workflow.mutate')->exists()) {
            throw new RuntimeException('Refusing to discard pharmacy tables while correlated audit evidence exists.');
        }
        foreach (self::TABLES as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to discard populated pharmacy evidence.');
            }
        }
        PharmacyMutableHeadGuard::remove();
        PharmacyAppendOnlyGuard::remove();
        foreach (self::TABLES as $table) {
            Schema::dropIfExists(SchemaQualifier::table($table));
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS pharmacy_append_only_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS pharmacy_append_only_truncate_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS pharmacy_mutable_head_guard()');
        }
    }

    private function addChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }
        $conditionColumn = DB::connection()->getQueryGrammar()->wrap('condition');
        DB::statement('ALTER TABLE '.SchemaQualifier::table('pharmacy_medicines')." ADD CONSTRAINT pm_state_ck CHECK (state IN ('ACTIVE','RETIRED') AND version>=1 AND acquisition_value>=0 AND teaching_sale_value>=0)");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('pharmacy_depots')." ADD CONSTRAINT pd_state_ck CHECK (state IN ('ACTIVE','RETIRED') AND version>=1)");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('pharmacy_stock_lots')." ADD CONSTRAINT psl_state_ck CHECK (state IN ('ACTIVE','QUARANTINED','RETIRED') AND available_quantity>=0 AND quarantined_quantity>=0 AND ((expiry_date IS NULL AND no_expiry_reason='NO_EXPIRY_ASSIGNED') OR expiry_date IS NOT NULL))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('pharmacy_prescriptions')." ADD CONSTRAINT pp_state_ck CHECK (status IN ('DRAFT','ORDERED','VERIFIED','PREPARED','PARTIALLY_HANDED_OVER','HANDED_OVER','UNFILLED_CLOSED','CANCELLED','REFUSED'))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('pharmacy_prescription_items').' ADD CONSTRAINT ppi_quantity_ck CHECK (requested_quantity>0 AND (verified_quantity IS NULL OR (verified_quantity>=0 AND verified_quantity<=requested_quantity)))');
        DB::statement('ALTER TABLE '.SchemaQualifier::table('pharmacy_verifications')." ADD CONSTRAINT pv_decision_ck CHECK (decision IN ('VERIFIED','REFUSED'))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('pharmacy_preparations')." ADD CONSTRAINT prep_state_ck CHECK (state IN ('ACTIVE','SUPERSEDED') AND sequence>0 AND ((state='SUPERSEDED' AND replacement_reason IS NOT NULL) OR state='ACTIVE'))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('pharmacy_preparation_allocations').' ADD CONSTRAINT ppa_quantity_ck CHECK (quantity>0 AND fefo_sequence>0)');
        DB::statement('ALTER TABLE '.SchemaQualifier::table('pharmacy_handovers')." ADD CONSTRAINT ph_state_ck CHECK (state IN ('FULL','PARTIAL') AND sequence>0 AND ((state='PARTIAL' AND partial_reason IS NOT NULL) OR state='FULL'))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('pharmacy_handover_items').' ADD CONSTRAINT phi_quantity_ck CHECK (quantity>0)');
        DB::statement('ALTER TABLE '.SchemaQualifier::table('pharmacy_stock_movements')." ADD CONSTRAINT psm_state_ck CHECK (movement_type IN ('OPENING','CORRECTION','QUARANTINE','HANDOVER','RETURN') AND ((available_delta<>0 OR quarantined_delta<>0) OR (movement_type='QUARANTINE' AND source_type='LOT' AND handover_item_id IS NULL) OR (movement_type='RETURN' AND source_type='RETURN_ITEM' AND reason_code='DESTROYED_OR_NOT_RETURNABLE' AND handover_item_id IS NOT NULL)) AND available_balance_after>=0 AND quarantined_balance_after>=0)");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('pharmacy_financial_source_events')." ADD CONSTRAINT pfse_state_ck CHECK (event_type IN ('CHARGE','REVERSAL') AND quantity>0 AND ((event_type='CHARGE' AND amount>=0 AND source_type='HANDOVER_ITEM') OR (event_type='REVERSAL' AND amount<=0 AND source_type='RETURN_ITEM')))");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('pharmacy_return_items')." ADD CONSTRAINT pri_condition_ck CHECK ({$conditionColumn} IN ('RETURN_TO_STOCK','QUARANTINE','DESTROYED_OR_NOT_RETURNABLE') AND quantity>0)");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('pharmacy_operation_receipts')." ADD CONSTRAINT por_result_ck CHECK (result_type IN ('MEDICINE','DEPOT','LOT','PRESCRIPTION','VERIFICATION','PREPARATION','HANDOVER','RETURN') AND result_version>0)");
    }
};
