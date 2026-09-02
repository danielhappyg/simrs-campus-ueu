#!/usr/bin/env ruby
# frozen_string_literal: true

require 'base64'
require 'digest'
require 'json'
require 'open3'
require 'securerandom'
require 'tempfile'
require 'time'

require_relative 'rehearse-local-laboratory-portability'

# Closed, disposable PostgreSQL 17/MySQL 8.4 rehearsal contract for the
# synthetic cross-setting pharmacy, FEFO stock, handover, and return graph.
class LocalPharmacyStockPortabilityRehearsal < LocalLaboratoryPortabilityRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_PHARMACY_STOCK_PORTABILITY'
  CONFIRMATION_ENV = 'SIMRS_PHARMACY_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-pharmacy-stock-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalPharmacyStockPortabilityHarnessContractTest.rb'
  MIGRATION_PATH = 'database/migrations/2026_09_01_000600_create_cross_setting_pharmacy_tables.php'
  EVIDENCE_TEMPLATE_PATH = 'docs/operations/T1_LOCAL_CROSS_SETTING_PHARMACY_STOCK_EVIDENCE_TEMPLATE_2026-09-01.md'
  EVIDENCE_KIND = 'SIMRS_LOCAL_CROSS_SETTING_PHARMACY_STOCK_PORTABILITY'
  EXECUTION_STATE = 'READY_NOT_RUN'
  RECOVERY_COUNT_KEYS = %w[
    pharmacy_medicines pharmacy_medicine_versions pharmacy_depots pharmacy_depot_versions
    pharmacy_inventory_mutexes pharmacy_stock_lots pharmacy_stock_movements pharmacy_prescriptions
    pharmacy_prescription_versions pharmacy_prescription_items pharmacy_verifications pharmacy_preparations
    pharmacy_preparation_allocations pharmacy_handovers pharmacy_handover_items pharmacy_returns
    pharmacy_return_items pharmacy_financial_source_events pharmacy_operation_receipts
  ].freeze
  RECOVERY_INTEGRITY_KEYS = %w[
    pharmacy_stock_balance_mismatches pharmacy_financial_source_mismatches pharmacy_prescription_state_mismatches
  ].freeze
  RECOVERY_ORPHAN_KEYS = %w[
    pharmacy_medicine_version_without_medicine pharmacy_depot_version_without_depot
    pharmacy_inventory_mutex_without_depot pharmacy_inventory_mutex_without_medicine
    pharmacy_lot_without_depot pharmacy_lot_without_medicine pharmacy_prescription_without_encounter
    pharmacy_prescription_without_patient pharmacy_prescription_without_depot
    pharmacy_prescription_version_without_prescription pharmacy_item_without_prescription
    pharmacy_item_without_medicine pharmacy_verification_without_prescription
    pharmacy_preparation_without_prescription pharmacy_allocation_without_preparation
    pharmacy_allocation_without_item pharmacy_allocation_without_lot pharmacy_handover_without_prescription
    pharmacy_handover_without_preparation pharmacy_handover_item_without_handover
    pharmacy_handover_item_without_item pharmacy_handover_item_without_lot pharmacy_return_without_handover
    pharmacy_return_item_without_return pharmacy_return_item_without_handover_item
    pharmacy_stock_movement_without_lot pharmacy_financial_event_without_prescription
    pharmacy_financial_event_without_item
  ].freeze
  RECOVERY_DIGEST_KEYS = RECOVERY_COUNT_KEYS.map { |key| "#{key}_sha256" }.freeze
  HOLD_MS = 3_500
  WAIT_TIMEOUT_SECONDS = 24

  SCENARIOS = %w[
    fresh-migration
    empty-down-reapply
    exact-role-boundary
    all-three-care-settings
    medicine-depot-version-retire
    opening-lot-expiry-quarantine
    verification-refusal
    deterministic-fefo-revalidation
    full-partial-unfilled-handover
    three-condition-return
    stock-financial-reconciliation
    encounter-lifecycle-blockers
    exact-replay-after-head-advance
    changed-payload-key-conflict
    competing-handovers-no-negative-stock
    return-vs-handover
    preparation-vs-transfer-discharge
    reset-recovery-vs-operation
    exact-lock-order-observability
    application-sql-guard-refusal
    database-update-delete-truncate-refusal
    evidence-chain-corruption-refusal
    least-privilege-runtime
    bounded-reset-recovery-audit-preservation
    retained-evidence-rollback-refusal
    invariant-verification
  ].freeze

  MUTABLE_HEAD_TABLES = %w[
    pharmacy_medicines pharmacy_depots pharmacy_stock_lots pharmacy_inventory_mutexes pharmacy_prescriptions
    pharmacy_preparations
  ].freeze
  IMMUTABLE_EVIDENCE_TABLES = %w[
    pharmacy_medicine_code_reservations pharmacy_medicine_versions pharmacy_depot_code_reservations
    pharmacy_depot_versions pharmacy_prescription_versions pharmacy_prescription_items pharmacy_verifications
    pharmacy_preparation_allocations pharmacy_handovers pharmacy_handover_items
    pharmacy_returns pharmacy_return_items pharmacy_stock_movements pharmacy_financial_source_events
    pharmacy_operation_receipts audit_events
  ].freeze
  RUNTIME_READ_TABLES = %w[
    users roles permissions role_user permission_role patients encounter_cancellations
    inpatient_wards inpatient_location_operation_receipts
    inpatient_discharge_summaries inpatient_discharge_summary_versions
    inpatient_discharge_coding_sources inpatient_discharge_coding_source_versions
  ].freeze
  RUNTIME_LOCK_TABLES = %w[encounters inpatient_location_events inpatient_beds].freeze
  RUNTIME_INSERT_LOCK_TABLES = %w[inpatient_patient_claim_mutexes].freeze
  RUNTIME_TABLE_GRANTS = (
    RUNTIME_READ_TABLES.to_h { |table| [table, 'SELECT'] }
      .merge(RUNTIME_LOCK_TABLES.to_h { |table| [table, 'SELECT, UPDATE'] })
      .merge(RUNTIME_INSERT_LOCK_TABLES.to_h { |table| [table, 'SELECT, INSERT, UPDATE'] })
      .merge(MUTABLE_HEAD_TABLES.to_h { |table| [table, 'SELECT, INSERT, UPDATE'] })
      .merge(IMMUTABLE_EVIDENCE_TABLES.to_h { |table| [table, 'SELECT, INSERT'] })
  ).freeze

  SOURCE_PATHS = %w[
    scripts/rehearse-local-pharmacy-stock-portability.rb
    scripts/rehearse-local-laboratory-portability.rb
    scripts/rehearse-local-inpatient-discharge-coding-source-portability.rb
    scripts/rehearse-local-portability-full-suite.rb
    tests/Documentation/LocalPharmacyStockPortabilityHarnessContractTest.rb
    tests/Documentation/CrossSettingMedicationDispensingStockLedgerV1LocalEngineeringAuthorizationTest.rb
    docs/new-simrs-rebuild/phase-1/CROSS_SETTING_MEDICATION_DISPENSING_STOCK_LEDGER_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-01.md
    docs/operations/T1_LOCAL_CROSS_SETTING_PHARMACY_STOCK_EVIDENCE_TEMPLATE_2026-09-01.md
    database/migrations/2026_09_01_000600_create_cross_setting_pharmacy_tables.php
    tests/Feature/Pharmacy/CrossSettingPharmacyWorkflowTest.php
    tests/Feature/Pharmacy/PharmacyBackendHardeningTest.php
    app/Support/Pharmacy/PharmacyActorPolicy.php
    app/Support/Pharmacy/PharmacyAppendOnlyGuard.php
    app/Support/Pharmacy/PharmacyCanonicalJson.php
    app/Support/Pharmacy/PharmacyEncounterLifecycleGate.php
    app/Support/Pharmacy/PharmacyEvidenceFingerprint.php
    app/Support/Pharmacy/PharmacyLockCoordinator.php
    app/Support/Pharmacy/PharmacyMasterService.php
    app/Support/Pharmacy/PharmacyMutationScope.php
    app/Support/Pharmacy/PharmacyOperationCoordinator.php
    app/Support/Pharmacy/PharmacyProjection.php
    app/Support/Pharmacy/PharmacySchemaMutationScope.php
    app/Support/Pharmacy/PharmacySqlWriteGuard.php
    app/Support/Pharmacy/PharmacyStockService.php
    app/Support/Pharmacy/PharmacyWorkflowService.php
    app/Support/Registration/EncounterCancellationDependencyRegistry.php
    app/Support/Clinical/OutpatientDocumentationService.php
    app/Support/Clinical/OutpatientRmCompletenessService.php
    app/Support/Emergency/EmergencyDispositionService.php
    app/Support/Emergency/EmergencyInpatientHandoffService.php
    app/Support/Emergency/EmergencyInpatientHandoffCompensationService.php
    app/Support/Inpatient/InpatientBedTransferService.php
    app/Support/Inpatient/InpatientDischargeService.php
    app/Support/Inpatient/InpatientRmService.php
    app/Support/Simulation/SyntheticResetService.php
    app/Support/Operations/SyntheticRecoverySnapshot.php
    app/Support/Audit/AuditEventSchemaRegistry.php
    tests/Feature/Registration/EncounterCancellationTest.php
    tests/Feature/Outpatient/StructuredOutpatientDocumentationTest.php
    tests/Feature/Emergency/StructuredEmergencyCoreWorkflowTest.php
    tests/Feature/Emergency/EmergencyInpatientHandoffTest.php
    tests/Feature/Inpatient/InpatientBedTransferTest.php
    tests/Feature/Inpatient/RoutineInpatientDischargeTest.php
    tests/Feature/Inpatient/InpatientRmClosureTest.php
  ].freeze

  FORBIDDEN_ENVIRONMENT = LocalInpatientDischargeCodingSourcePortabilityRehearsal::FORBIDDEN_ENVIRONMENT

  LIFECYCLE_GATE_TESTS = {
    'preclinical_cancellation' => ['tests/Feature/Registration/EncounterCancellationTest.php', 'test_any_pharmacy_prescription_evidence_blocks_preclinical_cancellation'],
    'outpatient_closure' => ['tests/Feature/Outpatient/StructuredOutpatientDocumentationTest.php', 'test_active_pharmacy_prescription_blocks_medical_final_and_rm_handoff'],
    'emergency_disposition' => ['tests/Feature/Emergency/StructuredEmergencyCoreWorkflowTest.php', 'test_active_pharmacy_prescription_blocks_emergency_disposition_completion'],
    'emergency_handoff' => ['tests/Feature/Emergency/EmergencyInpatientHandoffTest.php', 'test_active_source_pharmacy_prescription_blocks_igd_to_inpatient_handoff'],
    'emergency_compensation' => ['tests/Feature/Emergency/EmergencyInpatientHandoffTest.php', 'test_receiving_inpatient_pharmacy_evidence_makes_handoff_compensation_ineligible'],
    'inpatient_transfer' => ['tests/Feature/Inpatient/InpatientBedTransferTest.php', 'test_active_pharmacy_preparation_blocks_bed_transfer_without_moving_the_patient'],
    'inpatient_discharge' => ['tests/Feature/Inpatient/RoutineInpatientDischargeTest.php', 'test_active_pharmacy_prescription_blocks_discharge_and_keeps_bed_claim'],
    'inpatient_rm_closure' => ['tests/Feature/Inpatient/InpatientRmClosureTest.php', 'test_active_pharmacy_prescription_is_a_system_derived_blocker_and_denies_rmik_signoff'],
  }.freeze

  HARDENING_SCENARIO_TESTS = {
    'medicine-depot-version-retire' => ['tests/Feature/Pharmacy/PharmacyBackendHardeningTest.php', 'test_medicine_and_depot_versions_and_retirement_are_terminal'],
    'opening-lot-expiry-quarantine' => ['tests/Feature/Pharmacy/PharmacyBackendHardeningTest.php', 'test_opening_lot_expiry_quarantine_and_terminal_state_are_enforced'],
    'verification-refusal' => ['tests/Feature/Pharmacy/PharmacyBackendHardeningTest.php', 'test_verification_and_refusal_require_exact_roles_and_manual_checks'],
    'deterministic-fefo-revalidation' => ['tests/Feature/Pharmacy/PharmacyBackendHardeningTest.php', 'test_fefo_allocation_and_current_context_revalidation_fail_closed'],
    'full-partial-unfilled-handover' => ['tests/Feature/Pharmacy/PharmacyBackendHardeningTest.php', 'test_full_partial_and_unfilled_close_states_reconcile'],
    'three-condition-return' => ['tests/Feature/Pharmacy/PharmacyBackendHardeningTest.php', 'test_all_three_return_conditions_and_overage_are_enforced'],
    'stock-financial-reconciliation' => ['tests/Feature/Pharmacy/PharmacyBackendHardeningTest.php', 'test_stock_and_financial_reconciliation_is_exact'],
    'encounter-lifecycle-blockers' => ['tests/Feature/Pharmacy/PharmacyBackendHardeningTest.php', 'test_lifecycle_gate_blocks_active_evidence_and_ignores_partial_loaded_relations'],
    'changed-payload-key-conflict' => ['tests/Feature/Pharmacy/PharmacyBackendHardeningTest.php', 'test_changed_payload_idempotency_conflict_and_retained_replay'],
  }.freeze

  PRIMARY_SCENARIO_TESTS = {
    'full-partial-unfilled-handover' => ['tests/Feature/Pharmacy/CrossSettingPharmacyWorkflowTest.php', 'test_outpatient_flow_supports_partial_fefo_handover_remainder_return_and_reconciliation'],
    'stock-financial-reconciliation' => ['tests/Feature/Pharmacy/CrossSettingPharmacyWorkflowTest.php', 'test_outpatient_flow_supports_partial_fefo_handover_remainder_return_and_reconciliation'],
  }.freeze

  # The worker deliberately uses independent PHP processes and real database row
  # locks. The full workflow body is kept in the harness so its SHA is evidence.
  WORKER_SOURCE = <<~'PHP'
    <?php
    declare(strict_types=1);

    use App\Models\Encounter;
    use App\Support\Audit\AuditEvent;
    use App\Models\InpatientBed;
    use App\Models\InpatientWard;
    use App\Models\InpatientPatientClaimMutex;
    use App\Models\InpatientLocationEvent;
    use App\Models\Patient;
    use App\Models\PharmacyFinancialSourceEvent;
    use App\Models\PharmacyHandover;
    use App\Models\PharmacyInventoryMutex;
    use App\Models\PharmacyOperationReceipt;
    use App\Models\PharmacyPreparation;
    use App\Models\PharmacyPrescription;
    use App\Models\PharmacyReturnItem;
    use App\Models\PharmacyStockLot;
    use App\Models\PharmacyStockMovement;
    use App\Models\Role;
    use App\Models\User;
    use App\Support\Authorization\RoleCapabilityMatrix;
    use App\Support\Database\SchemaQualifier;
    use App\Support\Inpatient\InpatientBedTransferDenied;
    use App\Support\Inpatient\InpatientBedTransferService;
    use App\Support\Inpatient\InpatientDischargeDenied;
    use App\Support\Inpatient\InpatientDischargeService;
    use App\Support\Inpatient\InpatientLocationMutationScope;
    use App\Support\Inpatient\InpatientMasterService;
    use App\Support\Operations\SyntheticRecoverySnapshot;
    use App\Support\Pharmacy\PharmacyActorPolicy;
    use App\Support\Pharmacy\PharmacyDenied;
    use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
    use App\Support\Pharmacy\PharmacyEvidenceFingerprint;
    use App\Support\Pharmacy\PharmacyMasterService;
    use App\Support\Pharmacy\PharmacyMutationScope;
    use App\Support\Pharmacy\PharmacySqlWriteGuard;
    use App\Support\Pharmacy\PharmacyStockService;
    use App\Support\Pharmacy\PharmacyWorkflowService;
    use App\Support\Simulation\SyntheticResetService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Database\QueryException;
    use Illuminate\Support\Facades\DB;

    function must(bool $condition, string $label): void
    {
        if (! $condition) throw new RuntimeException('assertion failed: '.$label);
    }

    function protocol(string $state, array $extra = []): void
    {
        echo json_encode(array_merge([
            'schema_version' => 1, 'status' => 'PASS', 'protocol_state' => $state,
            'scenario' => (string) getenv('SIMRS_PHARMACY_SCENARIO'),
            'worker' => (string) getenv('SIMRS_PHARMACY_WORKER'),
        ], $extra), JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
    }

    function fixture(): array
    {
        $raw = base64_decode((string) getenv('SIMRS_PHARMACY_FIXTURE'), true);
        $decoded = $raw === false ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    function user(string $publicId): User { return User::query()->where('public_id', $publicId)->firstOrFail(); }
    function backendConnectionId(): int { return DB::connection()->getDriverName() === 'pgsql' ? (int) data_get(DB::selectOne('SELECT pg_backend_pid() AS id'), 'id') : (int) data_get(DB::selectOne('SELECT CONNECTION_ID() AS id'), 'id'); }
    function fingerprintPrescription(PharmacyPrescription $p): string { return app(PharmacyEvidenceFingerprint::class)->prescription($p->fresh()); }
    function fingerprintPreparation(PharmacyPreparation $p): string { return app(PharmacyEvidenceFingerprint::class)->preparation($p->fresh()); }
    function fingerprintHandover(PharmacyHandover $h): string { return app(PharmacyEvidenceFingerprint::class)->handover($h->fresh()); }

    function prescriptionRows(string $medicine, int $quantity): array
    {
        return [[
            'medicine_public_id' => $medicine, 'dose_text' => '1 tablet', 'route' => 'ORAL',
            'frequency_text' => 'Sekali sehari', 'duration_text' => $quantity.' hari',
            'requested_quantity' => $quantity, 'instruction' => 'Sesudah makan',
        ]];
    }

    function ordered(string $encounter, User $physician, string $depot, string $medicine, int $quantity, string $key): PharmacyPrescription
    {
        $workflow = app(PharmacyWorkflowService::class);
        $draft = $workflow->createDraft($encounter, $physician, $depot, prescriptionRows($medicine, $quantity), 'Synthetic rehearsal', $key.'-draft')->record;
        must($draft instanceof PharmacyPrescription, 'draft created');
        return $workflow->order($draft->public_id, $physician, 1, fingerprintPrescription($draft), $key.'-order')->record;
    }

    function verified(string $encounter, User $physician, User $pharmacist, string $depot, string $medicine, int $quantity, string $key): PharmacyPrescription
    {
        $workflow = app(PharmacyWorkflowService::class);
        $p = ordered($encounter, $physician, $depot, $medicine, $quantity, $key);
        $item = $p->items()->firstOrFail();
        $workflow->verify($p->public_id, $pharmacist, fingerprintPrescription($p), 'REVIEWED_NO_CONFLICT', [
            'identity_confirmed' => true, 'context_confirmed' => true,
            'medicine_readable' => true, 'instruction_readable' => true,
        ], [['item_public_id' => $item->public_id, 'verified_quantity' => $quantity, 'reason_code' => null]], $key.'-verify');
        return $p->fresh();
    }

    function prepared(string $encounter, User $physician, User $pharmacist, User $technician, string $depot, string $medicine, int $quantity, string $key): PharmacyPreparation
    {
        $p = verified($encounter, $physician, $pharmacist, $depot, $medicine, $quantity, $key);
        return app(PharmacyWorkflowService::class)->prepare($p->public_id, $technician, fingerprintPrescription($p), $key.'-prepare')->record;
    }

    function createEncounter(User $registrar, string $setting, int $queue, string $token): Encounter
    {
        $patient = Patient::query()->create([
            'medical_record_number' => 'PHR'.strtoupper($token).str_pad((string) $queue, 2, '0', STR_PAD_LEFT),
            'full_name' => 'Synthetic pharmacy rehearsal '.$queue, 'date_of_birth' => '1990-01-01',
            'sex' => Patient::SEX_PEREMPUAN, 'is_synthetic' => true, 'created_by_user_id' => $registrar->id,
        ]);
        return InpatientLocationMutationScope::run(fn () => Encounter::query()->create([
            'patient_id' => $patient->id, 'care_setting' => $setting, 'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Synthetic '.$setting, 'visit_date' => now()->toDateString(), 'payer_type' => Encounter::PAYER_UMUM,
            'queue_date' => now()->toDateString(), 'queue_number' => $queue, 'registered_at' => now(),
            'registered_by_user_id' => $registrar->id,
        ]));
    }

    function createManagedInpatient(User $registrar, User $admin, int $queue, string $token): array
    {
        $master=app(InpatientMasterService::class);
        $ward=$master->createWard($admin,'PHW-'.strtoupper($token),'Bangsal Pharmacy Rehearsal',InpatientMasterService::REASON_INITIAL_SETUP,'pharmacy-ward-'.$token,null)->master;
        $source=$master->createBed($admin,$ward->public_id,'PHB-'.strtoupper($token).'-A','Bed A','Ruang Pharmacy','Kelas 1',InpatientMasterService::REASON_INITIAL_SETUP,'pharmacy-bed-a-'.$token,null)->master;
        $target=$master->createBed($admin,$ward->public_id,'PHB-'.strtoupper($token).'-B','Bed B','Ruang Pharmacy','Kelas 1',InpatientMasterService::REASON_INITIAL_SETUP,'pharmacy-bed-b-'.$token,null)->master;
        $patient=Patient::query()->create(['medical_record_number'=>'PHRI'.strtoupper($token),'full_name'=>'Synthetic pharmacy inpatient','date_of_birth'=>'1990-01-01','sex'=>Patient::SEX_PEREMPUAN,'is_synthetic'=>true,'created_by_user_id'=>$registrar->id]);
        $encounter=DB::transaction(function() use($patient,$ward,$source,$queue,$registrar): Encounter {
            $encounter=InpatientLocationMutationScope::run(fn()=>Encounter::query()->create([
                'patient_id'=>$patient->id,'care_setting'=>Encounter::CARE_SETTING_INPATIENT,'status'=>Encounter::STATUS_IN_EXAMINATION,
                'clinic_name'=>$ward->display_name,'ward_name'=>$ward->display_name,'ward_class'=>$source->service_class,
                'bed_code'=>$source->code,'inpatient_bed_id'=>$source->id,'active_inpatient_patient_id'=>$patient->id,
                'continue_from'=>Encounter::CONTINUE_LANGSUNG,'visit_date'=>now()->toDateString(),'payer_type'=>Encounter::PAYER_UMUM,
                'queue_date'=>now()->toDateString(),'queue_number'=>$queue,'registered_at'=>now(),'registered_by_user_id'=>$registrar->id,
            ]));
            app(InpatientBedTransferService::class)->recordAdmission($encounter,$ward,$source,$registrar,null);
            return $encounter;
        });
        return ['encounter'=>$encounter,'source'=>$source,'target'=>$target];
    }

    function prepareFixture(string $token): array
    {
        $roleMap = [
            'registrar' => RoleCapabilityMatrix::ROLE_REGISTRAR,
            'admin' => RoleCapabilityMatrix::ROLE_ADMIN,
            'physician' => RoleCapabilityMatrix::ROLE_PHYSICIAN,
            'pharmacist' => RoleCapabilityMatrix::ROLE_PHARMACIST,
            'technician' => RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN,
            'inventory' => RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER,
        ];
        $fixture = [];
        foreach ($roleMap as $name => $role) {
            $actor = User::query()->create(['name' => 'Pharmacy '.$name, 'email' => 'pharmacy.'.$name.'.'.$token.'@example.invalid', 'password' => bin2hex(random_bytes(24)), 'status' => 'ACTIVE']);
            $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);
            must($actor->fresh()->roleSlugs() === [$role], 'exact role '.$role);
            $fixture[$name] = $actor->public_id;
        }
        $inventory = user($fixture['inventory']);
        $master = app(PharmacyMasterService::class);
        $medicine = $master->createMedicine($inventory, [
            'medicine_code' => 'MED-'.$token, 'generic_name' => 'Paracetamol Synthetic', 'brand_name' => null,
            'strength_text' => '500 mg', 'dosage_form' => 'TABLET', 'base_unit' => 'TABLET',
            'route_choices' => ['ORAL'], 'acquisition_value' => 500, 'teaching_sale_value' => 750,
        ], 'pharmacy-master-medicine-'.$token)->record;
        $depot = $master->createDepot($inventory, [
            'depot_code' => 'DEP-'.$token, 'display_name' => 'Depo Synthetic',
            'eligible_care_settings' => Encounter::CARE_SETTINGS,
        ], 'pharmacy-master-depot-'.$token)->record;
        $stock = app(PharmacyStockService::class);
        $early = $stock->openLot($inventory, $medicine->public_id, $depot->public_id, [
            'lot_code' => 'EARLY-'.$token, 'received_at' => now()->subDay(), 'expiry_date' => now()->addMonths(3)->toDateString(),
            'opening_quantity' => 40, 'source_reference' => 'SYNTHETIC-'.$token,
        ], 'pharmacy-lot-early-'.$token)->record;
        $late = $stock->openLot($inventory, $medicine->public_id, $depot->public_id, [
            'lot_code' => 'LATE-'.$token, 'received_at' => now(), 'expiry_date' => now()->addMonths(6)->toDateString(),
            'opening_quantity' => 60, 'source_reference' => 'SYNTHETIC-'.$token,
        ], 'pharmacy-lot-late-'.$token)->record;
        foreach ([Encounter::CARE_SETTING_OUTPATIENT, Encounter::CARE_SETTING_EMERGENCY] as $index => $setting) {
            $fixture[strtolower($setting).'_encounter'] = createEncounter(user($fixture['registrar']), $setting, $index + 1, $token)->public_id;
        }
        $inpatient=createManagedInpatient(user($fixture['registrar']),user($fixture['admin']),3,$token);
        $fixture['inpatient_encounter']=$inpatient['encounter']->public_id;
        $fixture['inpatient_source_bed']=$inpatient['source']->public_id;
        $fixture['inpatient_target_bed']=$inpatient['target']->public_id;
        $fixture += ['medicine' => $medicine->public_id, 'depot' => $depot->public_id, 'early_lot' => $early->public_id, 'late_lot' => $late->public_id];
        return $fixture;
    }

    function runVerificationRefusalScenario(array $f,string $token): array
    {
        $workflow=app(PharmacyWorkflowService::class);$physician=user($f['physician']);$pharmacist=user($f['pharmacist']);
        $refused=ordered($f['outpatient_encounter'],$physician,$f['depot'],$f['medicine'],1,'refusal-'.$token);
        $refusal=$workflow->refuse($refused->public_id,$pharmacist,fingerprintPrescription($refused),'REVIEWED_WITH_NOTE',[
            'identity_confirmed'=>true,'context_confirmed'=>true,'medicine_readable'=>true,'instruction_readable'=>true,
        ],'MANUAL_REVIEW_REFUSAL','Manual refusal rehearsal','refusal-'.$token.'-decision')->record;
        must($refusal->decision==='REFUSED'&&$refused->fresh()->status===PharmacyPrescription::REFUSED,'pharmacist refusal terminal evidence');
        $blocked=ordered($f['outpatient_encounter'],$physician,$f['depot'],$f['medicine'],1,'manual-check-'.$token);
        $reason=null;
        try{$workflow->verify($blocked->public_id,$pharmacist,fingerprintPrescription($blocked),'UNKNOWN_BLOCKED',[
            'identity_confirmed'=>true,'context_confirmed'=>true,'medicine_readable'=>true,'instruction_readable'=>true,
        ],[['item_public_id'=>$blocked->items()->sole()->public_id,'verified_quantity'=>1,'reason_code'=>null]],'manual-check-'.$token.'-verify');}
        catch(PharmacyDenied $denial){$reason=$denial->reason;}
        must($reason==='verification_check_failed','manual verification checklist fails closed');
        return ['pharmacist_refusal_recorded'=>true,'manual_check_denial_reason'=>$reason];
    }

    function runExpiryQuarantineScenario(array $f,string $token): array
    {
        $stock=app(PharmacyStockService::class);$inventory=user($f['inventory']);
        $expired=$stock->openLot($inventory,$f['medicine'],$f['depot'],[
            'lot_code'=>'EXPIRED-'.$token,'received_at'=>now()->subDays(5),'expiry_date'=>now()->subDay()->toDateString(),
            'opening_quantity'=>4,'source_reference'=>'SYNTHETIC-EXPIRED-'.$token,
        ],'expired-lot-'.$token)->record;
        $quarantined=$stock->openLot($inventory,$f['medicine'],$f['depot'],[
            'lot_code'=>'QUARANTINE-'.$token,'received_at'=>now()->subDays(4),'expiry_date'=>now()->addMonth()->toDateString(),
            'opening_quantity'=>4,'source_reference'=>'SYNTHETIC-QUARANTINE-'.$token,
        ],'quarantine-lot-'.$token)->record;
        $stock->changeLotState($quarantined->public_id,$inventory,PharmacyStockLot::QUARANTINED,'QUALITY_HOLD','quarantine-lot-state-'.$token);
        $prep=prepared($f['outpatient_encounter'],user($f['physician']),user($f['pharmacist']),user($f['technician']),$f['depot'],$f['medicine'],1,'expiry-quarantine-'.$token);
        $allocated=$prep->allocations()->pluck('stock_lot_id')->all();
        must(!in_array($expired->id,$allocated,true)&&!in_array($quarantined->id,$allocated,true),'expired and quarantined lots excluded from FEFO');
        app(PharmacyWorkflowService::class)->handover($prep->public_id,user($f['pharmacist']),fingerprintPreparation($prep),null,'expiry-quarantine-'.$token.'-handover');
        must($expired->fresh()->available_quantity===4&&$quarantined->fresh()->quarantined_quantity===4,'excluded lot balances unchanged');
        return ['expired_lot_excluded'=>true,'quarantined_lot_excluded'=>true,'excluded_balances_unchanged'=>true];
    }

    function runFefoRevalidationScenario(array $f,string $token): array
    {
        $workflow=app(PharmacyWorkflowService::class);$stock=app(PharmacyStockService::class);
        $early=PharmacyStockLot::query()->where('public_id',$f['early_lot'])->firstOrFail();
        $late=PharmacyStockLot::query()->where('public_id',$f['late_lot'])->firstOrFail();
        $earlyAvailable=$early->available_quantity;$quantity=$earlyAvailable+2;
        $p=verified($f['outpatient_encounter'],user($f['physician']),user($f['pharmacist']),$f['depot'],$f['medicine'],$quantity,'fefo-revalidation-'.$token);
        $prep=$workflow->prepare($p->public_id,user($f['technician']),fingerprintPrescription($p),'fefo-revalidation-'.$token.'-prepare')->record;
        $allocations=$prep->allocations()->orderBy('fefo_sequence')->get();
        must($allocations->count()===2&&$allocations[0]->stock_lot_id===$early->id&&$allocations[0]->quantity===$earlyAvailable&&$allocations[1]->stock_lot_id===$late->id&&$allocations[1]->quantity===2,'deterministic multi-lot FEFO order');
        $stock->correctLot($early->public_id,user($f['inventory']),-1,0,'CONCURRENT_STOCK_FACT_CHANGE','fefo-revalidation-'.$token.'-stock-change');
        $reason=null;
        try{$workflow->handover($prep->public_id,user($f['pharmacist']),fingerprintPreparation($prep),null,'fefo-revalidation-'.$token.'-stale-handover');}
        catch(PharmacyDenied $denial){$reason=$denial->reason;}
        must($reason==='stale_preparation','final handover rejects stale FEFO evidence');
        $p=$p->fresh();
        $replacement=$workflow->prepare($p->public_id,user($f['technician']),fingerprintPrescription($p),'fefo-revalidation-'.$token.'-replacement',null,'STOCK_FACT_CHANGED')->record;
        $handover=$workflow->handover($replacement->public_id,user($f['pharmacist']),fingerprintPreparation($replacement),null,'fefo-revalidation-'.$token.'-handover')->record;
        must($handover->prescription->fresh()->status===PharmacyPrescription::HANDED_OVER,'replacement FEFO handover committed');
        return ['multi_lot_fefo_order'=>[$early->public_id,$late->public_id],'stale_handover_denial_reason'=>$reason,'replacement_handover_committed'=>true];
    }

    function runSequential(array $f, string $token): array
    {
        $physician=user($f['physician']); $pharmacist=user($f['pharmacist']); $technician=user($f['technician']);
        $policy=app(PharmacyActorPolicy::class); $wrongRole=false;
        try { $policy->inventory($physician); } catch (AuthorizationException) { $wrongRole=true; }
        must($wrongRole, 'exact-role wrong actor denied');
        $workflow=app(PharmacyWorkflowService::class);
        foreach (['outpatient_encounter','emergency_encounter','inpatient_encounter'] as $index => $name) {
            $key='setting-'.$index.'-'.$token;
            $p=ordered($f[$name],$physician,$f['depot'],$f['medicine'],2,$key);
            $item=$p->items()->firstOrFail();
            $workflow->verify($p->public_id,$pharmacist,fingerprintPrescription($p),'REVIEWED_NO_CONFLICT',[
                'identity_confirmed'=>true,'context_confirmed'=>true,'medicine_readable'=>true,'instruction_readable'=>true,
            ],[['item_public_id'=>$item->public_id,'verified_quantity'=>2,'reason_code'=>null]],$key.'-verify');
            $p=$p->fresh();
            $prep=$workflow->prepare($p->public_id,$technician,fingerprintPrescription($p),$key.'-prepare')->record;
            $handover=$workflow->handover($prep->public_id,$pharmacist,fingerprintPreparation($prep),null,$key.'-handover')->record;
            must($handover->prescription->fresh()->status===PharmacyPrescription::HANDED_OVER,'cross-setting journey handed over');
            $replay=$workflow->createDraft($f[$name],$physician,$f['depot'],prescriptionRows($f['medicine'],2),'Synthetic rehearsal',$key.'-draft');
            must($replay->replayed&&$replay->record->status===PharmacyPrescription::DRAFT&&$replay->record->version===1,'exact replay after later head advance');
        }
        $verificationRefusal=runVerificationRefusalScenario($f,$token);
        $expiryQuarantine=runExpiryQuarantineScenario($f,$token);
        $fefoRevalidation=runFefoRevalidationScenario($f,$token);
        $returnPrep=prepared($f['emergency_encounter'],$physician,$pharmacist,$technician,$f['depot'],$f['medicine'],3,'return-base-'.$token);
        $handover=$workflow->handover($returnPrep->public_id,$pharmacist,fingerprintPreparation($returnPrep),null,'return-base-'.$token.'-handover')->record;
        $returnCompetingPrescription=verified($f['emergency_encounter'],$physician,$pharmacist,$f['depot'],$f['medicine'],2,'return-compete-'.$token);
        $lifecyclePrescription=verified($f['inpatient_encounter'],$physician,$pharmacist,$f['depot'],$f['medicine'],2,'lifecycle-race-'.$token);
        $lockOrderPrescription=verified($f['inpatient_encounter'],$physician,$pharmacist,$f['depot'],$f['medicine'],2,'lock-order-probe-'.$token);
        $corruptPrep=prepared($f['outpatient_encounter'],$physician,$pharmacist,$technician,$f['depot'],$f['medicine'],2,'corrupt-chain-'.$token);
        $resetRacePrescription=verified($f['outpatient_encounter'],$physician,$pharmacist,$f['depot'],$f['medicine'],2,'reset-race-'.$token);
        $racePrep=prepared($f['outpatient_encounter'],$physician,$pharmacist,$technician,$f['depot'],$f['medicine'],4,'handover-race-'.$token);
        return [
            'exact_role_boundary'=>true,'all_three_care_settings'=>true,'cross_setting_handover_journeys'=>3,
            'exact_replay_after_head_advance'=>true,'replay_after_head_advance_count'=>3,
            'verification_refusal'=>$verificationRefusal,'expiry_quarantine'=>$expiryQuarantine,'fefo_revalidation'=>$fefoRevalidation,
            'race_preparation'=>$racePrep->public_id,'return_handover'=>$handover->public_id,
            'return_competing_prescription'=>$returnCompetingPrescription->public_id,
            'lifecycle_prescription'=>$lifecyclePrescription->public_id,'corrupt_preparation'=>$corruptPrep->public_id,
            'reset_race_prescription'=>$resetRacePrescription->public_id,'lock_order_prescription'=>$lockOrderPrescription->public_id,
        ];
    }

    function prepareRaceBaseline(array $f,string $prescriptionKey,string $token): array
    {
        $prescription=PharmacyPrescription::query()->where('public_id',$f[$prescriptionKey])->firstOrFail();
        $preparation=app(PharmacyWorkflowService::class)->prepare(
            $prescription->public_id,user($f['technician']),fingerprintPrescription($prescription),
            'race-baseline-'.$prescriptionKey.'-'.$token
        )->record;
        return ['preparation'=>$preparation->public_id];
    }

    function lockInventoryForPreparation(PharmacyPreparation $preparation): void
    {
        $p=$preparation->prescription()->firstOrFail();
        PharmacyMutationScope::run(fn()=>PharmacyInventoryMutex::query()->where('depot_id',$p->depot_id)->lockForUpdate()->get());
    }

    function raceHandover(array $f, string $token): array
    {
        $prep=PharmacyPreparation::query()->where('public_id',$f['race_preparation'])->firstOrFail();
        protocol('STARTED',['backend_connection_id'=>backendConnectionId()]);
        try {
            DB::transaction(function () use ($prep,$f,$token): void {
                lockInventoryForPreparation($prep);
                if ((string)getenv('SIMRS_PHARMACY_WORKER')==='A') { protocol('HOLDING',['lock_order_trace'=>['pharmacy_inventory_mutex']]); usleep(((int)getenv('SIMRS_PHARMACY_HOLD_MS'))*1000); }
                app(PharmacyWorkflowService::class)->handover($prep->public_id,user($f['pharmacist']),fingerprintPreparation($prep),null,'competing-handover-'.getenv('SIMRS_PHARMACY_WORKER').'-'.$token);
            },3);
            return ['outcome'=>'APPLIED'];
        } catch (PharmacyDenied $denial) { return ['outcome'=>'DENIED','reason'=>$denial->reason]; }
    }

    function raceReturnHandover(array $f, string $token): array
    {
        protocol('STARTED',['backend_connection_id'=>backendConnectionId()]);
        $worker=(string)getenv('SIMRS_PHARMACY_WORKER');
        if ($worker==='A') {
            $handover=PharmacyHandover::query()->where('public_id',$f['return_handover'])->with('items')->firstOrFail();
            DB::transaction(function () use($handover,$f,$token): void {
                lockInventoryForPreparation($handover->preparation()->firstOrFail()); protocol('HOLDING',['lock_order_trace'=>['pharmacy_inventory_mutex']]); usleep(((int)getenv('SIMRS_PHARMACY_HOLD_MS'))*1000);
                $item=$handover->items->first();
                $prescription=$handover->prescription()->firstOrFail();
                app(PharmacyWorkflowService::class)->recordReturn($handover->public_id,$prescription->public_id,user($f['pharmacist']),fingerprintHandover($handover),'PATIENT_RETURN',null,[['handover_item_public_id'=>$item->public_id,'condition'=>PharmacyReturnItem::RETURN_TO_STOCK,'quantity'=>1]],'return-race-'.$token);
            },3);
        } else {
            $prep=PharmacyPreparation::query()->where('public_id',$f['return_competing_preparation'])->firstOrFail();
            try{app(PharmacyWorkflowService::class)->handover($prep->public_id,user($f['pharmacist']),fingerprintPreparation($prep),null,'handover-return-race-'.$token);}
            catch(PharmacyDenied $denial){return ['outcome'=>'DENIED','reason'=>$denial->reason];}
        }
        return ['outcome'=>'APPLIED'];
    }

    function racePreparationLifecycle(array $f, string $token): array
    {
        $worker=(string)getenv('SIMRS_PHARMACY_WORKER');
        $prescription=PharmacyPrescription::query()->where('public_id',$f['lifecycle_prescription'])->firstOrFail();
        $encounter=$prescription->encounter()->firstOrFail();
        protocol('STARTED',['backend_connection_id'=>backendConnectionId()]);
        if($worker==='A'){
            DB::transaction(function()use($prescription,$encounter,$f,$token):void{
                InpatientPatientClaimMutex::query()->where('patient_id',$encounter->patient_id)->lockForUpdate()->firstOrFail();
                PharmacyMutationScope::run(fn()=>app(PharmacyEncounterLifecycleGate::class)->lockInventoryForEncounter($encounter->id));
                protocol('HOLDING',['lock_order_trace'=>['inpatient_patient_claim_mutex','pharmacy_inventory_mutex']]);usleep(((int)getenv('SIMRS_PHARMACY_HOLD_MS'))*1000);
                app(PharmacyWorkflowService::class)->prepare($prescription->public_id,user($f['technician']),fingerprintPrescription($prescription),'lifecycle-prepare-'.$token);
            },3);
            return ['outcome'=>'PREPARED'];
        }
        $transferDenied=false;$dischargeDenied=false;$transferReason=null;$dischargeReason=null;
        try{app(InpatientBedTransferService::class)->transfer($encounter->public_id,user($f['registrar']),1,$f['inpatient_source_bed'],$f['inpatient_target_bed'],'Pharmacy race transfer','lifecycle-transfer-'.$token);}
        catch(InpatientBedTransferDenied $e){$transferReason=$e->reason;$transferDenied=$e->reason==='active_pharmacy_preparation';}
        try{app(InpatientDischargeService::class)->execute($encounter->public_id,user($f['physician']),1,0,$f['inpatient_source_bed'],'lifecycle-discharge-'.$token);}
        catch(InpatientDischargeDenied $e){$dischargeReason=$e->reason;$dischargeDenied=$e->reason==='active_pharmacy_prescriptions';}
        must($transferDenied&&$dischargeDenied,'preparation blocks transfer and discharge: transfer='.$transferReason.', discharge='.$dischargeReason);
        return ['outcome'=>'DENIED_BOTH'];
    }

    function raceResetRecoveryOperation(array $f, string $token): array
    {
        $worker=(string)getenv('SIMRS_PHARMACY_WORKER');
        protocol('STARTED',['backend_connection_id'=>backendConnectionId()]);
        if($worker==='A'){
            $prep=PharmacyPreparation::query()->where('public_id',$f['reset_race_preparation'])->firstOrFail();
            DB::transaction(function()use($prep,$f,$token):void{
                lockInventoryForPreparation($prep);
                protocol('HOLDING',['lock_order_trace'=>['pharmacy_inventory_mutex']]);
                usleep(((int)getenv('SIMRS_PHARMACY_HOLD_MS'))*1000);
                app(PharmacyWorkflowService::class)->handover($prep->public_id,user($f['pharmacist']),fingerprintPreparation($prep),null,'reset-race-handover-'.$token);
            },3);
            return ['outcome'=>'HANDED_OVER'];
        }
        $reset=runReset($f);
        return ['outcome'=>'RESET','reset'=>$reset];
    }

    function runLockOrderProbe(array $f, string $token): array
    {
        $trace=[];
        DB::listen(function($query)use(&$trace):void{
            $sql=mb_strtolower($query->sql);
            if(!str_contains($sql,'for update')){return;}
            foreach([
                'inpatient_patient_claim_mutexes'=>'inpatient_patient_claim_mutex',
                'pharmacy_inventory_mutexes'=>'pharmacy_inventory_mutex',
                'encounters'=>'encounter',
                'inpatient_location_events'=>'inpatient_location_event',
                'inpatient_beds'=>'inpatient_bed',
                'pharmacy_prescriptions'=>'pharmacy_prescription',
            ] as $table=>$stage){
                if(str_contains($sql,$table)&&($trace===[]||end($trace)!==$stage)){$trace[]=$stage;break;}
            }
        });
        $prescription=PharmacyPrescription::query()->where('public_id',$f['lock_order_prescription'])->firstOrFail();
        app(PharmacyWorkflowService::class)->prepare($prescription->public_id,user($f['technician']),fingerprintPrescription($prescription),'lock-order-probe-prepare-'.$token);
        $expected=['inpatient_patient_claim_mutex','pharmacy_inventory_mutex','encounter','inpatient_location_event','inpatient_bed','pharmacy_prescription'];
        must($trace===$expected,'exact service lock order');
        return ['exact_service_lock_order'=>$trace,'query_listener_observed_for_update'=>true];
    }

    function expectRefusal(callable $operation,string $label): void
    {
        try{$operation();}catch(Throwable){return;}throw new RuntimeException('expected refusal: '.$label);
    }

    function expectRefusalContaining(callable $operation,string $label,string $needle): void
    {
        try{$operation();}
        catch(Throwable $exception){
            must(str_contains(mb_strtolower($exception->getMessage()),mb_strtolower($needle)),'expected refusal reason: '.$label);
            return;
        }
        throw new RuntimeException('expected refusal: '.$label);
    }

    function runApplicationGuards(): array
    {
        $guard=app(PharmacySqlWriteGuard::class);
        foreach(['UPDATE pharmacy_prescriptions SET status=\'HANDED_OVER\'','DELETE FROM pharmacy_stock_movements','TRUNCATE TABLE pharmacy_operation_receipts'] as $sql){expectRefusal(fn()=>$guard->assertAllowed($sql),$sql);}
        return ['application_sql_guard_refusals'=>3];
    }

    function runDatabaseGuards(array $f): array
    {
        $version=DB::table(SchemaQualifier::table('pharmacy_prescription_versions'))->first();
        $table=SchemaQualifier::table('pharmacy_prescription_versions');
        $attempts=[
            'append_only_update'=>["UPDATE {$table} SET content_digest=? WHERE id=?",[str_repeat('f',64),$version->id]],
            'append_only_delete'=>["DELETE FROM {$table} WHERE id=?",[$version->id]],
        ];
        if(DB::connection()->getDriverName()==='pgsql'){$attempts['append_only_truncate']=["TRUNCATE TABLE {$table}",[]];}
        $refused=[];
        foreach($attempts as $label=>[$sql,$bindings]){
            expectRefusalContaining(fn()=>PharmacyMutationScope::run(fn()=>DB::statement($sql,$bindings)),'database immutable guard '.$label,'pharmacy append-only evidence is immutable');
            $refused[]=$label;
        }
        must(DB::table($table)->where('id',$version->id)->value('content_digest')===$version->content_digest,'append-only evidence unchanged after destructive attempts');
        return [
            'database_append_only_refusals'=>$refused,
            'postgres_evidence_truncate_refusal'=>DB::connection()->getDriverName()==='pgsql',
            'mysql_evidence_truncate_boundary'=>DB::connection()->getDriverName()==='mysql'?'NOT_SUPPORTED_BY_ROW_TRIGGER_CONTRACT':'NOT_APPLICABLE',
            'evidence_table'=>'pharmacy_prescription_versions',
            'evidence_chain_unchanged'=>true,
        ];
    }

    function runPrivilegeGuards(): array
    {
        foreach([
            "UPDATE ".SchemaQualifier::table('pharmacy_prescription_versions')." SET content_digest='".str_repeat('f',64)."'",
            "DELETE FROM ".SchemaQualifier::table('pharmacy_prescriptions'),
            "DELETE FROM ".SchemaQualifier::table('audit_events')." WHERE action='pharmacy.workflow.mutate'",
            "DROP TABLE ".SchemaQualifier::table('pharmacy_returns'),
        ] as $sql){expectRefusal(fn()=>DB::statement($sql),'least privilege');}
        return ['least_privilege_forbidden_writes'=>4];
    }

    function runCorruption(array $f,string $token): array
    {
        $prep=PharmacyPreparation::query()->where('public_id',$f['corrupt_preparation'])->firstOrFail();
        $p=$prep->prescription()->firstOrFail();
        $driver=DB::connection()->getDriverName();
        $denied=false;
        DB::beginTransaction();
        try{
            if($driver==='pgsql'){DB::statement("SET LOCAL simrs.pharmacy_mutation = '1'");}
            elseif($driver==='mysql'){DB::statement('SET @simrs_pharmacy_mutation = 1');}
            PharmacyMutationScope::run(fn()=>DB::table(SchemaQualifier::table('pharmacy_prescriptions'))->where('id',$p->id)->update(['current_content_digest'=>str_repeat('0',64)]));
            try{app(PharmacyWorkflowService::class)->handover($prep->public_id,user($f['pharmacist']),fingerprintPreparation($prep),null,'corrupt-handover-'.$token);}
            catch(PharmacyDenied $e){$denied=$e->reason==='evidence_fingerprint_invalid';}
        }finally{
            DB::rollBack();
            if($driver==='mysql'){DB::statement('SET @simrs_pharmacy_mutation = 0');}
        }
        must($denied,'evidence-chain corruption refusal');
        must($p->fresh()->current_content_digest!==str_repeat('0',64),'corruption probe rolled back');
        return ['evidence_chain_corruption_refusal'=>true];
    }

    function runReset(array $f): array
    {
        $auditBefore=AuditEvent::query()->where('action','pharmacy.workflow.mutate')->count();must($auditBefore>0,'pharmacy audit before reset');
        app(SyntheticResetService::class)->reset(['actor'=>user($f['admin']),'reason'=>'bounded_pharmacy_portability_reset']);
        foreach(['pharmacy_prescriptions','pharmacy_stock_lots','pharmacy_handovers','pharmacy_returns','pharmacy_stock_movements','pharmacy_financial_source_events','pharmacy_operation_receipts'] as $table){must(DB::table(SchemaQualifier::table($table))->count()===0,'reset removed '.$table);}
        must(AuditEvent::query()->where('action','pharmacy.workflow.mutate')->count()>=$auditBefore,'pharmacy audit preserved');
        must(AuditEvent::query()->where('action','teaching.reset.completed')->exists(),'reset audit preserved');
        return ['bounded_synthetic_deletion'=>true,'dependency_order_deletion'=>true,'pharmacy_audit_preserved'=>true,'reset_audit_preserved'=>true];
    }

    function authoritativePharmacyIntegrity(): array
    {
        $snapshot=app(SyntheticRecoverySnapshot::class);$integrity=[];
        foreach([
            'pharmacy_stock_balance_mismatches'=>'pharmacyStockBalanceMismatchCount',
            'pharmacy_financial_source_mismatches'=>'pharmacyFinancialSourceMismatchCount',
            'pharmacy_prescription_state_mismatches'=>'pharmacyPrescriptionStateMismatchCount',
        ] as $key=>$method){
            $reflection=new ReflectionMethod($snapshot,$method);$reflection->setAccessible(true);
            $integrity[$key]=(int)$reflection->invoke($snapshot);
            must($integrity[$key]===0,'authoritative integrity '.$key);
        }
        return $integrity;
    }

    function verifyInvariants(array $f): array
    {
        $lots=PharmacyStockLot::query()->get();
        must($lots->every(fn($lot)=>$lot->available_quantity>=0&&$lot->quarantined_quantity>=0),'no negative stock');
        foreach($lots as $lot){$latest=PharmacyStockMovement::query()->where('stock_lot_id',$lot->id)->orderByDesc('id')->first();must($latest!==null&&$latest->available_balance_after===$lot->available_quantity,'movement balance matches lot');}
        $integrity=authoritativePharmacyIntegrity();
        return [
            'no_negative_stock'=>true,'movement_lot_reconciled'=>true,
            'financial_source_reconciled'=>$integrity['pharmacy_financial_source_mismatches']===0,
            'prescription_state_reconciled'=>$integrity['pharmacy_prescription_state_mismatches']===0,
            'authoritative_integrity'=>$integrity,'durable_third_connection_assertions'=>true,
        ];
    }

    function semanticRecoveryReadback(): array
    {
        $tables=['pharmacy_medicines','pharmacy_medicine_versions','pharmacy_depots','pharmacy_depot_versions','pharmacy_inventory_mutexes','pharmacy_stock_lots','pharmacy_stock_movements','pharmacy_prescriptions','pharmacy_prescription_versions','pharmacy_prescription_items','pharmacy_verifications','pharmacy_preparations','pharmacy_preparation_allocations','pharmacy_handovers','pharmacy_handover_items','pharmacy_returns','pharmacy_return_items','pharmacy_financial_source_events','pharmacy_operation_receipts'];
        $counts=[];$digests=[];
        foreach($tables as $table){$rows=DB::table(SchemaQualifier::table($table))->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();$counts[$table]=count($rows);$digests[$table.'_sha256']=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));}
        $relations=[
            'pharmacy_medicine_version_without_medicine'=>['pharmacy_medicine_versions','pharmacy_medicines','medicine_id'],
            'pharmacy_depot_version_without_depot'=>['pharmacy_depot_versions','pharmacy_depots','depot_id'],
            'pharmacy_inventory_mutex_without_depot'=>['pharmacy_inventory_mutexes','pharmacy_depots','depot_id'],
            'pharmacy_inventory_mutex_without_medicine'=>['pharmacy_inventory_mutexes','pharmacy_medicines','medicine_id'],
            'pharmacy_lot_without_depot'=>['pharmacy_stock_lots','pharmacy_depots','depot_id'],'pharmacy_lot_without_medicine'=>['pharmacy_stock_lots','pharmacy_medicines','medicine_id'],
            'pharmacy_prescription_without_encounter'=>['pharmacy_prescriptions','encounters','encounter_id'],'pharmacy_prescription_without_patient'=>['pharmacy_prescriptions','patients','patient_id'],'pharmacy_prescription_without_depot'=>['pharmacy_prescriptions','pharmacy_depots','depot_id'],
            'pharmacy_prescription_version_without_prescription'=>['pharmacy_prescription_versions','pharmacy_prescriptions','prescription_id'],'pharmacy_item_without_prescription'=>['pharmacy_prescription_items','pharmacy_prescriptions','prescription_id'],'pharmacy_item_without_medicine'=>['pharmacy_prescription_items','pharmacy_medicines','medicine_id'],
            'pharmacy_verification_without_prescription'=>['pharmacy_verifications','pharmacy_prescriptions','prescription_id'],'pharmacy_preparation_without_prescription'=>['pharmacy_preparations','pharmacy_prescriptions','prescription_id'],
            'pharmacy_allocation_without_preparation'=>['pharmacy_preparation_allocations','pharmacy_preparations','preparation_id'],'pharmacy_allocation_without_item'=>['pharmacy_preparation_allocations','pharmacy_prescription_items','prescription_item_id'],'pharmacy_allocation_without_lot'=>['pharmacy_preparation_allocations','pharmacy_stock_lots','stock_lot_id'],
            'pharmacy_handover_without_prescription'=>['pharmacy_handovers','pharmacy_prescriptions','prescription_id'],'pharmacy_handover_without_preparation'=>['pharmacy_handovers','pharmacy_preparations','preparation_id'],
            'pharmacy_handover_item_without_handover'=>['pharmacy_handover_items','pharmacy_handovers','handover_id'],'pharmacy_handover_item_without_item'=>['pharmacy_handover_items','pharmacy_prescription_items','prescription_item_id'],'pharmacy_handover_item_without_lot'=>['pharmacy_handover_items','pharmacy_stock_lots','stock_lot_id'],
            'pharmacy_return_without_handover'=>['pharmacy_returns','pharmacy_handovers','handover_id'],'pharmacy_return_item_without_return'=>['pharmacy_return_items','pharmacy_returns','return_id'],'pharmacy_return_item_without_handover_item'=>['pharmacy_return_items','pharmacy_handover_items','handover_item_id'],
            'pharmacy_stock_movement_without_lot'=>['pharmacy_stock_movements','pharmacy_stock_lots','stock_lot_id'],'pharmacy_financial_event_without_prescription'=>['pharmacy_financial_source_events','pharmacy_prescriptions','prescription_id'],'pharmacy_financial_event_without_item'=>['pharmacy_financial_source_events','pharmacy_prescription_items','prescription_item_id'],
        ];
        $orphans=[];foreach($relations as $key=>[$child,$parent,$fk]){$orphans[$key]=DB::table(SchemaQualifier::table($child).' as c')->leftJoin(SchemaQualifier::table($parent).' as p','p.id','=','c.'.$fk)->whereNull('p.id')->count();must($orphans[$key]===0,'recovery orphan '.$key);}
        verifyInvariants([]);
        $integrity=authoritativePharmacyIntegrity();
        foreach($digests as $digest){must(preg_match('/\A[0-9a-f]{64}\z/',$digest)===1,'recovery digest');}
        return ['counts'=>$counts,'orphans'=>$orphans,'integrity'=>$integrity,'digests'=>$digests,'authoritative_source'=>'SyntheticRecoverySnapshot'];
    }

    $root=(string)getenv('SIMRS_REHEARSAL_ROOT'); require $root.'/vendor/autoload.php';
    $app=require $root.'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
    $action=(string)getenv('SIMRS_PHARMACY_ACTION'); $token=(string)getenv('SIMRS_PHARMACY_RUN_TOKEN'); $f=fixture();
    try {
        $result=match($action){
            'prepare'=>['fixture'=>prepareFixture($token)],
            'sequential'=>runSequential($f,$token),
            'prepare_return_race'=>prepareRaceBaseline($f,'return_competing_prescription',$token),
            'prepare_reset_race'=>prepareRaceBaseline($f,'reset_race_prescription',$token),
            'race_handover'=>raceHandover($f,$token),
            'race_return_handover'=>raceReturnHandover($f,$token),
            'race_preparation_lifecycle'=>racePreparationLifecycle($f,$token),
            'race_reset_recovery_operation'=>raceResetRecoveryOperation($f,$token),
            'lock_order_probe'=>runLockOrderProbe($f,$token),
            'verify'=>verifyInvariants($f),
            'recovery'=>semanticRecoveryReadback(),
            'application_guards'=>runApplicationGuards(),
            'database_guards'=>runDatabaseGuards($f),
            'privilege_guards'=>runPrivilegeGuards(),
            'corrupt'=>runCorruption($f,$token),
            'reset'=>runReset($f),
            default=>throw new RuntimeException('unknown action'),
        };
        protocol('COMMITTED',$action==='prepare'?$result:(['outcome'=>$result['outcome']??'APPLIED','result'=>$result]+$result));
    } catch(Throwable $e) {
        $query=$e instanceof QueryException ? $e : null;
        $errorInfo=$query?->errorInfo ?? [];
        echo json_encode(['schema_version'=>1,'status'=>'BLOCKED','protocol_state'=>'FAILED','scenario'=>(string)getenv('SIMRS_PHARMACY_SCENARIO'),'worker'=>(string)getenv('SIMRS_PHARMACY_WORKER'),'exception_class'=>get_class($e),'exception_fingerprint'=>hash('sha256',get_class($e)."\0".$e->getMessage()),'diagnostic_message'=>($e instanceof LogicException||$e instanceof RuntimeException) ? mb_substr($e->getMessage(),0,300) : null,'failure_stage'=>null,'sql_state'=>(string)($errorInfo[0]??''),'driver_code'=>(string)($errorInfo[1]??''),'query_head'=>$query ? mb_substr($query->getSql(),0,180) : null],JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
  PHP

  def assert_contract!
    unless @operator_environment[CONFIRMATION_ENV] == CONFIRMATION
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize the disposable local rehearsal."
    end
    rejected = FORBIDDEN_ENVIRONMENT.select { |name| !@operator_environment.fetch(name, '').to_s.strip.empty? }
    raise CommandFailed, "Pharmacy rehearsal refuses inherited overrides: #{rejected.join(', ')}." unless rejected.empty?
    LocalPortabilityFullSuiteRehearsal.instance_method(:assert_contract!).bind(self).call
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    raise CommandFailed, 'Pharmacy scenario catalogue drifted.' unless SCENARIOS.length == 26 && SCENARIOS.uniq.length == 26
    raise CommandFailed, 'Embedded pharmacy worker source is unexpectedly small.' unless WORKER_SOURCE.bytesize > 14_000
  end

  def application_environment
    LocalPortabilityFullSuiteRehearsal.instance_method(:application_environment).bind(self).call.merge(
      CONFIRMATION_ENV => CONFIRMATION, 'BPJS_INTEGRATION_ENABLED' => 'false',
      'VCLAIM_ENABLED' => 'false', 'SATUSEHAT_ENABLED' => 'false', 'COLUMNS' => '300'
    )
  end

  def current_pharmacy_bindings
    files = SOURCE_PATHS.to_h { |path| [path, Digest::SHA256.file(safe_source_path(path)).hexdigest] }
    {
      'files' => files,
      'aggregate_sha256' => Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h)),
      'worker_source_sha256' => Digest::SHA256.hexdigest(WORKER_SOURCE),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)),
      'runtime_grant_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(RUNTIME_TABLE_GRANTS)),
    }
  end

  def worker_environment(action:, scenario:, worker:, fixture:, hold_ms:, connection_environment: nil)
    action = @race_action if action == 'race' && @race_action
    (connection_environment || @runtime_application_environment || application_environment).merge(
      'SIMRS_REHEARSAL_ROOT' => ROOT, 'SIMRS_PHARMACY_ACTION' => action,
      'SIMRS_PHARMACY_SCENARIO' => scenario, 'SIMRS_PHARMACY_WORKER' => worker,
      'SIMRS_PHARMACY_RUN_TOKEN' => @run_token, 'SIMRS_PHARMACY_HOLD_MS' => hold_ms.to_s,
      'SIMRS_PHARMACY_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {}))
    )
  end

  def create_worker_file!
    @worker_tempfile = Tempfile.new(['simrs-pharmacy-portability-', '.php'])
    @worker_tempfile.binmode
    @worker_tempfile.write(WORKER_SOURCE)
    @worker_tempfile.flush
    @worker_tempfile.chmod(0o600)
    @worker_tempfile.close
  end

  def start_pharmacy_race_worker!(fixture:, scenario:, worker:, hold_ms:, connection_environment: nil)
    @command_catalog << ['pharmacy-worker', 'race', "--scenario=#{scenario}", "--worker=#{worker}"]
    stdin, stdout, stderr, wait_thread = Open3.popen3(
      @runner.process_environment(worker_environment(
        action: 'race', scenario: scenario, worker: worker, fixture: fixture,
        hold_ms: hold_ms, connection_environment: connection_environment
      )),
      @php_binary, @worker_tempfile.path, unsetenv_others: true
    )
    stdin.close
    process = Worker.new(
      stdout: stdout, stderr: stderr, wait_thread: wait_thread,
      stderr_reader: Thread.new { stderr.read }, scenario: scenario, label: worker
    )
    @workers << process
    process
  end

  def run_observed_race!(fixture, scenario, action, expected_outcomes, expected_lock_order:, second_connection_environment: nil)
    @race_action=action
    first=start_pharmacy_race_worker!(fixture: fixture,scenario: scenario,worker: 'A',hold_ms: HOLD_MS)
    await_protocol!(first,'STARTED')
    holding=await_protocol!(first,'HOLDING')
    actual_lock_order=holding.fetch('lock_order_trace')
    raise CommandFailed, "#{scenario} lock-order trace drifted." unless actual_lock_order==expected_lock_order
    second=start_pharmacy_race_worker!(fixture: fixture,scenario: scenario,worker: 'B',hold_ms: 0,connection_environment: second_connection_environment)
    started=await_protocol!(second,'STARTED')
    raise CommandFailed, "#{scenario} did not expose a real database wait." unless observe_real_database_wait!(Integer(started.fetch('backend_connection_id')))
    finals=[await_final!(first),await_final!(second)]
    outcomes=finals.map{|document|document.fetch('outcome')}.sort
    unless outcomes == expected_outcomes.sort
      details=finals.map{|document|document.slice('worker','outcome','reason')}
      raise CommandFailed, "#{scenario} outcomes drifted: expected=#{expected_outcomes.sort.join(',')}, actual=#{outcomes.join(',')}, details=#{JSON.generate(details)}."
    end
    {
      'status'=>'PASS','proof_kind'=>'OBSERVED_DATABASE_RACE','independent_application_processes'=>2,
      'real_database_wait_observed'=>true,'outcomes'=>outcomes,
      'observed_precondition_lock_order'=>actual_lock_order,'lock_order_trace_asserted'=>true,
      'worker_results'=>finals.map{|document|document.slice('worker','outcome','result','reset')},
    }
  ensure
    @race_action=nil
    terminate_workers!
  end

  def provision_postgres_runtime_identities!
    runtime="simrs_runtime_#{@run_token}"; reset="simrs_reset_#{@run_token}"
    raise CommandFailed,'Generated PostgreSQL identities failed closed pattern.' unless runtime.match?(IDENTITY_PATTERN)&&reset.match?(IDENTITY_PATTERN)
    tables=@runner.run!(postgres_psql_arguments(@postgres_database)+['--tuples-only','--no-align','--command',"SELECT tablename FROM pg_tables WHERE schemaname='laravel' ORDER BY tablename"],env:postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    sequences=@runner.run!(postgres_psql_arguments(@postgres_database)+['--tuples-only','--no-align','--command',"SELECT sequencename FROM pg_sequences WHERE schemaname='laravel' ORDER BY sequencename"],env:postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    missing=RUNTIME_TABLE_GRANTS.keys-tables;raise CommandFailed,"PostgreSQL runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements=[%(CREATE ROLE "#{runtime}" LOGIN),%(CREATE ROLE "#{reset}" LOGIN),%(GRANT CONNECT ON DATABASE "#{@postgres_database}" TO "#{runtime}", "#{reset}"),%(GRANT USAGE ON SCHEMA "laravel" TO "#{runtime}", "#{reset}")]
    RUNTIME_TABLE_GRANTS.each{|table,grants|statements<<%(GRANT #{grants} ON TABLE "laravel"."#{table}" TO "#{runtime}")}
    tables.each{|table|statements<<%(GRANT #{table=='audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON TABLE "laravel"."#{table}" TO "#{reset}")}
    insert_tables=RUNTIME_TABLE_GRANTS.select{|_,grants|grants.include?('INSERT')}.keys
    sequences.each do |sequence|
      statements<<%(GRANT USAGE, SELECT ON SEQUENCE "laravel"."#{sequence}" TO "#{runtime}") if insert_tables.any?{|table|sequence=="#{table}_id_seq"}
      statements<<%(GRANT USAGE, SELECT, UPDATE ON SEQUENCE "laravel"."#{sequence}" TO "#{reset}")
    end
    @runner.run!(postgres_psql_arguments(@postgres_database)+['--set','ON_ERROR_STOP=1','--command',statements.join(";\n")+';'],env:postgres_tool_environment)
    RUNTIME_TABLE_GRANTS.each do |table,expected|
      actual=@runner.run!(postgres_psql_arguments(@postgres_database)+['--tuples-only','--no-align','--command',"SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name='#{table}'"],env:postgres_tool_environment).strip
      raise CommandFailed,"PostgreSQL runtime grant drifted for #{table}." unless actual==expected.split(', ').sort.join(',')
    end
    unexpected=@runner.run!(postgres_psql_arguments(@postgres_database)+['--tuples-only','--no-align','--command',"SELECT table_name FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name NOT IN (#{RUNTIME_TABLE_GRANTS.keys.map{|table|"'#{table}'"}.join(',')}) ORDER BY table_name"],env:postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    raise CommandFailed,"PostgreSQL runtime has grants outside the closed map: #{unexpected.join(', ')}." unless unexpected.empty?
    @runtime_application_environment=application_environment.merge('DB_USERNAME'=>runtime,'DB_PASSWORD'=>'')
    @reset_application_environment=application_environment.merge('DB_USERNAME'=>reset,'DB_PASSWORD'=>'')
  end

  def provision_mysql_runtime_identities!
    runtime="simrs_runtime_#{@run_token}";reset="simrs_reset_#{@run_token}"
    raise CommandFailed,'Generated MySQL identities failed closed pattern.' unless runtime.match?(IDENTITY_PATTERN)&&reset.match?(IDENTITY_PATTERN)
    runtime_password=SecureRandom.hex(24);reset_password=SecureRandom.hex(24)
    tables=@runner.run!(mysql_root_arguments+['--batch','--skip-column-names'],stdin_data:"SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='#{@mysql_database}' ORDER BY TABLE_NAME;").lines.map(&:strip).reject(&:empty?)
    missing=RUNTIME_TABLE_GRANTS.keys-tables;raise CommandFailed,"MySQL runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements=["CREATE USER '#{runtime}'@'127.0.0.1' IDENTIFIED BY '#{runtime_password}'","CREATE USER '#{reset}'@'127.0.0.1' IDENTIFIED BY '#{reset_password}'"]
    RUNTIME_TABLE_GRANTS.each{|table,grants|statements<<"GRANT #{grants} ON `#{@mysql_database}`.`#{table}` TO '#{runtime}'@'127.0.0.1'"}
    tables.each{|table|statements<<"GRANT #{table=='audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON `#{@mysql_database}`.`#{table}` TO '#{reset}'@'127.0.0.1'"}
    statements<<'FLUSH PRIVILEGES';@runner.run!(mysql_root_arguments,stdin_data:statements.join(";\n")+';')
    grants=@runner.run!(mysql_root_arguments+['--batch','--skip-column-names'],stdin_data:"SHOW GRANTS FOR '#{runtime}'@'127.0.0.1';").upcase
    raise CommandFailed,'Reduced MySQL runtime retained forbidden DDL grants.' unless %w[DROP ALTER TRIGGER CREATE].none?{|privilege|grants.match?(/\b#{privilege}\b/)}
    RUNTIME_TABLE_GRANTS.each do |table,expected_grants|
      expected="GRANT #{expected_grants} ON `#{@mysql_database.upcase}`.`#{table.upcase}`"
      raise CommandFailed,"MySQL runtime grant drifted for #{table}." unless grants.include?(expected)
    end
    raise CommandFailed,'MySQL runtime received a schema wildcard grant.' if grants.include?("ON `#{@mysql_database.upcase}`.*")
    @runtime_application_environment=application_environment.merge('DB_USERNAME'=>runtime,'DB_PASSWORD'=>runtime_password)
    @reset_application_environment=application_environment.merge('DB_USERNAME'=>reset,'DB_PASSWORD'=>reset_password)
  end

  def expect_pharmacy_rollback_refusal!
    arguments=['migrate:rollback','--path='+MIGRATION_PATH,'--force','--no-interaction'];@command_catalog<<['artisan',*arguments]
    stdout,stderr,status=Open3.capture3(@runner.process_environment(application_environment),@php_binary,File.join(ROOT,'artisan'),*arguments,unsetenv_others:true)
    raise CommandFailed,'Retained pharmacy audit unexpectedly allowed rollback.' if status.success?
    combined=stdout+stderr
    raise CommandFailed,'Pharmacy rollback failed for an unexpected reason.' unless combined.gsub(/\s+/,'').include?('correlatedauditevidenceexists')
    @result_catalog<<[['artisan',*arguments],'EXPECTED_REFUSAL'];'PASS'
  end

  def run_filtered_feature_evidence!(bindings)
    bindings.to_h do |label, (path, method)|
      source=File.read(safe_source_path(path),encoding:Encoding::UTF_8)
      unless source.include?("function #{method}")
        raise CommandFailed,"Scenario-bound pharmacy feature method is missing: #{path}##{method}."
      end
      cache_key=[path,method]
      proof=(@filtered_feature_evidence_cache||={})[cache_key]||=begin
        recorded_artisan!('test',path,"--filter=#{method}")
        {'status'=>'PASS','proof_kind'=>'FILTERED_FEATURE_TEST','path'=>path,'method'=>method}
      end
      [label,proof.merge('scenario_binding'=>label)]
    end
  end

  def write_pharmacy_evidence!(bindings:,engine_binding:,migration_duration_ms:,scenarios:)
    assert_evidence_directory!
    path=File.join(EVIDENCE_DIRECTORY,"#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-pharmacy-#{SecureRandom.hex(6)}.json")
    evidence={
      'schema_version'=>1,'kind'=>EVIDENCE_KIND,'status'=>'PASS','recorded_at_utc'=>Time.now.utc.iso8601,
      'claim'=>'LOCAL_DISPOSABLE_CROSS_SETTING_PHARMACY_STOCK_ONLY','hosted_readiness_claim'=>false,
      'deployment_claim'=>false,'owner_acceptance_claim'=>false,
      'source_bindings'=>bindings.merge('command_catalog_sha256'=>Digest::SHA256.hexdigest(JSON.generate(@command_catalog)),'result_catalog_sha256'=>Digest::SHA256.hexdigest(JSON.generate(@result_catalog))),
      'command_catalog'=>@command_catalog,'protocol_result_catalog'=>@protocol_catalog,
      'boundary'=>{'application_mode'=>'SIMULATION','synthetic_only'=>true,'live_integrations_enabled'=>false,'disposable_local_engine'=>true,'external_database_configuration_accepted'=>false},
      'engine'=>engine_binding,'migration'=>{'fresh_apply'=>'PASS','duration_ms_observed'=>migration_duration_ms},'scenarios'=>scenarios,
      'cleanup'=>{'database_removed'=>true,'temporary_server_removed'=>true,'temporary_user_state_removed'=>true,'temporary_worker_removed'=>true},
      'open_boundaries'=>['Local disposable-engine evidence only; no deployment, hosted migration, UAT, pharmacy-owner acceptance, or production-readiness claim.','No supplier, payment, BPJS, VClaim, SATUSEHAT, mail, device, or production integration is exercised.'],
    }
    sanitize_evidence!(evidence);File.write(path,JSON.pretty_generate(evidence)+"\n",mode:'wx',perm:0o600);File.chmod(0o600,path);path
  end

  def run!
    assert_contract!
    bindings=current_pharmacy_bindings
    engine_binding=prepare_engine!
    @run_token=database_run_token
    create_worker_file!
    started=@clock.call
    recorded_artisan!('migrate:fresh','--force','--no-interaction')
    migration_duration_ms=elapsed_ms(started)
    recorded_artisan!('db:seed','--class=Database\\Seeders\\RbacSeeder','--force','--no-interaction')
    recorded_artisan!('migrate:rollback','--path='+MIGRATION_PATH,'--force','--no-interaction')
    recorded_artisan!('migrate','--path='+MIGRATION_PATH,'--force','--no-interaction')
    recorded_artisan!('test','tests/Feature/Pharmacy/CrossSettingPharmacyWorkflowTest.php')
    hardening_evidence=run_filtered_feature_evidence!(HARDENING_SCENARIO_TESTS)
    primary_evidence=run_filtered_feature_evidence!(PRIMARY_SCENARIO_TESTS)
    lifecycle_gate_evidence=run_filtered_feature_evidence!(LIFECYCLE_GATE_TESTS)
    recorded_artisan!('migrate:fresh','--force','--no-interaction')
    recorded_artisan!('db:seed','--class=Database\\Seeders\\RbacSeeder','--force','--no-interaction')
    prepared=run_worker_command!(action:'prepare',scenario:'fresh-migration',worker:'PREPARE',connection_environment:application_environment)
    fixture=prepared.fetch('fixture')
    sequential=run_worker_command!(action:'sequential',scenario:'all-three-care-settings',worker:'SEQUENTIAL',fixture:fixture,connection_environment:application_environment)
    fixture.merge!(sequential.slice('race_preparation','return_handover','return_competing_prescription','lifecycle_prescription','corrupt_preparation','reset_race_prescription','lock_order_prescription'))
    provision_runtime_identities!
    app_guards=run_worker_command!(action:'application_guards',scenario:'application-sql-guard-refusal',worker:'APP_GUARDS',fixture:fixture)
    db_guards=run_worker_command!(action:'database_guards',scenario:'database-update-delete-truncate-refusal',worker:'OWNER_GUARDS',fixture:fixture,connection_environment:application_environment)
    corruption=run_worker_command!(action:'corrupt',scenario:'evidence-chain-corruption-refusal',worker:'CORRUPTION',fixture:fixture)
    lock_order=run_worker_command!(action:'lock_order_probe',scenario:'exact-lock-order-observability',worker:'LOCK_ORDER',fixture:fixture)
    handover_race=run_observed_race!(fixture,'competing-handovers-no-negative-stock','race_handover',%w[APPLIED DENIED],expected_lock_order:%w[pharmacy_inventory_mutex])
    handover_race['durable_assertions']=run_worker_command!(action:'verify',scenario:'competing-handovers-no-negative-stock',worker:'VERIFY_HANDOVER',fixture:fixture).fetch('result')
    return_baseline=run_worker_command!(action:'prepare_return_race',scenario:'return-vs-handover',worker:'PREPARE_RETURN_BASELINE',fixture:fixture)
    fixture['return_competing_preparation']=return_baseline.fetch('preparation')
    return_race=run_observed_race!(fixture,'return-vs-handover','race_return_handover',%w[APPLIED DENIED],expected_lock_order:%w[pharmacy_inventory_mutex])
    return_race['durable_assertions']=run_worker_command!(action:'verify',scenario:'return-vs-handover',worker:'VERIFY_RETURN',fixture:fixture).fetch('result')
    lifecycle_race=run_observed_race!(fixture,'preparation-vs-transfer-discharge','race_preparation_lifecycle',%w[PREPARED DENIED_BOTH],expected_lock_order:%w[inpatient_patient_claim_mutex pharmacy_inventory_mutex],second_connection_environment:application_environment)
    lifecycle_race['durable_assertions']=run_worker_command!(action:'verify',scenario:'preparation-vs-transfer-discharge',worker:'VERIFY_LIFECYCLE',fixture:fixture).fetch('result')
    invariants=run_worker_command!(action:'verify',scenario:'invariant-verification',worker:'VERIFY',fixture:fixture)
    privilege=run_worker_command!(action:'privilege_guards',scenario:'least-privilege-runtime',worker:'RUNTIME_GUARDS',fixture:fixture)
    reset_baseline=run_worker_command!(action:'prepare_reset_race',scenario:'reset-recovery-vs-operation',worker:'PREPARE_RESET_BASELINE',fixture:fixture)
    fixture['reset_race_preparation']=reset_baseline.fetch('preparation')
    recovery_before=run_worker_command!(action:'recovery',scenario:'bounded-reset-recovery-audit-preservation',worker:'RECOVERY_BEFORE',fixture:fixture)
    reset_race=run_observed_race!(fixture,'reset-recovery-vs-operation','race_reset_recovery_operation',%w[HANDED_OVER RESET],expected_lock_order:%w[pharmacy_inventory_mutex],second_connection_environment:@reset_application_environment)
    recovery_after=run_worker_command!(action:'recovery',scenario:'bounded-reset-recovery-audit-preservation',worker:'RECOVERY_AFTER',fixture:fixture,connection_environment:@reset_application_environment)
    raise CommandFailed,'Post-reset pharmacy recovery counts were not empty.' unless recovery_after.fetch('counts').values.all?(&:zero?)
    reset_race['durable_recovery_after']=recovery_after.fetch('result')
    rollback=expect_pharmacy_rollback_refusal!
    reset_worker=reset_race.fetch('worker_results').find{|result|result['outcome']=='RESET'}
    raise CommandFailed,'Reset race did not retain reset worker evidence.' unless reset_worker&.fetch('reset',nil).is_a?(Hash)
    scenarios={
      'fresh-migration'=>{'status'=>'PASS','proof_kind'=>'ARTISAN_MIGRATION','fresh_apply'=>true},
      'empty-down-reapply'=>{'status'=>'PASS','proof_kind'=>'ARTISAN_MIGRATION','empty_down'=>true,'reapply'=>true},
      'exact-role-boundary'=>{
        'status'=>'PASS','proof_kind'=>'WORKER_AND_FILTERED_FEATURE_TEST',
        'worker_exact_role_boundary'=>sequential.fetch('exact_role_boundary'),
        'filtered_feature_test'=>hardening_evidence.fetch('verification-refusal'),
      },
      'all-three-care-settings'=>{
        'status'=>'PASS','proof_kind'=>'REAL_SERVICE_WORKER','all_three_care_settings'=>sequential.fetch('all_three_care_settings'),
        'prescription_to_handover_journeys'=>sequential.fetch('cross_setting_handover_journeys'),
      },
      'medicine-depot-version-retire'=>hardening_evidence.fetch('medicine-depot-version-retire'),
      'opening-lot-expiry-quarantine'=>{
        'status'=>'PASS','proof_kind'=>'REAL_SERVICE_WORKER_AND_FILTERED_FEATURE_TEST',
        'worker_evidence'=>sequential.fetch('expiry_quarantine'),'filtered_feature_test'=>hardening_evidence.fetch('opening-lot-expiry-quarantine'),
      },
      'verification-refusal'=>{
        'status'=>'PASS','proof_kind'=>'REAL_SERVICE_WORKER_AND_FILTERED_FEATURE_TEST',
        'worker_evidence'=>sequential.fetch('verification_refusal'),'filtered_feature_test'=>hardening_evidence.fetch('verification-refusal'),
      },
      'deterministic-fefo-revalidation'=>{
        'status'=>'PASS','proof_kind'=>'REAL_SERVICE_WORKER_AND_FILTERED_FEATURE_TEST',
        'worker_evidence'=>sequential.fetch('fefo_revalidation'),'filtered_feature_test'=>hardening_evidence.fetch('deterministic-fefo-revalidation'),
      },
      'full-partial-unfilled-handover'=>{
        'status'=>'PASS','proof_kind'=>'FILTERED_FEATURE_TEST_SET',
        'full_and_partial_handover'=>primary_evidence.fetch('full-partial-unfilled-handover'),
        'unfilled_close'=>hardening_evidence.fetch('full-partial-unfilled-handover'),
      },
      'three-condition-return'=>hardening_evidence.fetch('three-condition-return'),
      'stock-financial-reconciliation'=>{
        'status'=>'PASS','proof_kind'=>'FILTERED_FEATURE_AND_AUTHORITATIVE_RECOVERY',
        'filtered_feature_test'=>hardening_evidence.fetch('stock-financial-reconciliation'),
        'full_flow_feature_test'=>primary_evidence.fetch('stock-financial-reconciliation'),
        'recovery_integrity'=>recovery_before.fetch('integrity'),
      },
      'encounter-lifecycle-blockers'=>{
        'status'=>'PASS','proof_kind'=>'FILTERED_FEATURE_TEST_SET',
        'authoritative_relation_regression'=>hardening_evidence.fetch('encounter-lifecycle-blockers'),
        'lifecycle_gate_tests'=>lifecycle_gate_evidence,
      },
      'exact-replay-after-head-advance'=>{
        'status'=>'PASS','proof_kind'=>'REAL_SERVICE_WORKER',
        'exact_replay_after_head_advance'=>sequential.fetch('exact_replay_after_head_advance'),
        'replay_count'=>sequential.fetch('replay_after_head_advance_count'),
      },
      'changed-payload-key-conflict'=>hardening_evidence.fetch('changed-payload-key-conflict'),
      'competing-handovers-no-negative-stock'=>handover_race,
      'return-vs-handover'=>return_race,
      'preparation-vs-transfer-discharge'=>lifecycle_race,
      'reset-recovery-vs-operation'=>reset_race,
      'exact-lock-order-observability'=>{
        'status'=>'PASS','proof_kind'=>'REAL_FOR_UPDATE_QUERY_TRACE',
        'exact_service_lock_order'=>lock_order.fetch('exact_service_lock_order'),
        'query_listener_observed_for_update'=>lock_order.fetch('query_listener_observed_for_update'),
      },
      'application-sql-guard-refusal'=>{
        'status'=>'PASS','proof_kind'=>'APPLICATION_GUARD_WORKER',
        'application_sql_guard_refusals'=>app_guards.fetch('application_sql_guard_refusals'),
      },
      'database-update-delete-truncate-refusal'=>{
        'status'=>'PASS','proof_kind'=>'OWNER_DATABASE_GUARD_WORKER',
        'database_append_only_refusals'=>db_guards.fetch('database_append_only_refusals'),
        'postgres_evidence_truncate_refusal'=>db_guards.fetch('postgres_evidence_truncate_refusal'),
        'mysql_evidence_truncate_boundary'=>db_guards.fetch('mysql_evidence_truncate_boundary'),
        'evidence_table'=>db_guards.fetch('evidence_table'),'evidence_chain_unchanged'=>db_guards.fetch('evidence_chain_unchanged'),
      },
      'evidence-chain-corruption-refusal'=>{
        'status'=>'PASS','proof_kind'=>'REAL_SERVICE_CORRUPTION_WORKER',
        'evidence_chain_corruption_refusal'=>corruption.fetch('evidence_chain_corruption_refusal'),
      },
      'least-privilege-runtime'=>{
        'status'=>'PASS','proof_kind'=>'EXACT_GRANT_READBACK_AND_FORBIDDEN_WRITES',
        'forbidden_writes'=>privilege.fetch('least_privilege_forbidden_writes'),'runtime_grant_catalog'=>'READ_BACK_EXACT',
      },
      'bounded-reset-recovery-audit-preservation'=>{
        'status'=>'PASS','proof_kind'=>'RESET_RACE_AND_AUTHORITATIVE_RECOVERY',
        'reset'=>reset_worker.fetch('reset'),'semantic_recovery_before'=>recovery_before.fetch('result'),
        'semantic_recovery_after'=>recovery_after.fetch('result'),'authoritative_source'=>'SyntheticRecoverySnapshot',
      },
      'retained-evidence-rollback-refusal'=>{'status'=>'PASS','proof_kind'=>'ARTISAN_EXPECTED_REFUSAL','refusal'=>rollback},
      'invariant-verification'=>{'status'=>'PASS','proof_kind'=>'DURABLE_THIRD_CONNECTION_WORKER','result'=>invariants.fetch('result')},
    }
    raise CommandFailed,'Pharmacy scenario result catalogue drifted.' unless scenarios.keys==SCENARIOS
    raise CommandFailed,'Pharmacy scenario evidence cannot contain a non-PASS result.' unless scenarios.values.all?{|result|result['status']=='PASS'}
    assert_unchanged_binding!('Pharmacy execution bindings',bindings,current_pharmacy_bindings)
    cleanup!(strict:true);remove_worker_file!
    evidence_path=write_pharmacy_evidence!(bindings:bindings,engine_binding:engine_binding,migration_duration_ms:migration_duration_ms,scenarios:scenarios)
    {'status'=>'PASS','claim'=>'LOCAL_DISPOSABLE_CROSS_SETTING_PHARMACY_STOCK_ONLY','engine'=>@engine,'scenario_count'=>scenarios.length,'evidence_path'=>evidence_path}
  ensure
    terminate_workers!
    remove_worker_file!
    cleanup!
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalPharmacyStockPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalPharmacyStockPortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalPharmacyStockPortabilityRehearsal::CommandFailed => e
    warn "pharmacy portability rehearsal failed: #{e.message}"
    exit 1
  end
end
