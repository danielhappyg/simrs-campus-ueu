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

# Fail-closed local exact-engine evidence for the bounded connection between a
# original immutable verified laboratory result, its governed tariff and the
# versioned bill. SQLite is a mandatory application gate; PostgreSQL 17 and
# MySQL 8.4 are harness-owned disposable exact-engine gates.
class LocalLaboratoryTariffSourcePortabilityRehearsal < LocalFinanceTariffComponentMasterPortabilityRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_LABORATORY_TARIFF_SOURCE'
  CONFIRMATION_ENV = 'SIMRS_LABORATORY_TARIFF_SOURCE_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-laboratory-tariff-source-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalLaboratoryTariffSourcePortabilityHarnessContractTest.rb'
  MIGRATION_PATH = 'database/migrations/2026_09_02_000400_create_laboratory_verified_result_tariff_source.php'
  EVIDENCE_TEMPLATE_PATH = 'docs/operations/T1_LOCAL_LABORATORY_TARIFF_SOURCE_EVIDENCE_TEMPLATE_2026-09-02.md'
  EVIDENCE_KIND = 'SIMRS_LOCAL_LABORATORY_TARIFF_SOURCE_PORTABILITY'
  EXECUTION_STATE = 'READY_NOT_RUN'

  SCENARIOS = %w[
    fresh-migration
    empty-down-reapply
    exact-role-boundary
    three-care-setting-bindings
    original-verified-result-service-date-resolution
    future-half-open-terminal-retirement
    upstream-result-chain-refusal
    order-specimen-draft-cancellation-no-charge
    one-original-verified-result-one-typed-source
    verified-amendment-acknowledgement-no-extra-charge
    laboratory-only-bill-snapshot
    partial-sync-unresolved-issue-refusal
    later-gap-resolution-new-version
    replay-key-conflict-retroactive-stale-refusal
    same-verified-result-import-concurrency
    competing-binding-writer-concurrency
    verification-synchronization-cutoff-concurrency
    application-sql-guard-refusal
    database-append-only-head-typed-source-refusal
    audit-corruption-reconciliation-refusal
    least-privilege-runtime
    bounded-reset-recovery-rollback-strict-cleanup
  ].freeze

  LABORATORY_TABLES = %w[
    finance_laboratory_tariff_bindings finance_laboratory_tariff_binding_versions
    finance_laboratory_tariff_operation_receipts finance_laboratory_source_events
  ].freeze
  RUNTIME_READ_TABLES = %w[
    users roles permissions role_user permission_role patients encounters
    laboratory_examination_masters laboratory_examination_master_versions
    laboratory_orders laboratory_order_cancellations laboratory_specimen_attempts
    laboratory_specimen_events laboratory_result_versions
    laboratory_critical_communications laboratory_result_acknowledgements
    laboratory_operation_receipts
    finance_cost_component_groups finance_cost_component_group_versions
    finance_cost_components finance_cost_component_versions
    finance_tariff_catalogues finance_tariff_catalogue_versions
    finance_tariff_items finance_tariff_item_versions
    finance_laboratory_tariff_bindings finance_laboratory_tariff_binding_versions
    finance_laboratory_tariff_operation_receipts finance_laboratory_source_events
    finance_charge_events finance_bills finance_bill_versions finance_bill_lines
    finance_operation_receipts audit_events
  ].freeze
  RUNTIME_TABLE_GRANTS = RUNTIME_READ_TABLES.to_h { |table| [table, 'SELECT'] }.freeze

  SOURCE_PATHS = %w[
    scripts/rehearse-local-laboratory-tariff-source-portability.rb
    scripts/rehearse-local-finance-tariff-component-master-portability.rb
    scripts/rehearse-local-finance-billing-portability.rb
    scripts/rehearse-local-laboratory-portability.rb
    scripts/rehearse-local-portability-full-suite.rb
    tests/Documentation/LocalLaboratoryTariffSourcePortabilityHarnessContractTest.rb
    tests/Documentation/CrossSettingLaboratoryVerifiedResultTariffSourceV1LocalEngineeringAuthorizationTest.rb
    docs/new-simrs-rebuild/phase-1/CROSS_SETTING_LABORATORY_VERIFIED_RESULT_TARIFF_SOURCE_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md
    docs/operations/T1_LOCAL_LABORATORY_TARIFF_SOURCE_EVIDENCE_TEMPLATE_2026-09-02.md
    database/migrations/2026_09_02_000400_create_laboratory_verified_result_tariff_source.php
    tests/Feature/Finance/FinanceLaboratoryTariffBindingCoreTest.php
    tests/Feature/Finance/FinanceLaboratorySourceAdapterTest.php
    tests/Feature/Finance/FinanceLaboratoryTariffHttpWorkflowTest.php
    tests/Feature/Database/FinanceLaboratoryTariffGuardTest.php
    app/Models/FinanceLaboratoryTariffBinding.php
    app/Models/FinanceLaboratoryTariffBindingVersion.php
    app/Models/FinanceLaboratoryTariffOperationReceipt.php
    app/Models/FinanceLaboratorySourceEvent.php
    app/Support/Finance/FinanceLaboratoryTariffActorPolicy.php
    app/Support/Finance/FinanceLaboratoryTariffAppendOnlyGuard.php
    app/Support/Finance/FinanceLaboratoryTariffBindingService.php
    app/Support/Finance/FinanceLaboratoryTariffContentDigest.php
    app/Support/Finance/FinanceLaboratoryTariffMutableHeadGuard.php
    app/Support/Finance/FinanceLaboratoryTariffMutationScope.php
    app/Support/Finance/FinanceLaboratoryTariffProjection.php
    app/Support/Finance/FinanceLaboratoryTariffSqlWriteGuard.php
    app/Support/Finance/FinanceLaboratorySourceAdapter.php
    app/Support/Finance/FinanceSourceCoordinator.php
    app/Support/Finance/FinanceBillService.php
    app/Support/Operations/SyntheticRecoverySnapshot.php
    app/Support/Simulation/SyntheticResetService.php
  ].freeze

  FEATURE_SCENARIO_TESTS = {
    'exact-role-boundary' => ['tests/Feature/Finance/FinanceLaboratoryTariffBindingCoreTest.php', 'test_exact_roles_are_non_bypass_and_cashier_is_view_only'],
    'three-care-setting-bindings' => ['tests/Feature/Finance/FinanceLaboratorySourceAdapterTest.php', 'test_original_verified_result_resolves_in_all_three_care_settings'],
    'original-verified-result-service-date-resolution' => ['tests/Feature/Finance/FinanceLaboratoryTariffBindingCoreTest.php', 'test_resolver_uses_exact_master_snapshot_and_verified_service_date_without_writing_source'],
    'future-half-open-terminal-retirement' => ['tests/Feature/Finance/FinanceLaboratoryTariffBindingCoreTest.php', 'test_binding_replay_half_open_versions_and_terminal_retirement'],
    'upstream-result-chain-refusal' => ['tests/Feature/Finance/FinanceLaboratoryTariffBindingCoreTest.php', 'test_resolver_classifies_missing_and_future_bindings_closed'],
    'order-specimen-draft-cancellation-no-charge' => ['tests/Feature/Finance/FinanceLaboratorySourceAdapterTest.php', 'test_only_original_verified_result_materializes_one_typed_source_and_charge'],
    'one-original-verified-result-one-typed-source' => ['tests/Feature/Finance/FinanceLaboratorySourceAdapterTest.php', 'test_only_original_verified_result_materializes_one_typed_source_and_charge'],
    'verified-amendment-acknowledgement-no-extra-charge' => ['tests/Feature/Finance/FinanceLaboratorySourceAdapterTest.php', 'test_amendment_and_acknowledgement_do_not_duplicate_or_revalue_original_source'],
    'laboratory-only-bill-snapshot' => ['tests/Feature/Finance/FinanceLaboratorySourceAdapterTest.php', 'test_original_verified_result_is_ready_synchronized_and_issued_with_exact_laboratory_provenance'],
    'partial-sync-unresolved-issue-refusal' => ['tests/Feature/Finance/FinanceLaboratorySourceAdapterTest.php', 'test_mapped_laboratory_result_synchronizes_but_unmapped_result_blocks_issue'],
    'replay-key-conflict-retroactive-stale-refusal' => ['tests/Feature/Finance/FinanceLaboratoryTariffHttpWorkflowTest.php', 'test_finance_steward_creates_replays_revises_retires_and_reads_immutable_history'],
    'application-sql-guard-refusal' => ['tests/Feature/Database/FinanceLaboratoryTariffGuardTest.php', 'test_guard_rejects_ddl_comments_and_multiple_statements_but_allows_reads_and_schema_scope'],
    'database-append-only-head-typed-source-refusal' => ['tests/Feature/Database/FinanceLaboratoryTariffGuardTest.php', 'test_guard_refuses_direct_writes_to_every_new_table'],
    'audit-corruption-reconciliation-refusal' => ['tests/Feature/Finance/FinanceLaboratorySourceAdapterTest.php', 'test_strict_recovery_tamper_refusal_can_roll_back_without_altering_valid_laboratory_source'],
    'bounded-reset-recovery-rollback-strict-cleanup' => ['tests/Feature/Finance/FinanceLaboratorySourceAdapterTest.php', 'test_synthetic_reset_removes_laboratory_finance_graph_and_leaves_other_source_tables_consistent'],
  }.freeze
  SQLITE_FEATURE_TESTS = FEATURE_SCENARIO_TESTS.values.uniq.freeze

  WORKER_SOURCE = <<~'PHP'
    <?php
    declare(strict_types=1);

    use App\Models\Encounter;
    use App\Models\FinanceBill;
    use App\Models\FinanceBillVersion;
    use App\Models\FinanceChargeEvent;
    use App\Models\FinanceLaboratorySourceEvent;
    use App\Models\FinanceLaboratoryTariffBinding;
    use App\Models\LaboratoryExaminationMaster;
    use App\Models\LaboratoryExaminationMasterVersion;
    use App\Models\LaboratoryOrder;
    use App\Models\LaboratoryResultVersion;
    use App\Models\Patient;
    use App\Models\Role;
    use App\Models\User;
    use App\Support\Authorization\RoleCapabilityMatrix;
    use App\Support\Finance\FinanceBillService;
    use App\Support\Finance\FinanceDenied;
    use App\Support\Finance\FinanceLaboratorySourceAdapter;
    use App\Support\Finance\FinanceLaboratoryTariffBindingService;
    use App\Support\Finance\FinanceLaboratoryTariffMutationScope;
    use App\Support\Finance\FinanceMutationScope;
    use App\Support\Finance\FinanceProjection;
    use App\Support\Finance\FinanceSourceCoordinator;
    use App\Support\Finance\FinanceTariffDenied;
    use App\Support\Finance\FinanceTariffMasterService;
    use App\Support\Laboratory\LaboratoryMasterService;
    use App\Support\Laboratory\LaboratoryWorkflowService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\QueryException;
    use Illuminate\Support\Facades\DB;

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
            'schema_version' => 1,
            'status' => 'PASS',
            'protocol_state' => $state,
            'scenario' => (string) getenv('SIMRS_LABORATORY_TARIFF_SOURCE_SCENARIO'),
            'worker' => (string) getenv('SIMRS_LABORATORY_TARIFF_SOURCE_WORKER'),
        ], $extra), JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
    }

    function fixture(): array
    {
        $raw = base64_decode((string) getenv('SIMRS_LABORATORY_TARIFF_SOURCE_FIXTURE'), true);
        $decoded = $raw === false ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    function backendConnectionId(): int
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? (int) data_get(DB::selectOne('SELECT pg_backend_pid() AS id'), 'id')
            : (int) data_get(DB::selectOne('SELECT CONNECTION_ID() AS id'), 'id');
    }

    function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);
        return $user->fresh();
    }

    function createMaster(User $admin, string $prefix): LaboratoryExaminationMasterVersion
    {
        $master = app(LaboratoryMasterService::class)->create(
            $admin, 'LAB-'.$prefix.'-'.str()->upper(str()->random(6)), 'Laboratorium portability',
            'Darah EDTA', 'Koleksi sesuai prosedur.', [[
                'code' => 'HGB', 'display_name' => 'Hemoglobin', 'value_kind' => 'NUMERIC',
                'unit_text' => 'g/dL', 'reference_text' => 'Rujukan unit.', 'critical_allowed' => true,
            ]], 'lab-port-master-'.str()->lower(str()->random(12)),
        )->record;
        must($master instanceof LaboratoryExaminationMaster, 'laboratory master');
        return LaboratoryExaminationMasterVersion::query()
            ->where('laboratory_examination_master_id', $master->id)->sole();
    }

    function createTariff(User $steward, string $careSetting, int $amount, string $prefix): object
    {
        $service = app(FinanceTariffMasterService::class);
        $suffix = str()->upper(str()->random(6));
        $group = $service->createGroup(
            $steward, 'LG'.$suffix, 'Laboratorium', 'Portability',
            'lab-port-group-'.$prefix.'-'.str()->lower(str()->random(8)),
        )->record;
        $component = $service->createComponent(
            $steward, $group->public_id, 'LC'.$suffix, 'Komponen laboratorium',
            null, null, 'Portability',
            'lab-port-component-'.$prefix.'-'.str()->lower(str()->random(8)),
        )->record;
        $catalogue = $service->createCatalogue(
            $steward, 'LK'.$suffix, 'Katalog laboratorium', 'Portability',
            'lab-port-catalogue-'.$prefix.'-'.str()->lower(str()->random(8)),
        )->record;
        return $service->createTariffItem(
            $steward, $catalogue->public_id, $component->public_id, 'LT'.$suffix,
            'Tarif laboratorium portability', $careSetting, 'LABORATORY',
            null, null, $amount, '2026-09-02', 'Portability',
            'lab-port-tariff-'.$prefix.'-'.str()->lower(str()->random(8)),
        )->record;
    }

    function createAcceptedDraft(
        Encounter $encounter,
        LaboratoryExaminationMasterVersion $masterVersion,
        User $physician,
        User $nurse,
        User $technologist,
        string $prefix,
    ): array {
        $workflow = app(LaboratoryWorkflowService::class);
        $master = LaboratoryExaminationMaster::query()->findOrFail($masterVersion->laboratory_examination_master_id);
        $order = $workflow->createOrder(
            $encounter->public_id, $master->public_id, $physician,
            'ROUTINE', 'Pemeriksaan portability.',
            'lab-port-order-'.$prefix.'-'.str()->lower(str()->random(8)),
        )->record;
        must($order instanceof LaboratoryOrder, 'laboratory order');
        $attempt = $workflow->collectSpecimen(
            $order->public_id, $nurse, 1, null,
            'lab-port-collect-'.$prefix.'-'.str()->lower(str()->random(8)),
        )->record;
        $workflow->receiveSpecimen(
            $attempt->public_id, $technologist,
            'lab-port-receive-'.$prefix.'-'.str()->lower(str()->random(8)),
        );
        $workflow->acceptSpecimen(
            $attempt->public_id, $technologist, 1,
            'lab-port-accept-'.$prefix.'-'.str()->lower(str()->random(8)),
        );
        $draft = $workflow->saveDraft(
            $order->public_id, $technologist, 0,
            [['code' => 'HGB', 'value' => '13.2', 'note' => null, 'interpretation' => 'NORMAL']],
            'lab-port-draft-'.$prefix.'-'.str()->lower(str()->random(8)),
        )->record;
        must($draft instanceof LaboratoryResultVersion, 'laboratory draft');
        return [$order->fresh(), $draft->fresh()];
    }

    function prepareFixture(): array
    {
        $admin = actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $steward = actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $cashier = actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $physician = actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = actor(RoleCapabilityMatrix::ROLE_NURSE);
        $technologist = actor(RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST);
        $verifier = actor(RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER);
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Poliklinik Laboratorium Pengajaran',
        ])->fresh('patient');
        $masterVersion = createMaster($admin, 'MAIN');

        $bindings = [];
        foreach (Encounter::CARE_SETTINGS as $index => $careSetting) {
            $tariff = createTariff($steward, $careSetting, 125000 + $index, strtolower($careSetting));
            $binding = app(FinanceLaboratoryTariffBindingService::class)->create(
                $steward, $masterVersion->public_id, $careSetting, $tariff->public_id,
                '2026-09-02', 'Pemetaan portability '.$careSetting,
                'lab-port-binding-'.strtolower($careSetting).'-'.str()->lower(str()->random(8)),
            )->record;
            $bindings[$careSetting] = ['binding' => $binding, 'tariff' => $tariff];
        }

        [$order, $draft] = createAcceptedDraft(
            $encounter, $masterVersion, $physician, $nurse, $technologist, 'main',
        );
        $verified = app(LaboratoryWorkflowService::class)->verify(
            $order->public_id, $verifier, $draft->version, null,
            'lab-port-verify-main-'.str()->lower(str()->random(8)),
        )->record;
        must($verified instanceof LaboratoryResultVersion, 'original verified result');
        must($verified->state === LaboratoryResultVersion::VERIFIED, 'verified trigger');
        must($verified->base_verified_version_id === null, 'original immutable trigger');

        [$visibilityOrder, $visibilityDraft] = createAcceptedDraft(
            $encounter, $masterVersion, $physician, $nurse, $technologist, 'visibility',
        );
        $outpatient = $bindings[Encounter::CARE_SETTING_OUTPATIENT];

        return [
            'encounter_public_id' => $encounter->public_id,
            'encounter_id' => $encounter->id,
            'admin_public_id' => $admin->public_id,
            'steward_public_id' => $steward->public_id,
            'cashier_public_id' => $cashier->public_id,
            'physician_public_id' => $physician->public_id,
            'nurse_public_id' => $nurse->public_id,
            'technologist_public_id' => $technologist->public_id,
            'verifier_public_id' => $verifier->public_id,
            'master_version_public_id' => $masterVersion->public_id,
            'binding_public_id' => $outpatient['binding']->public_id,
            'binding_version' => $outpatient['binding']->version,
            'binding_digest' => $outpatient['binding']->current_content_digest,
            'tariff_public_id' => $outpatient['tariff']->public_id,
            'order_id' => $order->id,
            'verified_result_id' => $verified->id,
            'visibility_order_public_id' => $visibilityOrder->public_id,
            'visibility_draft_version' => $visibilityDraft->version,
            'visibility_draft_id' => $visibilityDraft->id,
            'binding_care_settings' => array_keys($bindings),
        ];
    }

    function user(string $publicId): User
    {
        return User::query()->where('public_id', $publicId)->sole();
    }

    function synchronizeRace(array $fixture): array
    {
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        return DB::transaction(function () use ($fixture): array {
            Encounter::query()->whereKey($fixture['encounter_id'])->lockForUpdate()->sole();
            $before = FinanceLaboratorySourceEvent::query()
                ->where('laboratory_result_version_id', $fixture['verified_result_id'])->count();
            protocol('HOLDING', ['lock_order_trace' => ['encounters']]);
            usleep(max(0, (int) getenv('SIMRS_LABORATORY_TARIFF_SOURCE_HOLD_MS')) * 1000);
            $result = app(FinanceBillService::class)->synchronize(
                $fixture['encounter_public_id'], user($fixture['cashier_public_id']),
                'lab-port-sync-'.strtolower((string) getenv('SIMRS_LABORATORY_TARIFF_SOURCE_WORKER')).'-'.str()->lower(str()->random(8)),
            );
            return [
                'outcome' => $before === 0 ? 'MATERIALIZED' : 'RECONCILED',
                'bill_public_id' => $result->record->public_id,
                'source_count' => FinanceLaboratorySourceEvent::query()
                    ->where('laboratory_result_version_id', $fixture['verified_result_id'])->count(),
            ];
        }, 3);
    }

    function bindingRace(array $fixture): array
    {
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        try {
            $result = DB::transaction(function () use ($fixture) {
                FinanceLaboratoryTariffMutationScope::run(
                    fn () => FinanceLaboratoryTariffBinding::query()
                        ->where('public_id', $fixture['binding_public_id'])->lockForUpdate()->sole(),
                );
                protocol('HOLDING', ['lock_order_trace' => ['finance_laboratory_tariff_bindings']]);
                usleep(max(0, (int) getenv('SIMRS_LABORATORY_TARIFF_SOURCE_HOLD_MS')) * 1000);
                return app(FinanceLaboratoryTariffBindingService::class)->appendVersion(
                    user($fixture['steward_public_id']), $fixture['binding_public_id'],
                    $fixture['tariff_public_id'], '2026-09-03',
                    $fixture['binding_version'], $fixture['binding_digest'],
                    'Penulis bersamaan portability',
                    'lab-port-bind-race-'.strtolower((string) getenv('SIMRS_LABORATORY_TARIFF_SOURCE_WORKER')),
                );
            }, 3);
            return ['outcome' => 'APPLIED', 'version' => $result->record->version];
        } catch (FinanceTariffDenied $denied) {
            must(in_array($denied->reason, ['stale_version', 'stale_digest', 'concurrent_state_conflict'], true), 'expected concurrent denial');
            return ['outcome' => 'DENIED', 'reason' => $denied->reason];
        }
    }

    function verifyVisibility(array $fixture): array
    {
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        return DB::transaction(function () use ($fixture): array {
            Encounter::query()->whereKey($fixture['encounter_id'])->lockForUpdate()->sole();
            protocol('HOLDING', ['lock_order_trace' => ['encounters']]);
            usleep(max(0, (int) getenv('SIMRS_LABORATORY_TARIFF_SOURCE_HOLD_MS')) * 1000);
            $verified = app(LaboratoryWorkflowService::class)->verify(
                $fixture['visibility_order_public_id'], user($fixture['verifier_public_id']),
                $fixture['visibility_draft_version'], null,
                'lab-port-visibility-verify-'.str()->lower(str()->random(8)),
            )->record;
            must($verified instanceof LaboratoryResultVersion, 'visibility verified result');
            return ['outcome' => 'VERIFIED', 'verified_result_id' => $verified->id];
        }, 3);
    }

    function synchronizeVisibility(array $fixture): array
    {
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        $before = FinanceLaboratorySourceEvent::query()
            ->where('laboratory_order_id', LaboratoryOrder::query()
                ->where('public_id', $fixture['visibility_order_public_id'])->value('id'))->count();
        $result = app(FinanceBillService::class)->synchronize(
            $fixture['encounter_public_id'], user($fixture['cashier_public_id']),
            'lab-port-visibility-sync-'.str()->lower(str()->random(8)),
        );
        $after = FinanceLaboratorySourceEvent::query()
            ->where('laboratory_order_id', LaboratoryOrder::query()
                ->where('public_id', $fixture['visibility_order_public_id'])->value('id'))->count();
        must($after === 1, 'post-verification source visibility');
        return [
            'outcome' => $before === 0 ? 'MATERIALIZED' : 'RECONCILED',
            'bill_public_id' => $result->record->public_id,
            'source_count' => $after,
        ];
    }

    function verifyFixture(array $fixture): array
    {
        $encounter = Encounter::query()->where('public_id', $fixture['encounter_public_id'])->sole();
        $source = FinanceLaboratorySourceEvent::query()
            ->where('laboratory_result_version_id', $fixture['verified_result_id'])->sole();
        $charges = FinanceChargeEvent::query()
            ->where('finance_laboratory_source_event_id', $source->id)->get();
        must($charges->count() === 1, 'one typed charge per original verified result');
        must($source->resultVersion->state === LaboratoryResultVersion::VERIFIED, 'source trigger remains verified');
        must($source->resultVersion->base_verified_version_id === null, 'source trigger remains original');
        app(FinanceLaboratorySourceAdapter::class)->verifyRetained($encounter, $charges);
        $readiness = app(FinanceSourceCoordinator::class)->readiness($encounter);
        must($readiness['unresolved_count'] === 0, 'source reconciled');
        return [
            'source_count' => 1,
            'charge_count' => $charges->count(),
            'unit_amount' => $source->unit_amount,
            'source_domain' => $charges->sole()->source_domain,
            'binding_care_settings' => $fixture['binding_care_settings'],
            'original_verified_only' => true,
            'reconciled' => true,
        ];
    }

    function resolveLaterGap(array $fixture): array
    {
        $cashier = user($fixture['cashier_public_id']);
        $steward = user($fixture['steward_public_id']);
        $admin = user($fixture['admin_public_id']);
        $physician = user($fixture['physician_public_id']);
        $nurse = user($fixture['nurse_public_id']);
        $technologist = user($fixture['technologist_public_id']);
        $verifier = user($fixture['verifier_public_id']);
        $encounter = Encounter::query()->where('public_id', $fixture['encounter_public_id'])->sole();
        $billing = app(FinanceBillService::class);
        $projection = app(FinanceProjection::class);
        $bill = FinanceBill::query()->where('encounter_id', $encounter->id)->sole();
        $versionOne = $billing->issue(
            $bill->public_id, $cashier, $projection->fingerprint($bill->fresh()),
            'Penerbitan sebelum sumber susulan.', 'lab-port-gap-issue-v1',
        )->record;
        $versionOneLines = $versionOne->lines()->count();

        $gapMaster = createMaster($admin, 'GAP');
        [$gapOrder, $gapDraft] = createAcceptedDraft(
            $encounter, $gapMaster, $physician, $nurse, $technologist, 'gap',
        );
        $gapVerified = app(LaboratoryWorkflowService::class)->verify(
            $gapOrder->public_id, $verifier, $gapDraft->version, null,
            'lab-port-gap-verify-'.str()->lower(str()->random(8)),
        )->record;
        $billing->synchronize($encounter->public_id, $cashier, 'lab-port-gap-unresolved-sync');
        $readiness = app(FinanceSourceCoordinator::class)->readiness($encounter->fresh());
        must($readiness['unresolved_count'] === 1 && $readiness['issue_blocked'] === true, 'gap blocks issue');
        try {
            $billing->issue(
                $bill->public_id, $cashier, $projection->fingerprint($bill->fresh()),
                'Penerbitan harus ditolak saat gap.', 'lab-port-gap-denied-issue',
            );
            throw new RuntimeException('unresolved issue unexpectedly allowed');
        } catch (FinanceDenied $denied) {
            must($denied->reason === 'unresolved_laboratory_source', 'unresolved issue reason');
        }
        must(FinanceLaboratorySourceEvent::query()
            ->where('laboratory_result_version_id', $gapVerified->id)->doesntExist(), 'gap not materialized');

        $tariff = createTariff($steward, Encounter::CARE_SETTING_OUTPATIENT, 75000, 'gap');
        app(FinanceLaboratoryTariffBindingService::class)->create(
            $steward, $gapMaster->public_id, Encounter::CARE_SETTING_OUTPATIENT,
            $tariff->public_id, '2026-09-02', 'Resolusi gap',
            'lab-port-gap-binding-'.str()->lower(str()->random(8)),
        );
        $billing->synchronize($encounter->public_id, $cashier, 'lab-port-gap-resolved-sync');
        $bill = $bill->fresh();
        $versionTwo = $billing->issue(
            $bill->public_id, $cashier, $projection->fingerprint($bill),
            'Penerbitan setelah gap terselesaikan.', 'lab-port-gap-issue-v2',
        )->record;
        must($versionTwo instanceof FinanceBillVersion && $versionTwo->version === 2, 'version two issued');
        must($versionOne->fresh()->lines()->count() === $versionOneLines, 'version one remains immutable');
        must($versionTwo->lines()->count() === $versionOneLines + 1, 'version two includes resolved gap');
        return [
            'unresolved_issue_refused' => true,
            'gap_source_count_before' => 0,
            'gap_source_count_after' => 1,
            'version_one_lines' => $versionOneLines,
            'version_two_lines' => $versionOneLines + 1,
            'history_rewritten' => false,
        ];
    }

    function privilegeGuards(): array
    {
        $tables = [
            'finance_laboratory_tariff_bindings', 'finance_laboratory_tariff_binding_versions',
            'finance_laboratory_tariff_operation_receipts', 'finance_laboratory_source_events',
            'finance_charge_events', 'finance_bills', 'finance_bill_versions', 'finance_bill_lines',
        ];
        foreach ($tables as $table) {
            DB::table($table)->limit(1)->get();
            try {
                DB::transaction(fn () => FinanceMutationScope::run(
                    fn () => FinanceLaboratoryTariffMutationScope::run(
                        fn () => DB::table($table)->update(['id' => DB::raw('id')]),
                    ),
                ));
                throw new RuntimeException('write unexpectedly allowed: '.$table);
            } catch (QueryException) {
                // Exact reduced runtime identity is SELECT-only.
            }
        }
        return ['read_tables' => count($tables), 'forbidden_operations_refused' => true];
    }

    $action = (string) getenv('SIMRS_LABORATORY_TARIFF_SOURCE_ACTION');
    $scenario = (string) getenv('SIMRS_LABORATORY_TARIFF_SOURCE_SCENARIO');
    try {
        $fixture = fixture();
        $result = match ($action) {
            'prepare' => ['fixture' => prepareFixture()],
            'synchronize_race' => synchronizeRace($fixture),
            'binding_race' => bindingRace($fixture),
            'verify_visibility' => verifyVisibility($fixture),
            'synchronize_visibility' => synchronizeVisibility($fixture),
            'verify' => ['result' => verifyFixture($fixture)],
            'resolve_gap' => ['result' => resolveLaterGap($fixture)],
            'privilege_guards' => ['result' => privilegeGuards()],
            default => throw new RuntimeException('unknown laboratory tariff/source action'),
        };
        protocol('COMMITTED', $result);
    } catch (Throwable $exception) {
        $query = $exception instanceof QueryException ? $exception : null;
        $errorInfo = $query?->errorInfo ?? [];
        echo json_encode([
            'schema_version' => 1, 'status' => 'BLOCKED', 'protocol_state' => 'FAILED',
            'scenario' => $scenario,
            'worker' => (string) getenv('SIMRS_LABORATORY_TARIFF_SOURCE_WORKER'),
            'exception_class' => get_class($exception),
            'exception_fingerprint' => hash('sha256', get_class($exception)."\0".$exception->getMessage()),
            'diagnostic_message' => ($exception instanceof LogicException || $exception instanceof RuntimeException)
                ? mb_substr($exception->getMessage(), 0, 300) : null,
            'failure_stage' => $action,
            'sql_state' => (string) ($errorInfo[0] ?? ''),
            'driver_code' => (string) ($errorInfo[1] ?? ''),
            'query_head' => $query ? mb_substr($query->getSql(), 0, 180) : null,
        ], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
  PHP

  def assert_contract!
    unless @operator_environment[CONFIRMATION_ENV] == CONFIRMATION
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize the disposable local rehearsal."
    end
    rejected = FORBIDDEN_ENVIRONMENT.select { |name| !@operator_environment.fetch(name, '').to_s.strip.empty? }
    raise CommandFailed, "Laboratory tariff/source rehearsal refuses inherited overrides: #{rejected.join(', ')}." unless rejected.empty?
    LocalPortabilityFullSuiteRehearsal.instance_method(:assert_contract!).bind(self).call
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    raise CommandFailed, 'Laboratory tariff/source scenario catalogue drifted.' unless SCENARIOS.length == 22 && SCENARIOS.uniq.length == 22
    raise CommandFailed, 'Embedded laboratory tariff/source worker source is unexpectedly small.' unless WORKER_SOURCE.bytesize > 12_000
  end

  def application_environment
    LocalPortabilityFullSuiteRehearsal.instance_method(:application_environment).bind(self).call.merge(
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false', 'VCLAIM_ENABLED' => 'false',
      'SATUSEHAT_ENABLED' => 'false', 'LIS_INTEGRATION_ENABLED' => 'false',
      'PACS_INTEGRATION_ENABLED' => 'false', 'COLUMNS' => '300'
    )
  end

  def current_laboratory_tariff_source_bindings
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

  def worker_environment(action:, scenario:, worker:, fixture:, hold_ms:, connection_environment: nil)
    (connection_environment || @runtime_application_environment || application_environment).merge(
      'SIMRS_REHEARSAL_ROOT' => ROOT,
      'SIMRS_LABORATORY_TARIFF_SOURCE_ACTION' => action,
      'SIMRS_LABORATORY_TARIFF_SOURCE_SCENARIO' => scenario,
      'SIMRS_LABORATORY_TARIFF_SOURCE_WORKER' => worker,
      'SIMRS_LABORATORY_TARIFF_SOURCE_RUN_TOKEN' => @run_token,
      'SIMRS_LABORATORY_TARIFF_SOURCE_HOLD_MS' => hold_ms.to_s,
      'SIMRS_LABORATORY_TARIFF_SOURCE_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {}))
    )
  end

  def create_worker_file!
    @worker_tempfile = Tempfile.new(['simrs-laboratory-tariff-source-portability-', '.php'])
    @worker_tempfile.binmode
    @worker_tempfile.write(WORKER_SOURCE)
    @worker_tempfile.flush
    @worker_tempfile.chmod(0o600)
    @worker_tempfile.close
  end

  def bind_feature_evidence!(bindings)
    bindings.to_h do |label, (path, method)|
      source = File.read(safe_source_path(path), encoding: Encoding::UTF_8)
      raise CommandFailed, "Scenario-bound laboratory tariff/source feature method is missing: #{path}##{method}." unless source.include?("function #{method}")
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

  def start_domain_race_worker!(action:, scenario:, fixture:, worker:, hold_ms:)
    @command_catalog << ['laboratory-tariff-source-worker', action, "--scenario=#{scenario}", "--worker=#{worker}"]
    stdin, stdout, stderr, wait_thread = Open3.popen3(
      @runner.process_environment(worker_environment(
        action: action, scenario: scenario, worker: worker, fixture: fixture,
        hold_ms: hold_ms, connection_environment: application_environment
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
    await_protocol!(first, 'STARTED')
    holding = await_protocol!(first, 'HOLDING')
    raise CommandFailed, "#{scenario} lock trace drifted." unless holding.fetch('lock_order_trace') == expected_lock_order
    second = start_domain_race_worker!(action: action, scenario: scenario, fixture: fixture, worker: 'B', hold_ms: 0)
    started = await_protocol!(second, 'STARTED')
    raise CommandFailed, "#{scenario} did not expose a real database wait." unless observe_real_database_wait!(Integer(started.fetch('backend_connection_id')))
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

  def run_visibility_race!(fixture:)
    scenario = 'verification-synchronization-cutoff-concurrency'
    verifier = start_domain_race_worker!(action: 'verify_visibility', scenario: scenario, fixture: fixture, worker: 'VERIFY', hold_ms: HOLD_MS)
    await_protocol!(verifier, 'STARTED')
    holding = await_protocol!(verifier, 'HOLDING')
    raise CommandFailed, "#{scenario} lock trace drifted." unless holding.fetch('lock_order_trace') == %w[encounters]
    synchronizer = start_domain_race_worker!(action: 'synchronize_visibility', scenario: scenario, fixture: fixture, worker: 'SYNC', hold_ms: 0)
    started = await_protocol!(synchronizer, 'STARTED')
    raise CommandFailed, "#{scenario} did not expose a real database wait." unless observe_real_database_wait!(Integer(started.fetch('backend_connection_id')))
    finals = [await_final!(verifier), await_final!(synchronizer)]
    outcomes = finals.map { |document| document.fetch('outcome') }.sort
    raise CommandFailed, "#{scenario} outcomes drifted: #{outcomes.join(',')}." unless outcomes == %w[MATERIALIZED VERIFIED]
    {
      'status' => 'PASS', 'proof_kind' => 'OBSERVED_VERIFY_SYNCHRONIZE_VISIBILITY_RACE',
      'independent_application_processes' => 2, 'real_database_wait_observed' => true,
      'observed_lock_order' => %w[encounters], 'outcomes' => outcomes,
      'visibility_rule' => 'committed_original_verified_result_only', 'deadlock_observed' => false,
    }
  ensure
    terminate_workers!
  end

  def provision_postgres_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    raise CommandFailed, 'Generated PostgreSQL identities failed closed pattern.' unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
    tables = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT tablename FROM pg_tables WHERE schemaname='laravel' ORDER BY tablename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "PostgreSQL laboratory tariff/source runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = [%(CREATE ROLE "#{runtime}" LOGIN), %(CREATE ROLE "#{reset}" LOGIN), %(GRANT CONNECT ON DATABASE "#{@postgres_database}" TO "#{runtime}", "#{reset}"), %(GRANT USAGE ON SCHEMA "laravel" TO "#{runtime}", "#{reset}")]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << %(GRANT #{grants} ON TABLE "laravel"."#{table}" TO "#{runtime}") }
    tables.each { |table| statements << %(GRANT #{table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON TABLE "laravel"."#{table}" TO "#{reset}") }
    @runner.run!(postgres_psql_arguments(@postgres_database) + ['--set', 'ON_ERROR_STOP=1', '--command', statements.join(";\n")+';'], env: postgres_tool_environment)
    RUNTIME_TABLE_GRANTS.each do |table, expected|
      actual = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name='#{table}'"], env: postgres_tool_environment).strip
      raise CommandFailed, "PostgreSQL laboratory tariff/source runtime grant drifted for #{table}." unless actual == expected
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
    raise CommandFailed, "MySQL laboratory tariff/source runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = ["CREATE USER '#{runtime}'@'127.0.0.1' IDENTIFIED BY '#{runtime_password}'", "CREATE USER '#{reset}'@'127.0.0.1' IDENTIFIED BY '#{reset_password}'"]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << "GRANT #{grants} ON `#{@mysql_database}`.`#{table}` TO '#{runtime}'@'127.0.0.1'" }
    tables.each { |table| statements << "GRANT #{table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON `#{@mysql_database}`.`#{table}` TO '#{reset}'@'127.0.0.1'" }
    statements << 'FLUSH PRIVILEGES'
    @runner.run!(mysql_root_arguments, stdin_data: statements.join(";\n")+';')
    grants = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SHOW GRANTS FOR '#{runtime}'@'127.0.0.1';").upcase
    raise CommandFailed, 'Reduced MySQL laboratory tariff/source runtime retained forbidden write or DDL grants.' unless %w[INSERT UPDATE DELETE DROP ALTER TRIGGER CREATE].none? { |privilege| grants.match?(/\b#{privilege}\b/) }
    raise CommandFailed, 'MySQL laboratory tariff/source runtime received a schema wildcard grant.' if grants.include?("ON `#{@mysql_database.upcase}`.*")
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => runtime_password)
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => reset_password)
  end

  def expect_laboratory_tariff_source_rollback_refusal!
    arguments = ['migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(@runner.process_environment(application_environment), @php_binary, File.join(ROOT, 'artisan'), *arguments, unsetenv_others: true)
    raise CommandFailed, 'Retained laboratory tariff/source evidence unexpectedly allowed rollback.' if status.success?
    combined = (stdout + stderr).gsub(/\s+/, '').downcase
    expected = %w[correlatedauditevidenceremains typedfinancechargesremain retainedrowsremain]
    raise CommandFailed, 'Laboratory tariff/source rollback failed for an unexpected reason.' unless expected.any? { |fragment| combined.include?(fragment) }
    @result_catalog << [['artisan', *arguments], 'EXPECTED_REFUSAL']
    'PASS'
  end

  def write_laboratory_tariff_source_evidence!(bindings:, engine_binding:, migration_duration_ms:, scenarios:, sqlite_gate:)
    assert_evidence_directory!
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-laboratory-tariff-source-#{SecureRandom.hex(6)}.json")
    evidence = {
      'schema_version' => 1, 'kind' => EVIDENCE_KIND, 'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_LABORATORY_TARIFF_SOURCE_ONLY',
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
    bindings = current_laboratory_tariff_source_bindings
    sqlite_gate = run_sqlite_gate!
    engine_binding = prepare_engine!
    @run_token = database_run_token
    create_worker_file!
    started = @clock.call
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(started)
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    recorded_artisan!('migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    recorded_artisan!('migrate', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    feature = bind_feature_evidence!(FEATURE_SCENARIO_TESTS)
    recreate_application_schema_outside_guard!
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    prepared = run_worker_command!(action: 'prepare', scenario: 'fresh-migration', worker: 'PREPARE', connection_environment: application_environment)
    fixture = prepared.fetch('fixture')
    source_race = run_domain_race!(
      action: 'synchronize_race', scenario: 'same-verified-result-import-concurrency', fixture: fixture,
      expected_outcomes: %w[MATERIALIZED RECONCILED], expected_lock_order: %w[encounters]
    )
    visibility_race = run_visibility_race!(fixture: fixture)
    verified = run_worker_command!(action: 'verify', scenario: 'one-original-verified-result-one-typed-source', worker: 'VERIFY', fixture: fixture, connection_environment: application_environment)
    gap_resolution = run_worker_command!(action: 'resolve_gap', scenario: 'later-gap-resolution-new-version', worker: 'RESOLVE_GAP', fixture: fixture, connection_environment: application_environment)
    binding_race = run_domain_race!(
      action: 'binding_race', scenario: 'competing-binding-writer-concurrency', fixture: fixture,
      expected_outcomes: %w[APPLIED DENIED], expected_lock_order: %w[finance_laboratory_tariff_bindings]
    )
    provision_runtime_identities!
    privilege = run_worker_command!(action: 'privilege_guards', scenario: 'least-privilege-runtime', worker: 'RUNTIME', fixture: fixture)
    rollback = expect_laboratory_tariff_source_rollback_refusal!

    scenarios = {
      'fresh-migration' => { 'status' => 'PASS', 'proof_kind' => 'FRESH_EXACT_ENGINE_MIGRATION_AND_EMPTY_READBACK' },
      'empty-down-reapply' => { 'status' => 'PASS', 'proof_kind' => 'EMPTY_MIGRATION_ROLLBACK_REAPPLY' },
      'exact-role-boundary' => feature.fetch('exact-role-boundary'),
      'three-care-setting-bindings' => { 'status' => 'PASS', 'proof_kind' => 'REAL_GOVERNED_BINDING_SERVICE_WORKER_AND_FILTERED_TEST', 'care_settings' => verified.fetch('result').fetch('binding_care_settings'), 'feature' => feature.fetch('three-care-setting-bindings') },
      'original-verified-result-service-date-resolution' => feature.fetch('original-verified-result-service-date-resolution'),
      'future-half-open-terminal-retirement' => feature.fetch('future-half-open-terminal-retirement'),
      'upstream-result-chain-refusal' => feature.fetch('upstream-result-chain-refusal'),
      'order-specimen-draft-cancellation-no-charge' => feature.fetch('order-specimen-draft-cancellation-no-charge'),
      'one-original-verified-result-one-typed-source' => { 'status' => 'PASS', 'proof_kind' => 'REAL_SERVICE_WORKER_AND_FILTERED_EXACT_ENGINE_TEST', 'worker' => verified.fetch('result'), 'feature' => feature.fetch('one-original-verified-result-one-typed-source') },
      'verified-amendment-acknowledgement-no-extra-charge' => feature.fetch('verified-amendment-acknowledgement-no-extra-charge'),
      'laboratory-only-bill-snapshot' => feature.fetch('laboratory-only-bill-snapshot'),
      'partial-sync-unresolved-issue-refusal' => feature.fetch('partial-sync-unresolved-issue-refusal'),
      'later-gap-resolution-new-version' => { 'status' => 'PASS', 'proof_kind' => 'REAL_GAP_RESOLUTION_AND_IMMUTABLE_BILL_VERSION_WORKER', 'worker' => gap_resolution.fetch('result'), 'feature' => feature.fetch('partial-sync-unresolved-issue-refusal') },
      'replay-key-conflict-retroactive-stale-refusal' => feature.fetch('replay-key-conflict-retroactive-stale-refusal'),
      'same-verified-result-import-concurrency' => source_race,
      'competing-binding-writer-concurrency' => binding_race,
      'verification-synchronization-cutoff-concurrency' => visibility_race,
      'application-sql-guard-refusal' => feature.fetch('application-sql-guard-refusal'),
      'database-append-only-head-typed-source-refusal' => feature.fetch('database-append-only-head-typed-source-refusal'),
      'audit-corruption-reconciliation-refusal' => { 'status' => 'PASS', 'proof_kind' => 'FILTERED_CORRUPTION_FEATURE_AND_DURABLE_RETAINED_SOURCE_RECONCILIATION', 'feature' => feature.fetch('audit-corruption-reconciliation-refusal'), 'worker' => verified.fetch('result') },
      'least-privilege-runtime' => { 'status' => 'PASS', 'proof_kind' => 'READ_BACK_SELECT_ONLY_RUNTIME_IDENTITY', 'worker' => privilege.fetch('result') },
      'bounded-reset-recovery-rollback-strict-cleanup' => { 'status' => rollback, 'proof_kind' => 'EXPECTED_MIGRATION_REFUSAL_AND_ENGINE_OWNED_STRICT_CLEANUP', 'feature' => feature.fetch('bounded-reset-recovery-rollback-strict-cleanup') },
    }
    raise CommandFailed, 'Laboratory tariff/source scenario evidence is incomplete.' unless scenarios.keys == SCENARIOS && scenarios.values.all? { |result| result['status'] == 'PASS' }
    assert_unchanged_binding!('Laboratory tariff/source execution bindings', bindings, current_laboratory_tariff_source_bindings)
    cleanup!(strict: true)
    evidence_path = write_laboratory_tariff_source_evidence!(bindings: bindings, engine_binding: engine_binding, migration_duration_ms: migration_duration_ms, scenarios: scenarios, sqlite_gate: sqlite_gate)
    {
      'status' => 'PASS', 'claim' => 'LOCAL_DISPOSABLE_LABORATORY_TARIFF_SOURCE_ONLY',
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
    unless ARGV.length == 1 && LocalLaboratoryTariffSourcePortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalLaboratoryTariffSourcePortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalLaboratoryTariffSourcePortabilityRehearsal::CommandFailed => e
    warn "laboratory tariff/source portability rehearsal failed: #{e.message}"
    exit 1
  end
end
