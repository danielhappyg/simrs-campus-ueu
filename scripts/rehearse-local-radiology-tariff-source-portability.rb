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
# performed radiology service, its governed tariff and the versioned bill.
class LocalRadiologyTariffSourcePortabilityRehearsal < LocalFinanceTariffComponentMasterPortabilityRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_RADIOLOGY_TARIFF_SOURCE'
  CONFIRMATION_ENV = 'SIMRS_RADIOLOGY_TARIFF_SOURCE_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-radiology-tariff-source-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalRadiologyTariffSourcePortabilityHarnessContractTest.rb'
  MIGRATION_PATH = 'database/migrations/2026_09_02_000300_create_radiology_performance_tariff_source.php'
  EVIDENCE_TEMPLATE_PATH = 'docs/operations/T1_LOCAL_RADIOLOGY_TARIFF_SOURCE_EVIDENCE_TEMPLATE_2026-09-02.md'
  EVIDENCE_KIND = 'SIMRS_LOCAL_RADIOLOGY_TARIFF_SOURCE_PORTABILITY'
  EXECUTION_STATE = 'READY_NOT_RUN'

  SCENARIOS = %w[
    fresh-migration
    empty-down-reapply
    exact-role-boundary
    three-care-setting-bindings
    performed-service-date-resolution
    future-half-open-terminal-retirement
    upstream-context-refusal
    order-report-amendment-no-charge
    one-performance-one-typed-source
    radiology-only-bill-snapshot
    partial-sync-unresolved-issue-refusal
    later-gap-resolution-new-version
    replay-key-conflict-stale-refusal
    same-performance-import-concurrency
    competing-binding-writer-concurrency
    application-sql-guard-refusal
    database-append-only-and-head-refusal
    audit-corruption-and-reconciliation-refusal
    least-privilege-runtime
    bounded-reset-recovery-rollback-and-strict-cleanup
  ].freeze

  RADIOLOGY_TABLES = %w[
    finance_radiology_tariff_bindings finance_radiology_tariff_binding_versions
    finance_radiology_tariff_operation_receipts finance_radiology_source_events
  ].freeze
  RUNTIME_READ_TABLES = %w[
    users roles permissions role_user permission_role patients encounters
    radiology_examination_masters radiology_examination_master_versions
    radiology_orders radiology_performances
    finance_cost_component_groups finance_cost_component_group_versions
    finance_cost_components finance_cost_component_versions
    finance_tariff_catalogues finance_tariff_catalogue_versions
    finance_tariff_items finance_tariff_item_versions
    finance_radiology_tariff_bindings finance_radiology_tariff_binding_versions
    finance_radiology_tariff_operation_receipts finance_radiology_source_events
    finance_charge_events finance_bills finance_bill_versions finance_bill_lines
    finance_operation_receipts audit_events
  ].freeze
  RUNTIME_TABLE_GRANTS = RUNTIME_READ_TABLES.to_h { |table| [table, 'SELECT'] }.freeze

  SOURCE_PATHS = %w[
    scripts/rehearse-local-radiology-tariff-source-portability.rb
    scripts/rehearse-local-finance-tariff-component-master-portability.rb
    scripts/rehearse-local-finance-billing-portability.rb
    scripts/rehearse-local-radiology-portability.rb
    scripts/rehearse-local-portability-full-suite.rb
    tests/Documentation/LocalRadiologyTariffSourcePortabilityHarnessContractTest.rb
    tests/Documentation/CrossSettingRadiologyPerformanceTariffSourceV1LocalEngineeringAuthorizationTest.rb
    docs/new-simrs-rebuild/phase-1/CROSS_SETTING_RADIOLOGY_PERFORMANCE_TARIFF_SOURCE_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md
    docs/operations/T1_LOCAL_RADIOLOGY_TARIFF_SOURCE_EVIDENCE_TEMPLATE_2026-09-02.md
    database/migrations/2026_09_02_000300_create_radiology_performance_tariff_source.php
    tests/Feature/Finance/FinanceRadiologyTariffBindingCoreTest.php
    tests/Feature/Finance/FinanceRadiologySourceAdapterTest.php
    tests/Feature/Database/FinanceRadiologyTariffGuardTest.php
    tests/Feature/Operations/FinanceRadiologyTariffRecoverySnapshotTest.php
    app/Models/FinanceRadiologyTariffBinding.php
    app/Models/FinanceRadiologyTariffBindingVersion.php
    app/Models/FinanceRadiologyTariffOperationReceipt.php
    app/Models/FinanceRadiologySourceEvent.php
    app/Support/Finance/FinanceRadiologyTariffActorPolicy.php
    app/Support/Finance/FinanceRadiologyTariffAppendOnlyGuard.php
    app/Support/Finance/FinanceRadiologyTariffBindingService.php
    app/Support/Finance/FinanceRadiologyTariffContentDigest.php
    app/Support/Finance/FinanceRadiologyTariffMutableHeadGuard.php
    app/Support/Finance/FinanceRadiologyTariffMutationScope.php
    app/Support/Finance/FinanceRadiologyTariffProjection.php
    app/Support/Finance/FinanceRadiologyTariffSqlWriteGuard.php
    app/Support/Finance/FinanceRadiologySourceAdapter.php
    app/Support/Finance/FinanceSourceCoordinator.php
    app/Support/Finance/FinanceBillService.php
    app/Support/Operations/SyntheticRecoverySnapshot.php
    app/Support/Simulation/SyntheticResetService.php
  ].freeze

  FEATURE_SCENARIO_TESTS = {
    'exact-role-boundary' => ['tests/Feature/Finance/FinanceRadiologyTariffBindingCoreTest.php', 'test_exact_roles_are_non_bypass_and_cashier_is_view_only'],
    'performed-service-date-resolution' => ['tests/Feature/Finance/FinanceRadiologyTariffBindingCoreTest.php', 'test_resolver_uses_exact_order_snapshot_and_performed_service_date'],
    'future-half-open-terminal-retirement' => ['tests/Feature/Finance/FinanceRadiologyTariffBindingCoreTest.php', 'test_binding_versions_replay_and_terminal_retirement_preserve_half_open_history'],
    'upstream-context-refusal' => ['tests/Feature/Finance/FinanceRadiologyTariffBindingCoreTest.php', 'test_resolver_distinguishes_future_binding_and_missing_master_relation'],
    'one-performance-one-typed-source' => ['tests/Feature/Finance/FinanceRadiologySourceAdapterTest.php', 'test_one_performance_materializes_one_typed_source_and_one_radiology_only_bill_version'],
    'radiology-only-bill-snapshot' => ['tests/Feature/Finance/FinanceRadiologySourceAdapterTest.php', 'test_one_performance_materializes_one_typed_source_and_one_radiology_only_bill_version'],
    'partial-sync-unresolved-issue-refusal' => ['tests/Feature/Finance/FinanceRadiologySourceAdapterTest.php', 'test_valid_source_synchronizes_but_unmapped_performance_blocks_issue'],
    'replay-key-conflict-stale-refusal' => ['tests/Feature/Finance/FinanceRadiologyTariffBindingCoreTest.php', 'test_changed_idempotency_payload_and_retroactive_or_stale_versions_fail_closed'],
    'application-sql-guard-refusal' => ['tests/Feature/Database/FinanceRadiologyTariffGuardTest.php', 'test_guard_rejects_ddl_comments_and_multiple_statements_but_allows_reads_and_schema_scope'],
    'database-append-only-and-head-refusal' => ['tests/Feature/Database/FinanceRadiologyTariffGuardTest.php', 'test_guard_refuses_direct_writes_to_every_new_table'],
    'audit-corruption-and-reconciliation-refusal' => ['tests/Feature/Operations/FinanceRadiologyTariffRecoverySnapshotTest.php', 'test_integrity_helpers_detect_binding_receipt_and_source_corruption'],
    'bounded-reset-recovery-rollback-and-strict-cleanup' => ['tests/Feature/Operations/FinanceRadiologyTariffRecoverySnapshotTest.php', 'test_reset_removes_complete_radiology_finance_graph_and_preserves_audit_evidence'],
  }.freeze

  WORKER_SOURCE = <<~'PHP'
    <?php
    declare(strict_types=1);

    use App\Models\Encounter;
    use App\Models\FinanceBill;
    use App\Models\FinanceBillVersion;
    use App\Models\FinanceChargeEvent;
    use App\Models\FinanceRadiologySourceEvent;
    use App\Models\FinanceRadiologyTariffBinding;
    use App\Models\FinanceRadiologyTariffBindingVersion;
    use App\Models\Patient;
    use App\Models\RadiologyExaminationMaster;
    use App\Models\RadiologyExaminationMasterVersion;
    use App\Models\RadiologyOrder;
    use App\Models\RadiologyPerformance;
    use App\Models\Role;
    use App\Models\User;
    use App\Support\Authorization\RoleCapabilityMatrix;
    use App\Support\Finance\FinanceBillService;
    use App\Support\Finance\FinanceDenied;
    use App\Support\Finance\FinanceMutationScope;
    use App\Support\Finance\FinanceProjection;
    use App\Support\Finance\FinanceRadiologySourceAdapter;
    use App\Support\Finance\FinanceRadiologyTariffBindingService;
    use App\Support\Finance\FinanceRadiologyTariffMutationScope;
    use App\Support\Finance\FinanceRadiologyTariffProjection;
    use App\Support\Finance\FinanceSourceCoordinator;
    use App\Support\Finance\FinanceTariffDenied;
    use App\Support\Finance\FinanceTariffMasterService;
    use App\Support\Radiology\RadiologyMasterService;
    use App\Support\Radiology\RadiologyMutationScope;
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
            'scenario' => (string) getenv('SIMRS_RADIOLOGY_TARIFF_SOURCE_SCENARIO'),
            'worker' => (string) getenv('SIMRS_RADIOLOGY_TARIFF_SOURCE_WORKER'),
        ], $extra), JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
    }

    function fixture(): array
    {
        $raw = base64_decode((string) getenv('SIMRS_RADIOLOGY_TARIFF_SOURCE_FIXTURE'), true);
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

    function prepareFixture(): array
    {
        $admin = actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $steward = actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $cashier = actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $physician = actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $technologist = actor(RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST);
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Poliklinik Radiologi Pengajaran',
        ]);
        $master = app(RadiologyMasterService::class)->create(
            $admin, 'RAD-PORT-'.str()->upper(str()->random(6)), 'Radiologi portability', null,
            'rad-port-master-'.str()->lower(str()->random(12)),
        )->record;
        must($master instanceof RadiologyExaminationMaster, 'radiology master');
        $masterVersion = RadiologyExaminationMasterVersion::query()
            ->where('radiology_examination_master_id', $master->id)->sole();

        $tariffs = app(FinanceTariffMasterService::class);
        $suffix = str()->upper(str()->random(6));
        $group = $tariffs->createGroup($steward, 'RG'.$suffix, 'Radiologi', 'Portability', 'rad-port-group-'.str()->lower(str()->random(12)))->record;
        $component = $tariffs->createComponent($steward, $group->public_id, 'RC'.$suffix, 'Komponen radiologi', null, null, 'Portability', 'rad-port-component-'.str()->lower(str()->random(12)))->record;
        $catalogue = $tariffs->createCatalogue($steward, 'RK'.$suffix, 'Katalog radiologi', 'Portability', 'rad-port-catalogue-'.str()->lower(str()->random(12)))->record;
        $tariff = $tariffs->createTariffItem(
            $steward, $catalogue->public_id, $component->public_id, 'RT'.$suffix,
            'Tarif radiologi portability', 'OUTPATIENT', 'RADIOLOGY', null, null, 125000,
            '2026-09-02', 'Portability', 'rad-port-tariff-'.str()->lower(str()->random(12)),
        )->record;
        $binding = app(FinanceRadiologyTariffBindingService::class)->create(
            $steward, $masterVersion->public_id, 'OUTPATIENT', $tariff->public_id,
            '2026-09-02', 'Pemetaan portability', 'rad-port-binding-'.str()->lower(str()->random(12)),
        )->record;

        foreach (['EMERGENCY', 'INPATIENT'] as $careSetting) {
            $careSuffix = substr($careSetting, 0, 3).str()->upper(str()->random(4));
            $careTariff = $tariffs->createTariffItem(
                $steward, $catalogue->public_id, $component->public_id, 'RT'.$careSuffix,
                'Tarif radiologi '.$careSetting, $careSetting, 'RADIOLOGY', null, null, 125000,
                '2026-09-02', 'Portability '.$careSetting,
                'rad-port-tariff-'.strtolower($careSetting).'-'.str()->lower(str()->random(8)),
            )->record;
            app(FinanceRadiologyTariffBindingService::class)->create(
                $steward, $masterVersion->public_id, $careSetting, $careTariff->public_id,
                '2026-09-02', 'Pemetaan portability '.$careSetting,
                'rad-port-binding-'.strtolower($careSetting).'-'.str()->lower(str()->random(8)),
            );
        }

        [$order, $performance] = RadiologyMutationScope::run(function () use ($encounter, $master, $masterVersion, $physician, $technologist): array {
            $order = RadiologyOrder::query()->create([
                'encounter_id' => $encounter->id,
                'master_id' => $master->id,
                'ordered_by_user_id' => $physician->id,
                'master_version' => $masterVersion->version,
                'master_version_public_id' => $masterVersion->public_id,
                'master_content_digest' => $masterVersion->content_digest,
                'master_code' => $master->examination_code,
                'master_display_name' => $masterVersion->display_name,
                'master_preparation_instruction' => $masterVersion->preparation_instruction,
                'care_setting' => $encounter->care_setting,
                'encounter_status_snapshot' => $encounter->status,
                'encounter_number_snapshot' => $encounter->public_id,
                'care_location_label_snapshot' => $encounter->clinic_name,
                'clinical_indication' => 'Indikasi portability.',
                'status' => RadiologyOrder::PERFORMED,
                'version' => 2,
                'ordered_at' => now()->subHour(),
            ]);
            $performance = RadiologyPerformance::query()->create([
                'radiology_order_id' => $order->id,
                'performed_by_user_id' => $technologist->id,
                'performed_at' => now(),
                'created_at' => now(),
            ]);
            RadiologyOrder::query()->create([
                'encounter_id' => $encounter->id,
                'master_id' => $master->id,
                'ordered_by_user_id' => $physician->id,
                'master_version' => $masterVersion->version,
                'master_version_public_id' => $masterVersion->public_id,
                'master_content_digest' => $masterVersion->content_digest,
                'master_code' => $master->examination_code,
                'master_display_name' => $masterVersion->display_name,
                'master_preparation_instruction' => $masterVersion->preparation_instruction,
                'care_setting' => $encounter->care_setting,
                'encounter_status_snapshot' => $encounter->status,
                'encounter_number_snapshot' => $encounter->public_id,
                'care_location_label_snapshot' => $encounter->clinic_name,
                'clinical_indication' => 'Pesanan tanpa performa.',
                'status' => RadiologyOrder::ORDERED,
                'version' => 1,
                'ordered_at' => now(),
            ]);
            return [$order, $performance];
        });

        return [
            'encounter_public_id' => $encounter->public_id,
            'encounter_id' => $encounter->id,
            'cashier_public_id' => $cashier->public_id,
            'steward_public_id' => $steward->public_id,
            'admin_public_id' => $admin->public_id,
            'physician_public_id' => $physician->public_id,
            'technologist_public_id' => $technologist->public_id,
            'binding_public_id' => $binding->public_id,
            'binding_version' => $binding->version,
            'binding_digest' => $binding->current_content_digest,
            'tariff_public_id' => $tariff->public_id,
            'performance_id' => $performance->id,
            'order_id' => $order->id,
            'binding_care_settings' => FinanceRadiologyTariffBinding::query()
                ->where('radiology_master_version_id', $masterVersion->id)
                ->orderBy('care_setting')->pluck('care_setting')->all(),
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
            $before = FinanceRadiologySourceEvent::query()
                ->where('radiology_performance_id', $fixture['performance_id'])->count();
            protocol('HOLDING', ['lock_order_trace' => ['encounters']]);
            usleep(max(0, (int) getenv('SIMRS_RADIOLOGY_TARIFF_SOURCE_HOLD_MS')) * 1000);
            $result = app(FinanceBillService::class)->synchronize(
                $fixture['encounter_public_id'], user($fixture['cashier_public_id']),
                'rad-port-sync-'.strtolower((string) getenv('SIMRS_RADIOLOGY_TARIFF_SOURCE_WORKER')).'-'.str()->lower(str()->random(8)),
            );
            return [
                'outcome' => $before === 0 ? 'MATERIALIZED' : 'RECONCILED',
                'bill_public_id' => $result->record->public_id,
                'source_count' => FinanceRadiologySourceEvent::query()->where('radiology_performance_id', $fixture['performance_id'])->count(),
                'charge_count' => FinanceChargeEvent::query()->where('finance_radiology_source_event_id', '!=', null)->count(),
            ];
        }, 3);
    }

    function bindingRace(array $fixture): array
    {
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        try {
            $result = DB::transaction(function () use ($fixture) {
                FinanceRadiologyTariffMutationScope::run(
                    fn () => FinanceRadiologyTariffBinding::query()
                        ->where('public_id', $fixture['binding_public_id'])->lockForUpdate()->sole(),
                );
                protocol('HOLDING', ['lock_order_trace' => ['finance_radiology_tariff_bindings']]);
                usleep(max(0, (int) getenv('SIMRS_RADIOLOGY_TARIFF_SOURCE_HOLD_MS')) * 1000);
                return app(FinanceRadiologyTariffBindingService::class)->appendVersion(
                    user($fixture['steward_public_id']), $fixture['binding_public_id'], $fixture['tariff_public_id'],
                    '2026-09-03', $fixture['binding_version'], $fixture['binding_digest'],
                    'Penulis bersamaan portability',
                    'rad-port-bind-race-'.strtolower((string) getenv('SIMRS_RADIOLOGY_TARIFF_SOURCE_WORKER')),
                );
            }, 3);
            return ['outcome' => 'APPLIED', 'version' => $result->record->version];
        } catch (FinanceTariffDenied $denied) {
            must(in_array($denied->reason, ['stale_version', 'stale_digest', 'concurrent_state_conflict'], true), 'expected concurrent denial');
            return ['outcome' => 'DENIED', 'reason' => $denied->reason];
        }
    }

    function verifyFixture(array $fixture): array
    {
        $encounter = Encounter::query()->where('public_id', $fixture['encounter_public_id'])->sole();
        $sources = FinanceRadiologySourceEvent::query()->where('radiology_performance_id', $fixture['performance_id'])->get();
        must($sources->count() === 1, 'one source per performance');
        must(RadiologyOrder::query()->where('encounter_id', $encounter->id)->count() === 2, 'order without performance retained');
        $charges = FinanceChargeEvent::query()->where('finance_radiology_source_event_id', $sources->sole()->id)->get();
        must($charges->count() === 1, 'one typed charge per source');
        app(FinanceRadiologySourceAdapter::class)->verifyRetained($encounter, $charges);
        $readiness = app(FinanceSourceCoordinator::class)->readiness($encounter);
        must($readiness['unresolved_count'] === 0, 'source reconciled');
        return [
            'source_count' => $sources->count(), 'charge_count' => $charges->count(),
            'unit_amount' => $sources->sole()->unit_amount,
            'source_domain' => $charges->sole()->source_domain,
            'unperformed_order_created_charge' => false,
            'binding_care_settings' => $fixture['binding_care_settings'],
            'reconciled' => true,
        ];
    }

    function resolveLaterGap(array $fixture): array
    {
        $cashier = user($fixture['cashier_public_id']);
        $steward = user($fixture['steward_public_id']);
        $encounter = Encounter::query()->where('public_id', $fixture['encounter_public_id'])->sole();
        $bill = FinanceBill::query()->where('encounter_id', $encounter->id)->sole();
        $billing = app(FinanceBillService::class);
        $projection = app(FinanceProjection::class);
        $versionOne = $billing->issue(
            $bill->public_id, $cashier, $projection->fingerprint($bill->fresh()),
            'Penerbitan sebelum sumber susulan.', 'rad-port-gap-issue-v1',
        )->record;
        must($versionOne instanceof FinanceBillVersion && $versionOne->lines()->count() === 1, 'version one retained');

        $admin = user($fixture['admin_public_id']);
        $physician = user($fixture['physician_public_id']);
        $technologist = user($fixture['technologist_public_id']);
        $master = app(RadiologyMasterService::class)->create(
            $admin, 'RAD-GAP-'.str()->upper(str()->random(6)), 'Radiologi gap susulan', null,
            'rad-port-gap-master-'.str()->lower(str()->random(10)),
        )->record;
        must($master instanceof RadiologyExaminationMaster, 'gap master');
        $masterVersion = RadiologyExaminationMasterVersion::query()
            ->where('radiology_examination_master_id', $master->id)->sole();

        [$order, $performance] = RadiologyMutationScope::run(function () use ($encounter, $master, $masterVersion, $physician, $technologist): array {
            $order = RadiologyOrder::query()->create([
                'encounter_id' => $encounter->id, 'master_id' => $master->id,
                'ordered_by_user_id' => $physician->id, 'master_version' => $masterVersion->version,
                'master_version_public_id' => $masterVersion->public_id,
                'master_content_digest' => $masterVersion->content_digest,
                'master_code' => $master->examination_code, 'master_display_name' => $masterVersion->display_name,
                'master_preparation_instruction' => $masterVersion->preparation_instruction,
                'care_setting' => $encounter->care_setting, 'encounter_status_snapshot' => $encounter->status,
                'encounter_number_snapshot' => $encounter->public_id,
                'care_location_label_snapshot' => $encounter->clinic_name,
                'clinical_indication' => 'Sumber susulan belum dipetakan.',
                'status' => RadiologyOrder::PERFORMED, 'version' => 2, 'ordered_at' => now(),
            ]);
            $performance = RadiologyPerformance::query()->create([
                'radiology_order_id' => $order->id, 'performed_by_user_id' => $technologist->id,
                'performed_at' => now(), 'created_at' => now(),
            ]);
            return [$order, $performance];
        });

        $billing->synchronize($encounter->public_id, $cashier, 'rad-port-gap-unresolved-sync');
        $readiness = app(FinanceSourceCoordinator::class)->readiness($encounter->fresh());
        must($readiness['unresolved_count'] === 1 && $readiness['issue_blocked'] === true, 'gap blocks issue');
        try {
            $billing->issue(
                $bill->public_id, $cashier, $projection->fingerprint($bill->fresh()),
                'Penerbitan harus ditolak saat gap.', 'rad-port-gap-denied-issue',
            );
            throw new RuntimeException('unresolved issue unexpectedly allowed');
        } catch (FinanceDenied $denied) {
            must($denied->reason === 'unresolved_radiology_source', 'unresolved issue reason');
        }
        must(FinanceRadiologySourceEvent::query()->where('radiology_performance_id', $performance->id)->doesntExist(), 'gap not materialized');

        $tariffs = app(FinanceTariffMasterService::class);
        $suffix = str()->upper(str()->random(6));
        $group = $tariffs->createGroup($steward, 'GG'.$suffix, 'Gap radiologi', 'Resolusi gap', 'rad-gap-group-'.str()->lower(str()->random(9)))->record;
        $component = $tariffs->createComponent($steward, $group->public_id, 'GC'.$suffix, 'Komponen gap', null, null, 'Resolusi gap', 'rad-gap-component-'.str()->lower(str()->random(9)))->record;
        $catalogue = $tariffs->createCatalogue($steward, 'GK'.$suffix, 'Katalog gap', 'Resolusi gap', 'rad-gap-catalogue-'.str()->lower(str()->random(9)))->record;
        $tariff = $tariffs->createTariffItem(
            $steward, $catalogue->public_id, $component->public_id, 'GT'.$suffix,
            'Tarif gap radiologi', 'OUTPATIENT', 'RADIOLOGY', null, null, 75000,
            '2026-09-02', 'Resolusi gap', 'rad-gap-tariff-'.str()->lower(str()->random(9)),
        )->record;
        app(FinanceRadiologyTariffBindingService::class)->create(
            $steward, $masterVersion->public_id, 'OUTPATIENT', $tariff->public_id,
            '2026-09-02', 'Resolusi gap', 'rad-gap-binding-'.str()->lower(str()->random(9)),
        );
        $billing->synchronize($encounter->public_id, $cashier, 'rad-port-gap-resolved-sync');
        $bill = $bill->fresh();
        $versionTwo = $billing->issue(
            $bill->public_id, $cashier, $projection->fingerprint($bill),
            'Penerbitan setelah gap terselesaikan.', 'rad-port-gap-issue-v2',
        )->record;
        must($versionTwo instanceof FinanceBillVersion && $versionTwo->version === 2, 'version two issued');
        must($versionOne->fresh()->lines()->count() === 1, 'version one remains immutable');
        must($versionTwo->lines()->count() === 2, 'version two includes resolved gap');
        return [
            'unresolved_issue_refused' => true, 'gap_source_count_before' => 0,
            'gap_source_count_after' => 1, 'version_one_lines' => 1, 'version_two_lines' => 2,
            'history_rewritten' => false,
        ];
    }

    function privilegeGuards(): array
    {
        foreach ([
            'finance_radiology_tariff_bindings', 'finance_radiology_tariff_binding_versions',
            'finance_radiology_tariff_operation_receipts', 'finance_radiology_source_events',
            'finance_charge_events', 'finance_bills', 'finance_bill_versions', 'finance_bill_lines',
        ] as $table) {
            DB::table($table)->limit(1)->get();
            try {
                DB::transaction(fn () => FinanceMutationScope::run(
                    fn () => FinanceRadiologyTariffMutationScope::run(
                        fn () => DB::table($table)->update(['id' => DB::raw('id')]),
                    ),
                ));
                throw new RuntimeException('write unexpectedly allowed: '.$table);
            } catch (QueryException) {
                // Expected: exact reduced runtime identity is SELECT-only.
            }
        }
        return ['read_tables' => 8, 'forbidden_operations_refused' => true];
    }

    $action = (string) getenv('SIMRS_RADIOLOGY_TARIFF_SOURCE_ACTION');
    $scenario = (string) getenv('SIMRS_RADIOLOGY_TARIFF_SOURCE_SCENARIO');
    try {
        $fixture = fixture();
        $result = match ($action) {
            'prepare' => ['fixture' => prepareFixture()],
            'synchronize_race' => synchronizeRace($fixture),
            'binding_race' => bindingRace($fixture),
            'verify' => ['result' => verifyFixture($fixture)],
            'resolve_gap' => ['result' => resolveLaterGap($fixture)],
            'privilege_guards' => ['result' => privilegeGuards()],
            default => throw new RuntimeException('unknown radiology tariff/source action'),
        };
        protocol('COMMITTED', $result);
    } catch (Throwable $exception) {
        $query = $exception instanceof QueryException ? $exception : null;
        $errorInfo = $query?->errorInfo ?? [];
        echo json_encode([
            'schema_version' => 1, 'status' => 'BLOCKED', 'protocol_state' => 'FAILED',
            'scenario' => $scenario, 'worker' => (string) getenv('SIMRS_RADIOLOGY_TARIFF_SOURCE_WORKER'),
            'exception_class' => get_class($exception),
            'exception_fingerprint' => hash('sha256', get_class($exception)."\0".$exception->getMessage()),
            'diagnostic_message' => ($exception instanceof LogicException || $exception instanceof RuntimeException)
                ? mb_substr($exception->getMessage(), 0, 300) : null,
            'failure_stage' => $action, 'sql_state' => (string) ($errorInfo[0] ?? ''),
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
    raise CommandFailed, "Radiology tariff/source rehearsal refuses inherited overrides: #{rejected.join(', ')}." unless rejected.empty?
    LocalPortabilityFullSuiteRehearsal.instance_method(:assert_contract!).bind(self).call
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    raise CommandFailed, 'Radiology tariff/source scenario catalogue drifted.' unless SCENARIOS.length == 20 && SCENARIOS.uniq.length == 20
    raise CommandFailed, 'Embedded radiology tariff/source worker source is unexpectedly small.' unless WORKER_SOURCE.bytesize > 12_000
  end

  def application_environment
    LocalPortabilityFullSuiteRehearsal.instance_method(:application_environment).bind(self).call.merge(
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false', 'VCLAIM_ENABLED' => 'false',
      'SATUSEHAT_ENABLED' => 'false', 'LIS_INTEGRATION_ENABLED' => 'false',
      'PACS_INTEGRATION_ENABLED' => 'false', 'COLUMNS' => '300'
    )
  end

  def current_radiology_tariff_source_bindings
    files = SOURCE_PATHS.to_h { |path| [path, Digest::SHA256.file(safe_source_path(path)).hexdigest] }
    aggregate = Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h))
    {
      'files' => files, 'aggregate_sha256' => aggregate, 'application_source_sha256' => aggregate,
      'worker_source_sha256' => Digest::SHA256.hexdigest(WORKER_SOURCE),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)),
      'runtime_grant_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(RUNTIME_TABLE_GRANTS)),
    }
  end

  def worker_environment(action:, scenario:, worker:, fixture:, hold_ms:, connection_environment: nil)
    (connection_environment || @runtime_application_environment || application_environment).merge(
      'SIMRS_REHEARSAL_ROOT' => ROOT,
      'SIMRS_RADIOLOGY_TARIFF_SOURCE_ACTION' => action,
      'SIMRS_RADIOLOGY_TARIFF_SOURCE_SCENARIO' => scenario,
      'SIMRS_RADIOLOGY_TARIFF_SOURCE_WORKER' => worker,
      'SIMRS_RADIOLOGY_TARIFF_SOURCE_RUN_TOKEN' => @run_token,
      'SIMRS_RADIOLOGY_TARIFF_SOURCE_HOLD_MS' => hold_ms.to_s,
      'SIMRS_RADIOLOGY_TARIFF_SOURCE_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {}))
    )
  end

  def create_worker_file!
    @worker_tempfile = Tempfile.new(['simrs-radiology-tariff-source-portability-', '.php'])
    @worker_tempfile.binmode
    @worker_tempfile.write(WORKER_SOURCE)
    @worker_tempfile.flush
    @worker_tempfile.chmod(0o600)
    @worker_tempfile.close
  end

  def bind_feature_evidence!(bindings)
    bindings.to_h do |label, (path, method)|
      source = File.read(safe_source_path(path), encoding: Encoding::UTF_8)
      raise CommandFailed, "Scenario-bound radiology tariff/source feature method is missing: #{path}##{method}." unless source.include?("function #{method}")
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
    @command_catalog << ['radiology-tariff-source-worker', action, "--scenario=#{scenario}", "--worker=#{worker}"]
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

  def provision_postgres_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    raise CommandFailed, 'Generated PostgreSQL identities failed closed pattern.' unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
    tables = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT tablename FROM pg_tables WHERE schemaname='laravel' ORDER BY tablename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "PostgreSQL radiology tariff/source runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = [%(CREATE ROLE "#{runtime}" LOGIN), %(CREATE ROLE "#{reset}" LOGIN), %(GRANT CONNECT ON DATABASE "#{@postgres_database}" TO "#{runtime}", "#{reset}"), %(GRANT USAGE ON SCHEMA "laravel" TO "#{runtime}", "#{reset}")]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << %(GRANT #{grants} ON TABLE "laravel"."#{table}" TO "#{runtime}") }
    tables.each { |table| statements << %(GRANT #{table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON TABLE "laravel"."#{table}" TO "#{reset}") }
    @runner.run!(postgres_psql_arguments(@postgres_database) + ['--set', 'ON_ERROR_STOP=1', '--command', statements.join(";\n")+';'], env: postgres_tool_environment)
    RUNTIME_TABLE_GRANTS.each do |table, expected|
      actual = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name='#{table}'"], env: postgres_tool_environment).strip
      raise CommandFailed, "PostgreSQL radiology tariff/source runtime grant drifted for #{table}." unless actual == expected
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
    raise CommandFailed, "MySQL radiology tariff/source runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = ["CREATE USER '#{runtime}'@'127.0.0.1' IDENTIFIED BY '#{runtime_password}'", "CREATE USER '#{reset}'@'127.0.0.1' IDENTIFIED BY '#{reset_password}'"]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << "GRANT #{grants} ON `#{@mysql_database}`.`#{table}` TO '#{runtime}'@'127.0.0.1'" }
    tables.each { |table| statements << "GRANT #{table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON `#{@mysql_database}`.`#{table}` TO '#{reset}'@'127.0.0.1'" }
    statements << 'FLUSH PRIVILEGES'
    @runner.run!(mysql_root_arguments, stdin_data: statements.join(";\n")+';')
    grants = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SHOW GRANTS FOR '#{runtime}'@'127.0.0.1';").upcase
    raise CommandFailed, 'Reduced MySQL radiology tariff/source runtime retained forbidden write or DDL grants.' unless %w[INSERT UPDATE DELETE DROP ALTER TRIGGER CREATE].none? { |privilege| grants.match?(/\b#{privilege}\b/) }
    raise CommandFailed, 'MySQL radiology tariff/source runtime received a schema wildcard grant.' if grants.include?("ON `#{@mysql_database.upcase}`.*")
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => runtime_password)
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => reset_password)
  end

  def expect_radiology_tariff_source_rollback_refusal!
    arguments = ['migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(@runner.process_environment(application_environment), @php_binary, File.join(ROOT, 'artisan'), *arguments, unsetenv_others: true)
    raise CommandFailed, 'Retained radiology tariff/source evidence unexpectedly allowed rollback.' if status.success?
    combined = (stdout + stderr).gsub(/\s+/, '').downcase
    expected = %w[correlatedauditevidenceremains typedfinancechargesremain retainedrowsremain]
    raise CommandFailed, 'Radiology tariff/source rollback failed for an unexpected reason.' unless expected.any? { |fragment| combined.include?(fragment) }
    @result_catalog << [['artisan', *arguments], 'EXPECTED_REFUSAL']
    'PASS'
  end

  def write_radiology_tariff_source_evidence!(bindings:, engine_binding:, migration_duration_ms:, scenarios:)
    assert_evidence_directory!
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-radiology-tariff-source-#{SecureRandom.hex(6)}.json")
    evidence = {
      'schema_version' => 1, 'kind' => EVIDENCE_KIND, 'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_RADIOLOGY_TARIFF_SOURCE_ONLY',
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
    bindings = current_radiology_tariff_source_bindings
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
      action: 'synchronize_race', scenario: 'same-performance-import-concurrency', fixture: fixture,
      expected_outcomes: %w[MATERIALIZED RECONCILED], expected_lock_order: %w[encounters]
    )
    verified = run_worker_command!(action: 'verify', scenario: 'one-performance-one-typed-source', worker: 'VERIFY', fixture: fixture, connection_environment: application_environment)
    gap_resolution = run_worker_command!(action: 'resolve_gap', scenario: 'later-gap-resolution-new-version', worker: 'RESOLVE_GAP', fixture: fixture, connection_environment: application_environment)
    binding_race = run_domain_race!(
      action: 'binding_race', scenario: 'competing-binding-writer-concurrency', fixture: fixture,
      expected_outcomes: %w[APPLIED DENIED], expected_lock_order: %w[finance_radiology_tariff_bindings]
    )
    provision_runtime_identities!
    privilege = run_worker_command!(action: 'privilege_guards', scenario: 'least-privilege-runtime', worker: 'RUNTIME', fixture: fixture)
    rollback = expect_radiology_tariff_source_rollback_refusal!

    scenarios = {
      'fresh-migration' => { 'status' => 'PASS', 'proof_kind' => 'FRESH_EXACT_ENGINE_MIGRATION_AND_EMPTY_READBACK' },
      'empty-down-reapply' => { 'status' => 'PASS', 'proof_kind' => 'EMPTY_MIGRATION_ROLLBACK_REAPPLY' },
      'exact-role-boundary' => feature.fetch('exact-role-boundary'),
      'three-care-setting-bindings' => { 'status' => 'PASS', 'proof_kind' => 'REAL_GOVERNED_BINDING_SERVICE_WORKER', 'care_settings' => verified.fetch('result').fetch('binding_care_settings') },
      'performed-service-date-resolution' => feature.fetch('performed-service-date-resolution'),
      'future-half-open-terminal-retirement' => feature.fetch('future-half-open-terminal-retirement'),
      'upstream-context-refusal' => feature.fetch('upstream-context-refusal'),
      'order-report-amendment-no-charge' => { 'status' => 'PASS', 'proof_kind' => 'REAL_UNPERFORMED_ORDER_READBACK', 'worker' => verified.fetch('result') },
      'one-performance-one-typed-source' => { 'status' => 'PASS', 'proof_kind' => 'REAL_SERVICE_WORKER_AND_FILTERED_EXACT_ENGINE_TEST', 'worker' => verified.fetch('result'), 'feature' => feature.fetch('one-performance-one-typed-source') },
      'radiology-only-bill-snapshot' => feature.fetch('radiology-only-bill-snapshot'),
      'partial-sync-unresolved-issue-refusal' => feature.fetch('partial-sync-unresolved-issue-refusal'),
      'later-gap-resolution-new-version' => { 'status' => 'PASS', 'proof_kind' => 'REAL_GAP_RESOLUTION_AND_IMMUTABLE_BILL_VERSION_WORKER', 'worker' => gap_resolution.fetch('result'), 'feature' => feature.fetch('partial-sync-unresolved-issue-refusal') },
      'replay-key-conflict-stale-refusal' => feature.fetch('replay-key-conflict-stale-refusal'),
      'same-performance-import-concurrency' => source_race,
      'competing-binding-writer-concurrency' => binding_race,
      'application-sql-guard-refusal' => feature.fetch('application-sql-guard-refusal'),
      'database-append-only-and-head-refusal' => feature.fetch('database-append-only-and-head-refusal'),
      'audit-corruption-and-reconciliation-refusal' => { 'status' => 'PASS', 'proof_kind' => 'FILTERED_CORRUPTION_FEATURE_AND_DURABLE_RETAINED_SOURCE_RECONCILIATION', 'feature' => feature.fetch('audit-corruption-and-reconciliation-refusal'), 'worker' => verified.fetch('result') },
      'least-privilege-runtime' => { 'status' => 'PASS', 'proof_kind' => 'READ_BACK_SELECT_ONLY_RUNTIME_IDENTITY', 'worker' => privilege.fetch('result') },
      'bounded-reset-recovery-rollback-and-strict-cleanup' => { 'status' => rollback, 'proof_kind' => 'EXPECTED_MIGRATION_REFUSAL_AND_ENGINE_OWNED_STRICT_CLEANUP', 'feature' => feature.fetch('bounded-reset-recovery-rollback-and-strict-cleanup') },
    }
    raise CommandFailed, 'Radiology tariff/source scenario evidence is incomplete.' unless scenarios.keys == SCENARIOS && scenarios.values.all? { |result| result['status'] == 'PASS' }
    assert_unchanged_binding!('Radiology tariff/source execution bindings', bindings, current_radiology_tariff_source_bindings)
    cleanup!(strict: true)
    evidence_path = write_radiology_tariff_source_evidence!(bindings: bindings, engine_binding: engine_binding, migration_duration_ms: migration_duration_ms, scenarios: scenarios)
    {
      'status' => 'PASS', 'claim' => 'LOCAL_DISPOSABLE_RADIOLOGY_TARIFF_SOURCE_ONLY',
      'engine' => @engine, 'scenario_count' => scenarios.length,
      'application_source_sha256' => bindings.fetch('application_source_sha256'),
      'evidence_path' => evidence_path,
    }
  ensure
    terminate_workers!
    remove_worker_file!
    cleanup!
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalRadiologyTariffSourcePortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalRadiologyTariffSourcePortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalRadiologyTariffSourcePortabilityRehearsal::CommandFailed => e
    warn "radiology tariff/source portability rehearsal failed: #{e.message}"
    exit 1
  end
end
