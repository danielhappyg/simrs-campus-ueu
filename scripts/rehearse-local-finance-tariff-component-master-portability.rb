#!/usr/bin/env ruby
# frozen_string_literal: true

require 'base64'
require 'digest'
require 'json'
require 'open3'
require 'securerandom'
require 'tempfile'
require 'time'

require_relative 'rehearse-local-finance-billing-portability'

# Closed, fail-closed, disposable PostgreSQL 17.10/MySQL 8.4.11 rehearsal
# contract for the governed, effective-dated finance tariff/component master.
class LocalFinanceTariffComponentMasterPortabilityRehearsal < LocalFinanceBillingPortabilityRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_FINANCE_TARIFF_COMPONENT_MASTER'
  CONFIRMATION_ENV = 'SIMRS_FINANCE_TARIFF_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-finance-tariff-component-master-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalFinanceTariffComponentMasterPortabilityHarnessContractTest.rb'
  MIGRATION_PATH = 'database/migrations/2026_09_02_000100_create_governed_finance_tariff_component_master.php'
  EVIDENCE_TEMPLATE_PATH = 'docs/operations/T1_LOCAL_GOVERNED_FINANCE_TARIFF_COMPONENT_MASTER_EVIDENCE_TEMPLATE_2026-09-02.md'
  EVIDENCE_KIND = 'SIMRS_LOCAL_GOVERNED_FINANCE_TARIFF_COMPONENT_MASTER_PORTABILITY'
  EXECUTION_STATE = 'READY_NOT_RUN'

  SCENARIOS = %w[
    fresh-migration
    empty-down-reapply
    exact-finance-steward-role-boundary
    cashier-admin-mixed-denial
    stable-code-reservations
    integer-rupiah-refusal
    current-effective-resolution
    future-authored-today-effective
    half-open-effective-boundary
    terminal-retirement-history
    upstream-retirement-refusal
    idempotent-replay-key-conflict
    stale-and-concurrent-writer-refusal
    application-sql-guard-refusal
    database-mutable-head-refusal
    database-append-only-update-delete-truncate-refusal
    audit-and-corruption-refusal
    least-privilege-runtime
    bounded-reset-recovery-audit-preservation
    retained-evidence-rollback-refusal-and-strict-cleanup
  ].freeze

  TARIFF_TABLES = %w[
    finance_cost_component_groups finance_cost_component_group_versions
    finance_cost_components finance_cost_component_versions
    finance_tariff_catalogues finance_tariff_catalogue_versions
    finance_tariff_items finance_tariff_item_versions
    finance_tariff_code_reservations finance_tariff_operation_receipts
  ].freeze
  TARIFF_COUNT_KEYS = TARIFF_TABLES.freeze
  TARIFF_ORPHAN_KEYS = %w[
    finance_tariff_group_version_without_group finance_tariff_group_version_without_actor
    finance_tariff_component_without_group finance_tariff_component_version_without_component
    finance_tariff_component_version_without_actor finance_tariff_catalogue_version_without_catalogue
    finance_tariff_catalogue_version_without_actor finance_tariff_item_without_catalogue
    finance_tariff_item_without_component finance_tariff_item_version_without_item
    finance_tariff_item_version_without_actor finance_tariff_reservation_without_actor
    finance_tariff_receipt_without_actor
  ].freeze
  TARIFF_INTEGRITY_KEYS = %w[
    finance_tariff_version_chain_mismatches finance_tariff_head_mismatches
    finance_tariff_effective_period_mismatches finance_tariff_upstream_mismatches
    finance_tariff_code_reservation_mismatches finance_tariff_receipt_result_mismatches
  ].freeze
  TARIFF_DIGEST_KEYS = TARIFF_TABLES.map { |table| "#{table}_sha256" }.freeze

  RUNTIME_READ_TABLES = %w[users roles permissions role_user permission_role].freeze
  MUTABLE_HEAD_TABLES = %w[
    finance_cost_component_groups finance_cost_components finance_tariff_catalogues finance_tariff_items
  ].freeze
  IMMUTABLE_EVIDENCE_TABLES = %w[
    finance_cost_component_group_versions finance_cost_component_versions
    finance_tariff_catalogue_versions finance_tariff_item_versions
    finance_tariff_code_reservations finance_tariff_operation_receipts audit_events
  ].freeze
  RUNTIME_TABLE_GRANTS = (
    RUNTIME_READ_TABLES.to_h { |table| [table, 'SELECT'] }
      .merge(MUTABLE_HEAD_TABLES.to_h { |table| [table, 'SELECT, INSERT, UPDATE'] })
      .merge(IMMUTABLE_EVIDENCE_TABLES.to_h { |table| [table, 'SELECT, INSERT'] })
  ).freeze

  SOURCE_PATHS = %w[
    scripts/rehearse-local-finance-tariff-component-master-portability.rb
    scripts/rehearse-local-finance-billing-portability.rb
    scripts/rehearse-local-pharmacy-stock-portability.rb
    scripts/rehearse-local-portability-full-suite.rb
    tests/Documentation/LocalFinanceTariffComponentMasterPortabilityHarnessContractTest.rb
    tests/Documentation/GovernedEffectiveDatedFinanceTariffComponentMasterV1LocalEngineeringAuthorizationTest.rb
    docs/new-simrs-rebuild/phase-1/GOVERNED_EFFECTIVE_DATED_FINANCE_TARIFF_COMPONENT_MASTER_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md
    docs/operations/T1_LOCAL_GOVERNED_FINANCE_TARIFF_COMPONENT_MASTER_EVIDENCE_TEMPLATE_2026-09-02.md
    database/migrations/2026_09_02_000100_create_governed_finance_tariff_component_master.php
    tests/Feature/Authorization/FinanceTariffAccessTest.php
    tests/Feature/Database/FinanceTariffSqlWriteGuardTest.php
    tests/Feature/Finance/FinanceTariffMasterCoreTest.php
    tests/Feature/Finance/FinanceTariffMasterHttpTest.php
    tests/Feature/Operations/FinanceTariffRecoverySnapshotTest.php
    tests/Feature/Simulation/FinanceTariffResetTest.php
    tests/Unit/Audit/FinanceTariffAuditIntegrationTest.php
    app/Models/FinanceCostComponentGroup.php
    app/Models/FinanceCostComponentGroupVersion.php
    app/Models/FinanceCostComponent.php
    app/Models/FinanceCostComponentVersion.php
    app/Models/FinanceTariffCatalogue.php
    app/Models/FinanceTariffCatalogueVersion.php
    app/Models/FinanceTariffItem.php
    app/Models/FinanceTariffItemVersion.php
    app/Models/FinanceTariffCodeReservation.php
    app/Models/FinanceTariffOperationReceipt.php
    app/Support/Finance/FinanceTariffActorPolicy.php
    app/Support/Finance/FinanceTariffAppendOnlyGuard.php
    app/Support/Finance/FinanceTariffAuditUnavailable.php
    app/Support/Finance/FinanceTariffContentDigest.php
    app/Support/Finance/FinanceTariffDenied.php
    app/Support/Finance/FinanceTariffMasterService.php
    app/Support/Finance/FinanceTariffMutableHeadGuard.php
    app/Support/Finance/FinanceTariffMutationResult.php
    app/Support/Finance/FinanceTariffMutationScope.php
    app/Support/Finance/FinanceTariffProjection.php
    app/Support/Finance/FinanceTariffSchemaMutationScope.php
    app/Support/Finance/FinanceTariffSqlWriteGuard.php
    app/Support/Simulation/SyntheticResetService.php
    app/Support/Operations/SyntheticRecoverySnapshot.php
    app/Support/Audit/AuditEventSchemaRegistry.php
    app/Providers/AppServiceProvider.php
    app/Http/Controllers/Finance/FinanceTariffMasterController.php
    routes/web.php
    resources/js/components/finance/tariff-master-types.ts
    resources/js/components/finance/tariff-master-workspace.tsx
    resources/js/pages/manajemen-data/tarif-komponen-biaya/index.tsx
  ].freeze

  FEATURE_SCENARIO_TESTS = {
    'exact-finance-steward-role-boundary' => ['tests/Feature/Finance/FinanceTariffMasterCoreTest.php', 'test_service_authorizes_before_resource_lookup'],
    'cashier-admin-mixed-denial' => ['tests/Feature/Finance/FinanceTariffMasterHttpTest.php', 'test_admin_system_administrator_and_mixed_role_are_denied'],
    'current-effective-resolution' => ['tests/Feature/Finance/FinanceTariffMasterCoreTest.php', 'test_effective_versions_replay_and_terminal_retirement_preserve_history'],
    'future-authored-today-effective' => ['tests/Feature/Finance/FinanceTariffMasterCoreTest.php', 'test_overview_keeps_today_effective_version_and_future_only_items_visible'],
    'upstream-retirement-refusal' => ['tests/Feature/Finance/FinanceTariffMasterCoreTest.php', 'test_upstreams_can_retire_only_after_dependent_tariff_retirement_is_effective'],
    'audit-and-corruption-refusal' => ['tests/Feature/Finance/FinanceTariffMasterCoreTest.php', 'test_required_success_audit_failure_rolls_back_every_mutation_artifact'],
    'bounded-reset-recovery-audit-preservation' => ['tests/Feature/Operations/FinanceTariffRecoverySnapshotTest.php', 'test_integrity_helpers_reconcile_real_latest_authored_and_future_tariff_versions'],
  }.freeze

  WORKER_SOURCE = <<~'PHP'
    <?php
    declare(strict_types=1);

    use App\Models\FinanceCostComponent;
    use App\Models\FinanceCostComponentGroup;
    use App\Models\FinanceCostComponentGroupVersion;
    use App\Models\FinanceCostComponentVersion;
    use App\Models\FinanceTariffCatalogue;
    use App\Models\FinanceTariffCatalogueVersion;
    use App\Models\FinanceTariffCodeReservation;
    use App\Models\FinanceTariffItem;
    use App\Models\FinanceTariffItemVersion;
    use App\Models\FinanceTariffOperationReceipt;
    use App\Models\Role;
    use App\Models\User;
    use App\Support\Authorization\RoleCapabilityMatrix;
    use App\Support\Database\SchemaQualifier;
    use App\Support\Finance\FinanceTariffActorPolicy;
    use App\Support\Finance\FinanceTariffAppendOnlyGuard;
    use App\Support\Finance\FinanceTariffDenied;
    use App\Support\Finance\FinanceTariffMasterService;
    use App\Support\Finance\FinanceTariffMutationScope;
    use App\Support\Finance\FinanceTariffProjection;
    use App\Support\Finance\FinanceTariffSchemaMutationScope;
    use App\Support\Finance\FinanceTariffSqlWriteGuard;
    use App\Support\Operations\SyntheticRecoverySnapshot;
    use App\Support\Simulation\SyntheticResetService;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\QueryException;
    use Illuminate\Support\Carbon;
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
            'scenario' => (string) getenv('SIMRS_FINANCE_TARIFF_SCENARIO'),
            'worker' => (string) getenv('SIMRS_FINANCE_TARIFF_WORKER'),
        ], $extra), JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
    }

    function fixture(): array
    {
        $raw = base64_decode((string) getenv('SIMRS_FINANCE_TARIFF_FIXTURE'), true);
        $decoded = $raw === false ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    function backendConnectionId(): int
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? (int) data_get(DB::selectOne('SELECT pg_backend_pid() AS id'), 'id')
            : (int) data_get(DB::selectOne('SELECT CONNECTION_ID() AS id'), 'id');
    }

    function userByPublicId(string $publicId): User
    {
        return User::query()->where('public_id', $publicId)->firstOrFail();
    }

    function actor(string $token, string $label, string $role, bool $systemAdministrator = false): User
    {
        $actor = User::query()->create([
            'name' => 'Tariff portability '.$label,
            'email' => 'tariff.'.$label.'.'.$token.'@example.invalid',
            'password' => bin2hex(random_bytes(24)),
            'status' => 'ACTIVE',
            'is_system_administrator' => $systemAdministrator,
        ]);
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);
        return $actor->fresh();
    }

    function expectDenied(string $reason, callable $callback): void
    {
        try {
            $callback();
            throw new RuntimeException('expected finance tariff denial '.$reason);
        } catch (FinanceTariffDenied $denied) {
            must($denied->reason === $reason, 'denial reason '.$reason);
        }
    }

    function expectAuthorization(callable $callback): void
    {
        try {
            $callback();
            throw new RuntimeException('expected finance tariff authorization refusal');
        } catch (AuthorizationException) {
            // expected
        }
    }

    function tariffTables(): array
    {
        return [
            'finance_cost_component_groups', 'finance_cost_component_group_versions',
            'finance_cost_components', 'finance_cost_component_versions',
            'finance_tariff_catalogues', 'finance_tariff_catalogue_versions',
            'finance_tariff_items', 'finance_tariff_item_versions',
            'finance_tariff_code_reservations', 'finance_tariff_operation_receipts',
        ];
    }

    function prepareFixture(string $token): array
    {
        foreach (tariffTables() as $table) {
            must(DB::table(SchemaQualifier::table($table))->count() === 0, 'fresh master empty '.$table);
        }
        $steward = actor($token, 'steward', RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $cashier = actor($token, 'cashier', RoleCapabilityMatrix::ROLE_CASHIER);
        $admin = actor($token, 'admin', RoleCapabilityMatrix::ROLE_ADMIN);
        $system = actor($token, 'system', RoleCapabilityMatrix::ROLE_FINANCE_STEWARD, true);
        $mixed = actor($token, 'mixed', RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $mixed->roles()->attach(Role::query()->where('slug', RoleCapabilityMatrix::ROLE_CASHIER)->sole()->id);
        $mixed = $mixed->fresh();
        $policy = app(FinanceTariffActorPolicy::class);
        must($policy->canManage($steward), 'exact finance steward manages');
        must($policy->canView($steward), 'exact finance steward views');
        must($policy->canView($cashier) && ! $policy->canManage($cashier), 'cashier is read only');
        foreach ([$admin, $system, $mixed] as $denied) {
            must(! $policy->canView($denied) && ! $policy->canManage($denied), 'admin system mixed non bypass');
        }
        expectAuthorization(fn () => app(FinanceTariffMasterService::class)->retireGroup(
            $cashier, '01J99999999999999999999999', 1, str_repeat('a', 64),
            'Uji batas peran.', 'tariff-portability-cashier-denied'
        ));
        return [
            'steward' => $steward->public_id,
            'cashier' => $cashier->public_id,
            'admin' => $admin->public_id,
            'system' => $system->public_id,
            'mixed' => $mixed->public_id,
        ];
    }

    function exerciseCore(array $fixture): array
    {
        $steward = userByPublicId($fixture['steward']);
        $service = app(FinanceTariffMasterService::class);
        $projection = app(FinanceTariffProjection::class);
        $today = now()->startOfDay();
        $dayOne = $today->copy()->addDay();
        $dayTwo = $today->copy()->addDays(2);

        $groupResult = $service->createGroup($steward, ' layanan ', 'Layanan Klinis', 'Pembentukan group awal.', 'tariff-portability-group-create');
        $group = $groupResult->record;
        $groupReplay = $service->createGroup($steward, ' layanan ', 'Layanan Klinis', 'Pembentukan group awal.', 'tariff-portability-group-create');
        must($groupReplay->replayed && $groupReplay->record->version === 1, 'exact retained replay');
        expectDenied('idempotency_key_conflict', fn () => $service->createGroup(
            $steward, 'berbeda', 'Muatan Berbeda', 'Pembentukan berbeda.', 'tariff-portability-group-create'
        ));
        $component = $service->createComponent(
            $steward, $group->public_id, 'jasa_dokter', 'Jasa Dokter',
            'Komponen jasa profesional.', null, 'Pembentukan komponen awal.',
            'tariff-portability-component-create'
        )->record;
        $catalogue = $service->createCatalogue(
            $steward, 'umum', 'Tarif Umum', 'Pembentukan katalog awal.',
            'tariff-portability-catalogue-create'
        )->record;
        $item = $service->createTariffItem(
            $steward, $catalogue->public_id, $component->public_id,
            'rj_konsul', 'Konsultasi Rawat Jalan', 'OUTPATIENT', 'GENERAL_SERVICE',
            null, null, 150000, $today->toDateString(), 'Pembentukan tarif awal.',
            'tariff-portability-item-create'
        )->record;

        must(FinanceTariffCodeReservation::query()->count() === 4, 'four stable code reservations');
        foreach ([
            ['GROUP', 'LAYANAN'], ['COMPONENT', 'JASA_DOKTER'],
            ['CATALOGUE', 'UMUM'], ['TARIFF_ITEM', 'RJ_KONSUL'],
        ] as [$type, $code]) {
            must(FinanceTariffCodeReservation::query()->where('reservation_type', $type)->where('normalized_code', $code)->exists(), 'reserved '.$type.' '.$code);
        }
        expectDenied('validation_failed', fn () => $service->createCatalogue(
            $steward, 'umum', 'Kode Tidak Boleh Dipakai Lagi', 'Uji reservasi kode.',
            'tariff-portability-catalogue-reuse'
        ));
        try {
            $service->createTariffItem(
                $steward, $catalogue->public_id, $component->public_id,
                'float_refusal', 'Tarif Float', 'OUTPATIENT', 'GENERAL_SERVICE',
                null, null, 1000.5, $today->toDateString(), 'Uji nilai bulat.',
                'tariff-portability-float-refusal'
            );
            throw new RuntimeException('floating-point rupiah refusal expected');
        } catch (TypeError) {
            // strict integer contract
        }

        $future = $service->appendTariffItemVersion(
            $steward, $item->public_id, 'Konsultasi Rawat Jalan', 'OUTPATIENT', 'GENERAL_SERVICE',
            null, null, 175000, $dayOne->toDateString(), 1, $item->current_content_digest,
            'Penyesuaian tarif mendatang.', 'tariff-portability-item-future'
        )->record;
        must($service->resolveEffectiveTariff($steward, 'RJ_KONSUL', $today->toDateString())?->amount_rupiah === 150000, 'today remains version one');
        must($service->resolveEffectiveTariff($steward, 'RJ_KONSUL', $dayOne->toDateString())?->amount_rupiah === 175000, 'half open boundary selects version two');
        $overview = collect($projection->overview($steward, $today->toDateString())['tariffs'])->keyBy('code');
        must($overview['RJ_KONSUL']['version'] === 1 && $overview['RJ_KONSUL']['latest_head_version'] === 2, 'effective and latest authored distinguished');

        expectDenied('stale_version', fn () => $service->appendTariffItemVersion(
            $steward, $item->public_id, 'Stale', 'OUTPATIENT', 'GENERAL_SERVICE',
            null, null, 180000, $dayTwo->toDateString(), 1, $item->current_content_digest,
            'Uji versi basi.', 'tariff-portability-item-stale'
        ));
        expectDenied('dependent_tariffs_remain', fn () => $service->retireCatalogue(
            $steward, $catalogue->public_id, 1, $catalogue->current_content_digest,
            'Belum boleh pensiun.', 'tariff-portability-catalogue-retire-early'
        ));

        $retired = $service->retireTariffItem(
            $steward, $item->public_id, $dayTwo->toDateString(), 2, $future->current_content_digest,
            'Pensiun tarif terjadwal.', 'tariff-portability-item-retire'
        )->record;
        must($service->resolveEffectiveTariff($steward, 'RJ_KONSUL', $dayOne->toDateString())?->amount_rupiah === 175000, 'history survives retirement authoring');
        must($service->resolveEffectiveTariff($steward, 'RJ_KONSUL', $dayTwo->toDateString()) === null, 'retirement boundary unselectable');
        $history = $projection->tariffHistory($steward, $item->public_id);
        must(array_column($history['versions'], 'effective_until') === [$dayOne->toDateString(), $dayTwo->toDateString(), null], 'half open history intervals');

        Carbon::setTestNow($dayTwo->copy()->addHour());
        try {
            $component = $service->retireComponent(
                $steward, $component->public_id, 1, $component->current_content_digest,
                'Pensiun komponen setelah tarif.', 'tariff-portability-component-retire'
            )->record;
            $catalogue = $service->retireCatalogue(
                $steward, $catalogue->public_id, 1, $catalogue->current_content_digest,
                'Pensiun katalog setelah tarif.', 'tariff-portability-catalogue-retire'
            )->record;
            $group = $service->retireGroup(
                $steward, $group->public_id, 1, $group->current_content_digest,
                'Pensiun group setelah komponen.', 'tariff-portability-group-retire'
            )->record;
        } finally {
            Carbon::setTestNow();
        }
        must($retired->state === FinanceTariffItem::RETIRED, 'tariff terminal retired');
        must($component->state === FinanceCostComponent::RETIRED, 'component retired');
        must($catalogue->state === FinanceTariffCatalogue::RETIRED, 'catalogue retired');
        must($group->state === FinanceCostComponentGroup::RETIRED, 'group retired');
        expectDenied('validation_failed', fn () => $service->createGroup(
            $steward, 'layanan', 'Reuse Tidak Diizinkan', 'Uji kode tetap dicadangkan.',
            'tariff-portability-group-reuse'
        ));

        $raceGroup = $service->createGroup(
            $steward, 'race_group', 'Group Balapan', 'Fixture penulis bersaing.',
            'tariff-portability-race-group-create'
        )->record;
        return [
            'today' => $today->toDateString(),
            'day_one' => $dayOne->toDateString(),
            'day_two' => $dayTwo->toDateString(),
            'tariff' => $item->public_id,
            'race_group' => $raceGroup->public_id,
            'race_group_version' => $raceGroup->version,
            'race_group_digest' => $raceGroup->current_content_digest,
            'versions' => FinanceTariffItemVersion::query()->where('tariff_item_id', $item->id)->count(),
            'reservations' => FinanceTariffCodeReservation::query()->count(),
            'receipts' => FinanceTariffOperationReceipt::query()->count(),
        ];
    }

    function raceWriter(array $fixture): array
    {
        $steward = userByPublicId($fixture['steward']);
        $group = FinanceCostComponentGroup::query()->where('public_id', $fixture['race_group'])->sole();
        $worker = (string) getenv('SIMRS_FINANCE_TARIFF_WORKER');
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        try {
            $result = DB::transaction(function () use ($steward, $group, $worker) {
                return FinanceTariffMutationScope::run(function () use ($steward, $group, $worker) {
                    FinanceCostComponentGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
                    if ($worker === 'A') {
                        protocol('HOLDING', ['lock_order_trace' => ['finance_cost_component_groups']]);
                        usleep(((int) getenv('SIMRS_FINANCE_TARIFF_HOLD_MS')) * 1000);
                    }
                    return app(FinanceTariffMasterService::class)->reviseGroup(
                        $steward, $group->public_id, 'Group Balapan '.$worker,
                        1, $group->current_content_digest, 'Uji serialisasi penulis.',
                        'tariff-portability-race-'.mb_strtolower($worker)
                    );
                });
            });
            return ['outcome' => 'APPLIED', 'version' => $result->record->version, 'replayed' => $result->replayed];
        } catch (FinanceTariffDenied $denied) {
            must(in_array($denied->reason, ['stale_version', 'stale_digest', 'concurrent_state_conflict'], true), 'race denial is closed');
            return ['outcome' => 'DENIED', 'reason' => $denied->reason];
        }
    }

    function applicationGuards(): array
    {
        $guard = app(FinanceTariffSqlWriteGuard::class);
        $guard->assertAllowed('SELECT * FROM finance_tariff_items');
        $writes = [
            "UPDATE finance_tariff_items SET state='ACTIVE'",
            'DELETE FROM finance_tariff_item_versions',
            'TRUNCATE finance_tariff_operation_receipts',
            'DROP TABLE finance_cost_components',
        ];
        foreach ($writes as $sql) {
            try {
                $guard->assertAllowed($sql);
                throw new RuntimeException('application SQL guard refusal expected');
            } catch (LogicException) {
                // expected
            }
        }
        return ['outcome' => 'APPLIED', 'read_allowed' => true, 'writes_refused' => count($writes)];
    }

    function databaseGuards(): array
    {
        $group = FinanceCostComponentGroup::query()->firstOrFail();
        $version = FinanceCostComponentGroupVersion::query()->firstOrFail();
        $headTable = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('finance_cost_component_groups'));
        $versionTable = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('finance_cost_component_group_versions'));
        $receiptTable = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('finance_tariff_operation_receipts'));
        $pdo = DB::connection()->getPdo();
        $attempts = [
            'mutable_head_update' => "UPDATE {$headTable} SET display_name=display_name WHERE id=".(int) $group->id,
            'append_only_update' => "UPDATE {$versionTable} SET display_name=display_name WHERE id=".(int) $version->id,
            'append_only_delete' => "DELETE FROM {$versionTable} WHERE id=".(int) $version->id,
        ];
        if (DB::connection()->getDriverName() === 'pgsql') {
            $attempts['append_only_truncate'] = "TRUNCATE TABLE {$receiptTable}";
        }
        $refused = [];
        foreach ($attempts as $label => $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable) {
                $refused[] = $label;
            }
        }
        must(count($refused) === count($attempts), 'database triggers refuse all owner bypass writes');
        return [
            'outcome' => 'APPLIED',
            'refused' => $refused,
            'postgres_truncate_trigger' => DB::connection()->getDriverName() === 'pgsql',
            'mysql_truncate_boundary' => DB::connection()->getDriverName() === 'mysql' ? 'LEAST_PRIVILEGE' : 'NOT_APPLICABLE',
        ];
    }

    function corruptionRefusal(array $fixture): array
    {
        $steward = userByPublicId($fixture['steward']);
        $receipt = FinanceTariffOperationReceipt::query()->where('idempotency_key', 'tariff-portability-group-create')->sole();
        $original = $receipt->result_digest;
        DB::transaction(fn () => FinanceTariffAppendOnlyGuard::runSyntheticReset(
            fn () => FinanceTariffSchemaMutationScope::run(
                fn () => DB::table(SchemaQualifier::table('finance_tariff_operation_receipts'))
                    ->where('id', $receipt->id)->update(['result_digest' => str_repeat('0', 64)])
            )
        ));
        expectDenied('receipt_corrupt', fn () => app(FinanceTariffMasterService::class)->createGroup(
            $steward, ' layanan ', 'Layanan Klinis', 'Pembentukan group awal.',
            'tariff-portability-group-create'
        ));
        DB::transaction(fn () => FinanceTariffAppendOnlyGuard::runSyntheticReset(
            fn () => FinanceTariffSchemaMutationScope::run(
                fn () => DB::table(SchemaQualifier::table('finance_tariff_operation_receipts'))
                    ->where('id', $receipt->id)->update(['result_digest' => $original])
            )
        ));
        $restored = app(FinanceTariffMasterService::class)->createGroup(
            $steward, ' layanan ', 'Layanan Klinis', 'Pembentukan group awal.',
            'tariff-portability-group-create'
        );
        must($restored->replayed, 'restored receipt replays');
        return ['outcome' => 'APPLIED', 'corruption_refused' => true, 'receipt_restored' => true];
    }

    function privilegeGuards(): array
    {
        $blocked = 0;
        $attempts = [
            'UPDATE '.SchemaQualifier::table('finance_tariff_item_versions').' SET amount_rupiah=amount_rupiah',
            'DELETE FROM '.SchemaQualifier::table('finance_tariff_operation_receipts'),
            'TRUNCATE TABLE '.SchemaQualifier::table('finance_tariff_code_reservations'),
            'CREATE TABLE '.SchemaQualifier::table('finance_tariff_forbidden_probe').' (id INT)',
        ];
        foreach ($attempts as $sql) {
            try {
                FinanceTariffSchemaMutationScope::run(fn () => DB::unprepared($sql));
            } catch (Throwable) {
                $blocked++;
            }
        }
        must($blocked === count($attempts), 'least privilege blocks immutable update delete truncate and ddl');
        return ['outcome' => 'APPLIED', 'forbidden_operations_refused' => $blocked];
    }

    function tariffInvariants(): array
    {
        foreach ([
            [FinanceCostComponentGroup::class, FinanceCostComponentGroupVersion::class, 'group_id'],
            [FinanceCostComponent::class, FinanceCostComponentVersion::class, 'component_id'],
            [FinanceTariffCatalogue::class, FinanceTariffCatalogueVersion::class, 'catalogue_id'],
            [FinanceTariffItem::class, FinanceTariffItemVersion::class, 'tariff_item_id'],
        ] as [$headClass, $versionClass, $foreignKey]) {
            foreach ($headClass::query()->get() as $head) {
                $latest = $versionClass::query()->where($foreignKey, $head->id)->orderByDesc('version')->firstOrFail();
                must($head->version === $latest->version, 'head latest version reconciliation');
                must(hash_equals($head->current_content_digest, $latest->content_digest), 'head digest reconciliation');
            }
        }
        $snapshot = new SyntheticRecoverySnapshot;
        $reflection = new ReflectionClass($snapshot);
        $checks = [
            'financeTariffVersionChainMismatchCount', 'financeTariffHeadMismatchCount',
            'financeTariffEffectivePeriodMismatchCount', 'financeTariffUpstreamMismatchCount',
            'financeTariffCodeReservationMismatchCount', 'financeTariffReceiptResultMismatchCount',
        ];
        foreach ($checks as $method) must($reflection->getMethod($method)->invoke($snapshot) === 0, 'recovery '.$method);
        return [
            'outcome' => 'APPLIED',
            'groups' => FinanceCostComponentGroup::query()->count(),
            'components' => FinanceCostComponent::query()->count(),
            'catalogues' => FinanceTariffCatalogue::query()->count(),
            'tariffs' => FinanceTariffItem::query()->count(),
            'versions' => FinanceTariffItemVersion::query()->count(),
            'reservations' => FinanceTariffCodeReservation::query()->count(),
            'receipts' => FinanceTariffOperationReceipt::query()->count(),
            'recovery_mismatches' => 0,
        ];
    }

    function resetAndVerify(array $fixture): array
    {
        $auditBefore = DB::table(SchemaQualifier::table('audit_events'))->count();
        app(SyntheticResetService::class)->reset([
            'actor' => userByPublicId($fixture['steward']),
            'reason' => 'finance_tariff_exact_engine_portability',
        ]);
        $counts = [];
        foreach (tariffTables() as $table) $counts[$table] = DB::table(SchemaQualifier::table($table))->count();
        must(array_sum($counts) === 0, 'post-reset tariff master graph empty');
        $auditAfter = DB::table(SchemaQualifier::table('audit_events'))->count();
        must($auditAfter >= $auditBefore + 2, 'reset audit evidence preserved');
        $snapshot = new SyntheticRecoverySnapshot;
        $reflection = new ReflectionClass($snapshot);
        foreach ([
            'financeTariffVersionChainMismatchCount', 'financeTariffHeadMismatchCount',
            'financeTariffEffectivePeriodMismatchCount', 'financeTariffUpstreamMismatchCount',
            'financeTariffCodeReservationMismatchCount', 'financeTariffReceiptResultMismatchCount',
        ] as $method) must($reflection->getMethod($method)->invoke($snapshot) === 0, 'empty recovery '.$method);
        return ['outcome' => 'APPLIED', 'counts' => $counts, 'audit_preserved' => true, 'recovery_mismatches' => 0];
    }

    $action = (string) getenv('SIMRS_FINANCE_TARIFF_ACTION');
    $scenario = (string) getenv('SIMRS_FINANCE_TARIFF_SCENARIO');
    $token = (string) getenv('SIMRS_FINANCE_TARIFF_RUN_TOKEN');
    $fixture = fixture();
    if ($action !== 'race') protocol('STARTED');
    try {
        $result = match ($action) {
            'prepare' => ['outcome' => 'APPLIED', 'fixture' => prepareFixture($token)],
            'exercise_core' => ['outcome' => 'APPLIED', 'result' => exerciseCore($fixture)],
            'race' => raceWriter($fixture),
            'application_guards' => applicationGuards(),
            'database_guards' => databaseGuards(),
            'corrupt' => corruptionRefusal($fixture),
            'privilege_guards' => privilegeGuards(),
            'verify' => ['outcome' => 'APPLIED', 'result' => tariffInvariants()],
            'reset' => resetAndVerify($fixture),
            default => throw new RuntimeException('unknown finance tariff action'),
        };
        protocol('COMMITTED', $action === 'prepare' ? $result : (['outcome' => $result['outcome'] ?? 'APPLIED'] + $result));
    } catch (Throwable $exception) {
        $query = $exception instanceof QueryException ? $exception : null;
        $errorInfo = $query?->errorInfo ?? [];
        echo json_encode([
            'schema_version' => 1,
            'status' => 'BLOCKED',
            'protocol_state' => 'FAILED',
            'scenario' => $scenario,
            'worker' => (string) getenv('SIMRS_FINANCE_TARIFF_WORKER'),
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
    raise CommandFailed, "Finance tariff rehearsal refuses inherited overrides: #{rejected.join(', ')}." unless rejected.empty?
    LocalPortabilityFullSuiteRehearsal.instance_method(:assert_contract!).bind(self).call
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    raise CommandFailed, 'Finance tariff scenario catalogue drifted.' unless SCENARIOS.length == 20 && SCENARIOS.uniq.length == 20
    raise CommandFailed, 'Embedded finance tariff worker source is unexpectedly small.' unless WORKER_SOURCE.bytesize > 18_000
  end

  def application_environment
    LocalPortabilityFullSuiteRehearsal.instance_method(:application_environment).bind(self).call.merge(
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false',
      'VCLAIM_ENABLED' => 'false',
      'SATUSEHAT_ENABLED' => 'false',
      'LIS_INTEGRATION_ENABLED' => 'false',
      'PACS_INTEGRATION_ENABLED' => 'false',
      'COLUMNS' => '300'
    )
  end

  def current_tariff_bindings
    files = SOURCE_PATHS.to_h { |path| [path, Digest::SHA256.file(safe_source_path(path)).hexdigest] }
    aggregate = Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h))
    {
      'files' => files,
      'aggregate_sha256' => aggregate,
      'application_source_sha256' => aggregate,
      'worker_source_sha256' => Digest::SHA256.hexdigest(WORKER_SOURCE),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)),
      'runtime_grant_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(RUNTIME_TABLE_GRANTS)),
    }
  end

  def worker_environment(action:, scenario:, worker:, fixture:, hold_ms:, connection_environment: nil)
    (connection_environment || @runtime_application_environment || application_environment).merge(
      'SIMRS_REHEARSAL_ROOT' => ROOT,
      'SIMRS_FINANCE_TARIFF_ACTION' => action,
      'SIMRS_FINANCE_TARIFF_SCENARIO' => scenario,
      'SIMRS_FINANCE_TARIFF_WORKER' => worker,
      'SIMRS_FINANCE_TARIFF_RUN_TOKEN' => @run_token,
      'SIMRS_FINANCE_TARIFF_HOLD_MS' => hold_ms.to_s,
      'SIMRS_FINANCE_TARIFF_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {}))
    )
  end

  def create_worker_file!
    @worker_tempfile = Tempfile.new(['simrs-finance-tariff-component-master-portability-', '.php'])
    @worker_tempfile.binmode
    @worker_tempfile.write(WORKER_SOURCE)
    @worker_tempfile.flush
    @worker_tempfile.chmod(0o600)
    @worker_tempfile.close
  end

  def provision_postgres_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    raise CommandFailed, 'Generated PostgreSQL identities failed closed pattern.' unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
    tables = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT tablename FROM pg_tables WHERE schemaname='laravel' ORDER BY tablename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    sequences = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT sequencename FROM pg_sequences WHERE schemaname='laravel' ORDER BY sequencename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "PostgreSQL tariff runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = [%(CREATE ROLE "#{runtime}" LOGIN), %(CREATE ROLE "#{reset}" LOGIN), %(GRANT CONNECT ON DATABASE "#{@postgres_database}" TO "#{runtime}", "#{reset}"), %(GRANT USAGE ON SCHEMA "laravel" TO "#{runtime}", "#{reset}")]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << %(GRANT #{grants} ON TABLE "laravel"."#{table}" TO "#{runtime}") }
    tables.each { |table| statements << %(GRANT #{table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON TABLE "laravel"."#{table}" TO "#{reset}") }
    insert_tables = RUNTIME_TABLE_GRANTS.select { |_table, grants| grants.include?('INSERT') }.keys
    sequences.each do |sequence|
      statements << %(GRANT USAGE, SELECT ON SEQUENCE "laravel"."#{sequence}" TO "#{runtime}") if insert_tables.any? { |table| sequence == "#{table}_id_seq" }
      statements << %(GRANT USAGE, SELECT, UPDATE ON SEQUENCE "laravel"."#{sequence}" TO "#{reset}")
    end
    @runner.run!(postgres_psql_arguments(@postgres_database) + ['--set', 'ON_ERROR_STOP=1', '--command', statements.join(";\n")+';'], env: postgres_tool_environment)
    RUNTIME_TABLE_GRANTS.each do |table, expected|
      actual = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name='#{table}'"], env: postgres_tool_environment).strip
      raise CommandFailed, "PostgreSQL tariff runtime grant drifted for #{table}." unless actual == expected.split(', ').sort.join(',')
    end
    unexpected = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT table_name FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name NOT IN (#{RUNTIME_TABLE_GRANTS.keys.map { |table| "'#{table}'" }.join(',')}) ORDER BY table_name"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    raise CommandFailed, "PostgreSQL tariff runtime has grants outside the closed map: #{unexpected.join(', ')}." unless unexpected.empty?
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
    raise CommandFailed, "MySQL tariff runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = ["CREATE USER '#{runtime}'@'127.0.0.1' IDENTIFIED BY '#{runtime_password}'", "CREATE USER '#{reset}'@'127.0.0.1' IDENTIFIED BY '#{reset_password}'"]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << "GRANT #{grants} ON `#{@mysql_database}`.`#{table}` TO '#{runtime}'@'127.0.0.1'" }
    tables.each { |table| statements << "GRANT #{table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON `#{@mysql_database}`.`#{table}` TO '#{reset}'@'127.0.0.1'" }
    statements << 'FLUSH PRIVILEGES'
    @runner.run!(mysql_root_arguments, stdin_data: statements.join(";\n")+';')
    grants = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SHOW GRANTS FOR '#{runtime}'@'127.0.0.1';").upcase
    raise CommandFailed, 'Reduced MySQL tariff runtime retained forbidden DDL grants.' unless %w[DROP ALTER TRIGGER CREATE].none? { |privilege| grants.match?(/\b#{privilege}\b/) }
    RUNTIME_TABLE_GRANTS.each do |table, expected_grants|
      expected = "GRANT #{expected_grants} ON `#{@mysql_database.upcase}`.`#{table.upcase}`"
      raise CommandFailed, "MySQL tariff runtime grant drifted for #{table}." unless grants.include?(expected)
    end
    raise CommandFailed, 'MySQL tariff runtime received a schema wildcard grant.' if grants.include?("ON `#{@mysql_database.upcase}`.*")
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => runtime_password)
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => reset_password)
  end

  def bind_feature_evidence!(bindings)
    bindings.to_h do |label, (path, method)|
      source = File.read(safe_source_path(path), encoding: Encoding::UTF_8)
      raise CommandFailed, "Scenario-bound finance tariff feature method is missing: #{path}##{method}." unless source.include?("function #{method}")
      [label, {
        'status' => 'PASS', 'proof_kind' => 'EXACT_ENGINE_FEATURE_SUITE',
        'path' => path, 'method' => method, 'scenario_binding' => label,
      }]
    end
  end

  def expect_tariff_rollback_refusal!
    arguments = ['migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(@runner.process_environment(application_environment), @php_binary, File.join(ROOT, 'artisan'), *arguments, unsetenv_others: true)
    raise CommandFailed, 'Retained finance tariff audit unexpectedly allowed rollback.' if status.success?
    combined = stdout + stderr
    raise CommandFailed, 'Finance tariff rollback failed for an unexpected reason.' unless combined.gsub(/\s+/, '').include?('correlatedauditevidenceremains')
    @result_catalog << [['artisan', *arguments], 'EXPECTED_REFUSAL']
    'PASS'
  end

  def start_tariff_race_worker!(fixture:, worker:, hold_ms:)
    scenario = 'stale-and-concurrent-writer-refusal'
    @command_catalog << ['finance-tariff-worker', 'race', "--scenario=#{scenario}", "--worker=#{worker}"]
    stdin, stdout, stderr, wait_thread = Open3.popen3(
      @runner.process_environment(worker_environment(
        action: 'race', scenario: scenario, worker: worker, fixture: fixture,
        hold_ms: hold_ms, connection_environment: @runtime_application_environment
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

  def run_tariff_race!(fixture)
    first = start_tariff_race_worker!(fixture: fixture, worker: 'A', hold_ms: HOLD_MS)
    await_protocol!(first, 'STARTED')
    holding = await_protocol!(first, 'HOLDING')
    raise CommandFailed, 'Tariff race lock trace drifted.' unless holding.fetch('lock_order_trace') == ['finance_cost_component_groups']
    second = start_tariff_race_worker!(fixture: fixture, worker: 'B', hold_ms: 0)
    started = await_protocol!(second, 'STARTED')
    raise CommandFailed, 'Tariff race did not expose a real database wait.' unless observe_real_database_wait!(Integer(started.fetch('backend_connection_id')))
    finals = [await_final!(first), await_final!(second)]
    outcomes = finals.map { |document| document.fetch('outcome') }.sort
    raise CommandFailed, "Tariff race outcomes drifted: #{outcomes.join(',')}." unless outcomes == %w[APPLIED DENIED]
    result = {
      'status' => 'PASS', 'proof_kind' => 'OBSERVED_DATABASE_RACE',
      'independent_application_processes' => 2, 'real_database_wait_observed' => true,
      'observed_lock_order' => holding.fetch('lock_order_trace'),
      'outcomes' => outcomes, 'deadlock_observed' => false,
    }
    @result_catalog << [['stale-and-concurrent-writer-refusal', 'race-proof'], result]
    result
  ensure
    terminate_workers!
  end

  def write_tariff_evidence!(bindings:, engine_binding:, migration_duration_ms:, scenarios:)
    assert_evidence_directory!
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-finance-tariff-component-master-#{SecureRandom.hex(6)}.json")
    evidence = {
      'schema_version' => 1,
      'kind' => EVIDENCE_KIND,
      'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_GOVERNED_FINANCE_TARIFF_COMPONENT_MASTER_ONLY',
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
        'Local disposable-engine evidence only; no deployment, hosted migration, UAT, owner acceptance, parity acceptance, or production-readiness claim.',
        'No seeded or asserted real hospital price, automatic charge generation, payment, accounting, claim, BPJS, VClaim, SATUSEHAT, LIS, PACS/RIS, mail, or production integration is exercised.',
      ],
    }
    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence)+"\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    path
  end

  def run!
    assert_contract!
    bindings = current_tariff_bindings
    engine_binding = prepare_engine!
    @run_token = database_run_token
    create_worker_file!
    started = @clock.call
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(started)
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    recorded_artisan!('migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    recorded_artisan!('migrate', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    recreate_application_schema_outside_guard!
    recorded_artisan!(
      'test',
      'tests/Feature/Authorization/FinanceTariffAccessTest.php',
      'tests/Feature/Database/FinanceTariffSqlWriteGuardTest.php',
      'tests/Feature/Finance/FinanceTariffMasterCoreTest.php',
      'tests/Feature/Finance/FinanceTariffMasterHttpTest.php',
      'tests/Feature/Operations/FinanceTariffRecoverySnapshotTest.php',
      'tests/Feature/Simulation/FinanceTariffResetTest.php',
      'tests/Unit/Audit/FinanceTariffAuditIntegrationTest.php'
    )
    feature_evidence = bind_feature_evidence!(FEATURE_SCENARIO_TESTS)
    recreate_application_schema_outside_guard!
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    prepared = run_worker_command!(action: 'prepare', scenario: 'fresh-migration', worker: 'PREPARE', connection_environment: application_environment)
    fixture = prepared.fetch('fixture')
    provision_runtime_identities!
    core = run_worker_command!(action: 'exercise_core', scenario: 'current-effective-resolution', worker: 'CORE', fixture: fixture)
    fixture = fixture.merge(core.fetch('result'))
    race = run_tariff_race!(fixture)
    application_guards = run_worker_command!(action: 'application_guards', scenario: 'application-sql-guard-refusal', worker: 'APP_GUARDS', fixture: fixture)
    database_guards = run_worker_command!(action: 'database_guards', scenario: 'database-append-only-update-delete-truncate-refusal', worker: 'DB_GUARDS', fixture: fixture, connection_environment: application_environment)
    corruption = run_worker_command!(action: 'corrupt', scenario: 'audit-and-corruption-refusal', worker: 'CORRUPTION', fixture: fixture, connection_environment: application_environment)
    invariants = run_worker_command!(action: 'verify', scenario: 'bounded-reset-recovery-audit-preservation', worker: 'VERIFY', fixture: fixture)
    privilege = run_worker_command!(action: 'privilege_guards', scenario: 'least-privilege-runtime', worker: 'PRIVILEGE', fixture: fixture)
    reset = run_worker_command!(action: 'reset', scenario: 'bounded-reset-recovery-audit-preservation', worker: 'RESET', fixture: fixture, connection_environment: @reset_application_environment)
    rollback = expect_tariff_rollback_refusal!

    scenarios = {
      'fresh-migration' => { 'status' => 'PASS', 'proof_kind' => 'FRESH_EXACT_ENGINE_MIGRATION_AND_EMPTY_READBACK' },
      'empty-down-reapply' => { 'status' => 'PASS', 'proof_kind' => 'EMPTY_MIGRATION_ROLLBACK_REAPPLY' },
      'exact-finance-steward-role-boundary' => { 'status' => 'PASS', 'proof_kind' => 'REAL_POLICY_WORKER_AND_FILTERED_TEST', 'feature' => feature_evidence.fetch('exact-finance-steward-role-boundary') },
      'cashier-admin-mixed-denial' => { 'status' => 'PASS', 'proof_kind' => 'REAL_POLICY_WORKER_AND_FILTERED_TEST', 'feature' => feature_evidence.fetch('cashier-admin-mixed-denial') },
      'stable-code-reservations' => { 'status' => 'PASS', 'proof_kind' => 'REAL_SERVICE_WORKER', 'reservations' => core.fetch('result').fetch('reservations') },
      'integer-rupiah-refusal' => { 'status' => 'PASS', 'proof_kind' => 'STRICT_TYPED_REAL_SERVICE_WORKER' },
      'current-effective-resolution' => { 'status' => 'PASS', 'proof_kind' => 'REAL_SERVICE_WORKER_AND_FILTERED_TEST', 'feature' => feature_evidence.fetch('current-effective-resolution') },
      'future-authored-today-effective' => { 'status' => 'PASS', 'proof_kind' => 'REAL_PROJECTION_WORKER_AND_FILTERED_TEST', 'feature' => feature_evidence.fetch('future-authored-today-effective') },
      'half-open-effective-boundary' => { 'status' => 'PASS', 'proof_kind' => 'REAL_SERVICE_AND_HISTORY_READBACK' },
      'terminal-retirement-history' => { 'status' => 'PASS', 'proof_kind' => 'REAL_SERVICE_AND_HISTORY_READBACK', 'versions' => core.fetch('result').fetch('versions') },
      'upstream-retirement-refusal' => { 'status' => 'PASS', 'proof_kind' => 'REAL_SERVICE_WORKER_AND_FILTERED_TEST', 'feature' => feature_evidence.fetch('upstream-retirement-refusal') },
      'idempotent-replay-key-conflict' => { 'status' => 'PASS', 'proof_kind' => 'REAL_RECEIPT_REPLAY_AND_CONFLICT' },
      'stale-and-concurrent-writer-refusal' => race,
      'application-sql-guard-refusal' => { 'status' => 'PASS', 'proof_kind' => 'APPLICATION_GUARD_WORKER', 'result' => application_guards },
      'database-mutable-head-refusal' => { 'status' => 'PASS', 'proof_kind' => 'DATABASE_TRIGGER_WORKER', 'result' => database_guards },
      'database-append-only-update-delete-truncate-refusal' => { 'status' => 'PASS', 'proof_kind' => 'DATABASE_TRIGGER_AND_REDUCED_GRANT_WORKERS', 'database' => database_guards, 'runtime' => privilege },
      'audit-and-corruption-refusal' => { 'status' => 'PASS', 'proof_kind' => 'REQUIRED_AUDIT_TEST_AND_CORRUPTION_WORKER', 'result' => corruption, 'feature' => feature_evidence.fetch('audit-and-corruption-refusal') },
      'least-privilege-runtime' => { 'status' => 'PASS', 'proof_kind' => 'READ_BACK_REDUCED_RUNTIME_IDENTITY', 'result' => privilege },
      'bounded-reset-recovery-audit-preservation' => { 'status' => 'PASS', 'proof_kind' => 'DURABLE_RECOVERY_AND_RESET_READBACK', 'before' => invariants.fetch('result'), 'reset' => reset, 'feature' => feature_evidence.fetch('bounded-reset-recovery-audit-preservation') },
      'retained-evidence-rollback-refusal-and-strict-cleanup' => { 'status' => rollback, 'proof_kind' => 'EXPECTED_MIGRATION_REFUSAL_AND_ENGINE_OWNED_STRICT_CLEANUP' },
    }
    raise CommandFailed, 'Finance tariff scenario evidence is incomplete.' unless scenarios.keys == SCENARIOS && scenarios.values.all? { |result| result['status'] == 'PASS' }
    assert_unchanged_binding!('Finance tariff execution bindings', bindings, current_tariff_bindings)
    cleanup!(strict: true)
    evidence_path = write_tariff_evidence!(bindings: bindings, engine_binding: engine_binding, migration_duration_ms: migration_duration_ms, scenarios: scenarios)
    {
      'status' => 'PASS',
      'claim' => 'LOCAL_DISPOSABLE_GOVERNED_FINANCE_TARIFF_COMPONENT_MASTER_ONLY',
      'engine' => @engine,
      'scenario_count' => scenarios.length,
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
    unless ARGV.length == 1 && LocalFinanceTariffComponentMasterPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalFinanceTariffComponentMasterPortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalFinanceTariffComponentMasterPortabilityRehearsal::CommandFailed => e
    warn "finance tariff/component master portability rehearsal failed: #{e.message}"
    exit 1
  end
end
