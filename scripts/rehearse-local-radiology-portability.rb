#!/usr/bin/env ruby
# frozen_string_literal: true

require 'base64'
require 'digest'
require 'json'
require 'open3'
require 'securerandom'
require 'tempfile'
require 'time'

require_relative 'rehearse-local-inpatient-discharge-coding-source-portability'

# Exact, disposable PostgreSQL 17/MySQL 8.4 proof for the synthetic-only,
# cross-setting radiology master/order/report evidence contract.
class LocalRadiologyPortabilityRehearsal < LocalInpatientDischargeCodingSourcePortabilityRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_RADIOLOGY_PORTABILITY'
  CONFIRMATION_ENV = 'SIMRS_RADIOLOGY_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-radiology-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalRadiologyPortabilityHarnessContractTest.rb'
  FOUNDATION_PATH = 'scripts/rehearse-local-portability-full-suite.rb'
  MIGRATION_PATH = 'database/migrations/2026_08_31_001100_create_cross_setting_radiology_tables.php'
  FEATURE_PATH = 'tests/Feature/Radiology/CrossSettingRadiologyWorkflowTest.php'
  EVIDENCE_TEMPLATE_PATH = 'docs/operations/T1_LOCAL_CROSS_SETTING_RADIOLOGY_EVIDENCE_TEMPLATE_2026-09-01.md'
  EVIDENCE_KIND = 'SIMRS_LOCAL_CROSS_SETTING_RADIOLOGY_PORTABILITY'
  HOLD_MS = 3_500
  WAIT_TIMEOUT_SECONDS = 24
  IDENTITY_PATTERN = /\Asimrs_(?:runtime|reset)_[0-9a-f]{12}\z/

  SCENARIOS = %w[
    fresh-migration
    empty-down-reapply
    exact-role-boundary
    all-three-care-settings
    master-create-update-retire
    order-perform-draft-verify-amend-acknowledge
    closure-and-cancellation-blockers
    exact-replay-after-head-advance
    changed-payload-key-conflict
    same-key-concurrency-unique-reconciliation
    application-sql-guard-refusal
    database-snapshot-delete-truncate-refusal
    full-chain-corruption-refusal
    least-privilege-runtime
    bounded-reset-amendment-first-audit-preservation
    retained-evidence-rollback-refusal
    invariant-verification
  ].freeze

  IMMUTABLE_EVIDENCE_TABLES = %w[
    radiology_master_code_reservations
    radiology_examination_master_versions
    radiology_order_cancellations
    radiology_performances
    radiology_report_versions
    radiology_report_acknowledgements
    radiology_operation_receipts
    audit_events
  ].freeze
  MUTABLE_HEAD_TABLES = %w[
    radiology_examination_masters
    radiology_orders
  ].freeze
  RUNTIME_READ_TABLES = %w[
    users
    roles
    permissions
    role_user
    permission_role
    patients
    encounter_cancellations
    clinical_entries
    outpatient_clinical_documents
    inpatient_clinical_documents
    inpatient_location_events
    lab_service_requests
    outpatient_rm_completeness_reviews
  ].freeze
  RUNTIME_LOCK_TABLES = %w[
    encounters
  ].freeze
  RUNTIME_TABLE_GRANTS = (
    RUNTIME_READ_TABLES.to_h { |table| [table, 'SELECT'] }
      .merge(RUNTIME_LOCK_TABLES.to_h { |table| [table, 'SELECT, UPDATE'] })
      .merge(MUTABLE_HEAD_TABLES.to_h { |table| [table, 'SELECT, INSERT, UPDATE'] })
      .merge(IMMUTABLE_EVIDENCE_TABLES.to_h { |table| [table, 'SELECT, INSERT'] })
  ).freeze

  SOURCE_PATHS = %w[
    scripts/rehearse-local-radiology-portability.rb
    scripts/rehearse-local-inpatient-discharge-coding-source-portability.rb
    scripts/rehearse-local-portability-full-suite.rb
    tests/Documentation/LocalRadiologyPortabilityHarnessContractTest.rb
    docs/operations/T1_LOCAL_CROSS_SETTING_RADIOLOGY_EVIDENCE_TEMPLATE_2026-09-01.md
    tests/Feature/Radiology/CrossSettingRadiologyWorkflowTest.php
    database/migrations/2026_08_31_001100_create_cross_setting_radiology_tables.php
    app/Support/Radiology/RadiologyActorPolicy.php
    app/Support/Radiology/RadiologyClosureGate.php
    app/Support/Radiology/RadiologyDenied.php
    app/Support/Radiology/RadiologyEvidenceFingerprint.php
    app/Support/Radiology/RadiologyMasterService.php
    app/Support/Radiology/RadiologyMutationResult.php
    app/Support/Radiology/RadiologyMutationScope.php
    app/Support/Radiology/RadiologySchemaMutationScope.php
    app/Support/Radiology/RadiologySqlWriteGuard.php
    app/Support/Radiology/RadiologyWorkflowService.php
    app/Support/Registration/EncounterCancellationDependencyRegistry.php
    app/Support/Simulation/SyntheticResetService.php
    app/Support/Audit/AuditEventSchemaRegistry.php
    app/Models/RadiologyExaminationMaster.php
    app/Models/RadiologyExaminationMasterVersion.php
    app/Models/RadiologyMasterCodeReservation.php
    app/Models/RadiologyOperationReceipt.php
    app/Models/RadiologyOrder.php
    app/Models/RadiologyOrderCancellation.php
    app/Models/RadiologyPerformance.php
    app/Models/RadiologyReportAcknowledgement.php
    app/Models/RadiologyReportVersion.php
  ].freeze
  FORBIDDEN_ENVIRONMENT = LocalInpatientDischargeCodingSourcePortabilityRehearsal::FORBIDDEN_ENVIRONMENT

  parent_worker = LocalInpatientDischargeCodingSourcePortabilityRehearsal::WORKER_SOURCE
  dispatch_marker = "\n$action = (string) getenv('SIMRS_DISCHARGE_ACTION');"
  raise 'Cannot isolate the exact-engine fixture worker.' unless parent_worker.include?(dispatch_marker)

  shared_worker = parent_worker.split(dispatch_marker, 2).first
  shared_worker = shared_worker.sub(
    "use App\\Models\\Patient;\n",
    <<~'PHP'
      use App\Models\Patient;
      use App\Models\RadiologyExaminationMaster;
      use App\Models\RadiologyExaminationMasterVersion;
      use App\Models\RadiologyOperationReceipt;
      use App\Models\RadiologyOrder;
      use App\Models\RadiologyReportVersion;
    PHP
  ).sub(
    "use App\\Support\\Database\\SchemaQualifier;\n",
    <<~'PHP'
      use App\Support\Database\SchemaQualifier;
      use App\Support\Radiology\RadiologyActorPolicy;
      use App\Support\Radiology\RadiologyClosureGate;
      use App\Support\Radiology\RadiologyDenied;
      use App\Support\Radiology\RadiologyMasterService;
      use App\Support\Radiology\RadiologyMutationScope;
      use App\Support\Radiology\RadiologySqlWriteGuard;
      use App\Support\Radiology\RadiologyWorkflowService;
      use App\Support\Registration\EncounterCancellationDependencyRegistry;
    PHP
  )

  WORKER_SOURCE = shared_worker + <<~'PHP'

    function prepareRadiologyFixture(string $token): array
    {
        $roles = [
            'registrar' => RoleCapabilityMatrix::ROLE_REGISTRAR,
            'admin' => RoleCapabilityMatrix::ROLE_ADMIN,
            'physician' => RoleCapabilityMatrix::ROLE_PHYSICIAN,
            'technologist' => RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST,
            'radiologist' => RoleCapabilityMatrix::ROLE_RADIOLOGIST,
        ];
        $fixture = [];
        foreach ($roles as $name => $role) {
            $actor = User::query()->create([
                'name' => 'Radiology portability '.$name,
                'email' => 'radiology.'.$name.'.'.$token.'@example.invalid',
                'password' => bin2hex(random_bytes(24)),
                'status' => 'ACTIVE',
            ]);
            $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);
            must($actor->fresh()->roleSlugs() === [$role], 'exact role '.$role);
            must(!$actor->is_system_administrator, 'non-system actor '.$role);
            $fixture[$name] = $actor->public_id;
        }

        $registrar = userByPublicId($fixture['registrar']);
        $settings = [
            'outpatient' => Encounter::CARE_SETTING_OUTPATIENT,
            'emergency' => Encounter::CARE_SETTING_EMERGENCY,
            'inpatient' => Encounter::CARE_SETTING_INPATIENT,
            'cancel_order' => Encounter::CARE_SETTING_OUTPATIENT,
            'active_blocker' => Encounter::CARE_SETTING_EMERGENCY,
            'corrupt_chain' => Encounter::CARE_SETTING_OUTPATIENT,
            'race' => Encounter::CARE_SETTING_EMERGENCY,
            'retired_refusal' => Encounter::CARE_SETTING_INPATIENT,
        ];
        $sequence = 0;
        foreach ($settings as $index => $careSetting) {
            $sequence++;
            $patient = Patient::query()->create([
                'medical_record_number' => 'RPR'.strtoupper($token).str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
                'full_name' => 'Synthetic radiology patient '.$sequence,
                'date_of_birth' => '1990-01-01',
                'sex' => Patient::SEX_PEREMPUAN,
                'is_synthetic' => true,
                'created_by_user_id' => $registrar->id,
            ]);
            $label = 'Radiology '.strtoupper((string) $index);
            $encounterAttributes = [
                'patient_id' => $patient->id,
                'care_setting' => $careSetting,
                'status' => Encounter::STATUS_IN_EXAMINATION,
                'clinic_name' => $label,
                'ward_name' => $careSetting === Encounter::CARE_SETTING_INPATIENT ? $label : null,
                'continue_from' => $careSetting === Encounter::CARE_SETTING_INPATIENT ? Encounter::CONTINUE_LANGSUNG : null,
                'visit_date' => now()->toDateString(),
                'payer_type' => Encounter::PAYER_UMUM,
                'queue_date' => now()->toDateString(),
                'queue_number' => $sequence,
                'registered_at' => now(),
                'registered_by_user_id' => $registrar->id,
            ];
            $encounter = InpatientLocationMutationScope::run(
                fn () => Encounter::query()->create($encounterAttributes),
            );
            $fixture[$index.'_encounter'] = $encounter->public_id;
        }
        return $fixture;
    }

    function radiologyLifecycle(RadiologyWorkflowService $service, Encounter $encounter, $master, User $physician, User $technologist, User $radiologist, string $token, string $suffix): array
    {
        $orderResult = $service->createOrder($encounter->public_id, $master->public_id, $physician, 'Clinical indication '.$suffix, 'radiology-order-'.$suffix.'-'.$token);
        must(!$orderResult->replayed, 'order applied '.$suffix);
        $order = $orderResult->record;
        must($order instanceof RadiologyOrder, 'order type '.$suffix);
        must($order->care_setting === $encounter->care_setting, 'care-setting snapshot '.$suffix);
        must($order->encounter_status_snapshot === Encounter::STATUS_IN_EXAMINATION, 'status snapshot '.$suffix);
        must($order->master_version === 2, 'exact master head snapshot '.$suffix);
        $service->perform($order->public_id, $technologist, 1, 'radiology-perform-'.$suffix.'-'.$token);
        $draft = $service->saveDraft(
            $order->public_id, $radiologist, 0,
            'No acute abnormality '.$suffix, 'Stable radiology impression '.$suffix, null,
            'radiology-draft-'.$suffix.'-'.$token,
        )->record;
        must($draft instanceof RadiologyReportVersion && $draft->state === RadiologyReportVersion::DRAFT, 'Draft '.$suffix);
        $verified = $service->verify($order->public_id, $radiologist, $draft->version, 'radiology-verify-'.$suffix.'-'.$token)->record;
        must($verified instanceof RadiologyReportVersion && $verified->state === RadiologyReportVersion::VERIFIED, 'Verified '.$suffix);
        $gate = app(RadiologyClosureGate::class);
        must($gate->inspect($encounter)['stale_acknowledgement_order_public_ids'] === [$order->public_id], 'closure stale before first acknowledgement '.$suffix);
        $service->acknowledge($order->public_id, $physician, $order->fresh()->version, $verified->version, 'radiology-ack-base-'.$suffix.'-'.$token);
        must($gate->inspect($encounter)['stale_acknowledgement_order_public_ids'] === [], 'closure clear after first acknowledgement '.$suffix);
        $amended = $service->amendVerified(
            $order->public_id, $radiologist, $verified->version,
            'CLINICAL_CLARIFICATION', 'Additional verified statement '.$suffix,
            'radiology-amend-'.$suffix.'-'.$token,
        )->record;
        must($amended instanceof RadiologyReportVersion && $amended->state === RadiologyReportVersion::AMENDED_VERIFIED, 'amended Verified '.$suffix);
        must($gate->inspect($encounter)['stale_acknowledgement_order_public_ids'] === [$order->public_id], 'closure stale after amendment '.$suffix);
        $service->acknowledge($order->public_id, $physician, $order->fresh()->version, $amended->version, 'radiology-ack-amended-'.$suffix.'-'.$token);
        must($gate->inspect($encounter)['stale_acknowledgement_order_public_ids'] === [], 'closure clear after current acknowledgement '.$suffix);
        return ['order' => $order->fresh(), 'draft' => $draft, 'verified' => $verified, 'amended' => $amended];
    }

    function runRadiologySequential(array $fixture, string $token): array
    {
        $admin = userByPublicId($fixture['admin']);
        $physician = userByPublicId($fixture['physician']);
        $technologist = userByPublicId($fixture['technologist']);
        $radiologist = userByPublicId($fixture['radiologist']);
        $policy = app(RadiologyActorPolicy::class);
        $policy->master($admin);
        $policy->order($physician);
        $policy->perform($technologist);
        $policy->write($radiologist);
        $policy->verify($radiologist);
        $policy->acknowledge($physician);
        $wrongRoleDenied = false;
        try {
            $policy->perform($physician);
        } catch (AuthorizationException) {
            $wrongRoleDenied = true;
        }
        must($wrongRoleDenied, 'exact-role wrong actor denied');

        $masters = app(RadiologyMasterService::class);
        $master = $masters->create($admin, 'RAD-PORT-'.$token, 'Portable radiology study', 'Synthetic preparation.', 'radiology-master-create-'.$token)->record;
        must($master instanceof RadiologyExaminationMaster && $master->version === 1, 'master create version 1');
        $master = $masters->revise($master->public_id, $admin, 1, 'Portable radiology study revised', 'Revised synthetic preparation.', RadiologyExaminationMaster::ACTIVE, 'radiology-master-update-'.$token)->record;
        must($master->version === 2 && $master->state === RadiologyExaminationMaster::ACTIVE, 'master update version 2');
        $raceMaster = $masters->create($admin, 'RAD-RACE-'.$token, 'Concurrent radiology study', null, 'radiology-race-master-'.$token)->record;

        $chains = [];
        foreach (['outpatient', 'emergency', 'inpatient'] as $setting) {
            $encounter = Encounter::query()->where('public_id', $fixture[$setting.'_encounter'])->sole();
            $chains[$setting] = radiologyLifecycle($GLOBALS['radiology_service'], $encounter, $master, $physician, $technologist, $radiologist, $token, $setting);
        }

        $cancelEncounter = Encounter::query()->where('public_id', $fixture['cancel_order_encounter'])->sole();
        $cancelOrder = $GLOBALS['radiology_service']->createOrder($cancelEncounter->public_id, $master->public_id, $physician, 'Cancel before performance', 'radiology-cancel-order-'.$token)->record;
        $cancelled = $GLOBALS['radiology_service']->cancel($cancelOrder->public_id, $physician, 1, 'CLINICAL_PLAN_CHANGED', null, 'radiology-cancel-'.$token)->record;
        must($cancelOrder->fresh()->status === RadiologyOrder::CANCELLED, 'pre-performance cancellation terminal');
        must($cancelled->radiology_order_id === $cancelOrder->id, 'cancellation evidence bound');

        $performedCancelDenied = false;
        try {
            $GLOBALS['radiology_service']->cancel($chains['outpatient']['order']->public_id, $physician, 3, 'ORDERING_ERROR', null, 'radiology-cancel-too-late-'.$token);
        } catch (RadiologyDenied $denial) {
            $performedCancelDenied = $denial->reason === 'order_not_ordered';
        }
        must($performedCancelDenied, 'performed/report order cancellation blocked');

        $activeEncounter = Encounter::query()->where('public_id', $fixture['active_blocker_encounter'])->sole();
        $activeOrder = $GLOBALS['radiology_service']->createOrder($activeEncounter->public_id, $raceMaster->public_id, $physician, 'Active closure blocker', 'radiology-active-order-'.$token)->record;
        $gate = app(RadiologyClosureGate::class)->inspect($activeEncounter);
        must($gate['active_order_public_ids'] === [$activeOrder->public_id], 'active order closure blocker');
        must(app(EncounterCancellationDependencyRegistry::class)->firstDenialReason($activeEncounter) === 'diagnostic_activity_exists', 'encounter cancellation diagnostic blocker');

        $masters->revise($master->public_id, $admin, 2, $master->display_name, $master->preparation_instruction, RadiologyExaminationMaster::RETIRED, 'radiology-master-retire-'.$token);
        must($master->fresh()->version === 3 && $master->fresh()->state === RadiologyExaminationMaster::RETIRED, 'master retired terminal version 3');
        $retiredDenied = false;
        try {
            $GLOBALS['radiology_service']->createOrder($fixture['retired_refusal_encounter'], $master->public_id, $physician, 'Retired master must refuse', 'radiology-retired-refusal-'.$token);
        } catch (RadiologyDenied $denial) {
            $retiredDenied = $denial->reason === 'master_not_active';
        }
        must($retiredDenied, 'retired master new order refused');

        $draftReplay = $GLOBALS['radiology_service']->saveDraft(
            $chains['outpatient']['order']->public_id, $radiologist, 0,
            'No acute abnormality outpatient', 'Stable radiology impression outpatient', null,
            'RADIOLOGY-DRAFT-OUTPATIENT-'.$token,
        );
        must($draftReplay->replayed && $draftReplay->record->public_id === $chains['outpatient']['draft']->public_id, 'exact Draft replay after later head advance');
        $changedPayloadDenied = false;
        try {
            $GLOBALS['radiology_service']->saveDraft(
                $chains['outpatient']['order']->public_id, $radiologist, 0,
                'Changed same-key payload', 'Stable radiology impression outpatient', null,
                'radiology-draft-outpatient-'.$token,
            );
        } catch (RadiologyDenied $denial) {
            $changedPayloadDenied = $denial->reason === 'idempotency_key_conflict';
        }
        must($changedPayloadDenied, 'changed payload same key conflict');

        return [
            'exact_role_boundary' => true,
            'all_three_care_settings' => array_keys($chains),
            'master_create_update_retire' => true,
            'terminal_workflow_chain' => true,
            'closure_and_cancellation_blockers' => true,
            'exact_replay_after_head_advance' => true,
            'changed_payload_key_conflict' => true,
            'race_master_public_id' => $raceMaster->public_id,
            'good_order_public_ids' => array_values(array_map(fn ($chain) => $chain['order']->public_id, $chains)),
        ];
    }

    function expectRadiologyRefusal(callable $operation, string $label): void
    {
        try {
            $operation();
        } catch (Throwable) {
            return;
        }
        throw new RuntimeException('expected radiology refusal: '.$label);
    }

    function runRadiologyApplicationGuards(): array
    {
        $guard = app(RadiologySqlWriteGuard::class);
        foreach ([
            'snapshot_update' => "UPDATE radiology_orders SET care_setting='INPATIENT'",
            'evidence_delete' => 'DELETE FROM radiology_report_versions',
            'evidence_truncate' => 'TRUNCATE TABLE radiology_operation_receipts',
        ] as $label => $sql) {
            expectRadiologyRefusal(fn () => $guard->assertAllowed($sql), $label);
        }
        return ['application_sql_guard_refusals' => 3];
    }

    function runRadiologyDatabaseGuards(array $fixture): array
    {
        $order = RadiologyOrder::query()->where('public_id', $fixture['good_order_public_ids'][0])->sole();
        $version = RadiologyReportVersion::query()->where('radiology_order_id', $order->id)->orderBy('version')->firstOrFail();
        $statements = [
            'snapshot_update' => ["UPDATE ".SchemaQualifier::table('radiology_orders')." SET care_setting='INPATIENT' WHERE id=?", [$order->id]],
            'evidence_delete' => ["DELETE FROM ".SchemaQualifier::table('radiology_report_versions')." WHERE id=?", [$version->id]],
            'evidence_truncate' => ["TRUNCATE TABLE ".SchemaQualifier::table('radiology_orders'), []],
        ];
        foreach ($statements as $label => [$sql, $bindings]) {
            expectRadiologyRefusal(
                fn () => RadiologyMutationScope::run(fn () => DB::statement($sql, $bindings)),
                $label,
            );
        }
        return ['database_snapshot_delete_truncate_refusals' => 3];
    }

    function runRadiologyPrivilegeGuards(array $fixture): array
    {
        $order = RadiologyOrder::query()->where('public_id', $fixture['good_order_public_ids'][0])->sole();
        $version = RadiologyReportVersion::query()->where('radiology_order_id', $order->id)->firstOrFail();
        foreach ([
            'append_only_update' => ["UPDATE ".SchemaQualifier::table('radiology_report_versions')." SET findings='forbidden' WHERE id=?", [$version->id]],
            'append_only_delete' => ["DELETE FROM ".SchemaQualifier::table('radiology_report_versions')." WHERE id=?", [$version->id]],
            'audit_delete' => ["DELETE FROM ".SchemaQualifier::table('audit_events')." WHERE action='radiology.workflow.mutate'", []],
            'head_delete' => ["DELETE FROM ".SchemaQualifier::table('radiology_orders')." WHERE id=?", [$order->id]],
        ] as $label => [$sql, $bindings]) {
            expectRadiologyRefusal(
                fn () => RadiologyMutationScope::run(fn () => DB::statement($sql, $bindings)),
                $label,
            );
        }
        return ['least_privilege_forbidden_writes' => 4];
    }

    function runRadiologyCorruptionRefusal(array $fixture, string $token): array
    {
        $physician = userByPublicId($fixture['physician']);
        $technologist = userByPublicId($fixture['technologist']);
        $radiologist = userByPublicId($fixture['radiologist']);
        $encounter = Encounter::query()->where('public_id', $fixture['corrupt_chain_encounter'])->sole();
        $master = RadiologyExaminationMaster::query()->where('public_id', $fixture['race_master_public_id'])->sole();
        $order = $GLOBALS['radiology_service']->createOrder($encounter->public_id, $master->public_id, $physician, 'Corruption-refusal fixture', 'radiology-corrupt-order-'.$token)->record;
        $GLOBALS['radiology_service']->perform($order->public_id, $technologist, 1, 'radiology-corrupt-perform-'.$token);
        $draft = $GLOBALS['radiology_service']->saveDraft($order->public_id, $radiologist, 0, 'Bound finding', 'Bound impression', null, 'radiology-corrupt-draft-'.$token)->record;
        $verified = $GLOBALS['radiology_service']->verify($order->public_id, $radiologist, $draft->version, 'radiology-corrupt-verify-'.$token)->record;
        RadiologyMutationScope::run(fn () => RadiologyReportVersion::query()->create([
            'radiology_order_id' => $order->id,
            'author_user_id' => $radiologist->id,
            'base_verified_version_id' => $verified->id,
            'version' => $verified->version + 1,
            'state' => RadiologyReportVersion::AMENDED_VERIFIED,
            'findings' => $verified->findings,
            'impression' => $verified->impression,
            'recommendation' => $verified->recommendation,
            'amendment_reason' => 'CLINICAL_CLARIFICATION',
            'amended_statement' => 'Injected invalid digest for refusal proof.',
            'base_verified_digest' => $verified->content_digest,
            'prior_amendment_digest' => null,
            'content_digest' => str_repeat('0', 64),
            'verified_at' => now(),
            'created_at' => now(),
        ]));
        $denied = false;
        try {
            $GLOBALS['radiology_service']->acknowledge($order->public_id, $physician, $order->fresh()->version, $verified->version + 1, 'radiology-corrupt-ack-'.$token);
        } catch (RadiologyDenied $denial) {
            $denied = $denial->reason === 'evidence_fingerprint_invalid';
        }
        must($denied, 'full chain corruption acknowledgement refusal');
        must($order->reportVersions()->whereHas('acknowledgement')->count() === 0, 'corrupt chain produced no acknowledgement');
        return ['full_chain_corruption_refusal' => true, 'corrupt_order_public_id' => $order->public_id];
    }

    function runRadiologyRace(array $fixture, string $token, int $holdMs): array
    {
        $physician = userByPublicId($fixture['physician']);
        return DB::transaction(function () use ($fixture, $token, $holdMs, $physician): array {
            $encounter = Encounter::query()->where('public_id', $fixture['race_encounter'])->lockForUpdate()->sole();
            protocol('HOLDING', ['backend_connection_id' => backendConnectionId()]);
            if ($holdMs > 0) {
                usleep($holdMs * 1000);
            }
            $result = $GLOBALS['radiology_service']->createOrder(
                $encounter->public_id,
                $fixture['race_master_public_id'],
                $physician,
                'Concurrent same-key indication',
                'radiology-same-key-race-'.$token,
            );
            return ['outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED', 'order_public_id' => $result->record->public_id];
        }, 3);
    }

    function verifyRadiologyRace(array $fixture, string $token): array
    {
        $encounter = Encounter::query()->where('public_id', $fixture['race_encounter'])->sole();
        $physician = userByPublicId($fixture['physician']);
        must($encounter->radiologyOrders()->count() === 1, 'same-key race one durable order');
        must(RadiologyOperationReceipt::query()->where('actor_user_id', $physician->id)->where('operation', 'RADIOLOGY_ORDER_CREATE')->where('idempotency_key', 'radiology-same-key-race-'.$token)->count() === 1, 'same-key race one unique receipt');
        return ['durable_orders' => 1, 'durable_unique_receipts' => 1];
    }

    function verifyRadiologyInvariants(array $fixture): array
    {
        $goodOrders = RadiologyOrder::query()->whereIn('public_id', $fixture['good_order_public_ids'])->get();
        must($goodOrders->count() === 3, 'three good setting orders');
        foreach ($goodOrders as $order) {
            must(in_array($order->care_setting, Encounter::CARE_SETTINGS, true), 'closed care-setting universe');
            must($order->reportVersions()->count() === 3, 'Draft Verified amendment chain');
            must($order->reportVersions()->whereHas('acknowledgement')->count() === 2, 'base and amended acknowledgements');
            must(app(RadiologyClosureGate::class)->inspect($order->encounter) === ['active_order_public_ids' => [], 'stale_acknowledgement_order_public_ids' => []], 'good chain closes cleanly');
        }
        $orphans = DB::table(SchemaQualifier::table('radiology_report_versions').' as v')
            ->leftJoin(SchemaQualifier::table('radiology_orders').' as o', 'o.id', '=', 'v.radiology_order_id')
            ->whereNull('o.id')->count();
        must($orphans === 0, 'no orphan report versions');
        return [
            'good_setting_orders' => 3,
            'good_report_versions' => 9,
            'good_acknowledgements' => 6,
            'orphan_report_versions' => 0,
            'durable_third_connection_assertions' => true,
        ];
    }

    function runRadiologyReset(array $fixture): array
    {
        $auditBefore = AuditEvent::query()->where('action', 'radiology.workflow.mutate')->count();
        must($auditBefore > 0, 'radiology audit exists before reset');
        app(SyntheticResetService::class)->reset([
            'actor' => userByPublicId($fixture['admin']),
            'reason' => 'bounded_radiology_portability_reset',
        ]);
        must(RadiologyOrder::query()->count() === 0, 'radiology orders removed');
        must(RadiologyReportVersion::query()->count() === 0, 'amendment-first report chain removed');
        must(RadiologyOperationReceipt::query()->where('result_type', '!=', RadiologyOperationReceipt::RESULT_MASTER)->count() === 0, 'workflow receipts removed');
        must(RadiologyExaminationMaster::query()->count() === 2, 'governed masters retained');
        must(AuditEvent::query()->where('action', 'radiology.workflow.mutate')->count() >= $auditBefore, 'radiology audit preserved');
        must(AuditEvent::query()->where('action', 'teaching.reset.completed')->exists(), 'reset audit preserved');
        return [
            'bounded_synthetic_deletion' => true,
            'amendment_first_deletion' => true,
            'workflow_rows_removed' => true,
            'master_evidence_retained' => true,
            'radiology_audit_preserved' => true,
            'reset_audit_preserved' => true,
        ];
    }

    $action = (string) getenv('SIMRS_RADIOLOGY_ACTION');
    $scenario = (string) getenv('SIMRS_RADIOLOGY_SCENARIO');
    $worker = (string) getenv('SIMRS_RADIOLOGY_WORKER');
    $token = (string) getenv('SIMRS_RADIOLOGY_RUN_TOKEN');
    $holdMs = (int) getenv('SIMRS_RADIOLOGY_HOLD_MS');
    $GLOBALS['simrs_discharge_failure_stage'] = 'bootstrap';
    try {
        $root = realpath((string) getenv('SIMRS_REHEARSAL_ROOT'));
        if ($root === false || $root === '' || !is_file($root.'/artisan')) {
            throw new RuntimeException('repository root refused');
        }
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        must(config('simulation.mode') === 'SIMULATION', 'SIMULATION mode');
        must(config('simulation.synthetic_only') === true, 'synthetic-only mode');
        foreach (['BPJS_INTEGRATION_ENABLED', 'VCLAIM_ENABLED', 'SATUSEHAT_ENABLED'] as $flag) {
            must(getenv($flag) === 'false', $flag.' disabled');
        }
        $GLOBALS['radiology_service'] = app(RadiologyWorkflowService::class);
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        $fixture = fixture();
        $result = match ($action) {
            'prepare' => ['fixture' => prepareRadiologyFixture($token)],
            'sequential' => runRadiologySequential($fixture, $token),
            'application_guards' => runRadiologyApplicationGuards(),
            'database_guards' => runRadiologyDatabaseGuards($fixture),
            'privilege_guards' => runRadiologyPrivilegeGuards($fixture),
            'corrupt' => runRadiologyCorruptionRefusal($fixture, $token),
            'race' => runRadiologyRace($fixture, $token, $holdMs),
            'verify_race' => verifyRadiologyRace($fixture, $token),
            'verify' => verifyRadiologyInvariants($fixture),
            'reset' => runRadiologyReset($fixture),
            default => throw new RuntimeException('unsupported radiology action'),
        };
        protocol('COMMITTED', $action === 'prepare' ? $result : ['result' => $result] + $result);
    } catch (Throwable $exception) {
        echo json_encode([
            'schema_version' => 1,
            'status' => 'BLOCKED',
            'protocol_state' => 'FAILED',
            'scenario' => $scenario,
            'worker' => $worker,
            'exception_class' => get_class($exception),
            'exception_fingerprint' => hash('sha256', get_class($exception)."\0".$exception->getMessage()),
            'sql_state' => $exception instanceof \Illuminate\Database\QueryException ? (string) ($exception->errorInfo[0] ?? '') : '',
            'driver_code' => $exception instanceof \Illuminate\Database\QueryException ? (string) ($exception->errorInfo[1] ?? '') : '',
            'query_head' => $exception instanceof \Illuminate\Database\QueryException ? mb_substr((string) preg_replace('/\s+/', ' ', $exception->getSql()), 0, 240) : '',
            'failure_stage' => currentFailureStage(),
        ], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
  PHP

  def initialize(engine:, environment: ENV.to_h, runner: Runner.new, monotonic_clock: nil)
    @operator_environment = environment.dup
    LocalPortabilityFullSuiteRehearsal.instance_method(:initialize).bind(self).call(
      engine: engine,
      environment: environment.merge('SIMRS_PORTABILITY_REHEARSAL_CONFIRM' => LocalPortabilityFullSuiteRehearsal::CONFIRMATION),
      runner: runner,
      monotonic_clock: monotonic_clock
    )
    @workers = []
    @command_catalog = []
    @result_catalog = []
    @protocol_catalog = []
    @worker_tempfile = nil
    @runtime_application_environment = nil
    @reset_application_environment = nil
  end

  def assert_contract!
    unless @operator_environment[CONFIRMATION_ENV] == CONFIRMATION
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize the disposable local rehearsal."
    end
    rejected = FORBIDDEN_ENVIRONMENT.select { |name| !@operator_environment.fetch(name, '').to_s.strip.empty? }
    raise CommandFailed, "Radiology rehearsal refuses inherited overrides: #{rejected.join(', ')}." unless rejected.empty?
    LocalPortabilityFullSuiteRehearsal.instance_method(:assert_contract!).bind(self).call
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    raise CommandFailed, 'Radiology scenario catalogue drifted.' unless SCENARIOS.length == 17 && SCENARIOS.uniq.length == 17
    raise CommandFailed, 'Embedded radiology worker source is unexpectedly small.' unless WORKER_SOURCE.bytesize > 30_000
  end

  def application_environment
    LocalPortabilityFullSuiteRehearsal.instance_method(:application_environment).bind(self).call.merge(
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false',
      'VCLAIM_ENABLED' => 'false',
      'SATUSEHAT_ENABLED' => 'false'
    )
  end

  def current_radiology_bindings
    files = SOURCE_PATHS.to_h { |path| [path, Digest::SHA256.file(safe_source_path(path)).hexdigest] }
    {
      'files' => files,
      'aggregate_sha256' => Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h)),
      'worker_source_sha256' => Digest::SHA256.hexdigest(WORKER_SOURCE),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)),
      'runtime_grant_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(RUNTIME_TABLE_GRANTS)),
    }
  end

  def run!
    assert_contract!
    bindings = current_radiology_bindings
    engine_binding = prepare_engine!
    @run_token = database_run_token
    create_worker_file!
    started = @clock.call
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(started)
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    recorded_artisan!('migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    recorded_artisan!('migrate', '--path='+MIGRATION_PATH, '--force', '--no-interaction')

    prepared = run_worker_command!(action: 'prepare', scenario: 'fresh-migration', worker: 'PREPARE', connection_environment: application_environment)
    fixture = prepared.fetch('fixture')
    provision_runtime_identities!
    sequential = run_worker_command!(action: 'sequential', scenario: 'order-perform-draft-verify-amend-acknowledge', worker: 'SEQUENTIAL', fixture: fixture)
    fixture['race_master_public_id'] = sequential.fetch('race_master_public_id')
    fixture['good_order_public_ids'] = sequential.fetch('good_order_public_ids')
    application_guards = run_worker_command!(action: 'application_guards', scenario: 'application-sql-guard-refusal', worker: 'APP_GUARDS', fixture: fixture)
    database_guards = run_worker_command!(action: 'database_guards', scenario: 'database-snapshot-delete-truncate-refusal', worker: 'OWNER_GUARDS', fixture: fixture, connection_environment: application_environment)
    privilege_guards = run_worker_command!(action: 'privilege_guards', scenario: 'least-privilege-runtime', worker: 'RUNTIME_GUARDS', fixture: fixture)
    corruption = run_worker_command!(action: 'corrupt', scenario: 'full-chain-corruption-refusal', worker: 'CORRUPTION', fixture: fixture)
    fixture['corrupt_order_public_id'] = corruption.fetch('corrupt_order_public_id')
    race = run_radiology_race!(fixture)
    invariants = run_worker_command!(action: 'verify', scenario: 'invariant-verification', worker: 'VERIFY', fixture: fixture)
    reset = run_worker_command!(action: 'reset', scenario: 'bounded-reset-amendment-first-audit-preservation', worker: 'RESET', fixture: fixture, connection_environment: @reset_application_environment)
    down_refusal = expect_radiology_rollback_refusal!('correlated audit evidence exists')

    scenarios = {
      'fresh-migration' => { 'status' => 'PASS', 'fresh_apply' => true },
      'empty-down-reapply' => { 'status' => 'PASS', 'empty_down' => true, 'reapply' => true },
      'exact-role-boundary' => slice_result(sequential, %w[exact_role_boundary]),
      'all-three-care-settings' => slice_result(sequential, %w[all_three_care_settings]),
      'master-create-update-retire' => slice_result(sequential, %w[master_create_update_retire]),
      'order-perform-draft-verify-amend-acknowledge' => slice_result(sequential, %w[terminal_workflow_chain]),
      'closure-and-cancellation-blockers' => slice_result(sequential, %w[closure_and_cancellation_blockers]),
      'exact-replay-after-head-advance' => slice_result(sequential, %w[exact_replay_after_head_advance]),
      'changed-payload-key-conflict' => slice_result(sequential, %w[changed_payload_key_conflict]),
      'same-key-concurrency-unique-reconciliation' => race,
      'application-sql-guard-refusal' => slice_result(application_guards, %w[application_sql_guard_refusals]),
      'database-snapshot-delete-truncate-refusal' => slice_result(database_guards, %w[database_snapshot_delete_truncate_refusals]),
      'full-chain-corruption-refusal' => slice_result(corruption, %w[full_chain_corruption_refusal]),
      'least-privilege-runtime' => {
        'status' => 'PASS',
        'reference_prerequisites' => 'SELECT_ONLY',
        'encounter_lock_head' => 'SELECT_UPDATE_NO_INSERT_DELETE',
        'mutable_heads' => 'SELECT_INSERT_UPDATE_NO_DELETE',
        'append_only_evidence_and_audit' => 'SELECT_INSERT_ONLY',
        'reset_identity_separate' => true,
        'forbidden_writes' => privilege_guards,
      },
      'bounded-reset-amendment-first-audit-preservation' => slice_result(reset, %w[bounded_synthetic_deletion amendment_first_deletion workflow_rows_removed master_evidence_retained radiology_audit_preserved reset_audit_preserved]),
      'retained-evidence-rollback-refusal' => { 'status' => 'PASS', 'refusal' => down_refusal },
      'invariant-verification' => slice_result(invariants, %w[good_setting_orders good_report_versions good_acknowledgements orphan_report_versions durable_third_connection_assertions]),
    }
    raise CommandFailed, 'Radiology scenario result catalogue drifted.' unless scenarios.keys == SCENARIOS
    assert_unchanged_binding!('Radiology execution bindings', bindings, current_radiology_bindings)
    cleanup!(strict: true)
    remove_worker_file!
    evidence_path = write_radiology_evidence!(bindings: bindings, engine_binding: engine_binding, migration_duration_ms: migration_duration_ms, scenarios: scenarios)
    {
      'status' => 'PASS',
      'claim' => 'LOCAL_DISPOSABLE_CROSS_SETTING_RADIOLOGY_ONLY',
      'engine' => @engine,
      'scenario_count' => scenarios.length,
      'evidence_path' => evidence_path,
    }
  ensure
    terminate_workers!
    remove_worker_file!
    cleanup!
  end

  private

  def recorded_artisan!(*arguments)
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(
      @runner.process_environment(application_environment),
      @php_binary, File.join(ROOT, 'artisan'), *arguments,
      unsetenv_others: true
    )
    unless status.success?
      combined = stdout + stderr
      diagnostic = combined.lines.select do |line|
        line.match?(/(?:ERROR|SQLSTATE|QueryException|Syntax error|Migration name|at database\/migrations)/i)
      end
      diagnostic = combined.lines.first(20) + combined.lines.last(20) if diagnostic.empty?
      raise CommandFailed, "artisan #{arguments.first} failed: #{@runner.sanitize(diagnostic.join)}"
    end
    @result_catalog << [['artisan', *arguments], 'PASS']
  end

  def create_worker_file!
    @worker_tempfile = Tempfile.new(['simrs-radiology-portability-', '.php'])
    @worker_tempfile.binmode
    @worker_tempfile.write(WORKER_SOURCE)
    @worker_tempfile.flush
    @worker_tempfile.chmod(0o600)
    @worker_tempfile.close
  end

  def worker_environment(action:, scenario:, worker:, fixture:, hold_ms:, connection_environment: nil)
    (connection_environment || @runtime_application_environment || application_environment).merge(
      'SIMRS_REHEARSAL_ROOT' => ROOT,
      'SIMRS_RADIOLOGY_ACTION' => action,
      'SIMRS_RADIOLOGY_SCENARIO' => scenario,
      'SIMRS_RADIOLOGY_WORKER' => worker,
      'SIMRS_RADIOLOGY_RUN_TOKEN' => @run_token,
      'SIMRS_RADIOLOGY_HOLD_MS' => hold_ms.to_s,
      'SIMRS_DISCHARGE_SCENARIO' => scenario,
      'SIMRS_DISCHARGE_WORKER' => worker,
      'SIMRS_DISCHARGE_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {}))
    )
  end

  def provision_postgres_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    raise CommandFailed, 'Generated PostgreSQL identities failed closed pattern.' unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
    tables = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT tablename FROM pg_tables WHERE schemaname='laravel' ORDER BY tablename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    sequences = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT sequencename FROM pg_sequences WHERE schemaname='laravel' ORDER BY sequencename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "PostgreSQL runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = [%(CREATE ROLE "#{runtime}" LOGIN), %(CREATE ROLE "#{reset}" LOGIN), %(GRANT CONNECT ON DATABASE "#{@postgres_database}" TO "#{runtime}", "#{reset}"), %(GRANT USAGE ON SCHEMA "laravel" TO "#{runtime}", "#{reset}")]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << %(GRANT #{grants} ON TABLE "laravel"."#{table}" TO "#{runtime}") }
    tables.each do |table|
      reset_grants = table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'
      statements << %(GRANT #{reset_grants} ON TABLE "laravel"."#{table}" TO "#{reset}")
    end
    insert_tables = RUNTIME_TABLE_GRANTS.select { |_table, grants| grants.include?('INSERT') }.keys
    sequences.each do |sequence|
      statements << %(GRANT USAGE, SELECT ON SEQUENCE "laravel"."#{sequence}" TO "#{runtime}") if insert_tables.any? { |table| sequence == "#{table}_id_seq" }
      statements << %(GRANT USAGE, SELECT, UPDATE ON SEQUENCE "laravel"."#{sequence}" TO "#{reset}")
    end
    @runner.run!(postgres_psql_arguments(@postgres_database) + ['--set', 'ON_ERROR_STOP=1', '--command', statements.join(";\n")+';'], env: postgres_tool_environment)
    RUNTIME_TABLE_GRANTS.each do |table, expected_grants|
      privileges = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name='#{table}'"], env: postgres_tool_environment).strip
      raise CommandFailed, "PostgreSQL runtime grant drifted for #{table}." unless privileges == expected_grants.split(', ').sort.join(',')
    end
    unexpected = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT table_name FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name NOT IN (#{RUNTIME_TABLE_GRANTS.keys.map { |table| "'#{table}'" }.join(',')}) ORDER BY table_name"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    raise CommandFailed, "PostgreSQL runtime has grants outside the closed map: #{unexpected.join(', ')}." unless unexpected.empty?
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => '')
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => '')
  end

  def provision_mysql_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    raise CommandFailed, 'Generated MySQL identities failed closed pattern.' unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
    runtime_password = SecureRandom.hex(24)
    reset_password = SecureRandom.hex(24)
    tables = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='#{@mysql_database}' ORDER BY TABLE_NAME;").lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "MySQL runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = ["CREATE USER '#{runtime}'@'127.0.0.1' IDENTIFIED BY '#{runtime_password}'", "CREATE USER '#{reset}'@'127.0.0.1' IDENTIFIED BY '#{reset_password}'"]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << "GRANT #{grants} ON `#{@mysql_database}`.`#{table}` TO '#{runtime}'@'127.0.0.1'" }
    tables.each do |table|
      reset_grants = table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'
      statements << "GRANT #{reset_grants} ON `#{@mysql_database}`.`#{table}` TO '#{reset}'@'127.0.0.1'"
    end
    statements << 'FLUSH PRIVILEGES'
    @runner.run!(mysql_root_arguments, stdin_data: statements.join(";\n")+';')
    grants = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SHOW GRANTS FOR '#{runtime}'@'127.0.0.1';").upcase
    raise CommandFailed, 'Reduced MySQL runtime retained forbidden DDL grants.' unless %w[DROP ALTER TRIGGER CREATE].none? { |privilege| grants.match?(/\b#{privilege}\b/) }
    RUNTIME_TABLE_GRANTS.each do |table, expected_grants|
      expected = "GRANT #{expected_grants} ON `#{@mysql_database.upcase}`.`#{table.upcase}`"
      raise CommandFailed, "MySQL runtime grant drifted for #{table}." unless grants.include?(expected)
    end
    raise CommandFailed, 'MySQL runtime received a schema wildcard grant.' if grants.include?("ON `#{@mysql_database.upcase}`.*")
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => runtime_password)
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => reset_password)
  end

  def run_radiology_race!(fixture)
    scenario = 'same-key-concurrency-unique-reconciliation'
    first = start_race_worker!(fixture: fixture, scenario: scenario, worker: 'A', hold_ms: HOLD_MS)
    await_protocol!(first, 'STARTED')
    await_protocol!(first, 'HOLDING')
    second = start_race_worker!(fixture: fixture, scenario: scenario, worker: 'B', hold_ms: 0)
    second_started = await_protocol!(second, 'STARTED')
    wait_observed = observe_real_database_wait!(Integer(second_started.fetch('backend_connection_id')))
    raise CommandFailed, 'Same-key radiology race did not expose a real database wait.' unless wait_observed
    outcomes = [await_final!(first).fetch('outcome'), await_final!(second).fetch('outcome')].sort
    raise CommandFailed, 'Unexpected radiology same-key race reconciliation.' unless outcomes == %w[APPLIED REPLAYED]
    verified = run_worker_command!(action: 'verify_race', scenario: scenario, worker: 'VERIFY_RACE', fixture: fixture)
    {
      'status' => 'PASS',
      'proof_kind' => 'OBSERVED_DATABASE_RACE',
      'independent_application_processes' => 2,
      'real_database_wait_observed' => true,
      'outcomes' => outcomes,
      'unique_constraint_reconciliation' => true,
      'durable_third_connection_assertions' => true,
      'durable_assertions' => verified.fetch('result'),
    }
  ensure
    terminate_workers!
  end

  def expect_radiology_rollback_refusal!(expected)
    arguments = ['migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(@runner.process_environment(application_environment), @php_binary, File.join(ROOT, 'artisan'), *arguments, unsetenv_others: true)
    raise CommandFailed, 'Retained radiology evidence unexpectedly rolled back.' if status.success?
    combined = stdout + stderr
    raise CommandFailed, 'Radiology rollback failed for an unexpected reason.' unless combined.gsub(/\s+/, '').include?(expected.gsub(/\s+/, ''))
    @result_catalog << [['artisan', *arguments], 'EXPECTED_REFUSAL']
    'PASS'
  end

  def write_radiology_evidence!(bindings:, engine_binding:, migration_duration_ms:, scenarios:)
    assert_evidence_directory!
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-radiology-#{SecureRandom.hex(6)}.json")
    evidence = {
      'schema_version' => 1,
      'kind' => EVIDENCE_KIND,
      'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_CROSS_SETTING_RADIOLOGY_ONLY',
      'hosted_readiness_claim' => false,
      'deployment_claim' => false,
      'owner_acceptance_claim' => false,
      'source_bindings' => bindings.merge(
        'command_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@command_catalog)),
        'result_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@result_catalog))
      ),
      'command_catalog' => @command_catalog,
      'protocol_result_catalog' => @protocol_catalog,
      'boundary' => {
        'application_mode' => 'SIMULATION',
        'synthetic_only' => true,
        'live_integrations_enabled' => false,
        'disposable_local_engine' => true,
        'external_database_configuration_accepted' => false,
      },
      'engine' => engine_binding,
      'migration' => { 'fresh_apply' => 'PASS', 'duration_ms_observed' => migration_duration_ms },
      'scenarios' => scenarios,
      'cleanup' => {
        'database_removed' => true,
        'temporary_server_removed' => true,
        'temporary_user_state_removed' => true,
        'temporary_worker_removed' => true,
      },
      'open_boundaries' => [
        'Local disposable-engine evidence only; no deployment, hosted migration, UAT, radiology-owner acceptance, or production-readiness claim.',
        'No external radiology, PACS, RIS, BPJS, VClaim, SATUSEHAT, mail, or production integration is exercised.',
      ],
    }
    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence)+"\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    path
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalRadiologyPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalRadiologyPortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalRadiologyPortabilityRehearsal::CommandFailed => e
    warn "radiology portability rehearsal failed: #{e.message}"
    exit 1
  end
end
