#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'open3'
require 'securerandom'
require 'time'
require 'tmpdir'

require_relative 'rehearse-local-portability-full-suite'

class LocalInpatientMasterPortabilityRehearsal < LocalPortabilityFullSuiteRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_INPATIENT_MASTER_PORTABILITY'
  CONFIRMATION_ENV = 'SIMRS_INPATIENT_MASTER_PORTABILITY_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-inpatient-master-portability.rb'
  FOUNDATION_HARNESS_PATH = 'scripts/rehearse-local-portability-full-suite.rb'
  CONTRACT_TEST = 'tests/Documentation/LocalInpatientMasterPortabilityHarnessContractTest.rb'
  FOCUSED_TEST_PATH = 'tests/Feature/Inpatient/InpatientWardBedMasterTest.php'
  MIGRATION_PATH = 'database/migrations/2026_08_30_000300_create_inpatient_ward_bed_masters.php'
  EVIDENCE_KIND = 'SIMRS_LOCAL_INPATIENT_MASTER_PORTABILITY'
  HOLD_MS = 3_500
  WAIT_TIMEOUT_SECONDS = 10
  RACE_SCENARIOS = %w[
    duplicate-normalized-code
    same-expected-version-update
    identical-replay
    conflicting-replay
    admission-vs-retirement
  ].freeze
  SCENARIOS = (RACE_SCENARIOS + %w[
    database-constraints
    census-repeatable-read
    empty-down
    populated-evidence-down-refusal
  ]).freeze
  SOURCE_PATHS = %w[
    app/Models/Encounter.php
    app/Models/InpatientBedClaimMutex.php
    app/Models/InpatientWard.php
    app/Models/InpatientWardVersion.php
    app/Models/InpatientBed.php
    app/Models/InpatientBedVersion.php
    app/Models/InpatientMasterCodeReservation.php
    app/Models/InpatientMasterOperationReceipt.php
    app/Models/Patient.php
    app/Models/Role.php
    app/Models/User.php
    app/Http/Controllers/Inpatient/InpatientExaminationController.php
    app/Http/Controllers/Inpatient/InpatientRegistrationController.php
    app/Http/Controllers/Inpatient/InpatientWardBedMasterController.php
    app/Providers/AppServiceProvider.php
    app/Support/Audit/AuditEvent.php
    app/Support/Audit/AuditRecorder.php
    app/Support/Audit/AuditEventSchemaRegistry.php
    app/Support/Audit/AuditSafeDataGuard.php
    app/Support/Authorization/Capability.php
    app/Support/Authorization/RoleCapabilityMatrix.php
    app/Support/CanonicalJson.php
    app/Support/Database/SchemaQualifier.php
    app/Support/Inpatient/InpatientMasterActorPolicy.php
    app/Support/Inpatient/InpatientMasterDirectWriteScope.php
    app/Support/Inpatient/InpatientMasterMutationScope.php
    app/Support/Inpatient/InpatientMasterService.php
    app/Support/Inpatient/InpatientMasterSqlWriteGuard.php
    app/Support/Inpatient/InpatientOccupancyProjection.php
    app/Support/Inpatient/InpatientWardReadModel.php
    app/Support/Registration/InpatientBedClaimGuard.php
    app/Support/Registration/InpatientBedUnavailable.php
    app/Support/Simulation/SyntheticResetService.php
    database/seeders/RbacSeeder.php
    database/migrations/2026_08_21_000100_create_rebuild_foundation_tables.php
    database/migrations/2026_08_25_000300_expand_audit_actor_attribution.php
    database/migrations/2026_08_28_000100_create_inpatient_bed_claim_mutexes.php
  ].freeze
  FORBIDDEN_ENVIRONMENT = %w[
    DB_URL DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SOCKET DB_SCHEMA
    PGHOST PGPORT PGUSER PGPASSWORD PGSERVICE PGSERVICEFILE
    MYSQL_HOST MYSQL_TCP_PORT MYSQL_UNIX_PORT MYSQL_PWD
    POSTGRES17_BIN MYSQL84_BIN PHP_BINARY GIT_BINARY
  ].freeze

  Worker = Struct.new(:stdout, :stderr, :wait_thread, :stderr_reader, :lines, keyword_init: true)

  class HarnessRunner < LocalPortabilityFullSuiteRehearsal::Runner
    def sanitize(value)
      redacted = value.to_s
        .gsub(/\e\[[0-9;?]*[ -\/]*[@-~]/, '')
        .gsub(/simrs_portability_(?:pg|my)_[0-9a-f]{12}/, '<disposable-db>')
        .gsub(/simrs_p_[0-9a-f]{12}/, '<disposable-user>')
        .gsub(%r{(?:postgres(?:ql)?|mysql)://\S+}i, '<redacted-dsn>')
        .gsub(/(password|secret|token)\s*[=:]\s*\S+/i, '\\1=<redacted>')
      diagnostics = redacted.lines.map(&:strip).select do |line|
        line.match?(/(?:FAILED\s+Tests\\|Failed asserting|Session is missing|unexpected validation|validation errors|Exception:| at tests\/|Tests:)/i)
      end
      return super(value) if diagnostics.empty?
      diagnostics.last(60).join(' | ').slice(0, 4_000)
    end
  end

  PHP_WORKER = <<~'PHP'.freeze
    $root = getenv('SIMRS_MASTER_ROOT');
    if (!is_string($root) || $root === '') { exit(70); }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    use App\Models\Encounter;
    use App\Support\Audit\AuditEvent;
    use App\Models\InpatientBed;
    use App\Models\InpatientMasterOperationReceipt;
    use App\Models\InpatientWard;
    use App\Models\Patient;
    use App\Models\Role;
    use App\Models\User;
    use App\Support\Authorization\RoleCapabilityMatrix;
    use App\Support\Inpatient\InpatientMasterDenied;
    use App\Support\Inpatient\InpatientMasterDirectWriteScope;
    use App\Support\Inpatient\InpatientMasterService;
    use App\Support\Registration\InpatientBedClaimGuard;
    use App\Support\Simulation\SyntheticResetService;
    use Illuminate\Database\QueryException;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Hash;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Str;

    $scenario = (string) getenv('SIMRS_MASTER_SCENARIO');
    $action = (string) getenv('SIMRS_MASTER_ACTION');
    $worker = (string) getenv('SIMRS_MASTER_WORKER');
    $token = (string) getenv('SIMRS_MASTER_RUN_TOKEN');
    $holdMs = (int) getenv('SIMRS_MASTER_HOLD_MS');
    $signalPath = (string) getenv('SIMRS_MASTER_SIGNAL_PATH');
    $allowed = [
      'duplicate-normalized-code', 'same-expected-version-update', 'identical-replay',
      'conflicting-replay', 'admission-vs-retirement', 'database-constraints', 'census-repeatable-read',
      'empty-down', 'populated-evidence-down-refusal'
    ];
    if (!in_array($scenario, $allowed, true) || !in_array($action, ['prepare', 'operate', 'verify', 'verify-admission-wins', 'retirement-first', 'admission-after-retirement', 'constraints', 'census-observe', 'census-rename', 'raw-populate', 'clear-raw-populated', 'audited-populate', 'verify-populated-refusal', 'verify-audit-refusal', 'reset', 'assert-empty-down'], true)
        || !preg_match('/\A[0-9a-f]{12}\z/', $token) || !in_array($worker, ['A', 'B'], true)
        || !preg_match('/\A\/private\/tmp\/simrs-inpatient-master-signal-[0-9A-Za-z-]+\/commit\.signal\z/', $signalPath)
        || !is_dir(dirname($signalPath)) || is_link(dirname($signalPath))
        || realpath(dirname($signalPath)) !== dirname($signalPath)) {
      fwrite(STDERR, "closed worker contract rejected\n"); exit(64);
    }
    if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true
        || config('break_glass.mode') !== 'off'
        || getenv('BPJS_INTEGRATION_ENABLED') !== 'false'
        || getenv('VCLAIM_ENABLED') !== 'false'
        || getenv('SATUSEHAT_ENABLED') !== 'false') {
      fwrite(STDERR, "simulation boundary rejected\n"); exit(65);
    }
    if (Patient::query()->where('is_synthetic', false)->exists()) {
      fwrite(STDERR, "non-synthetic boundary rejected\n"); exit(66);
    }

    $emit = static function (array $document): void {
      echo json_encode(['schema_version' => 1] + $document, JSON_UNESCAPED_SLASHES).PHP_EOL;
      if (function_exists('flush')) { flush(); }
    };
    $actorEmail = 'local-master-'.$scenario.'-'.$token.'@example.test';
    $code = strtoupper(substr(hash('sha256', $scenario."\0".$token), 0, 12));
    $wardCode = 'W-'.$code;
    $bedCode = 'B-'.$code;
    $reverseBedCode = $bedCode.'-R';
    $service = app(InpatientMasterService::class);
    $actor = static fn (): User => User::query()->where('email', $actorEmail)->firstOrFail();
    $ward = static fn (): InpatientWard => InpatientWard::query()->where('code', $wardCode)->firstOrFail();
    $bed = static fn (): InpatientBed => InpatientBed::query()->where('code', $bedCode)->firstOrFail();
    $reverseBed = static fn (): InpatientBed => InpatientBed::query()->where('code', $reverseBedCode)->firstOrFail();

    $backendId = static function (): int {
      $driver = DB::connection()->getDriverName();
      $row = $driver === 'pgsql'
        ? DB::selectOne('SELECT pg_backend_pid() AS backend_id')
        : DB::selectOne('SELECT CONNECTION_ID() AS backend_id');
      return (int) data_get($row, 'backend_id');
    };
    $hold = static function (int $milliseconds): void {
      if ($milliseconds <= 0) { return; }
      if (DB::connection()->getDriverName() === 'pgsql') {
        DB::selectOne('SELECT pg_sleep(?)', [$milliseconds / 1000]);
      } else {
        DB::selectOne('SELECT SLEEP(?)', [$milliseconds / 1000]);
      }
    };

    try {
      if ($action === 'prepare') {
        User::query()->create([
          'name' => 'Local Inpatient Master Rehearsal', 'email' => $actorEmail,
          'password' => Hash::make(Str::random(48)), 'status' => 'ACTIVE',
          'is_system_administrator' => true,
        ]);
        $admin = $actor();
        $adminRole = Role::query()->where('slug', RoleCapabilityMatrix::ROLE_ADMIN)->firstOrFail();
        $admin->roles()->sync([$adminRole->id]);
        if (!app(App\Support\Inpatient\InpatientMasterActorPolicy::class)->canManage($admin)) {
          throw new RuntimeException('prepared actor lacks the strict inpatient master permission binding');
        }
        if (in_array($scenario, ['same-expected-version-update', 'admission-vs-retirement', 'census-repeatable-read'], true)) {
          $createdWard = $service->createWard($admin, $wardCode, 'Bangsal Awal', 'INITIAL_SETUP', 'prepare-ward-'.$scenario, null)->master;
          if ($scenario === 'admission-vs-retirement') {
            $createdBed = $service->createBed($admin, $createdWard->public_id, $bedCode, 'Tempat Tidur Rehearsal', 'Ruang Rehearsal', 'Kelas 1', 'INITIAL_SETUP', 'prepare-bed-'.$scenario, null)->master;
            $service->createBed($admin, $createdWard->public_id, $reverseBedCode, 'Tempat Tidur Reverse', 'Ruang Rehearsal', 'Kelas 1', 'INITIAL_SETUP', 'prepare-bed-reverse-'.$scenario, null);
            Patient::query()->create([
              'medical_record_number' => 'MR-'.$code, 'full_name' => 'Pasien Sintetis Rehearsal',
              'date_of_birth' => '1990-01-01', 'sex' => Patient::SEX_TIDAK_DIKETAHUI,
              'is_synthetic' => true, 'created_by_user_id' => $admin->id,
            ]);
          }
        }
        $emit(['status' => 'PASS', 'protocol_state' => 'PREPARED', 'scenario' => $scenario]); exit(0);
      }

      if ($action === 'assert-empty-down') {
        $absent = !Schema::hasTable('inpatient_wards') && !Schema::hasTable('inpatient_beds')
          && !Schema::hasColumn('encounters', 'inpatient_bed_id');
        if (!$absent) { throw new RuntimeException('empty down left managed master schema'); }
        $emit(['status' => 'PASS', 'protocol_state' => 'VERIFIED', 'scenario' => $scenario, 'empty_down' => true]); exit(0);
      }

      if ($action === 'retirement-first') {
        $result = $service->retireBed($actor(), $reverseBed()->public_id, 1, 'RETIREMENT', 'retirement-first', null);
        if ($result->replayed || $result->master->state !== InpatientBed::STATE_RETIRED || $result->master->version !== 2) {
          throw new RuntimeException('retirement-first result was not a fresh committed retirement');
        }
        $emit([
          'status' => 'PASS', 'protocol_state' => 'RETIRED', 'scenario' => $scenario,
          'worker' => $worker, 'outcome' => 'APPLIED', 'reverse_bed_code' => $reverseBedCode,
        ]); exit(0);
      }

      if ($action === 'verify-admission-wins') {
        $primary = $bed();
        $activeEncounterCount = Encounter::query()
          ->where('bed_code', $bedCode)
          ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
          ->count();
        $retireReceiptCount = InpatientMasterOperationReceipt::query()
          ->where('actor_user_id', $actor()->id)
          ->where('operation', 'BED_RETIRE')
          ->where('idempotency_key', 'retire-vs-admission')
          ->count();
        $retireAuditCount = AuditEvent::query()
          ->where('action', 'master.inpatient.bed.retire')
          ->where('resource_id', $primary->public_id)
          ->count();
        if ($activeEncounterCount !== 1 || $primary->state !== InpatientBed::STATE_ACTIVE
            || $primary->version !== 1 || $retireReceiptCount !== 0 || $retireAuditCount !== 0) {
          throw new RuntimeException('admission-wins durable verification failed');
        }
        $emit([
          'status' => 'PASS', 'protocol_state' => 'VERIFIED', 'scenario' => $scenario,
          'ordering' => 'admission-wins', 'target_bed_code' => $bedCode,
          'active_encounter_count' => 1, 'bed_state' => InpatientBed::STATE_ACTIVE,
          'bed_version' => 1, 'retire_receipt_count' => 0, 'retire_audit_count' => 0,
        ]); exit(0);
      }

      if ($action === 'admission-after-retirement') {
        $denialReason = null;
        try {
          DB::transaction(function () use ($service, $reverseBed): void {
            $service->resolveActiveBedForAdmission($reverseBed()->public_id);
          });
        } catch (InpatientMasterDenied $denial) {
          if (in_array($denial->reasonCode, ['master_retired', 'bed_retired'], true)) {
            $denialReason = $denial->reasonCode;
          } else {
            throw $denial;
          }
        }
        $activeEncounterCount = Encounter::query()
          ->where('bed_code', $reverseBedCode)
          ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
          ->count();
        $target = $reverseBed();
        if ($denialReason === null || $activeEncounterCount !== 0
            || $target->state !== InpatientBed::STATE_RETIRED || $target->version !== 2) {
          throw new RuntimeException('admission-after-retirement did not fail closed');
        }
        $emit([
          'status' => 'PASS', 'protocol_state' => 'VERIFIED', 'scenario' => $scenario,
          'worker' => $worker, 'outcome' => 'DENIED', 'reason' => $denialReason,
          'reverse_bed_code' => $reverseBedCode, 'active_encounter_count' => 0,
          'retired_version' => 2,
        ]); exit(0);
      }

      if ($action === 'reset') {
        app(SyntheticResetService::class)->reset(['actor' => $actor(), 'reason' => 'inpatient_master_portability_rehearsal']);
        $removed = InpatientWard::query()->count() === 0
          && InpatientBed::query()->count() === 0
          && DB::table('inpatient_ward_versions')->count() === 0
          && DB::table('inpatient_bed_versions')->count() === 0
          && InpatientMasterOperationReceipt::query()->count() === 0;
        $reservationsRetained = DB::table('inpatient_master_code_reservations')->where('normalized_code', $wardCode)->exists();
        $reuseRefused = false;
        try {
          $service->createWard($actor(), $wardCode, 'Bangsal Tidak Boleh Digunakan Ulang', 'INITIAL_SETUP', 'reuse-after-reset', null);
        } catch (InpatientMasterDenied $denial) {
          $reuseRefused = $denial->reasonCode === 'duplicate_code';
        }
        if (!$removed || !$reservationsRetained || !$reuseRefused) {
          throw new RuntimeException('reset or retained tombstone verification failed');
        }
        $emit([
          'status' => 'PASS', 'protocol_state' => 'RESET', 'scenario' => $scenario,
          'master_chains_removed' => true, 'code_reservation_retained' => true, 'old_code_reuse_refused' => true,
        ]); exit(0);
      }

      if ($action === 'raw-populate') {
        InpatientMasterDirectWriteScope::run(static fn () => DB::table('inpatient_wards')->insert([
          'public_id' => (string) Str::ulid(), 'code' => $wardCode, 'display_name' => 'Bangsal Raw Tanpa Audit',
          'state' => 'ACTIVE', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $emit(['status' => 'PASS', 'protocol_state' => 'POPULATED', 'scenario' => $scenario]); exit(0);
      }

      if ($action === 'clear-raw-populated') {
        InpatientMasterDirectWriteScope::run(static fn () => DB::table('inpatient_wards')->where('code', $wardCode)->delete());
        $emit(['status' => 'PASS', 'protocol_state' => 'CLEARED', 'scenario' => $scenario]); exit(0);
      }

      if ($action === 'audited-populate') {
        $service->createWard($actor(), $wardCode, 'Bangsal Dengan Audit', 'INITIAL_SETUP', 'audited-populate', null);
        $emit(['status' => 'PASS', 'protocol_state' => 'POPULATED', 'scenario' => $scenario]); exit(0);
      }

      if ($action === 'verify-populated-refusal') {
        $retained = Schema::hasTable('inpatient_wards')
          && Schema::hasColumn('encounters', 'inpatient_bed_id')
          && InpatientWard::query()->where('code', $wardCode)->exists()
          && !AuditEvent::query()->where('action', 'like', 'master.inpatient.%')->exists();
        if (!$retained) { throw new RuntimeException('raw populated rollback refusal was not durable'); }
        $emit(['status' => 'PASS', 'protocol_state' => 'VERIFIED', 'scenario' => $scenario, 'raw_populated_retained' => true]); exit(0);
      }

      if ($action === 'verify-audit-refusal') {
        $retained = Schema::hasTable('inpatient_wards')
          && Schema::hasColumn('encounters', 'inpatient_bed_id')
          && InpatientWard::query()->count() === 0
          && AuditEvent::query()->where('action', 'like', 'master.inpatient.%')->exists();
        if (!$retained) { throw new RuntimeException('audit rollback refusal was not durable'); }
        $emit(['status' => 'PASS', 'protocol_state' => 'VERIFIED', 'scenario' => $scenario, 'correlated_audit_retained' => true]); exit(0);
      }

      if ($action === 'constraints') {
        $admin = $actor();
        $checks = [];
        $attempt = static function (string $name, callable $write) use (&$checks): void {
          try {
            InpatientMasterDirectWriteScope::run(static fn () => DB::transaction($write));
            throw new RuntimeException('constraint unexpectedly accepted');
          }
          catch (QueryException) { $checks[] = $name; }
        };
        $attempt('ward-state-check', static fn () => DB::table('inpatient_wards')->insert([
          'public_id' => (string) Str::ulid(), 'code' => 'BAD-STATE', 'display_name' => 'Bad',
          'state' => 'INVALID', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $attempt('ward-version-check', static fn () => DB::table('inpatient_wards')->insert([
          'public_id' => (string) Str::ulid(), 'code' => 'BAD-VERSION', 'display_name' => 'Bad',
          'state' => 'ACTIVE', 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $attempt('bed-foreign-key', static fn () => DB::table('inpatient_beds')->insert([
          'public_id' => (string) Str::ulid(), 'ward_id' => 999999999, 'code' => 'BAD-FK',
          'display_name' => 'Bad', 'room_label' => 'Bad', 'service_class' => 'Bad',
          'state' => 'ACTIVE', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $attempt('receipt-result-type-check', static fn () => DB::table('inpatient_master_operation_receipts')->insert([
          'public_id' => (string) Str::ulid(), 'actor_user_id' => $admin->id, 'operation' => 'WARD_CREATE',
          'idempotency_key' => 'bad-result-type', 'payload_digest' => str_repeat('a', 64),
          'result_type' => 'INVALID', 'result_public_id' => (string) Str::ulid(),
          'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));
        $attempt('reservation-master-type-check', static fn () => DB::table('inpatient_master_code_reservations')->insert([
          'public_id' => (string) Str::ulid(), 'actor_user_id' => $admin->id,
          'master_type' => 'INVALID', 'normalized_code' => 'BAD-TYPE', 'created_at' => now(),
        ]));
        if (count($checks) !== 5) { throw new RuntimeException('constraint coverage incomplete'); }
        $emit(['status' => 'PASS', 'protocol_state' => 'VERIFIED', 'scenario' => $scenario, 'constraints' => $checks]); exit(0);
      }

      if ($action === 'census-rename') {
        $emit(['status' => 'PASS', 'protocol_state' => 'STARTED', 'scenario' => $scenario, 'worker' => $worker, 'backend_connection_id' => $backendId()]);
        $service->updateWard($actor(), $ward()->public_id, 'Bangsal Setelah Commit', 1, 'OPERATIONAL_CHANGE', 'census-rename', null);
        $signal = fopen($signalPath, 'x');
        if ($signal === false || !chmod($signalPath, 0600)
            || fwrite($signal, "committed\n") === false || !fflush($signal) || !fclose($signal)
            || is_link($signalPath) || !is_file($signalPath) || (fileperms($signalPath) & 0777) !== 0600) {
          throw new RuntimeException('census signal write failed');
        }
        $emit(['status' => 'PASS', 'protocol_state' => 'COMMITTED', 'scenario' => $scenario, 'worker' => $worker, 'outcome' => 'APPLIED']); exit(0);
      }

      if ($action === 'census-observe') {
        $emit(['status' => 'PASS', 'protocol_state' => 'STARTED', 'scenario' => $scenario, 'worker' => $worker, 'backend_connection_id' => $backendId()]);
        $projectionService = new class(app(App\Support\Inpatient\InpatientMasterActorPolicy::class), $emit, $signalPath) extends App\Support\Inpatient\InpatientOccupancyProjection {
          public function __construct(
            App\Support\Inpatient\InpatientMasterActorPolicy $policy,
            private Closure $emitProtocol,
            private string $signalPath,
          ) { parent::__construct($policy); }

          protected function afterClaimsSnapshotRead(): void
          {
            ($this->emitProtocol)(['status' => 'PASS', 'protocol_state' => 'CLAIMS_READ', 'scenario' => 'census-repeatable-read', 'worker' => 'A']);
            $deadline = microtime(true) + 8.0;
            while (true) {
              if (is_link($this->signalPath)) { throw new RuntimeException('census signal symlink rejected'); }
              if (is_file($this->signalPath) && (fileperms($this->signalPath) & 0777) === 0600) { break; }
              if (microtime(true) >= $deadline) { throw new RuntimeException('census interleaving signal timeout'); }
              usleep(20_000);
            }
          }
        };
        $projection = $projectionService->forActor($actor(), [
          'q' => '', 'ward_code' => '', 'service_class' => '', 'occupancy_state' => '', 'master_state' => '',
        ]);
        if (!is_array($projection) || !isset($projection['totals'], $projection['wards'], $projection['filter_options'])) {
          throw new RuntimeException('census projection contract incomplete');
        }
        $targetRows = array_values(array_filter(
          $projection['wards'],
          static fn (array $row): bool => data_get($row, 'code') === $wardCode,
        ));
        $targetMatchCount = count($targetRows);
        $observedPrechangeTarget = $targetMatchCount === 1
          && data_get($targetRows[0], 'display_name') === 'Bangsal Awal';
        if (!$observedPrechangeTarget) {
          throw new RuntimeException('census projection did not preserve the exact pre-change target snapshot');
        }
        $emit([
          'status' => 'PASS', 'protocol_state' => 'VERIFIED', 'scenario' => $scenario,
          'repeatable_read_snapshot_observed' => true,
          'projected_ward_count' => count($projection['wards']),
          'target_ward_code' => $wardCode,
          'target_match_count' => $targetMatchCount,
          'exact_target_matched' => true,
          'observed_prechange_master' => true,
        ]);
        exit(0);
      }

      if ($action === 'verify') {
        $admin = $actor();
        $receiptCount = static fn (string $operation, string $key): int => InpatientMasterOperationReceipt::query()
          ->where('actor_user_id', $admin->id)->where('operation', $operation)->where('idempotency_key', $key)->count();
        $auditCount = static fn (string $action, string $resourceId): int => AuditEvent::query()
          ->where('action', $action)->where('resource_id', $resourceId)->count();
        $targetAssertions = ['target_identity' => $wardCode];
        $ok = false;
        if (in_array($scenario, ['duplicate-normalized-code', 'identical-replay', 'conflicting-replay'], true)) {
          $targetWards = InpatientWard::query()->where('code', $wardCode)->get();
          $target = $targetWards->sole();
          $targetVersionCount = $target->versions()->count();
          $targetAuditCount = $auditCount('master.inpatient.ward.create', $target->public_id);
          $expectedKey = match ($scenario) {
            'duplicate-normalized-code' => 'duplicate-a',
            'identical-replay' => 'identical-replay',
            default => 'conflicting-replay',
          };
          $conflictingCodeAbsent = $scenario !== 'conflicting-replay'
            || !InpatientWard::query()->where('code', $wardCode.'-B')->exists();
          $ok = $targetWards->count() === 1
            && $targetVersionCount === 1
            && $targetAuditCount === 1
            && $receiptCount('WARD_CREATE', $expectedKey) === 1
            && $conflictingCodeAbsent;
          $targetAssertions += [
            'target_version_count' => $targetVersionCount,
            'target_success_audit_count' => $targetAuditCount,
            'conflicting_code_absent' => $conflictingCodeAbsent,
          ];
        } elseif ($scenario === 'same-expected-version-update') {
          $target = $ward();
          $targetVersionCount = $target->versions()->count();
          $targetAuditCount = $auditCount('master.inpatient.ward.update', $target->public_id);
          $ok = $target->version === 2
            && $targetVersionCount === 2
            && $targetAuditCount === 1
            && $receiptCount('WARD_UPDATE', 'update-a') === 1;
          $targetAssertions += [
            'target_version_count' => $targetVersionCount,
            'target_success_audit_count' => $targetAuditCount,
          ];
        } elseif ($scenario === 'admission-vs-retirement') {
          $primary = $bed();
          $reverse = $reverseBed();
          $primaryActiveEncounters = Encounter::query()->where('bed_code', $bedCode)->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)->count();
          $reverseActiveEncounters = Encounter::query()->where('bed_code', $reverseBedCode)->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)->count();
          $primaryRetireAuditCount = $auditCount('master.inpatient.bed.retire', $primary->public_id);
          $reverseRetireAuditCount = $auditCount('master.inpatient.bed.retire', $reverse->public_id);
          $ok = $primaryActiveEncounters === 1
            && $primary->state === InpatientBed::STATE_ACTIVE && $primary->version === 1
            && $receiptCount('BED_RETIRE', 'retire-vs-admission') === 0
            && $primaryRetireAuditCount === 0
            && $reverseActiveEncounters === 0
            && $reverse->state === InpatientBed::STATE_RETIRED && $reverse->version === 2
            && $receiptCount('BED_RETIRE', 'retirement-first') === 1
            && $reverseRetireAuditCount === 1;
          $targetAssertions = [
            'target_identity' => $bedCode,
            'admission_wins_active_encounter_count' => $primaryActiveEncounters,
            'admission_wins_bed_version' => $primary->version,
            'admission_wins_retire_audit_count' => $primaryRetireAuditCount,
            'reverse_target_identity' => $reverseBedCode,
            'retirement_wins_active_encounter_count' => $reverseActiveEncounters,
            'retirement_wins_bed_version' => $reverse->version,
            'retirement_wins_retire_audit_count' => $reverseRetireAuditCount,
          ];
        }
        if (!$ok) { throw new RuntimeException('durable verification failed'); }
        $emit([
          'status' => 'PASS', 'protocol_state' => 'VERIFIED', 'scenario' => $scenario,
          'durable_third_connection_assertions' => true, 'target_assertions' => $targetAssertions,
        ]); exit(0);
      }

      $perform = function () use ($scenario, $worker, $actor, $ward, $bed, $wardCode, $bedCode, $service): string {
        $admin = $actor();
        $result = null;
        if ($scenario === 'duplicate-normalized-code') {
          $submittedCode = $worker === 'A' ? strtolower($wardCode) : '  '.$wardCode.'  ';
          $result = $service->createWard($admin, $submittedCode, 'Bangsal Duplikat', 'INITIAL_SETUP', 'duplicate-'.$worker, null);
        } elseif ($scenario === 'same-expected-version-update') {
          $result = $service->updateWard($admin, $ward()->public_id, 'Bangsal '.$worker, 1, 'OPERATIONAL_CHANGE', 'update-'.$worker, null);
        } elseif ($scenario === 'identical-replay') {
          $result = $service->createWard($admin, $wardCode, 'Bangsal Replay', 'INITIAL_SETUP', 'identical-replay', null);
        } elseif ($scenario === 'conflicting-replay') {
          $submittedCode = $worker === 'A' ? $wardCode : $wardCode.'-B';
          $result = $service->createWard($admin, $submittedCode, 'Bangsal Konflik '.$worker, 'INITIAL_SETUP', 'conflicting-replay', null);
        } elseif ($scenario === 'admission-vs-retirement' && $worker === 'A') {
          $target = $service->resolveActiveBedForAdmission($bed()->public_id);
          app(InpatientBedClaimGuard::class)->assertAvailable($target->code);
          $patient = Patient::query()->where('medical_record_number', 'MR-'.substr($wardCode, 2))->firstOrFail();
          Encounter::query()->create([
            'patient_id' => $patient->id, 'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED, 'clinic_name' => $target->ward->display_name,
            'ward_name' => $target->ward->display_name, 'ward_class' => $target->service_class,
            'bed_code' => $target->code, 'inpatient_bed_id' => $target->id,
            'continue_from' => Encounter::CONTINUE_LANGSUNG, 'visit_date' => now()->toDateString(),
            'payer_type' => Encounter::PAYER_UMUM, 'queue_date' => now()->toDateString(),
            'queue_number' => 1, 'registered_at' => now(), 'registered_by_user_id' => $admin->id,
          ]);
        } elseif ($scenario === 'admission-vs-retirement') {
          $result = $service->retireBed($admin, $bed()->public_id, 1, 'RETIREMENT', 'retire-vs-admission', null);
        }
        return $result?->replayed === true ? 'REPLAYED' : 'APPLIED';
      };

      if ($worker === 'A') {
        $outcome = DB::transaction(function () use ($scenario, $worker, $holdMs, $emit, $backendId, $hold, $perform): string {
          $emit(['status' => 'PASS', 'protocol_state' => 'STARTED', 'scenario' => $scenario, 'worker' => $worker, 'backend_connection_id' => $backendId()]);
          $result = $perform();
          $emit(['status' => 'PASS', 'protocol_state' => 'HOLDING', 'scenario' => $scenario, 'worker' => $worker]);
          $hold($holdMs);
          return $result;
        }, 1);
      } else {
        $emit(['status' => 'PASS', 'protocol_state' => 'STARTED', 'scenario' => $scenario, 'worker' => $worker, 'backend_connection_id' => $backendId()]);
        $outcome = $perform();
      }
      $emit(['status' => 'PASS', 'protocol_state' => 'COMMITTED', 'scenario' => $scenario, 'worker' => $worker, 'outcome' => $outcome]);
    } catch (InpatientMasterDenied $exception) {
      $allowedReasons = ['duplicate_code', 'stale_version', 'idempotency_key_conflict', 'bed_occupied', 'master_retired'];
      $reason = in_array($exception->reasonCode, $allowedReasons, true) ? $exception->reasonCode : 'denied';
      $emit(['status' => 'PASS', 'protocol_state' => 'COMMITTED', 'scenario' => $scenario, 'worker' => $worker, 'outcome' => 'DENIED', 'reason' => $reason]);
    } catch (Throwable $exception) {
      $emit([
        'status' => 'BLOCKED', 'protocol_state' => 'FAILED', 'scenario' => $scenario, 'worker' => $worker,
        'exception_class' => $exception::class,
        'exception_fingerprint' => hash('sha256', $exception::class."\0".$exception->getMessage()),
      ]);
      exit(1);
    }
  PHP

  def initialize(engine:, environment: ENV.to_h, runner: HarnessRunner.new, monotonic_clock: nil)
    @operator_environment = environment.dup
    super(
      engine: engine,
      environment: environment.merge(
        'SIMRS_PORTABILITY_REHEARSAL_CONFIRM' => LocalPortabilityFullSuiteRehearsal::CONFIRMATION
      ),
      runner: runner,
      monotonic_clock: monotonic_clock
    )
    @workers = []
    @census_signal_directory = nil
  end

  def run!
    assert_contract!
    execution_bindings = current_inpatient_master_execution_bindings
    contract_suite = run_contract_suite!
    engine_binding = prepare_engine!
    @run_token = database_run_token
    prepare_census_signal_directory!

    migration_started = @clock.call
    run_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(migration_started)
    run_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    focused_suite = run_test_suite!([FOCUSED_TEST_PATH])
    run_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    assert_no_non_synthetic_patients!

    started = @clock.call
    scenarios = RACE_SCENARIOS.to_h { |scenario| [scenario, run_race_scenario!(scenario)] }
    scenarios['database-constraints'] = run_database_constraints!
    scenarios['census-repeatable-read'] = run_census_projection!
    scenarios['empty-down'] = run_empty_down!
    scenarios['populated-evidence-down-refusal'] = run_populated_and_evidence_down_refusal!
    duration_ms = elapsed_ms(started)

    assert_no_non_synthetic_patients!
    assert_unchanged_binding!(
      'Inpatient master portability execution bindings',
      execution_bindings,
      current_inpatient_master_execution_bindings
    )

    cleanup_census_signal_directory!(strict: true)
    cleanup!(strict: true)
    evidence_path = write_inpatient_master_evidence!(
      execution_bindings: execution_bindings,
      engine_binding: engine_binding,
      contract_suite: contract_suite,
      focused_suite: focused_suite,
      migration_duration_ms: migration_duration_ms,
      duration_ms: duration_ms,
      scenarios: scenarios
    )

    {
      'status' => 'PASS',
      'claim' => 'LOCAL_DISPOSABLE_INPATIENT_MASTER_PORTABILITY_ONLY',
      'engine' => @engine,
      'scenario_count' => scenarios.length,
      'evidence_path' => evidence_path
    }
  ensure
    terminate_workers!
    cleanup_census_signal_directory!
    cleanup!
  end

  def assert_contract!
    unless @operator_environment[CONFIRMATION_ENV] == CONFIRMATION
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize disposable local engine creation."
    end
    rejected = FORBIDDEN_ENVIRONMENT.select { |name| !@operator_environment.fetch(name, '').to_s.strip.empty? }
    unless rejected.empty?
      raise CommandFailed, "Inpatient master portability rehearsal refuses inherited database or executable overrides: #{rejected.join(', ')}."
    end

    super
    ([SCRIPT_PATH, FOUNDATION_HARNESS_PATH, CONTRACT_TEST, FOCUSED_TEST_PATH, MIGRATION_PATH] + SOURCE_PATHS).each { |path| safe_source_path(path) }
    unless SCENARIOS.length == 9 && SCENARIOS == SCENARIOS.uniq && RACE_SCENARIOS.length == 5
      raise CommandFailed, 'Inpatient master portability scenario catalogue must contain exactly nine unique scenarios and five races.'
    end
  end

  def application_environment
    super.merge(
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false',
      'VCLAIM_ENABLED' => 'false',
      'SATUSEHAT_ENABLED' => 'false'
    )
  end

  def current_inpatient_master_execution_bindings
    paths = [SCRIPT_PATH, FOUNDATION_HARNESS_PATH, CONTRACT_TEST, FOCUSED_TEST_PATH, MIGRATION_PATH] + SOURCE_PATHS
    hashes = paths.to_h do |path|
      source = safe_source_path(path)
      [path, Digest::SHA256.file(source).hexdigest]
    end
    {
      'files' => hashes,
      'aggregate_sha256' => Digest::SHA256.hexdigest(JSON.generate(hashes.sort.to_h)),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)),
      'php_worker_sha256' => Digest::SHA256.hexdigest(PHP_WORKER)
    }
  end

  private

  def run_contract_suite!
    output = @runner.run!([@ruby_binary, File.join(ROOT, CONTRACT_TEST)])
    plain = output.gsub(/\e\[[0-9;?]*[ -\/]*[@-~]/, '')
    match = plain.match(/(\d+) runs, (\d+) assertions, (\d+) failures, (\d+) errors, (\d+) skips/)
    unless match && match[3].to_i.zero? && match[4].to_i.zero?
      raise CommandFailed, 'Inpatient master portability contract test did not return a passing aggregate result.'
    end
    {
      'tests' => match[1].to_i,
      'assertions' => match[2].to_i,
      'failures' => match[3].to_i,
      'errors' => match[4].to_i,
      'skipped' => match[5].to_i
    }
  end

  def run_race_scenario!(scenario)
    prepared = run_worker_command!(action: 'prepare', scenario: scenario, worker: 'A', hold_ms: 0)
    require_protocol!(prepared, 'PREPARED', scenario)

    first = start_worker!(scenario: scenario, worker: 'A', hold_ms: HOLD_MS)
    require_protocol!(await_protocol!(first, 'STARTED'), 'STARTED', scenario, 'A')
    require_protocol!(await_protocol!(first, 'HOLDING'), 'HOLDING', scenario, 'A')

    second = start_worker!(scenario: scenario, worker: 'B', hold_ms: 0)
    second_started = await_protocol!(second, 'STARTED')
    require_protocol!(second_started, 'STARTED', scenario, 'B')
    wait_observed = observe_real_database_wait!(second_started.fetch('backend_connection_id'))

    first_final = await_final!(first)
    second_final = await_final!(second)
    assert_scenario_outcomes!(scenario, first_final, second_final)

    ordering_evidence = {}
    if scenario == 'admission-vs-retirement'
      admission_wins = run_worker_command!(action: 'verify-admission-wins', scenario: scenario, worker: 'A', hold_ms: 0)
      require_protocol!(admission_wins, 'VERIFIED', scenario)
      admission_wins_valid = [
        admission_wins['ordering'] == 'admission-wins',
        admission_wins['active_encounter_count'] == 1,
        admission_wins['bed_state'] == 'ACTIVE',
        admission_wins['bed_version'] == 1,
        admission_wins['retire_receipt_count'] == 0,
        admission_wins['retire_audit_count'] == 0
      ].all?
      unless admission_wins_valid
        raise CommandFailed, 'Admission-wins ordering did not preserve its exact durable target state.'
      end

      retirement = run_worker_command!(action: 'retirement-first', scenario: scenario, worker: 'B', hold_ms: 0)
      require_protocol!(retirement, 'RETIRED', scenario)
      unless retirement['outcome'] == 'APPLIED' && retirement['reverse_bed_code'].is_a?(String)
        raise CommandFailed, 'Retirement-first ordering did not commit its exact reverse target.'
      end

      denied_admission = run_worker_command!(action: 'admission-after-retirement', scenario: scenario, worker: 'A', hold_ms: 0)
      require_protocol!(denied_admission, 'VERIFIED', scenario)
      denied_admission_valid = [
        denied_admission['outcome'] == 'DENIED',
        %w[master_retired bed_retired].include?(denied_admission['reason']),
        denied_admission['reverse_bed_code'] == retirement['reverse_bed_code'],
        denied_admission['active_encounter_count'] == 0,
        denied_admission['retired_version'] == 2
      ].all?
      unless denied_admission_valid
        raise CommandFailed, 'Admission-after-retirement ordering did not fail closed on its exact reverse target.'
      end

      ordering_evidence = {
        'admission_wins' => {
          'proof_kind' => 'observed_independent_process_lock_race',
          'target_bed_code' => admission_wins.fetch('target_bed_code'),
          'active_encounter_count' => 1,
          'bed_state' => 'ACTIVE',
          'bed_version' => 1,
          'retire_receipt_count' => 0,
          'retire_audit_count' => 0
        },
        'retirement_wins_sequential_state_proof' => {
          'proof_kind' => 'retirement_commit_then_fresh_admission_denial',
          'target_bed_code' => retirement.fetch('reverse_bed_code'),
          'retirement_outcome' => 'APPLIED',
          'subsequent_admission_outcome' => 'DENIED',
          'subsequent_admission_reason' => denied_admission.fetch('reason'),
          'active_encounter_count' => 0,
          'bed_state' => 'RETIRED',
          'bed_version' => 2
        }
      }
    end

    verified = run_worker_command!(action: 'verify', scenario: scenario, worker: 'A', hold_ms: 0)
    require_protocol!(verified, 'VERIFIED', scenario)
    unless verified['durable_third_connection_assertions'] == true
      raise CommandFailed, 'Inpatient master verification omitted fresh third-connection durable assertions.'
    end

    {
      'status' => 'PASS',
      'independent_processes' => 2,
      'outer_transaction_holding_protocol' => true,
      'real_database_wait_observed' => wait_observed,
      'observed_race_ordering' => scenario == 'admission-vs-retirement' ? 'admission-wins' : nil,
      'durable_third_connection_assertions' => true,
      'outcomes' => [first_final.fetch('outcome'), second_final.fetch('outcome')].sort,
      'target_assertions' => verified.fetch('target_assertions'),
      'ordering_evidence' => ordering_evidence
    }
  ensure
    terminate_workers!
  end

  def run_database_constraints!
    prepared = run_worker_command!(action: 'prepare', scenario: 'database-constraints', worker: 'A', hold_ms: 0)
    require_protocol!(prepared, 'PREPARED', 'database-constraints')
    verified = run_worker_command!(action: 'constraints', scenario: 'database-constraints', worker: 'A', hold_ms: 0)
    require_protocol!(verified, 'VERIFIED', 'database-constraints')
    checks = verified.fetch('constraints')
    raise CommandFailed, 'Database constraint rehearsal did not verify five closed checks.' unless checks.is_a?(Array) && checks.length == 5
    { 'status' => 'PASS', 'constraint_count' => checks.length }
  end

  def run_census_projection!
    prepared = run_worker_command!(action: 'prepare', scenario: 'census-repeatable-read', worker: 'A', hold_ms: 0)
    require_protocol!(prepared, 'PREPARED', 'census-repeatable-read')
    raise CommandFailed, 'Census signal unexpectedly exists before controlled interleaving.' if File.exist?(census_signal_path) || File.symlink?(census_signal_path)

    observer = start_worker!(scenario: 'census-repeatable-read', worker: 'A', hold_ms: 0, action: 'census-observe')
    require_protocol!(await_protocol!(observer, 'STARTED'), 'STARTED', 'census-repeatable-read', 'A')
    require_protocol!(await_protocol!(observer, 'CLAIMS_READ'), 'CLAIMS_READ', 'census-repeatable-read', 'A')

    renamer = start_worker!(scenario: 'census-repeatable-read', worker: 'B', hold_ms: 0, action: 'census-rename')
    require_protocol!(await_protocol!(renamer, 'STARTED'), 'STARTED', 'census-repeatable-read', 'B')
    renamed = await_final!(renamer)
    require_protocol!(renamed, 'COMMITTED', 'census-repeatable-read', 'B')

    verified = await_exit_protocol!(observer, 'VERIFIED')
    require_protocol!(verified, 'VERIFIED', 'census-repeatable-read')
    census_snapshot_valid = [
      verified['repeatable_read_snapshot_observed'] == true,
      verified['observed_prechange_master'] == true,
      verified['exact_target_matched'] == true,
      verified['target_match_count'] == 1,
      verified['target_ward_code'].is_a?(String)
    ].all?
    unless census_snapshot_valid
      raise CommandFailed, 'Census projection did not preserve the pre-change master in its controlled repeatable-read snapshot.'
    end
    {
      'status' => 'PASS',
      'controlled_interleaving' => 'claims-read_then_master-rename-committed_then_master-read',
      'repeatable_read_snapshot_observed' => true,
      'observed_prechange_master' => true,
      'target_identity' => { 'type' => 'immutable_ward_code', 'value' => verified.fetch('target_ward_code') },
      'target_match_count' => 1,
      'exact_target_matched' => true,
      'projected_ward_count' => Integer(verified.fetch('projected_ward_count'))
    }
  ensure
    terminate_workers!
    remove_census_signal!
  end

  def run_empty_down!
    run_artisan!('migrate:fresh', '--force', '--no-interaction')
    run_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    run_artisan!('migrate:rollback', "--path=#{MIGRATION_PATH}", '--force', '--no-interaction')
    verified = run_worker_command!(action: 'assert-empty-down', scenario: 'empty-down', worker: 'A', hold_ms: 0)
    require_protocol!(verified, 'VERIFIED', 'empty-down')
    run_artisan!('migrate', "--path=#{MIGRATION_PATH}", '--force', '--no-interaction')
    { 'status' => 'PASS', 'empty_down_succeeded' => true, 'reapply_succeeded' => true }
  end

  def run_populated_and_evidence_down_refusal!
    prepared = run_worker_command!(action: 'prepare', scenario: 'populated-evidence-down-refusal', worker: 'A', hold_ms: 0)
    require_protocol!(prepared, 'PREPARED', 'populated-evidence-down-refusal')
    raw = run_worker_command!(action: 'raw-populate', scenario: 'populated-evidence-down-refusal', worker: 'A', hold_ms: 0)
    require_protocol!(raw, 'POPULATED', 'populated-evidence-down-refusal')
    expect_rollback_refusal!('verify-populated-refusal', 'raw_populated_retained')
    cleared = run_worker_command!(action: 'clear-raw-populated', scenario: 'populated-evidence-down-refusal', worker: 'A', hold_ms: 0)
    require_protocol!(cleared, 'CLEARED', 'populated-evidence-down-refusal')
    audited = run_worker_command!(action: 'audited-populate', scenario: 'populated-evidence-down-refusal', worker: 'A', hold_ms: 0)
    require_protocol!(audited, 'POPULATED', 'populated-evidence-down-refusal')
    reset = run_worker_command!(action: 'reset', scenario: 'populated-evidence-down-refusal', worker: 'A', hold_ms: 0)
    require_protocol!(reset, 'RESET', 'populated-evidence-down-refusal')
    unless reset.values_at('master_chains_removed', 'code_reservation_retained', 'old_code_reuse_refused').all?(true)
      raise CommandFailed, 'Synthetic reset did not prove chain removal plus retained no-reuse tombstone semantics.'
    end
    expect_rollback_refusal!('verify-audit-refusal', 'correlated_audit_retained')
    {
      'status' => 'PASS',
      'raw_populated_down_refused' => true,
      'master_chains_removed' => true,
      'code_reservation_retained' => true,
      'old_code_reuse_refused' => true,
      'audit_evidence_down_refused' => true
    }
  end

  def expect_rollback_refusal!(verification_action, assertion_key)
    begin
      run_artisan!('migrate:rollback', "--path=#{MIGRATION_PATH}", '--force', '--no-interaction')
    rescue CommandFailed
      verified = run_worker_command!(
        action: verification_action,
        scenario: 'populated-evidence-down-refusal',
        worker: 'A',
        hold_ms: 0
      )
      require_protocol!(verified, 'VERIFIED', 'populated-evidence-down-refusal')
      unless verified[assertion_key] == true
        raise CommandFailed, 'Inpatient master rollback failure did not preserve the expected evidence boundary.'
      end
      return true
    end
    raise CommandFailed, 'Inpatient master rollback unexpectedly succeeded.'
  end

  def start_worker!(scenario:, worker:, hold_ms:, action: 'operate')
    stdin, stdout, stderr, wait_thread = Open3.popen3(
      @runner.process_environment(worker_environment(scenario: scenario, worker: worker, action: action, hold_ms: hold_ms)),
      *php_worker_argv,
      unsetenv_others: true
    )
    stdin.close
    process = Worker.new(
      stdout: stdout,
      stderr: stderr,
      wait_thread: wait_thread,
      stderr_reader: Thread.new { stderr.read },
      lines: []
    )
    @workers << process
    process
  end

  def await_protocol!(worker, state)
    deadline = @clock.call + WAIT_TIMEOUT_SECONDS
    loop do
      existing = worker.lines.find { |line| line['protocol_state'] == state }
      return existing if existing
      remaining = deadline - @clock.call
      raise CommandFailed, "Inpatient master worker did not reach #{state} before timeout." if remaining <= 0
      ready = IO.select([worker.stdout], nil, nil, [remaining, 0.25].min)
      next unless ready
      line = worker.stdout.gets
      raise_worker_failure!(worker, "Inpatient master worker exited before reaching #{state}.") if line.nil?
      document = parse_worker_line(line)
      next unless document
      worker.lines << document
      if document['status'] == 'BLOCKED'
        error_class = document.fetch('exception_class', 'unknown')
        fingerprint = document.fetch('exception_fingerprint', 'unknown')
        blocked_scenario = document.fetch('scenario', 'unknown')
        blocked_worker = document.fetch('worker', 'unknown')
        raise CommandFailed, "Inpatient master worker blocked (scenario=#{blocked_scenario}, worker=#{blocked_worker}, #{error_class}, #{fingerprint})."
      end
    end
  end

  def await_final!(worker)
    final = await_protocol!(worker, 'COMMITTED')
    status = worker.wait_thread.value
    stderr = worker.stderr_reader.value
    unless status.success?
      reason = @runner.sanitize(stderr)
      suffix = reason.empty? ? '' : ": #{reason}"
      raise CommandFailed, "Inpatient master worker failed (scenario=#{final['scenario']}, worker=#{final['worker']}, exit=#{status.exitstatus})#{suffix}"
    end
    close_worker!(worker)
    final
  end

  def await_exit_protocol!(worker, state)
    final = await_protocol!(worker, state)
    status = worker.wait_thread.value
    stderr = worker.stderr_reader.value
    unless status.success?
      reason = @runner.sanitize(stderr)
      suffix = reason.empty? ? '' : ": #{reason}"
      raise CommandFailed, "Inpatient master worker failed (scenario=#{final['scenario']}, worker=#{final['worker']}, exit=#{status.exitstatus})#{suffix}"
    end
    close_worker!(worker)
    final
  end

  def observe_real_database_wait!(backend_connection_id)
    id = Integer(backend_connection_id.to_s, 10)
    raise CommandFailed, 'Worker returned an invalid backend connection ID.' unless id.positive?
    deadline = @clock.call + 3.0
    loop do
      observed = @engine == 'postgresql17' ? postgres_wait_observed?(id) : mysql_wait_observed?(id)
      return true if observed
      raise CommandFailed, 'No real database lock wait was observed for the competing inpatient master worker.' if @clock.call >= deadline
      sleep 0.05
    end
  rescue ArgumentError, TypeError
    raise CommandFailed, 'Worker returned an invalid backend connection ID.'
  end

  def postgres_wait_observed?(backend_connection_id)
    output = @runner.run!(
      postgres_psql_arguments(@postgres_database) + [
        '--tuples-only', '--no-align', '--command',
        "SELECT CASE WHEN wait_event_type = 'Lock' AND cardinality(pg_blocking_pids(pid)) > 0 THEN '1' ELSE '0' END FROM pg_stat_activity WHERE pid = #{backend_connection_id}"
      ],
      env: postgres_tool_environment
    ).strip
    output == '1'
  end

  def mysql_wait_observed?(backend_connection_id)
    output = @runner.run!(
      mysql_root_arguments + ['--batch', '--skip-column-names'],
      stdin_data: <<~SQL
        SELECT COUNT(*)
        FROM performance_schema.data_lock_waits AS waits
        INNER JOIN performance_schema.threads AS threads
          ON threads.thread_id = waits.requesting_thread_id
        WHERE threads.processlist_id = #{backend_connection_id};
      SQL
    ).strip
    Integer(output, 10).positive?
  rescue ArgumentError
    false
  end

  def run_worker_command!(action:, scenario:, worker:, hold_ms:)
    output = @runner.run!(
      php_worker_argv,
      env: worker_environment(scenario: scenario, worker: worker, action: action, hold_ms: hold_ms)
    )
    document = nil
    output.lines.reverse_each do |line|
      parsed = parse_worker_line(line)
      if parsed
        document = parsed
        break
      end
    end
    unless document.is_a?(Hash) && document['status'] == 'PASS'
      raise CommandFailed, "Inpatient master #{action} worker returned no passing protocol result."
    end
    document
  end

  def worker_environment(scenario:, worker:, action:, hold_ms:)
    application_environment.merge(
      'SIMRS_MASTER_ROOT' => ROOT,
      'SIMRS_MASTER_SCENARIO' => scenario,
      'SIMRS_MASTER_ACTION' => action,
      'SIMRS_MASTER_WORKER' => worker,
      'SIMRS_MASTER_RUN_TOKEN' => @run_token,
      'SIMRS_MASTER_HOLD_MS' => hold_ms.to_s,
      'SIMRS_MASTER_SIGNAL_PATH' => census_signal_path
    )
  end

  def census_signal_path
    directory = @census_signal_directory
    raise CommandFailed, 'Census signal requires its owned temporary directory.' unless directory.is_a?(String)
    File.join(directory, 'commit.signal')
  end

  def remove_census_signal!
    path = census_signal_path
    raise CommandFailed, 'Census signal cleanup refuses a symlink.' if File.symlink?(path)
    File.delete(path) if File.file?(path)
  rescue SystemCallError
    nil
  end

  def prepare_census_signal_directory!
    directory = Dir.mktmpdir('simrs-inpatient-master-signal-', '/private/tmp')
    File.chmod(0o700, directory)
    stat = File.lstat(directory)
    unless stat.directory? && !stat.symlink? && (stat.mode & 0o777) == 0o700 && File.realpath(directory) == directory
      raise CommandFailed, 'Census signal directory is not an owned mode-0700 real directory.'
    end
    @census_signal_directory = directory
  rescue SystemCallError => error
    raise CommandFailed, "Cannot prepare census signal directory: #{@runner.sanitize(error.message)}"
  end

  def cleanup_census_signal_directory!(strict: false)
    directory = @census_signal_directory
    return unless directory.is_a?(String)
    stat = File.lstat(directory)
    raise CommandFailed, 'Census signal cleanup refuses a non-directory or symlink.' unless stat.directory? && !stat.symlink?
    path = File.join(directory, 'commit.signal')
    raise CommandFailed, 'Census signal cleanup refuses a symlink.' if File.symlink?(path)
    File.delete(path) if File.file?(path)
    entries = Dir.children(directory)
    raise CommandFailed, 'Census signal directory contains unexpected entries.' unless entries.empty?
    Dir.rmdir(directory)
    raise CommandFailed, 'Census signal directory cleanup was not verified.' if File.exist?(directory)
    @census_signal_directory = nil
  rescue SystemCallError => error
    raise CommandFailed, "Cannot clean census signal directory: #{@runner.sanitize(error.message)}" if strict
  ensure
    @census_signal_directory = nil unless strict
  end

  def php_worker_argv
    [@php_binary, '-r', PHP_WORKER]
  end

  def parse_worker_line(line)
    document = JSON.parse(line.strip)
    return nil unless document.is_a?(Hash) && document['schema_version'] == 1
    document
  rescue JSON::ParserError
    nil
  end

  def require_protocol!(document, state, scenario, worker = nil)
    valid = document['status'] == 'PASS' && document['protocol_state'] == state && document['scenario'] == scenario
    valid &&= document['worker'] == worker if worker
    raise CommandFailed, "Inpatient master worker protocol mismatch at #{state}." unless valid
  end

  def assert_scenario_outcomes!(scenario, first, second)
    require_protocol!(first, 'COMMITTED', scenario, 'A')
    require_protocol!(second, 'COMMITTED', scenario, 'B')
    expected = scenario == 'identical-replay' ? %w[APPLIED REPLAYED] : %w[APPLIED DENIED]
    actual = [first.fetch('outcome'), second.fetch('outcome')].sort
    raise CommandFailed, "Inpatient master race #{scenario} returned unexpected outcomes." unless actual == expected.sort

    expected_reason = {
      'duplicate-normalized-code' => 'duplicate_code',
      'same-expected-version-update' => 'stale_version',
      'conflicting-replay' => 'idempotency_key_conflict',
      'admission-vs-retirement' => 'bed_occupied'
    }[scenario]
    return unless expected_reason
    denied = [first, second].find { |result| result['outcome'] == 'DENIED' }
    unless denied && denied['reason'] == expected_reason
      raise CommandFailed, "Inpatient master race #{scenario} returned an unexpected denial reason."
    end
  end

  def raise_worker_failure!(worker, message)
    status = worker.wait_thread.value
    stderr = worker.stderr_reader.value
    reason = @runner.sanitize(stderr)
    suffix = reason.empty? ? '' : ": #{reason}"
    raise CommandFailed, "#{message} exit=#{status.exitstatus}#{suffix}"
  end

  def close_worker!(worker)
    worker.stdout.close unless worker.stdout.closed?
    worker.stderr.close unless worker.stderr.closed?
    @workers.delete(worker)
  end

  def terminate_workers!
    @workers.each do |worker|
      begin
        if worker.wait_thread.alive?
          Process.kill('TERM', worker.wait_thread.pid)
          worker.wait_thread.join(2)
          Process.kill('KILL', worker.wait_thread.pid) if worker.wait_thread.alive?
        end
      rescue Errno::ESRCH, Errno::ECHILD
        nil
      ensure
        worker.stdout.close unless worker.stdout.closed?
        worker.stderr.close unless worker.stderr.closed?
        worker.stderr_reader.join(0.5)
      end
    end
    @workers.clear
  end

  def database_run_token
    database = @engine == 'postgresql17' ? @postgres_database : @mysql_database
    token = database.to_s[/[0-9a-f]{12}\z/]
    raise CommandFailed, 'Disposable database did not expose the closed run-token binding.' unless token
    token
  end

  def write_inpatient_master_evidence!(execution_bindings:, engine_binding:, contract_suite:, focused_suite:, migration_duration_ms:, duration_ms:, scenarios:)
    assert_evidence_directory!
    timestamp = Time.now.utc.strftime('%Y%m%dT%H%M%SZ')
    path = File.join(EVIDENCE_DIRECTORY, "#{timestamp}-#{@engine}-inpatient-master-portability-#{SecureRandom.hex(6)}.json")
    head = @runner.run!([@git_binary, '-C', ROOT, 'rev-parse', 'HEAD']).strip
    dirty = !@runner.run!([@git_binary, '-C', ROOT, 'status', '--porcelain']).strip.empty?
    evidence = {
      'schema_version' => 1,
      'kind' => EVIDENCE_KIND,
      'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_INPATIENT_MASTER_PORTABILITY_ONLY',
      'hosted_concurrency_claim' => false,
      'deployment_claim' => false,
      'owner_acceptance_claim' => false,
      'baseline_git_sha' => head,
      'working_tree_state' => dirty ? 'UNCOMMITTED_LOCAL_MILESTONE' : 'CLEAN',
      'source_bindings' => execution_bindings,
      'boundary' => {
        'application_mode' => 'SIMULATION',
        'synthetic_only' => true,
        'real_patient_data_rows' => 0,
        'live_integrations_enabled' => false,
        'break_glass_mode' => 'off',
        'disposable_local_engine' => true,
        'independent_worker_processes' => true
      },
      'engine' => engine_binding,
      'verification_commands' => [
        {
          'name' => 'contract',
          'command' => "ruby #{CONTRACT_TEST}",
          'result' => { 'status' => 'PASS' }.merge(contract_suite)
        },
        {
          'name' => 'fresh-migration',
          'command' => 'php artisan migrate:fresh --force --no-interaction',
          'result' => { 'status' => 'PASS' }
        },
        {
          'name' => 'rbac-fixture',
          'command' => 'php artisan db:seed --class=Database\\Seeders\\RbacSeeder --force --no-interaction',
          'result' => { 'status' => 'PASS', 'executions' => 2, 'boundaries' => ['before-focused-suite', 'after-focused-suite'] }
        },
        {
          'name' => 'focused-feature-suite',
          'command' => "php artisan test #{FOCUSED_TEST_PATH}",
          'result' => { 'status' => 'PASS' }.merge(focused_suite)
        },
        {
          'name' => 'engine-harness',
          'command' => "#{CONFIRMATION_ENV}=#{CONFIRMATION} ruby #{SCRIPT_PATH} #{@engine}",
          'result' => { 'status' => 'PASS', 'scenario_count' => scenarios.length }
        }
      ],
      'migration' => { 'fresh_apply' => 'PASS', 'duration_ms_observed' => migration_duration_ms },
      'scenario_duration_ms_observed' => duration_ms,
      'scenarios' => scenarios,
      'cleanup' => {
        'database_removed' => true,
        'temporary_server_removed' => true,
        'temporary_user_state_removed' => true
      },
      'open_boundaries' => [
        'This evidence is limited to disposable local PostgreSQL 17.10 or MySQL 8.4.11 behavior.',
        'It is not hosted migration, deployment, production, capacity, clinical acceptance or owner acceptance evidence.',
        'No real patient data or live BPJS, VClaim or SATUSEHAT integration was used.'
      ]
    }
    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence) + "\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    path
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalInpatientMasterPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    result = LocalInpatientMasterPortabilityRehearsal.new(engine: ARGV.first).run!
    puts JSON.generate(result)
  rescue LocalInpatientMasterPortabilityRehearsal::CommandFailed => e
    warn "inpatient master portability rehearsal failed: #{e.message}"
    exit 1
  end
end
