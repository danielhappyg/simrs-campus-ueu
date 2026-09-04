#!/usr/bin/env ruby
# frozen_string_literal: true

require 'base64'
require 'digest'
require 'json'
require 'open3'
require 'securerandom'
require 'tempfile'
require 'time'

require_relative 'rehearse-local-finance-tariff-component-master-portability'

# Fail-closed exact-engine evidence for prospectively versioned inpatient bed
# placement, closed occupancy days, governed tariffs, typed charges and bills.
# SQLite is an application gate only; exact-engine waits belong to disposable
# PostgreSQL 17 and MySQL 8.4 instances owned by this harness.
class LocalInpatientAccommodationTariffSourcePortabilityRehearsal < LocalFinanceTariffComponentMasterPortabilityRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE'
  CONFIRMATION_ENV = 'SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-inpatient-accommodation-tariff-source-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalInpatientAccommodationTariffSourcePortabilityHarnessContractTest.rb'
  PROVENANCE_MIGRATION_PATH = 'database/migrations/2026_09_02_000500_add_bed_version_provenance_to_inpatient_location_events.php'
  MIGRATION_PATH = 'database/migrations/2026_09_02_000600_create_inpatient_accommodation_tariff_source.php'
  EVIDENCE_TEMPLATE_PATH = 'docs/operations/T1_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_EVIDENCE_TEMPLATE_2026-09-02.md'
  EVIDENCE_KIND = 'SIMRS_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_PORTABILITY'
  EXECUTION_STATE = 'READY_NOT_RUN'

  SCENARIOS = %w[
    fresh-migration empty-down-reapply exact-role-boundary
    prospective-bed-version-provenance database-provenance-trigger-refusal
    legacy-provenance-no-inference occupancy-day-calendar-allocation
    admission-census-cancellation-open-interval-no-charge
    exact-effective-dated-binding future-half-open-terminal-retirement
    exact-context-and-positive-integer-refusal four-domain-typed-union
    closed-interval-source-materialization open-interval-partial-sync-issue-refusal
    routine-discharge-complete-bill replay-conflict-retroactive-stale-refusal
    same-service-day-import-concurrency transfer-synchronization-concurrency
    discharge-issue-cutoff-concurrency bed-version-binding-resolution-concurrency
    competing-binding-writer-concurrency application-sql-guard-refusal
    database-append-only-head-trigger-check-refusal
    audit-corruption-reconciliation-refusal least-privilege-runtime
    reset-recovery-concurrency retained-evidence-rollback-refusal strict-cleanup
  ].freeze

  INPATIENT_ACCOMMODATION_TABLES = %w[
    finance_accommodation_tariff_bindings finance_accommodation_tariff_binding_versions
    finance_accommodation_tariff_operation_receipts finance_accommodation_source_events
  ].freeze
  RUNTIME_READ_TABLES = %w[
    users roles permissions role_user permission_role patients encounters
    inpatient_wards inpatient_ward_versions inpatient_beds inpatient_bed_versions
    inpatient_location_events inpatient_discharges inpatient_discharge_summaries
    inpatient_discharge_summary_versions inpatient_discharge_coding_sources
    inpatient_discharge_coding_source_versions inpatient_patient_claim_mutexes
    inpatient_location_operation_receipts inpatient_discharge_summary_operation_receipts
    inpatient_discharge_coding_source_operation_receipts inpatient_discharge_operation_receipts
    finance_cost_component_groups finance_cost_component_group_versions
    finance_cost_components finance_cost_component_versions
    finance_tariff_catalogues finance_tariff_catalogue_versions
    finance_tariff_items finance_tariff_item_versions
    finance_accommodation_tariff_bindings finance_accommodation_tariff_binding_versions
    finance_accommodation_tariff_operation_receipts finance_accommodation_source_events
    finance_charge_events finance_bills finance_bill_versions finance_bill_lines
    finance_operation_receipts audit_events
  ].freeze
  RUNTIME_TABLE_GRANTS = RUNTIME_READ_TABLES.to_h { |table| [table, 'SELECT'] }.freeze

  SOURCE_PATHS = %w[
    scripts/rehearse-local-inpatient-accommodation-tariff-source-portability.rb
    scripts/rehearse-local-finance-tariff-component-master-portability.rb
    scripts/rehearse-local-finance-billing-portability.rb
    scripts/rehearse-local-inpatient-transfer-portability.rb
    scripts/rehearse-local-portability-full-suite.rb
    tests/Documentation/LocalInpatientAccommodationTariffSourcePortabilityHarnessContractTest.rb
    tests/Documentation/InpatientAccommodationOccupancyDayTariffSourceV1LocalEngineeringAuthorizationTest.rb
    docs/new-simrs-rebuild/phase-1/INPATIENT_ACCOMMODATION_OCCUPANCY_DAY_TARIFF_SOURCE_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md
    docs/operations/T1_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_EVIDENCE_TEMPLATE_2026-09-02.md
    database/migrations/2026_09_02_000500_add_bed_version_provenance_to_inpatient_location_events.php
    database/migrations/2026_09_02_000600_create_inpatient_accommodation_tariff_source.php
    tests/Feature/Inpatient/InpatientLocationBedVersionProvenanceTest.php
    tests/Feature/Finance/FinanceAccommodationTariffCoreTest.php
    tests/Feature/Finance/FinanceAccommodationOccupancyEvidenceTest.php
    tests/Feature/Finance/FinanceAccommodationSourceMaterializationTest.php
    tests/Feature/Finance/FinanceAccommodationTariffHttpWorkflowTest.php
    tests/Feature/Operations/FinanceAccommodationRecoverySnapshotTest.php
    tests/Feature/Simulation/FinanceTariffResetTest.php
    tests/Feature/Database/FinanceTariffSqlWriteGuardTest.php
    tests/Feature/Authorization/FinanceTariffAccessTest.php
    tests/Unit/Finance/FinanceAccommodationOccupancyDayAllocatorTest.php
    app/Models/FinanceAccommodationTariffBinding.php
    app/Models/FinanceAccommodationTariffBindingVersion.php
    app/Models/FinanceAccommodationTariffOperationReceipt.php
    app/Models/FinanceAccommodationSourceEvent.php
    app/Support/Finance/FinanceAccommodationTariffActorPolicy.php
    app/Support/Finance/FinanceAccommodationTariffAppendOnlyGuard.php
    app/Support/Finance/FinanceAccommodationTariffBindingService.php
    app/Support/Finance/FinanceAccommodationTariffContentDigest.php
    app/Support/Finance/FinanceAccommodationTariffMutableHeadGuard.php
    app/Support/Finance/FinanceAccommodationTariffMutationScope.php
    app/Support/Finance/FinanceAccommodationTariffProjection.php
    app/Support/Finance/FinanceAccommodationTariffSqlWriteGuard.php
    app/Support/Finance/FinanceAccommodationSourceAdapter.php
    app/Support/Finance/FinanceAccommodationOccupancyDayAllocator.php
    app/Support/Finance/FinanceSourceCoordinator.php
    app/Support/Finance/FinanceBillService.php
    app/Support/Operations/SyntheticRecoverySnapshot.php
    app/Support/Simulation/SyntheticResetService.php
  ].freeze

  FEATURE_SCENARIO_TESTS = {
    'exact-role-boundary' => ['tests/Feature/Finance/FinanceAccommodationTariffHttpWorkflowTest.php', 'test_exact_finance_steward_can_create_and_cashier_can_only_read_the_mapping'],
    'prospective-bed-version-provenance' => ['tests/Feature/Inpatient/InpatientLocationBedVersionProvenanceTest.php', 'test_admission_and_transfer_retain_exact_versions_and_prior_destination_continuity_after_bed_revision'],
    'database-provenance-trigger-refusal' => ['tests/Feature/Inpatient/InpatientLocationBedVersionProvenanceTest.php', 'test_schema_is_nullable_fk_backed_and_declares_postgres_mysql_safe_guards'],
    'legacy-provenance-no-inference' => ['tests/Feature/Finance/FinanceAccommodationOccupancyEvidenceTest.php', 'test_legacy_location_without_exact_version_is_refused_without_inference'],
    'occupancy-day-calendar-allocation' => ['tests/Unit/Finance/FinanceAccommodationOccupancyDayAllocatorTest.php', 'test_multiple_same_day_and_multi_day_transfers_keep_one_encounter_date_anchor'],
    'admission-census-cancellation-open-interval-no-charge' => ['tests/Feature/Finance/FinanceAccommodationOccupancyEvidenceTest.php', 'test_open_admission_is_reported_without_materializing_a_source'],
    'exact-effective-dated-binding' => ['tests/Feature/Finance/FinanceAccommodationTariffCoreTest.php', 'test_binding_replay_history_resolution_and_terminal_retirement_are_exact'],
    'future-half-open-terminal-retirement' => ['tests/Feature/Finance/FinanceAccommodationTariffCoreTest.php', 'test_binding_replay_history_resolution_and_terminal_retirement_are_exact'],
    'exact-context-and-positive-integer-refusal' => ['tests/Feature/Finance/FinanceAccommodationTariffCoreTest.php', 'test_missing_or_corrupt_exact_version_refuses_without_source_materialization'],
    'four-domain-typed-union' => ['tests/Feature/Operations/FinanceAccommodationRecoverySnapshotTest.php', 'test_snapshot_registers_accommodation_counts_digests_orphans_integrity_and_exact_four_domain_union'],
    'closed-interval-source-materialization' => ['tests/Feature/Finance/FinanceAccommodationSourceMaterializationTest.php', 'test_governed_routine_discharge_closes_and_bill_sync_materializes_complete_daily_sources'],
    'open-interval-partial-sync-issue-refusal' => ['tests/Feature/Finance/FinanceAccommodationOccupancyEvidenceTest.php', 'test_open_admission_is_reported_without_materializing_a_source'],
    'routine-discharge-complete-bill' => ['tests/Feature/Finance/FinanceAccommodationSourceMaterializationTest.php', 'test_governed_routine_discharge_closes_and_bill_sync_materializes_complete_daily_sources'],
    'replay-conflict-retroactive-stale-refusal' => ['tests/Feature/Finance/FinanceAccommodationTariffCoreTest.php', 'test_binding_replay_history_resolution_and_terminal_retirement_are_exact'],
    'application-sql-guard-refusal' => ['tests/Feature/Database/FinanceTariffSqlWriteGuardTest.php', 'test_guard_refuses_ddl_write_cte_executable_comments_and_multiple_statements'],
    'database-append-only-head-trigger-check-refusal' => ['tests/Feature/Finance/FinanceAccommodationTariffCoreTest.php', 'test_model_sql_and_append_only_guards_preserve_reset_seam'],
    'audit-corruption-reconciliation-refusal' => ['tests/Feature/Operations/FinanceAccommodationRecoverySnapshotTest.php', 'test_integrity_helpers_reconcile_a_real_closed_occupancy_source'],
    'least-privilege-runtime' => ['tests/Feature/Authorization/FinanceTariffAccessTest.php', 'test_policy_allows_steward_management_and_cashier_read_only_without_admin_or_mixed_role_bypass'],
    'reset-recovery-concurrency' => ['tests/Feature/Operations/FinanceAccommodationRecoverySnapshotTest.php', 'test_reset_removes_accommodation_graph_before_inpatient_and_tariff_parents_and_preserves_audit'],
  }.freeze
  SQLITE_FEATURE_TESTS = (FEATURE_SCENARIO_TESTS.values + [
    ['tests/Feature/Inpatient/InpatientLocationBedVersionProvenanceTest.php', 'test_database_guard_rejects_digest_drift_and_reset_still_deletes_valid_provenance'],
    ['tests/Feature/Finance/FinanceAccommodationSourceMaterializationTest.php', 'test_open_final_interval_materializes_closed_days_once_with_historical_tariffs_and_tamper_refusal'],
    ['tests/Feature/Finance/FinanceAccommodationSourceMaterializationTest.php', 'test_governed_routine_discharge_closes_and_bill_sync_materializes_complete_daily_sources'],
    ['tests/Feature/Operations/FinanceAccommodationRecoverySnapshotTest.php', 'test_integrity_helpers_detect_binding_receipt_occupancy_source_and_typed_charge_corruption'],
  ]).uniq.freeze

  WORKER_SOURCE = <<~'PHP'
    <?php
    declare(strict_types=1);

    use App\Models\Encounter;
    use App\Models\FinanceAccommodationSourceEvent;
    use App\Models\FinanceAccommodationTariffBinding;
    use App\Models\FinanceBill;
    use App\Models\FinanceChargeEvent;
    use App\Models\FinanceCostComponent;
    use App\Models\FinanceCostComponentGroup;
    use App\Models\FinanceTariffCatalogue;
    use App\Models\FinanceTariffItem;
    use App\Models\InpatientBed;
    use App\Models\InpatientBedVersion;
    use App\Models\InpatientDischargeCodingSource;
    use App\Models\InpatientDischargeSummary;
    use App\Models\Patient;
    use App\Models\Role;
    use App\Models\User;
    use App\Support\Authorization\RoleCapabilityMatrix;
    use App\Support\Finance\FinanceAccommodationSourceAdapter;
    use App\Support\Finance\FinanceAccommodationTariffBindingService;
    use App\Support\Finance\FinanceAccommodationTariffMutationScope;
    use App\Support\Finance\FinanceAccommodationTariffProjection;
    use App\Support\Finance\FinanceBillService;
    use App\Support\Finance\FinanceDenied;
    use App\Support\Finance\FinanceMutationScope;
    use App\Support\Finance\FinanceProjection;
    use App\Support\Finance\FinanceTariffDenied;
    use App\Support\Finance\FinanceTariffMasterService;
    use App\Support\Database\SchemaQualifier;
    use App\Support\Inpatient\InpatientBedTransferService;
    use App\Support\Inpatient\InpatientDischargeCodingSourceService;
    use App\Support\Inpatient\InpatientDischargeService;
    use App\Support\Inpatient\InpatientDischargeSummaryService;
    use App\Support\Inpatient\InpatientMasterService;
    use App\Support\Inpatient\InpatientLocationMutationScope;
    use App\Support\Operations\SyntheticRecoverySnapshot;
    use App\Support\Simulation\SyntheticResetService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\QueryException;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;

    require (string) getenv('SIMRS_REHEARSAL_ROOT').'/vendor/autoload.php';
    $app = require (string) getenv('SIMRS_REHEARSAL_ROOT').'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    function must(bool $condition, string $label): void
    {
        if (! $condition) throw new RuntimeException('assertion failed: '.$label);
    }

    function protocol(string $state, array $extra = []): void
    {
        echo json_encode(array_merge([
            'schema_version' => 1, 'status' => 'PASS', 'protocol_state' => $state,
            'scenario' => (string) getenv('SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_SCENARIO'),
            'worker' => (string) getenv('SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_WORKER'),
        ], $extra), JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
    }

    function signalContenderStartup(): void
    {
        must(getenv('SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_WORKER') === 'B', 'startup barrier contender identity');
        $barrier = (string) getenv('SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_STARTUP_BARRIER');
        must($barrier !== '', 'startup barrier signal path');
        $handle = fopen($barrier, 'x');
        must(is_resource($handle), 'create-only startup barrier signal');
        fwrite($handle, "CONTENDER_STARTED\n");
        fclose($handle);
        chmod($barrier, 0600);
    }

    function holdAfterContenderStarts(): void
    {
        $barrier = (string) getenv('SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_STARTUP_BARRIER');
        must($barrier !== '', 'startup barrier path');
        $deadline = microtime(true) + 10.0;
        while (! is_file($barrier)) {
            must(microtime(true) < $deadline, 'contender startup barrier timeout');
            usleep(10_000);
        }
        while (! is_file($barrier.'.observed')) {
            must(microtime(true) < $deadline, 'native wait observation acknowledgement timeout');
            usleep(10_000);
        }
    }

    function fixture(): array
    {
        $raw = base64_decode((string) getenv('SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_FIXTURE'), true);
        $decoded = $raw === false ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    function backendConnectionId(): int
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? (int) data_get(DB::connection()->selectOne('SELECT pg_backend_pid() AS id', [], false), 'id')
            : (int) data_get(DB::connection()->selectOne('SELECT CONNECTION_ID() AS id', [], false), 'id');
    }

    function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);
        return $user->fresh();
    }

    function user(string $publicId): User
    {
        return User::query()->where('public_id', $publicId)->sole();
    }

    function lockExactTargetBinding(string $publicId, int $expectedId): FinanceAccommodationTariffBinding
    {
        must(DB::connection()->transactionLevel() > 0, 'exact target binding row lock requires active transaction');
        $table = SchemaQualifier::table('finance_accommodation_tariff_bindings');
        must(in_array($table, ['finance_accommodation_tariff_bindings', 'laravel.finance_accommodation_tariff_bindings'], true), 'closed target binding table identifier');
        $rows = DB::connection()->select("SELECT id FROM {$table} WHERE public_id = ? FOR UPDATE", [$publicId], false);
        must(count($rows) === 1 && (int) $rows[0]->id === $expectedId, 'exact canonical target binding row lock');
        $binding = FinanceAccommodationTariffBinding::query()->whereKey($expectedId)->sole();
        must($binding->public_id === $publicId, 'canonical target binding identity');
        return $binding;
    }

    function createTariff(User $steward): FinanceTariffItem
    {
        $tariffs = app(FinanceTariffMasterService::class);
        $suffix = str()->upper(str()->random(6));
        $group = $tariffs->createGroup($steward, 'AG'.$suffix, 'Akomodasi', 'Portability', 'acc-port-group-'.str()->lower(str()->random(10)))->record;
        must($group instanceof FinanceCostComponentGroup, 'component group');
        $component = $tariffs->createComponent($steward, $group->public_id, 'AC'.$suffix, 'Komponen akomodasi', null, null, 'Portability', 'acc-port-component-'.str()->lower(str()->random(10)))->record;
        must($component instanceof FinanceCostComponent, 'component');
        $catalogue = $tariffs->createCatalogue($steward, 'AK'.$suffix, 'Katalog akomodasi', 'Portability', 'acc-port-catalogue-'.str()->lower(str()->random(10)))->record;
        must($catalogue instanceof FinanceTariffCatalogue, 'catalogue');
        $tariff = $tariffs->createTariffItem(
            $steward, $catalogue->public_id, $component->public_id, 'AT'.$suffix,
            'Akomodasi occupancy day', 'INPATIENT', 'ACCOMMODATION', null, null,
            10000, '2026-09-02', 'Portability', 'acc-port-tariff-'.str()->lower(str()->random(10)),
        )->record;
        must($tariff instanceof FinanceTariffItem, 'tariff');
        $tariffs->appendTariffItemVersion(
            $steward, $tariff->public_id, 'Akomodasi occupancy day', 'INPATIENT', 'ACCOMMODATION',
            null, null, 12000, '2026-09-03', 1, $tariff->current_content_digest,
            'Prospective price only', 'acc-port-price-'.str()->lower(str()->random(10)),
        );
        return $tariff->fresh();
    }

    function prepareDischargeEvidence(Encounter $encounter, User $physician): void
    {
        $summaries = app(InpatientDischargeSummaryService::class);
        $draft = $summaries->saveDraft(
            $encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION, 0,
            ['admission_reason' => 'Observasi', 'significant_findings' => 'Stabil',
             'care_and_treatment_summary' => 'Pemantauan', 'condition_at_discharge' => 'Baik',
             'follow_up_plan' => 'Kontrol'],
            'acc-port-summary-draft-'.str()->lower(str()->random(10)),
        );
        $summaries->finalize(
            $encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
            $draft->summary->version, 'acc-port-summary-final-'.str()->lower(str()->random(10)),
        );
        $coding = app(InpatientDischargeCodingSourceService::class);
        $codingDraft = $coding->saveDraft(
            $encounter->public_id, $physician, InpatientDischargeCodingSource::DEFINITION_VERSION, 0,
            ['principal_diagnosis_statement' => 'Observasi sintetis', 'secondary_diagnosis_statements' => [],
             'procedure_attestation' => InpatientDischargeCodingSource::ATTESTATION_NONE,
             'performed_procedure_statements' => []],
            'acc-port-coding-draft-'.str()->lower(str()->random(10)),
        );
        $coding->finalize(
            $encounter->public_id, $physician, InpatientDischargeCodingSource::DEFINITION_VERSION,
            $codingDraft->source->version, 'acc-port-coding-final-'.str()->lower(str()->random(10)),
        );
    }

    function prepareFixture(): array
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $admin = actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $registrar = actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $steward = actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $cashier = actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $rmik = actor(RoleCapabilityMatrix::ROLE_RMIK);

        $masters = app(InpatientMasterService::class);
        $suffix = str()->upper(str()->random(6));
        $ward = $masters->createWard($admin, 'AP'.$suffix, 'Bangsal portability', InpatientMasterService::REASON_INITIAL_SETUP, 'acc-port-ward-'.str()->lower(str()->random(10)), null)->master;
        $sourceBed = $masters->createBed($admin, $ward->public_id, 'AS'.$suffix, 'Bed source', 'Ruang A', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, 'acc-port-bed-source-'.str()->lower(str()->random(10)), null)->master;
        $targetBed = $masters->createBed($admin, $ward->public_id, 'AT'.$suffix, 'Bed target', 'Ruang B', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, 'acc-port-bed-target-'.str()->lower(str()->random(10)), null)->master;
        $flowSourceBed = $masters->createBed($admin, $ward->public_id, 'FS'.$suffix, 'Flow source', 'Ruang C', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, 'acc-port-bed-flow-source-'.str()->lower(str()->random(10)), null)->master;
        $flowTargetBed = $masters->createBed($admin, $ward->public_id, 'FT'.$suffix, 'Flow target', 'Ruang D', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, 'acc-port-bed-flow-target-'.str()->lower(str()->random(10)), null)->master;
        must($sourceBed instanceof InpatientBed && $targetBed instanceof InpatientBed && $flowSourceBed instanceof InpatientBed && $flowTargetBed instanceof InpatientBed, 'four distinct beds');
        $sourceVersion = InpatientBedVersion::query()->where('bed_id', $sourceBed->id)->where('version', 1)->sole();
        $targetVersion = InpatientBedVersion::query()->where('bed_id', $targetBed->id)->where('version', 1)->sole();
        $flowSourceVersion = InpatientBedVersion::query()->where('bed_id', $flowSourceBed->id)->where('version', 1)->sole();
        $flowTargetVersion = InpatientBedVersion::query()->where('bed_id', $flowTargetBed->id)->where('version', 1)->sole();
        $tariff = createTariff($steward);
        $bindings = app(FinanceAccommodationTariffBindingService::class);
        $sourceBinding = $bindings->create($steward, $sourceVersion->public_id, 'INPATIENT', $tariff->public_id, '2026-09-02', 'Source binding', 'acc-port-source-binding-'.str()->lower(str()->random(10)))->record;
        $targetBinding = $bindings->create($steward, $targetVersion->public_id, 'INPATIENT', $tariff->public_id, '2026-09-02', 'Target binding', 'acc-port-target-binding-'.str()->lower(str()->random(10)))->record;
        $bindings->create($steward, $flowSourceVersion->public_id, 'INPATIENT', $tariff->public_id, '2026-09-02', 'Flow source binding', 'acc-port-flow-source-binding-'.str()->lower(str()->random(10)));
        $bindings->create($steward, $flowTargetVersion->public_id, 'INPATIENT', $tariff->public_id, '2026-09-02', 'Flow target binding', 'acc-port-flow-target-binding-'.str()->lower(str()->random(10)));

        $admit = function (InpatientBed $bed) use ($registrar): Encounter {
            $patient = Patient::factory()->create(['created_by_user_id' => $registrar->id, 'is_synthetic' => true]);
            return app(\App\Support\Inpatient\InpatientAdmissionService::class)->admitDirect(
                patient: $patient, actor: $registrar, bedPublicId: $bed->public_id,
                payerType: Encounter::PAYER_UMUM, insuranceNumber: null,
                continueFrom: Encounter::CONTINUE_LANGSUNG, chiefComplaint: 'Observasi portability sintetis',
                admissionAuthorityType: Encounter::AUTHORITY_PLANNED_ORDER,
                admissionAuthorityReference: 'ORDER-ACCOMMODATION-PORTABILITY-0001',
            )->encounter;
        };
        $importEncounter = $admit($sourceBed);
        $flowEncounter = $admit($flowSourceBed);
        Carbon::setTestNow('2026-09-04 08:00:00');
        app(InpatientBedTransferService::class)->transfer(
            $importEncounter->public_id, $registrar, 1, $sourceBed->public_id,
            $targetBed->public_id, 'Close source interval', 'acc-port-import-transfer-'.str()->lower(str()->random(10)),
        );

        return [
            'admin' => $admin->public_id, 'registrar' => $registrar->public_id,
            'physician' => $physician->public_id, 'steward' => $steward->public_id,
            'cashier' => $cashier->public_id, 'rmik' => $rmik->public_id,
            'source_bed' => $sourceBed->public_id, 'target_bed' => $targetBed->public_id,
            'flow_source_bed' => $flowSourceBed->public_id, 'flow_target_bed' => $flowTargetBed->public_id,
            'source_binding' => $sourceBinding->public_id, 'source_binding_version' => $sourceBinding->version,
            'source_binding_digest' => $sourceBinding->current_content_digest,
            'target_binding' => $targetBinding->public_id, 'target_binding_version' => $targetBinding->version,
            'target_binding_id' => $targetBinding->id,
            'target_binding_digest' => $targetBinding->current_content_digest,
            'tariff' => $tariff->public_id,
            'import_encounter_id' => $importEncounter->id, 'import_encounter' => $importEncounter->public_id,
            'flow_encounter_id' => $flowEncounter->id, 'flow_encounter' => $flowEncounter->public_id,
            'flow_patient_id' => $flowEncounter->patient_id,
        ];
    }

    function synchronizeRace(array $f): array
    {
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        return DB::transaction(function () use ($f): array {
            $encounter = Encounter::query()->whereKey($f['import_encounter_id'])->lockForUpdate()->sole();
            $before = FinanceAccommodationSourceEvent::query()->where('encounter_id', $encounter->id)->count();
            protocol('HOLDING', ['lock_order_trace' => ['encounters']]);
            usleep((int) getenv('SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_HOLD_MS') * 1000);
            $charges = app(FinanceAccommodationSourceAdapter::class)->synchronize($encounter->fresh('patient'), user($f['cashier']));
            return ['outcome' => $before === 0 ? 'MATERIALIZED' : 'RECONCILED', 'source_count' => $charges->count()];
        }, 3);
    }

    function provenanceTriggerRefusal(array $f): array
    {
        $prior = DB::table(SchemaQualifier::table('inpatient_location_events'))
            ->where('encounter_id', $f['import_encounter_id'])->orderByDesc('sequence')->first();
        must(is_object($prior), 'prior location event');
        $attributes = (array) $prior;
        unset($attributes['id']);
        $attributes['public_id'] = (string) Str::ulid();
        $attributes['sequence'] = ((int) $prior->sequence) + 1;
        foreach (['ward_public_id', 'ward_code', 'ward_display_name', 'bed_public_id', 'bed_code', 'bed_display_name', 'room_label', 'service_class', 'inpatient_bed_version_id', 'inpatient_bed_version_public_id', 'inpatient_bed_version', 'inpatient_bed_after_digest'] as $suffix) {
            $attributes['from_'.$suffix] = $attributes['to_'.$suffix];
        }
        $attributes['to_inpatient_bed_after_digest'] = str_repeat('f', 64);
        $attributes['payload_digest'] = str_repeat('e', 64);
        $before = DB::table(SchemaQualifier::table('inpatient_location_events'))->where('encounter_id', $f['import_encounter_id'])->count();
        DB::beginTransaction();
        DB::statement('SAVEPOINT accommodation_provenance_guard');
        try {
            InpatientLocationMutationScope::run(fn () => DB::table(SchemaQualifier::table('inpatient_location_events'))->insert($attributes));
            throw new RuntimeException('provenance trigger unexpectedly accepted digest drift');
        } catch (QueryException $exception) {
            DB::statement('ROLLBACK TO SAVEPOINT accommodation_provenance_guard');
            must(str_contains($exception->getMessage(), 'destination bed-version provenance does not resolve exactly'), 'trigger refusal fingerprint');
        } finally {
            DB::commit();
        }
        $after = DB::table(SchemaQualifier::table('inpatient_location_events'))->where('encounter_id', $f['import_encounter_id'])->count();
        must($before === $after, 'trigger refusal retained row count');
        return ['result' => ['trigger_refused' => true, 'row_count_unchanged' => true]];
    }

    function transferRace(array $f): array
    {
        Carbon::setTestNow('2026-09-03 00:00:00');
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        return DB::transaction(function () use ($f): array {
            Encounter::query()->whereKey($f['flow_encounter_id'])->lockForUpdate()->sole();
            protocol('HOLDING', ['lock_order_trace' => ['encounters']]);
            usleep((int) getenv('SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_HOLD_MS') * 1000);
            app(InpatientBedTransferService::class)->transfer($f['flow_encounter'], user($f['registrar']), 1, $f['flow_source_bed'], $f['flow_target_bed'], 'Transfer race', 'acc-port-transfer-race');
            return ['outcome' => 'TRANSFERRED'];
        }, 3);
    }

    function synchronizeFlow(array $f): array
    {
        Carbon::setTestNow('2026-09-03 00:00:00');
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        $bill = app(FinanceBillService::class)->synchronize($f['flow_encounter'], user($f['cashier']), 'acc-port-transfer-sync')->record;
        must($bill instanceof FinanceBill, 'bill after transfer');
        try {
            app(FinanceBillService::class)->issue($bill->public_id, user($f['cashier']), app(FinanceProjection::class)->fingerprint($bill->fresh()), 'Open interval refusal', 'acc-port-open-issue');
            throw new RuntimeException('open interval issue unexpectedly allowed');
        } catch (FinanceDenied $denied) {
            must($denied->reason === 'unresolved_accommodation_source', 'open interval refusal reason');
        }
        return ['outcome' => 'SYNCHRONIZED_OPEN_REFUSED'];
    }

    function dischargeRace(array $f): array
    {
        Carbon::setTestNow('2026-09-04 08:00:00');
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        return DB::transaction(function () use ($f): array {
            DB::table('inpatient_patient_claim_mutexes')->where('patient_id', $f['flow_patient_id'])->lockForUpdate()->get();
            Encounter::query()->whereKey($f['flow_encounter_id'])->lockForUpdate()->sole();
            protocol('HOLDING', ['lock_order_trace' => ['inpatient_patient_claim_mutexes', 'encounters']]);
            usleep((int) getenv('SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_HOLD_MS') * 1000);
            $summary = InpatientDischargeSummary::query()->where('encounter_id', $f['flow_encounter_id'])->sole();
            must($summary->summary_state === InpatientDischargeSummary::STATE_FINAL && $summary->version >= 1, 'exact final summary head before discharge');
            app(InpatientDischargeService::class)->execute($f['flow_encounter'], user($f['physician']), $summary->version, 2, $f['flow_target_bed'], 'acc-port-discharge-race');
            return ['outcome' => 'DISCHARGED'];
        }, 3);
    }

    function prepareFlowDischargeEvidence(array $f): array
    {
        Carbon::setTestNow('2026-09-04 08:00:00');
        $encounter = Encounter::query()->whereKey($f['flow_encounter_id'])->sole();
        must(DB::table('inpatient_location_events')->where('encounter_id', $encounter->id)->max('sequence') === 2, 'transfer committed before discharge evidence');
        $transfer = DB::table('inpatient_location_events')->where('encounter_id', $encounter->id)->where('sequence', 2)->sole();
        must(Carbon::parse((string) $transfer->occurred_at)->format('Y-m-d H:i:s') === '2026-09-03 00:00:00', 'transfer chronology retained before discharge evidence');
        must(DB::table('inpatient_discharge_summaries')->where('encounter_id', $encounter->id)->doesntExist(), 'summary absent during transfer race');
        must(DB::table('inpatient_discharge_coding_sources')->where('encounter_id', $encounter->id)->doesntExist(), 'coding source absent during transfer race');
        prepareDischargeEvidence($encounter, user($f['physician']));
        $summary = InpatientDischargeSummary::query()->where('encounter_id', $encounter->id)->sole();
        $coding = InpatientDischargeCodingSource::query()->where('encounter_id', $encounter->id)->sole();
        must($summary->summary_state === InpatientDischargeSummary::STATE_FINAL, 'summary final before discharge');
        must($coding->source_state === InpatientDischargeCodingSource::STATE_FINAL, 'coding source final before discharge');
        return ['result' => ['transfer_committed_without_discharge_evidence' => true, 'summary_final' => true, 'coding_source_final' => true,
            'transfer_occurred_at' => '2026-09-03 00:00:00', 'discharge_clock' => '2026-09-04 08:00:00', 'location_sequence' => 2]];
    }

    function issueAfterDischarge(array $f): array
    {
        Carbon::setTestNow('2026-09-04 08:00:00');
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        $billing = app(FinanceBillService::class);
        $bill = $billing->synchronize($f['flow_encounter'], user($f['cashier']), 'acc-port-discharge-sync')->record;
        $version = $billing->issue($bill->public_id, user($f['cashier']), app(FinanceProjection::class)->fingerprint($bill->fresh()), 'Complete occupancy issue', 'acc-port-discharge-issue')->record;
        must($version->coverage_profile === $version::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1, 'four-domain coverage profile');
        return ['outcome' => 'ISSUED', 'version' => $version->version, 'net_amount' => $version->net_amount];
    }

    function bindingRace(array $f): array
    {
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        try {
            return DB::transaction(function () use ($f): array {
                FinanceAccommodationTariffMutationScope::run(fn () => FinanceAccommodationTariffBinding::query()->where('public_id', $f['source_binding'])->lockForUpdate()->sole());
                protocol('HOLDING', ['lock_order_trace' => ['finance_accommodation_tariff_bindings']]);
                usleep((int) getenv('SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_HOLD_MS') * 1000);
                app(FinanceAccommodationTariffBindingService::class)->appendVersion(user($f['steward']), $f['source_binding'], $f['tariff'], '2026-09-05', $f['source_binding_version'], $f['source_binding_digest'], 'Competing writer', 'acc-port-binding-'.str()->lower(str()->random(10)));
                return ['outcome' => 'APPLIED'];
            }, 3);
        } catch (FinanceTariffDenied) {
            return ['outcome' => 'DENIED'];
        }
    }

    function revisionRace(array $f): array
    {
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        return DB::transaction(function () use ($f): array {
            $locked = FinanceAccommodationTariffMutationScope::run(fn () => lockExactTargetBinding($f['target_binding'], (int) $f['target_binding_id']));
            $lockIdentity = hash('sha256', $locked->public_id.':'.$locked->id);
            protocol('HOLDING', ['lock_order_trace' => ['finance_accommodation_tariff_bindings'],
                'locked_binding_id' => $locked->id, 'locked_binding_identity_sha256' => $lockIdentity]);
            holdAfterContenderStarts();
            app(FinanceAccommodationTariffBindingService::class)->appendVersion(user($f['steward']), $f['target_binding'], $f['tariff'], '2026-09-05', $f['target_binding_version'], $f['target_binding_digest'], 'Prospective revision', 'acc-port-target-revision');
            return ['outcome' => 'REVISED'];
        }, 3);
    }

    function resolveAfterRevision(array $f): array
    {
        return DB::transaction(function () use ($f): array {
            protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
            signalContenderStartup();
            $binding = FinanceAccommodationTariffMutationScope::run(
                fn () => lockExactTargetBinding($f['target_binding'], (int) $f['target_binding_id']),
            );
            $resolution = app(FinanceAccommodationTariffProjection::class)->resolveExact($binding->inpatient_bed_version_public_id, $binding->inpatient_bed_content_digest, '2026-09-05');
            must($resolution->amountRupiah === 12000, 'historical 12k resolution');
            return ['outcome' => 'RESOLVED', 'amount' => $resolution->amountRupiah, 'locked_binding_id' => $binding->id,
                'locked_binding_identity_sha256' => hash('sha256', $binding->public_id.':'.$binding->id)];
        }, 3);
    }

    function verifyFixture(array $f): array
    {
        $encounter = Encounter::query()->whereKey($f['import_encounter_id'])->sole();
        $charges = FinanceChargeEvent::query()->where('encounter_id', $encounter->id)->where('source_domain', 'ACCOMMODATION')->orderBy('occurred_at')->get();
        app(FinanceAccommodationSourceAdapter::class)->verifyRetained($encounter, $charges);
        $sources = FinanceAccommodationSourceEvent::query()->where('encounter_id', $encounter->id)->orderBy('service_date')->get();
        must($sources->pluck('service_date')->map->format('Y-m-d')->all() === ['2026-09-02', '2026-09-03', '2026-09-04'], 'one encounter/date');
        must($sources->pluck('unit_amount')->all() === [10000, 12000, 12000], 'historical 10k 12k resolution');
        must($charges->every(fn ($charge) => $charge->pharmacy_financial_source_event_id === null && $charge->finance_radiology_source_event_id === null && $charge->finance_laboratory_source_event_id === null && $charge->finance_accommodation_source_event_id !== null), 'four-domain typed union');
        return ['source_count' => 3, 'charge_count' => 3, 'amounts' => [10000, 12000, 12000], 'reconciled' => true];
    }

    function verifyFlow(array $f): array
    {
        $encounter = Encounter::query()->whereKey($f['flow_encounter_id'])->sole();
        $charges = FinanceChargeEvent::query()->where('encounter_id', $encounter->id)->where('source_domain', 'ACCOMMODATION')->get();
        app(FinanceAccommodationSourceAdapter::class)->verifyRetained($encounter, $charges);
        $transfer = DB::table('inpatient_location_events')->where('encounter_id', $encounter->id)->where('sequence', 2)->sole();
        must(Carbon::parse((string) $transfer->occurred_at)->format('Y-m-d H:i:s') === '2026-09-03 00:00:00', 'retained transfer timestamp');
        $discharge = DB::table('inpatient_discharges')->where('encounter_id', $encounter->id)->sole();
        must(Carbon::parse((string) $discharge->discharged_at)->format('Y-m-d H:i:s') === '2026-09-04 08:00:00', 'retained discharge timestamp');
        $bill = FinanceBill::query()->where('encounter_id', $encounter->id)->sole();
        must($bill->versions()->count() === 1, 'one issued bill version retained');
        $version = $bill->versions()->sole();
        must($version->coverage_profile === $version::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1, 'issued four-domain coverage profile retained');
        must($version->net_amount === 34000, 'issued accommodation net amount retained');
        $lines = $version->lines()->where('source_domain', FinanceChargeEvent::SOURCE_ACCOMMODATION)->get();
        must($lines->count() === 3, 'three accommodation bill lines retained');
        $sourceDates = FinanceAccommodationSourceEvent::query()
            ->whereIn('public_id', $lines->pluck('source_public_id'))->orderBy('service_date')->get()
            ->map(fn ($source) => $source->service_date->format('Y-m-d'))->all();
        must($sourceDates === ['2026-09-02', '2026-09-03', '2026-09-04'], 'issued accommodation source dates retained');
        return ['source_count' => $charges->count(), 'discharge_count' => 1, 'issued_version_count' => 1,
            'transfer_occurred_at' => '2026-09-03 00:00:00', 'discharged_at' => '2026-09-04 08:00:00',
            'coverage_profile' => $version->coverage_profile, 'net_amount' => $version->net_amount,
            'accommodation_line_count' => 3, 'source_dates' => $sourceDates, 'reconciled' => true];
    }

    function verifyBinding(array $f): array
    {
        $source = FinanceAccommodationTariffBinding::query()->where('public_id', $f['source_binding'])->sole();
        $target = FinanceAccommodationTariffBinding::query()->where('public_id', $f['target_binding'])->sole();
        must($source->version === 2 && $target->version === 2, 'both concurrent binding outcomes durable');
        return ['source_version' => 2, 'target_version' => 2, 'reconciled' => true];
    }

    function recoveryRace(array $f): array
    {
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        return DB::transaction(function () use ($f): array {
            FinanceMutationScope::run(fn () => FinanceAccommodationTariffMutationScope::run(
                fn () => FinanceAccommodationSourceEvent::query()->where('encounter_id', $f['flow_encounter_id'])->lockForUpdate()->get(),
            ));
            protocol('HOLDING', ['lock_order_trace' => ['finance_accommodation_source_events']]);
            usleep((int) getenv('SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_HOLD_MS') * 1000);
            $encounter = Encounter::query()->whereKey($f['flow_encounter_id'])->sole();
            $charges = FinanceChargeEvent::query()->where('encounter_id', $encounter->id)->where('source_domain', 'ACCOMMODATION')->get();
            app(FinanceAccommodationSourceAdapter::class)->verifyRetained($encounter, $charges);
            return ['outcome' => 'RECOVERED'];
        }, 3);
    }

    function resetRace(array $f): array
    {
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        app(SyntheticResetService::class)->reset(['actor' => user($f['admin']), 'reason' => 'accommodation_portability_reset']);
        return ['outcome' => 'RESET'];
    }

    function verifyReset(): array
    {
        foreach (['finance_accommodation_source_events', 'finance_accommodation_tariff_operation_receipts', 'finance_accommodation_tariff_binding_versions', 'finance_accommodation_tariff_bindings'] as $table) {
            must(DB::table($table)->count() === 0, 'reset '.$table);
        }
        must(DB::table('audit_events')->count() > 0, 'audit preserved');
        return ['reset_reconciled' => true, 'audit_preserved' => true];
    }

    function privilegeGuards(): array
    {
        $readTables = ['inpatient_location_events', 'inpatient_bed_versions', 'inpatient_discharges', 'finance_accommodation_tariff_bindings', 'finance_accommodation_tariff_binding_versions', 'finance_accommodation_tariff_operation_receipts', 'finance_accommodation_source_events', 'finance_charge_events', 'finance_bills', 'finance_bill_versions', 'finance_bill_lines'];
        $writeProbeTables = ['finance_accommodation_tariff_bindings', 'finance_accommodation_tariff_binding_versions', 'finance_accommodation_tariff_operation_receipts', 'finance_accommodation_source_events', 'finance_charge_events', 'finance_bills', 'finance_bill_versions', 'finance_bill_lines'];
        foreach ($readTables as $table) DB::table($table)->limit(1)->get();
        foreach ($writeProbeTables as $table) {
            try {
                DB::transaction(fn () => FinanceMutationScope::run(fn () => FinanceAccommodationTariffMutationScope::run(fn () => DB::table($table)->update(['id' => DB::raw('id')]))));
                throw new RuntimeException('write unexpectedly allowed: '.$table);
            } catch (QueryException) {
                // Reduced runtime is SELECT-only.
            }
        }
        return ['read_tables' => count($readTables), 'write_probe_tables' => count($writeProbeTables),
            'inpatient_tables_write_probed' => false, 'forbidden_operations_refused' => true];
    }

    $action = (string) getenv('SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_ACTION');
    try {
        $f = fixture();
        $result = match ($action) {
            'prepare' => ['fixture' => prepareFixture()],
            'synchronize_race' => synchronizeRace($f),
            'provenance_trigger_refusal' => provenanceTriggerRefusal($f),
            'transfer_race' => transferRace($f),
            'synchronize_flow' => synchronizeFlow($f),
            'prepare_discharge_evidence' => prepareFlowDischargeEvidence($f),
            'discharge_race' => dischargeRace($f),
            'issue_after_discharge' => issueAfterDischarge($f),
            'binding_race' => bindingRace($f),
            'revision_race' => revisionRace($f),
            'resolve_after_revision' => resolveAfterRevision($f),
            'verify' => ['result' => verifyFixture($f)],
            'verify_flow' => ['result' => verifyFlow($f)],
            'verify_binding' => ['result' => verifyBinding($f)],
            'recovery_race' => recoveryRace($f),
            'reset_race' => resetRace($f),
            'verify_reset' => ['result' => verifyReset()],
            'privilege_guards' => ['result' => privilegeGuards()],
            default => throw new RuntimeException('unknown accommodation tariff/source action'),
        };
        protocol('COMMITTED', $result);
    } catch (Throwable $exception) {
        $query = $exception instanceof QueryException ? $exception : null;
        $errorInfo = $query?->errorInfo ?? [];
        echo json_encode([
            'schema_version' => 1, 'status' => 'BLOCKED', 'protocol_state' => 'FAILED',
            'scenario' => (string) getenv('SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_SCENARIO'),
            'worker' => (string) getenv('SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_WORKER'),
            'exception_class' => get_class($exception),
            'exception_fingerprint' => hash('sha256', get_class($exception)."\0".$exception->getMessage()),
            'diagnostic_message' => ($exception instanceof LogicException || $exception instanceof RuntimeException) ? mb_substr($exception->getMessage(), 0, 300) : null,
            'failure_stage' => $action, 'sql_state' => (string) ($errorInfo[0] ?? ''),
            'driver_code' => (string) ($errorInfo[1] ?? ''),
            'query_sha256' => $query ? hash('sha256', $query->getSql()) : null,
        ], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
  PHP

  def assert_contract!
    unless @operator_environment[CONFIRMATION_ENV] == CONFIRMATION
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize the disposable local rehearsal."
    end
    rejected = FORBIDDEN_ENVIRONMENT.select { |name| !@operator_environment.fetch(name, '').to_s.strip.empty? }
    raise CommandFailed, "Inpatient accommodation tariff/source rehearsal refuses inherited overrides: #{rejected.join(', ')}." unless rejected.empty?
    LocalPortabilityFullSuiteRehearsal.instance_method(:assert_contract!).bind(self).call
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    raise CommandFailed, 'Inpatient accommodation tariff/source scenario catalogue drifted.' unless SCENARIOS.length == 28 && SCENARIOS.uniq.length == 28
    raise CommandFailed, 'Embedded inpatient accommodation tariff/source worker source is unexpectedly small.' unless WORKER_SOURCE.bytesize > 12_000
  end

  def application_environment
    LocalPortabilityFullSuiteRehearsal.instance_method(:application_environment).bind(self).call.merge(
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false', 'VCLAIM_ENABLED' => 'false',
      'SATUSEHAT_ENABLED' => 'false', 'LIS_INTEGRATION_ENABLED' => 'false',
      'PACS_INTEGRATION_ENABLED' => 'false', 'COLUMNS' => '300'
    )
  end

  def current_inpatient_accommodation_tariff_source_bindings
    files = SOURCE_PATHS.to_h { |path| [path, Digest::SHA256.file(safe_source_path(path)).hexdigest] }
    aggregate = Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h))
    {
      'files' => files, 'aggregate_sha256' => aggregate, 'application_source_sha256' => aggregate,
      'worker_source_sha256' => Digest::SHA256.hexdigest(WORKER_SOURCE),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)),
      'runtime_grant_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(RUNTIME_TABLE_GRANTS)),
      'sqlite_gate_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SQLITE_FEATURE_TESTS)),
    }
  end

  def run_sqlite_gate!
    assert_contract!
    environment = sqlite_application_environment
    results = SQLITE_FEATURE_TESTS.map do |path, method|
      raise CommandFailed, "SQLite-bound feature method is missing: #{path}##{method}." unless File.read(safe_source_path(path), encoding: Encoding::UTF_8).include?("function #{method}")
      command = [@php_binary, File.join(ROOT, 'artisan'), 'test', path, "--filter=#{method}"]
      stdout, stderr, status = Open3.capture3(@runner.process_environment(environment), *command, unsetenv_others: true)
      raise CommandFailed, "SQLite gate failed at #{path}##{method}: #{(stdout + stderr).lines.last(12).join}" unless status.success?
      { 'path' => path, 'method' => method, 'output_sha256' => Digest::SHA256.hexdigest(stdout + stderr) }
    end
    {
      'status' => 'PASS', 'engine' => 'sqlite', 'test_count' => results.length,
      'catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SQLITE_FEATURE_TESTS)),
      'result_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(results)),
      'tests' => results,
    }
  end

  def sqlite_application_environment
    {
      'APP_ENV' => 'testing', 'APP_KEY' => @application_key,
      'APP_MODE' => 'SIMULATION', 'APP_SYNTHETIC_ONLY' => 'true',
      'APP_MAINTENANCE_DRIVER' => 'file', 'BCRYPT_ROUNDS' => '4',
      'BROADCAST_CONNECTION' => 'null', 'CACHE_STORE' => 'array',
      'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
      'MAIL_MAILER' => 'array', 'PULSE_ENABLED' => 'false',
      'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
      'DEMO_SEED_ENABLED' => 'false', 'BREAK_GLASS_MODE' => 'off',
      'BREAK_GLASS_GLOBAL_DISABLED' => 'true', 'BREAK_GLASS_SESSION_HMAC_KEY' => '',
      'VERCEL_GIT_COMMIT_SHA' => '', 'POSTMARK_API_KEY' => '',
      'RESEND_API_KEY' => '', 'AWS_ACCESS_KEY_ID' => '', 'AWS_SECRET_ACCESS_KEY' => '',
      'SLACK_BOT_USER_OAUTH_TOKEN' => '', 'DB_URL' => '', 'LOG_CHANNEL' => 'stderr',
      'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false', 'VCLAIM_ENABLED' => 'false',
      'SATUSEHAT_ENABLED' => 'false', 'LIS_INTEGRATION_ENABLED' => 'false',
      'PACS_INTEGRATION_ENABLED' => 'false', 'COLUMNS' => '300',
    }
  end

  def worker_environment(action:, scenario:, worker:, fixture:, hold_ms:, connection_environment: nil, startup_barrier: nil)
    (connection_environment || @runtime_application_environment || application_environment).merge(
      'SIMRS_REHEARSAL_ROOT' => ROOT,
      'SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_ACTION' => action,
      'SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_SCENARIO' => scenario,
      'SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_WORKER' => worker,
      'SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_RUN_TOKEN' => @run_token,
      'SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_HOLD_MS' => hold_ms.to_s,
      'SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_STARTUP_BARRIER' => startup_barrier.to_s,
      'SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {}))
    )
  end

  def create_worker_file!
    @worker_tempfile = Tempfile.new(['simrs-inpatient-accommodation-tariff-source-portability-', '.php'])
    @worker_tempfile.binmode
    @worker_tempfile.write(WORKER_SOURCE)
    @worker_tempfile.flush
    @worker_tempfile.chmod(0o600)
    @worker_tempfile.close
  end

  def bind_feature_evidence!(bindings)
    bindings.to_h do |label, (path, method)|
      source = File.read(safe_source_path(path), encoding: Encoding::UTF_8)
      raise CommandFailed, "Scenario-bound inpatient accommodation tariff/source feature method is missing: #{path}##{method}." unless source.include?("function #{method}")
      cache_key = [path, method]
      proof = (@filtered_feature_evidence_cache ||= {})[cache_key] ||= begin
        begin
          # Every filtered method is a new PHPUnit process using RefreshDatabase.
          # Give that process an administrator-created empty disposable schema so
          # its own migrate:fresh never asks the application guard to drop a
          # populated finance schema left by the preceding method.
          recreate_application_schema_outside_guard!
          recorded_artisan!('test', path, "--filter=#{method}")
        rescue CommandFailed => error
          raise CommandFailed, "Exact-engine feature binding failed at #{path}##{method}: #{error.message}"
        end
        { 'status' => 'PASS', 'proof_kind' => 'FILTERED_EXACT_ENGINE_FEATURE_TEST', 'path' => path, 'method' => method }
      end
      [label, proof.merge('scenario_binding' => label)]
    end
  end

  def start_domain_race_worker!(action:, scenario:, fixture:, worker:, hold_ms:, startup_barrier: nil)
    @command_catalog << ['inpatient-accommodation-tariff-source-worker', action, "--scenario=#{scenario}", "--worker=#{worker}"]
    stdin, stdout, stderr, wait_thread = Open3.popen3(
      @runner.process_environment(worker_environment(
        action: action, scenario: scenario, worker: worker, fixture: fixture,
        hold_ms: hold_ms, connection_environment: application_environment,
        startup_barrier: startup_barrier
      )), @php_binary, @worker_tempfile.path, unsetenv_others: true
    )
    stdin.close
    process = Worker.new(
      stdout: stdout, stderr: stderr, wait_thread: wait_thread,
      stderr_reader: Thread.new { stderr.read }, scenario: scenario, label: worker
    )
    @workers << process
    process
  end

  def run_domain_race!(action:, scenario:, fixture:, expected_outcomes:, expected_lock_order:)
    first = start_domain_race_worker!(action: action, scenario: scenario, fixture: fixture, worker: 'A', hold_ms: HOLD_MS)
    first_started = await_protocol!(first, 'STARTED')
    holding = await_protocol!(first, 'HOLDING')
    raise CommandFailed, "#{scenario} lock trace drifted." unless holding.fetch('lock_order_trace') == expected_lock_order
    second = start_domain_race_worker!(action: action, scenario: scenario, fixture: fixture, worker: 'B', hold_ms: 0)
    started = await_protocol!(second, 'STARTED')
    wait_context = "first_protocol=STARTED/HOLDING first_backend=#{first_started.fetch('backend_connection_id')} second_protocol=STARTED second_backend=#{started.fetch('backend_connection_id')} expected_lock_order=#{expected_lock_order.join('>')}"
    begin
      wait_observed = observe_real_database_wait!(Integer(started.fetch('backend_connection_id')))
    rescue CommandFailed => error
      diagnostic = wait_failure_diagnostic(Integer(started.fetch('backend_connection_id')))
      worker_diagnostic = completed_worker_diagnostic(second)
      raise CommandFailed, "#{scenario} wait observation failed (#{wait_context} diagnostic=#{diagnostic} worker=#{worker_diagnostic}): #{error.message}"
    end
    raise CommandFailed, "#{scenario} did not expose a real database wait." unless wait_observed
    finals = [await_final!(first), await_final!(second)]
    outcomes = finals.map { |document| document.fetch('outcome') }.sort
    raise CommandFailed, "#{scenario} outcomes drifted: #{outcomes.join(',')}." unless outcomes == expected_outcomes.sort
    {
      'status' => 'PASS', 'proof_kind' => 'OBSERVED_DATABASE_RACE',
      'independent_application_processes' => 2, 'real_database_wait_observed' => true,
      'observed_lock_order' => expected_lock_order, 'outcomes' => outcomes, 'deadlock_observed' => false,
    }
  ensure
    terminate_workers!
  end

  def run_mixed_race!(first_action:, second_action:, scenario:, fixture:, expected_outcomes:, expected_lock_order:, startup_barrier: false)
    barrier_path = startup_barrier ? internal_startup_barrier_path(scenario) : nil
    first = start_domain_race_worker!(action: first_action, scenario: scenario, fixture: fixture, worker: 'A', hold_ms: HOLD_MS, startup_barrier: barrier_path)
    first_started = await_protocol!(first, 'STARTED')
    holding = await_protocol!(first, 'HOLDING')
    raise CommandFailed, "#{scenario} lock trace drifted." unless holding.fetch('lock_order_trace') == expected_lock_order
    second = start_domain_race_worker!(action: second_action, scenario: scenario, fixture: fixture, worker: 'B', hold_ms: 0, startup_barrier: barrier_path)
    started = await_protocol!(second, 'STARTED')
    wait_context = "first_protocol=STARTED/HOLDING first_backend=#{first_started.fetch('backend_connection_id')} second_protocol=STARTED second_backend=#{started.fetch('backend_connection_id')} expected_lock_order=#{expected_lock_order.join('>')}"
    begin
      wait_observed = observe_real_database_wait!(Integer(started.fetch('backend_connection_id')))
    rescue CommandFailed => error
      diagnostic = wait_failure_diagnostic(Integer(started.fetch('backend_connection_id')))
      worker_diagnostic = completed_worker_diagnostic(second)
      raise CommandFailed, "#{scenario} wait observation failed (#{wait_context} diagnostic=#{diagnostic} worker=#{worker_diagnostic}): #{error.message}"
    end
    raise CommandFailed, "#{scenario} did not expose a real database wait." unless wait_observed
    signal_native_wait_observed!(barrier_path) if startup_barrier
    finals = [await_final!(first), await_final!(second)]
    outcomes = finals.map { |document| document.fetch('outcome') }.sort
    raise CommandFailed, "#{scenario} outcomes drifted: #{outcomes.join(',')}." unless outcomes == expected_outcomes.sort
    canonical_locked_row_id = nil
    canonical_lock_identity_sha256 = nil
    if startup_barrier
      resolved = finals.find { |document| document.fetch('outcome') == 'RESOLVED' }
      canonical_locked_row_id = Integer(holding.fetch('locked_binding_id'))
      canonical_lock_identity_sha256 = holding.fetch('locked_binding_identity_sha256')
      raise CommandFailed, "#{scenario} canonical lock identity drifted." unless resolved &&
        canonical_locked_row_id == Integer(fixture.fetch('target_binding_id')) &&
        canonical_locked_row_id == Integer(resolved.fetch('locked_binding_id')) &&
        canonical_lock_identity_sha256 == resolved.fetch('locked_binding_identity_sha256') &&
        canonical_lock_identity_sha256.match?(/\A[0-9a-f]{64}\z/)
    end
    {
      'status' => 'PASS', 'proof_kind' => 'OBSERVED_DATABASE_RACE',
      'independent_application_processes' => 2, 'real_database_wait_observed' => true,
      'observed_lock_order' => expected_lock_order, 'outcomes' => outcomes,
      'third_connection_reconciliation_required' => true, 'deadlock_observed' => false,
      'pair_specific_startup_barrier' => startup_barrier,
      'canonical_locked_row_id' => canonical_locked_row_id,
      'canonical_lock_identity_sha256' => canonical_lock_identity_sha256,
    }
  ensure
    terminate_workers!
    cleanup_startup_barrier!(barrier_path)
  end

  def internal_startup_barrier_path(scenario)
    directory = File.dirname(@worker_tempfile.path)
    safe_scenario = scenario.gsub(/[^a-z0-9-]/, '')
    path = File.join(directory, "simrs-accommodation-#{@run_token}-#{safe_scenario}-#{SecureRandom.hex(8)}.barrier")
    raise CommandFailed, 'Harness startup barrier path collision.' if File.exist?(path)
    path
  end

  def cleanup_startup_barrier!(path)
    return if path.nil?
    observed_path = path+'.observed'
    File.unlink(observed_path) if File.file?(observed_path)
    File.unlink(path) if File.file?(path)
    raise CommandFailed, 'Harness startup barrier strict cleanup failed.' if File.exist?(path) || File.exist?(observed_path)
  end

  def signal_native_wait_observed!(path)
    raise CommandFailed, 'Native-wait acknowledgement requires internal startup barrier.' if path.nil? || !File.file?(path)
    File.write(path+'.observed', "NATIVE_WAIT_OBSERVED\n", mode: 'wx', perm: 0o600)
  end

  def wait_failure_diagnostic(connection_id)
    return @last_postgres_wait_diagnostic if @engine == 'postgresql17' && @last_postgres_wait_diagnostic
    return 'engine=mysql;activity=not_captured' unless @engine == 'postgresql17'
    JSON.generate([postgres_activity_snapshot(connection_id)])
  rescue StandardError => error
    "activity_diagnostic_failed=#{error.class.name}"
  end

  def completed_worker_diagnostic(worker)
    return 'state=RUNNING' if worker.wait_thread.alive?
    line = worker.stdout.gets
    document = line && parse_worker_line(line)
    return "state=EXITED;exit=#{worker.wait_thread.value.exitstatus};protocol=UNAVAILABLE" unless document
    allowed = %w[status protocol_state exception_class exception_fingerprint diagnostic_message failure_stage sql_state driver_code query_sha256]
    JSON.generate(document.select { |key, _value| allowed.include?(key) })
  rescue StandardError => error
    "state=DIAGNOSTIC_FAILED;class=#{error.class.name}"
  end

  def observe_real_database_wait!(connection_id)
    return super unless @engine == 'postgresql17'
    deadline = @clock.call + 5.0
    transitions = []
    loop do
      snapshot = postgres_activity_snapshot(connection_id)
      transitions << snapshot if transitions.last != snapshot
      if snapshot.fetch('wait_event_type') == 'Lock' && snapshot.fetch('blocking_pids').any?
        @last_postgres_wait_diagnostic = nil
        return true
      end
      if @clock.call >= deadline
        @last_postgres_wait_diagnostic = JSON.generate(transitions)
        raise CommandFailed, 'No native database lock wait was observed.'
      end
      sleep 0.05
    end
  end

  def postgres_activity_snapshot(connection_id)
    sql = <<~SQL.gsub("\n", ' ')
      SELECT concat_ws('|',
        coalesce(state, ''),
        coalesce(wait_event_type, ''),
        coalesce(wait_event, ''),
        coalesce(array_to_string(pg_blocking_pids(pid), ','), ''),
        CASE WHEN xact_start IS NULL THEN 'NONE' ELSE 'PRESENT' END,
        md5(coalesce(query, '')))
      FROM pg_stat_activity WHERE pid=#{Integer(connection_id)}
    SQL
    value = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', sql], env: postgres_tool_environment).strip
    return { 'state' => 'MISSING', 'wait_event_type' => '', 'wait_event' => '', 'blocking_pids' => [], 'transaction' => 'UNKNOWN', 'query_md5' => '' } if value.empty?
    state, wait_event_type, wait_event, blocking_pids, transaction, query_md5 = value.split('|', -1)
    {
      'state' => state, 'wait_event_type' => wait_event_type, 'wait_event' => wait_event,
      'blocking_pids' => blocking_pids.split(',').reject(&:empty?).map { |pid| Integer(pid) },
      'transaction' => transaction, 'query_md5' => query_md5,
    }
  end

  def provision_postgres_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    raise CommandFailed, 'Generated PostgreSQL identities failed closed pattern.' unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
    tables = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT tablename FROM pg_tables WHERE schemaname='laravel' ORDER BY tablename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "PostgreSQL inpatient accommodation tariff/source runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = [%(CREATE ROLE "#{runtime}" LOGIN), %(CREATE ROLE "#{reset}" LOGIN), %(GRANT CONNECT ON DATABASE "#{@postgres_database}" TO "#{runtime}", "#{reset}"), %(GRANT USAGE ON SCHEMA "laravel" TO "#{runtime}", "#{reset}")]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << %(GRANT #{grants} ON TABLE "laravel"."#{table}" TO "#{runtime}") }
    tables.each { |table| statements << %(GRANT #{table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON TABLE "laravel"."#{table}" TO "#{reset}") }
    @runner.run!(postgres_psql_arguments(@postgres_database) + ['--set', 'ON_ERROR_STOP=1', '--command', statements.join(";\n")+';'], env: postgres_tool_environment)
    RUNTIME_TABLE_GRANTS.each do |table, expected|
      actual = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name='#{table}'"], env: postgres_tool_environment).strip
      raise CommandFailed, "PostgreSQL inpatient accommodation tariff/source runtime grant drifted for #{table}." unless actual == expected
    end
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
    raise CommandFailed, "MySQL inpatient accommodation tariff/source runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = ["CREATE USER '#{runtime}'@'127.0.0.1' IDENTIFIED BY '#{runtime_password}'", "CREATE USER '#{reset}'@'127.0.0.1' IDENTIFIED BY '#{reset_password}'"]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << "GRANT #{grants} ON `#{@mysql_database}`.`#{table}` TO '#{runtime}'@'127.0.0.1'" }
    tables.each { |table| statements << "GRANT #{table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON `#{@mysql_database}`.`#{table}` TO '#{reset}'@'127.0.0.1'" }
    statements << 'FLUSH PRIVILEGES'
    @runner.run!(mysql_root_arguments, stdin_data: statements.join(";\n")+';')
    grants = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SHOW GRANTS FOR '#{runtime}'@'127.0.0.1';").upcase
    raise CommandFailed, 'Reduced MySQL inpatient accommodation tariff/source runtime retained forbidden write or DDL grants.' unless %w[INSERT UPDATE DELETE DROP ALTER TRIGGER CREATE].none? { |privilege| grants.match?(/\b#{privilege}\b/) }
    raise CommandFailed, 'MySQL inpatient accommodation tariff/source runtime received a schema wildcard grant.' if grants.include?("ON `#{@mysql_database.upcase}`.*")
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => runtime_password)
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => reset_password)
  end

  def expect_inpatient_accommodation_tariff_source_rollback_refusal!
    arguments = ['migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(@runner.process_environment(application_environment), @php_binary, File.join(ROOT, 'artisan'), *arguments, unsetenv_others: true)
    raise CommandFailed, 'Retained inpatient accommodation tariff/source evidence unexpectedly allowed rollback.' if status.success?
    combined = (stdout + stderr).gsub(/\s+/, '').downcase
    expected = %w[correlatedauditevidenceremains typedfinancechargesremain retainedrowsremain]
    raise CommandFailed, 'Inpatient accommodation tariff/source rollback failed for an unexpected reason.' unless expected.any? { |fragment| combined.include?(fragment) }
    @result_catalog << [['artisan', *arguments], 'EXPECTED_REFUSAL']
    'PASS'
  end

  def expect_provenance_rollback_refusal!
    arguments = ['migrate:rollback', '--path='+PROVENANCE_MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(@runner.process_environment(application_environment), @php_binary, File.join(ROOT, 'artisan'), *arguments, unsetenv_others: true)
    raise CommandFailed, 'Retained exact bed-version provenance unexpectedly allowed rollback.' if status.success?
    combined = (stdout + stderr).gsub(/\s+/, '').downcase
    raise CommandFailed, 'Exact bed-version provenance rollback failed for an unexpected reason.' unless combined.include?('prospectivelocationevidenceremains')
    @result_catalog << [['artisan', *arguments], 'EXPECTED_REFUSAL']
    'PASS'
  end

  def write_inpatient_accommodation_tariff_source_evidence!(bindings:, engine_binding:, migration_duration_ms:, scenarios:, sqlite_gate:)
    assert_evidence_directory!
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-inpatient-accommodation-tariff-source-#{SecureRandom.hex(6)}.json")
    evidence = {
      'schema_version' => 1, 'kind' => EVIDENCE_KIND, 'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_ONLY',
      'hosted_readiness_claim' => false, 'deployment_claim' => false,
      'owner_acceptance_claim' => false, 'g0_claim' => false, 'g3_claim' => false,
      'source_bindings' => bindings.merge(
        'command_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@command_catalog)),
        'result_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@result_catalog))
      ),
      'command_catalog' => @command_catalog, 'protocol_result_catalog' => @protocol_catalog,
      'boundary' => {
        'application_mode' => 'SIMULATION', 'synthetic_only' => true,
        'live_integrations_enabled' => false, 'disposable_local_engine' => true,
        'empty_by_default' => true, 'automatic_charge_generation' => false,
        'external_database_configuration_accepted' => false,
      },
      'engine' => engine_binding,
      'sqlite_gate' => sqlite_gate,
      'migration' => { 'fresh_apply' => 'PASS', 'duration_ms_observed' => migration_duration_ms },
      'scenarios' => scenarios,
      'cleanup' => {
        'database_removed' => true, 'temporary_server_removed' => true,
        'temporary_user_state_removed' => true, 'temporary_worker_removed' => true,
        'strict_cleanup_verified' => true,
      },
      'open_boundaries' => [
        'G0 and G3 remain OPEN; this local disposable-engine record is not owner acceptance, parity acceptance, deployment, hosted migration, UAT, or production readiness.',
        'No asserted real hospital price, automatic order charge, payment, accounting, claim, BPJS, VClaim, SATUSEHAT, LIS, PACS/RIS, mail, or production integration is exercised.',
      ],
    }
    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence)+"\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    path
  end

  def run!
    assert_contract!
    bindings = current_inpatient_accommodation_tariff_source_bindings
    sqlite_gate = run_sqlite_gate!
    engine_binding = prepare_engine!
    @run_token = database_run_token
    create_worker_file!
    started = @clock.call
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(started)
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    recorded_artisan!('migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    recorded_artisan!('migrate:rollback', '--path='+PROVENANCE_MIGRATION_PATH, '--force', '--no-interaction')
    recorded_artisan!('migrate', '--path='+PROVENANCE_MIGRATION_PATH, '--force', '--no-interaction')
    recorded_artisan!('migrate', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    feature = bind_feature_evidence!(FEATURE_SCENARIO_TESTS)
    recreate_application_schema_outside_guard!
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    prepared = run_worker_command!(action: 'prepare', scenario: 'fresh-migration', worker: 'PREPARE', connection_environment: application_environment)
    fixture = prepared.fetch('fixture')
    provenance_trigger = run_worker_command!(action: 'provenance_trigger_refusal', scenario: 'database-provenance-trigger-refusal', worker: 'TRIGGER', fixture: fixture, connection_environment: application_environment)
    source_race = run_domain_race!(
      action: 'synchronize_race', scenario: 'same-service-day-import-concurrency', fixture: fixture,
      expected_outcomes: %w[MATERIALIZED RECONCILED], expected_lock_order: %w[encounters]
    )
    verified = run_worker_command!(action: 'verify', scenario: 'closed-interval-source-materialization', worker: 'VERIFY', fixture: fixture, connection_environment: application_environment)
    transfer_race = run_mixed_race!(
      first_action: 'transfer_race', second_action: 'synchronize_flow',
      scenario: 'transfer-synchronization-concurrency', fixture: fixture,
      expected_outcomes: %w[TRANSFERRED SYNCHRONIZED_OPEN_REFUSED], expected_lock_order: %w[encounters]
    )
    discharge_precondition = run_worker_command!(action: 'prepare_discharge_evidence', scenario: 'routine-discharge-complete-bill', worker: 'PREPARE_DISCHARGE', fixture: fixture, connection_environment: application_environment)
    discharge_race = run_mixed_race!(
      first_action: 'discharge_race', second_action: 'issue_after_discharge',
      scenario: 'discharge-issue-cutoff-concurrency', fixture: fixture,
      expected_outcomes: %w[DISCHARGED ISSUED], expected_lock_order: %w[inpatient_patient_claim_mutexes encounters]
    )
    flow_reconciliation = run_worker_command!(action: 'verify_flow', scenario: 'routine-discharge-complete-bill', worker: 'RECONCILE', fixture: fixture, connection_environment: application_environment)
    revision_race = run_mixed_race!(
      first_action: 'revision_race', second_action: 'resolve_after_revision',
      scenario: 'bed-version-binding-resolution-concurrency', fixture: fixture,
      expected_outcomes: %w[REVISED RESOLVED], expected_lock_order: %w[finance_accommodation_tariff_bindings],
      startup_barrier: true
    )
    binding_race = run_domain_race!(
      action: 'binding_race', scenario: 'competing-binding-writer-concurrency', fixture: fixture,
      expected_outcomes: %w[APPLIED DENIED], expected_lock_order: %w[finance_accommodation_tariff_bindings]
    )
    binding_reconciliation = run_worker_command!(action: 'verify_binding', scenario: 'competing-binding-writer-concurrency', worker: 'RECONCILE', fixture: fixture, connection_environment: application_environment)
    provision_runtime_identities!
    privilege = run_worker_command!(action: 'privilege_guards', scenario: 'least-privilege-runtime', worker: 'RUNTIME', fixture: fixture)
    rollback = expect_inpatient_accommodation_tariff_source_rollback_refusal!
    provenance_rollback = expect_provenance_rollback_refusal!
    reset_race = run_mixed_race!(
      first_action: 'recovery_race', second_action: 'reset_race',
      scenario: 'reset-recovery-concurrency', fixture: fixture,
      expected_outcomes: %w[RECOVERED RESET], expected_lock_order: %w[finance_accommodation_source_events]
    )
    reset_reconciliation = run_worker_command!(action: 'verify_reset', scenario: 'reset-recovery-concurrency', worker: 'RECONCILE', fixture: fixture, connection_environment: application_environment)

    scenarios = {
      'fresh-migration' => { 'status' => 'PASS', 'proof_kind' => 'FRESH_EXACT_ENGINE_MIGRATION_AND_EMPTY_READBACK' },
      'empty-down-reapply' => { 'status' => 'PASS', 'proof_kind' => 'BOTH_EMPTY_MIGRATIONS_ROLLBACK_REAPPLY' },
      'exact-role-boundary' => feature.fetch('exact-role-boundary'),
      'prospective-bed-version-provenance' => feature.fetch('prospective-bed-version-provenance'),
      'database-provenance-trigger-refusal' => { 'status' => 'PASS', 'proof_kind' => 'EXACT_ENGINE_SAVEPOINT_TRIGGER_REFUSAL_AND_SCHEMA_CONTRACT', 'worker' => provenance_trigger.fetch('result'), 'feature' => feature.fetch('database-provenance-trigger-refusal') },
      'legacy-provenance-no-inference' => feature.fetch('legacy-provenance-no-inference'),
      'occupancy-day-calendar-allocation' => feature.fetch('occupancy-day-calendar-allocation'),
      'admission-census-cancellation-open-interval-no-charge' => feature.fetch('admission-census-cancellation-open-interval-no-charge'),
      'exact-effective-dated-binding' => feature.fetch('exact-effective-dated-binding'),
      'future-half-open-terminal-retirement' => feature.fetch('future-half-open-terminal-retirement'),
      'exact-context-and-positive-integer-refusal' => feature.fetch('exact-context-and-positive-integer-refusal'),
      'four-domain-typed-union' => feature.fetch('four-domain-typed-union'),
      'closed-interval-source-materialization' => { 'status' => 'PASS', 'proof_kind' => 'REAL_OCCUPANCY_WORKER_AND_FILTERED_EXACT_ENGINE_TEST', 'worker' => verified.fetch('result'), 'feature' => feature.fetch('closed-interval-source-materialization') },
      'open-interval-partial-sync-issue-refusal' => { 'status' => 'PASS', 'proof_kind' => 'REAL_TRANSFER_SYNCHRONIZATION_RACE_AND_FILTERED_TEST', 'race' => transfer_race, 'feature' => feature.fetch('open-interval-partial-sync-issue-refusal') },
      'routine-discharge-complete-bill' => { 'status' => 'PASS', 'proof_kind' => 'REAL_DISCHARGE_ISSUE_RACE_AND_THIRD_CONNECTION', 'precondition' => discharge_precondition.fetch('result'), 'race' => discharge_race, 'reconciliation' => flow_reconciliation.fetch('result'), 'feature' => feature.fetch('routine-discharge-complete-bill') },
      'replay-conflict-retroactive-stale-refusal' => feature.fetch('replay-conflict-retroactive-stale-refusal'),
      'same-service-day-import-concurrency' => source_race,
      'transfer-synchronization-concurrency' => transfer_race,
      'discharge-issue-cutoff-concurrency' => discharge_race,
      'bed-version-binding-resolution-concurrency' => revision_race,
      'competing-binding-writer-concurrency' => binding_race,
      'application-sql-guard-refusal' => feature.fetch('application-sql-guard-refusal'),
      'database-append-only-head-trigger-check-refusal' => feature.fetch('database-append-only-head-trigger-check-refusal'),
      'audit-corruption-reconciliation-refusal' => { 'status' => 'PASS', 'proof_kind' => 'FILTERED_CORRUPTION_FEATURE_AND_DURABLE_RETAINED_SOURCE_RECONCILIATION', 'feature' => feature.fetch('audit-corruption-reconciliation-refusal'), 'worker' => verified.fetch('result') },
      'least-privilege-runtime' => { 'status' => 'PASS', 'proof_kind' => 'READ_BACK_SELECT_ONLY_RUNTIME_IDENTITY', 'worker' => privilege.fetch('result') },
      'reset-recovery-concurrency' => { 'status' => 'PASS', 'proof_kind' => 'OBSERVED_RESET_RECOVERY_RACE_AND_THIRD_CONNECTION', 'race' => reset_race, 'reconciliation' => reset_reconciliation.fetch('result'), 'feature' => feature.fetch('reset-recovery-concurrency') },
      'retained-evidence-rollback-refusal' => { 'status' => rollback == 'PASS' && provenance_rollback == 'PASS' ? 'PASS' : 'BLOCKED', 'proof_kind' => 'BOTH_RETAINED_EVIDENCE_MIGRATION_DOWNS_REFUSED' },
      'strict-cleanup' => { 'status' => 'PASS', 'proof_kind' => 'ENGINE_OWNED_STRICT_CLEANUP_PENDING_FINAL_BINDING_RECHECK', 'binding_reconciliation' => binding_reconciliation.fetch('result') },
    }
    raise CommandFailed, 'Inpatient accommodation tariff/source scenario evidence is incomplete.' unless scenarios.keys == SCENARIOS && scenarios.values.all? { |result| result['status'] == 'PASS' }
    assert_unchanged_binding!('Inpatient accommodation tariff/source execution bindings', bindings, current_inpatient_accommodation_tariff_source_bindings)
    cleanup!(strict: true)
    evidence_path = write_inpatient_accommodation_tariff_source_evidence!(bindings: bindings, engine_binding: engine_binding, migration_duration_ms: migration_duration_ms, scenarios: scenarios, sqlite_gate: sqlite_gate)
    {
      'status' => 'PASS', 'claim' => 'LOCAL_DISPOSABLE_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_ONLY',
      'engine' => @engine, 'scenario_count' => scenarios.length,
      'application_source_sha256' => bindings.fetch('application_source_sha256'),
      'sqlite_gate_status' => sqlite_gate.fetch('status'),
      'evidence_path' => evidence_path, 'evidence_sha256' => Digest::SHA256.file(evidence_path).hexdigest,
    }
  ensure
    terminate_workers!
    remove_worker_file!
    cleanup!
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalInpatientAccommodationTariffSourcePortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalInpatientAccommodationTariffSourcePortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalInpatientAccommodationTariffSourcePortabilityRehearsal::CommandFailed => e
    warn "inpatient accommodation tariff/source portability rehearsal failed: #{e.message}"
    exit 1
  end
end
