#!/usr/bin/env ruby
# frozen_string_literal: true

require 'base64'
require 'digest'
require 'json'
require 'open3'
require 'securerandom'
require 'tempfile'
require 'time'

require_relative 'rehearse-local-portability-full-suite'

# Exact, disposable PostgreSQL 17/MySQL 8.4 proof for the atomic routine
# inpatient discharge workflow. It creates its own database and identities and
# refuses every inherited external database override.
class LocalInpatientDischargePortabilityRehearsal < LocalPortabilityFullSuiteRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_INPATIENT_DISCHARGE_PORTABILITY'
  CONFIRMATION_ENV = 'SIMRS_INPATIENT_DISCHARGE_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-inpatient-discharge-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalInpatientDischargePortabilityHarnessContractTest.rb'
  FOUNDATION_PATH = 'scripts/rehearse-local-portability-full-suite.rb'
  MIGRATION_PATH = 'database/migrations/2026_08_31_000700_create_inpatient_discharge_tables.php'
  FEATURE_PATH = 'tests/Feature/Inpatient/RoutineInpatientDischargeTest.php'
  EVIDENCE_KIND = 'SIMRS_LOCAL_INPATIENT_DISCHARGE_PORTABILITY'
  HOLD_MS = 3_500
  WAIT_TIMEOUT_SECONDS = 24
  IDENTITY_PATTERN = /\Asimrs_(?:runtime|reset)_[0-9a-f]{12}\z/
  IMMUTABLE_HISTORY_TABLES = %w[
    inpatient_discharges
    inpatient_discharge_operation_receipts
    inpatient_discharge_summary_versions
    inpatient_discharge_summary_operation_receipts
    inpatient_location_events
    inpatient_location_operation_receipts
  ].freeze

  RACE_SCENARIOS = %w[
    same-discharge-competing-execution
    final-vs-transfer-final-first
    final-vs-transfer-transfer-first
    released-bed-reuse
  ].freeze

  SCENARIOS = %w[
    fresh-migration
    empty-down-reapply
    successful-routine-discharge
    missing-draft-stale-summary-denials
    stale-placement-denial
    exact-idempotent-replay
    changed-payload-key-conflict
    corrupt-replay-binding-denial
    same-discharge-competing-execution
    final-vs-transfer-final-first
    final-vs-transfer-transfer-first
    released-bed-reuse
    audit-failure-atomic-rollback
    receipt-failure-atomic-rollback
    append-only-engine-refusal
    least-privilege-runtime
    bounded-synthetic-reset
    populated-evidence-down-refusal
    invariant-verification
  ].freeze

  SOURCE_PATHS = %w[
    scripts/rehearse-local-inpatient-discharge-portability.rb
    scripts/rehearse-local-portability-full-suite.rb
    tests/Documentation/LocalInpatientDischargePortabilityHarnessContractTest.rb
    tests/Feature/Inpatient/RoutineInpatientDischargeSummaryTest.php
    tests/Feature/Inpatient/RoutineInpatientDischargeTest.php
    tests/Unit/Inpatient/InpatientLocationSqlWriteGuardTest.php
    tests/Unit/Inpatient/InpatientDocumentationSqlWriteGuardTest.php
    database/migrations/2026_08_31_000600_create_inpatient_discharge_summary_tables.php
    database/migrations/2026_08_31_000700_create_inpatient_discharge_tables.php
    app/Support/Inpatient/InpatientDischargeService.php
    app/Support/Inpatient/InpatientDischargeActorPolicy.php
    app/Support/Inpatient/InpatientDischargeDenied.php
    app/Support/Inpatient/InpatientDischargeAuditUnavailable.php
    app/Support/Inpatient/InpatientDischargeResult.php
    app/Support/Inpatient/InpatientDischargeMutationScope.php
    app/Support/Inpatient/InpatientDischargeSchemaMutationScope.php
    app/Support/Inpatient/InpatientDischargeSummaryEvidenceDigest.php
    app/Support/Inpatient/InpatientDischargeSummaryService.php
    app/Support/Inpatient/InpatientDischargeSummaryActorPolicy.php
    app/Support/Inpatient/InpatientDischargeSummaryDenied.php
    app/Support/Inpatient/InpatientDischargeSummaryAuditUnavailable.php
    app/Support/Inpatient/InpatientDischargeSummaryMutationResult.php
    app/Support/Inpatient/InpatientDischargeSummaryMutationScope.php
    app/Support/Inpatient/InpatientDischargeSummarySchemaMutationScope.php
    app/Support/Inpatient/InpatientDischargeSummaryPlacementChanged.php
    app/Support/Inpatient/CanonicalInpatientBedOperationLockCoordinator.php
    app/Support/Registration/InpatientBedClaimGuard.php
    app/Support/Inpatient/InpatientBedTransferService.php
    app/Support/Inpatient/InpatientLocationMutationScope.php
    app/Support/Inpatient/InpatientLocationSqlWriteGuard.php
    app/Support/Inpatient/InpatientDocumentationSqlWriteGuard.php
    app/Models/InpatientDischargeSummary.php
    app/Models/InpatientDischargeSummaryVersion.php
    app/Models/InpatientDischargeSummaryOperationReceipt.php
    app/Models/InpatientDischarge.php
    app/Models/InpatientDischargeOperationReceipt.php
    app/Models/InpatientLocationEvent.php
    app/Support/Simulation/SyntheticResetService.php
    app/Support/Audit/AuditRecorder.php
    app/Support/Audit/AuditEventSchemaRegistry.php
    app/Support/Database/SchemaQualifier.php
    app/Providers/AppServiceProvider.php
  ].freeze

  FORBIDDEN_ENVIRONMENT = %w[
    DB_URL DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SOCKET DB_SCHEMA
    PGHOST PGPORT PGUSER PGPASSWORD PGSERVICE PGSERVICEFILE MYSQL_HOST MYSQL_TCP_PORT MYSQL_PWD
    POSTGRES17_BIN MYSQL84_BIN PHP_BINARY GIT_BINARY
  ].freeze

  Worker = Struct.new(:stdout, :stderr, :wait_thread, :stderr_reader, :scenario, :label, keyword_init: true)

  WORKER_SOURCE = <<~'PHP'
    <?php
    declare(strict_types=1);

    use App\Models\Encounter;
    use App\Models\EncounterCancellation;
    use App\Models\InpatientBed;
    use App\Models\InpatientDischarge;
    use App\Models\InpatientDischargeOperationReceipt;
    use App\Models\InpatientDischargeSummary;
    use App\Models\InpatientDischargeSummaryOperationReceipt;
    use App\Models\InpatientDischargeSummaryVersion;
    use App\Models\InpatientLocationEvent;
    use App\Models\InpatientWard;
    use App\Models\Patient;
    use App\Models\Role;
    use App\Models\User;
    use App\Support\Audit\AuditRecorder;
    use App\Support\Audit\AuditEvent;
    use App\Support\Authorization\RoleCapabilityMatrix;
    use App\Support\CanonicalJson;
    use App\Support\Database\SchemaQualifier;
    use App\Support\Inpatient\CanonicalInpatientBedOperationLockCoordinator;
    use App\Support\Inpatient\InpatientBedTransferService;
    use App\Support\Inpatient\InpatientDischargeActorPolicy;
    use App\Support\Inpatient\InpatientDischargeAuditUnavailable;
    use App\Support\Inpatient\InpatientDischargeDenied;
    use App\Support\Inpatient\InpatientDischargeMutationScope;
    use App\Support\Inpatient\InpatientDischargeSchemaMutationScope;
    use App\Support\Inpatient\InpatientDischargeService;
    use App\Support\Inpatient\InpatientDischargeSummaryEvidenceDigest;
    use App\Support\Inpatient\InpatientDischargeSummaryActorPolicy;
    use App\Support\Inpatient\InpatientDischargeSummaryAuditUnavailable;
    use App\Support\Inpatient\InpatientDischargeSummaryDenied;
    use App\Support\Inpatient\InpatientDischargeSummaryMutationScope;
    use App\Support\Inpatient\InpatientDischargeSummaryService;
    use App\Support\Inpatient\InpatientDischargeSummarySchemaMutationScope;
    use App\Support\Inpatient\InpatientLocationMutationScope;
    use App\Support\Inpatient\InpatientMasterService;
    use App\Support\Registration\InpatientBedClaimGuard;
    use App\Support\Simulation\SyntheticResetService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;

    function protocol(string $state, array $extra = []): void
    {
        echo json_encode(array_merge([
            'schema_version' => 1,
            'status' => 'PASS',
            'protocol_state' => $state,
            'scenario' => (string) getenv('SIMRS_DISCHARGE_SCENARIO'),
            'worker' => (string) getenv('SIMRS_DISCHARGE_WORKER'),
        ], $extra), JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
    }

    function must(bool $condition, string $label): void
    {
        if (! $condition) {
            throw new RuntimeException('assertion failed: '.$label);
        }
    }

    function setFailureStage(string $stage): void
    {
        $allowed = [
            'bootstrap',
            'dispatch',
            'audit_service_save',
            'audit_rollback_verification',
            'receipt_trigger_create',
            'receipt_service_save',
            'receipt_trigger_drop',
            'receipt_rollback_verification',
            'sequential_initial_draft',
            'sequential_draft_replay',
            'sequential_complete_draft',
            'sequential_finalize',
            'sequential_race_drafts',
            'sequential_final_replay',
            'sequential_legacy',
            'sequential_cancelled',
            'complete',
        ];
        if (! in_array($stage, $allowed, true)) {
            throw new RuntimeException('failure stage refused');
        }
        $GLOBALS['simrs_discharge_failure_stage'] = $stage;
    }

    function currentFailureStage(): string
    {
        $stage = $GLOBALS['simrs_discharge_failure_stage'] ?? 'bootstrap';
        return is_string($stage) ? $stage : 'bootstrap';
    }

    function backendConnectionId(): int
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? (int) data_get(DB::selectOne('SELECT pg_backend_pid() AS id'), 'id')
            : (int) data_get(DB::selectOne('SELECT CONNECTION_ID() AS id'), 'id');
    }

    function fixture(): array
    {
        $encoded = (string) getenv('SIMRS_DISCHARGE_FIXTURE');
        if ($encoded === '') {
            return [];
        }
        $decoded = base64_decode($encoded, true);
        $value = $decoded === false ? null : json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new RuntimeException('fixture is invalid');
        }
        return $value;
    }

    function userByPublicId(string $publicId): User
    {
        return User::query()->where('public_id', $publicId)->firstOrFail();
    }

    function completeFields(string $suffix = ''): array
    {
        return [
            'admission_reason' => 'Alasan masuk terstruktur '.$suffix,
            'significant_findings' => 'Temuan penting terstruktur '.$suffix,
            'care_and_treatment_summary' => 'Ringkasan perawatan terstruktur '.$suffix,
            'condition_at_discharge' => 'Kondisi saat pulang terstruktur '.$suffix,
            'follow_up_plan' => 'Rencana tindak lanjut terstruktur '.$suffix,
        ];
    }

    function createEncounter(
        User $registrar,
        InpatientWard $ward,
        InpatientBed $bed,
        int $queue,
        string $token,
        bool $recordAdmission = true,
    ): Encounter {
        return DB::transaction(function () use ($registrar, $ward, $bed, $queue, $token, $recordAdmission): Encounter {
            $patient = Patient::query()->create([
                'medical_record_number' => 'RMDS'.strtoupper($token).str_pad((string) $queue, 2, '0', STR_PAD_LEFT),
                'full_name' => 'Pasien Ringkasan Pulang '.$queue,
                'date_of_birth' => '1990-01-01',
                'sex' => Patient::SEX_PEREMPUAN,
                'is_synthetic' => true,
                'created_by_user_id' => $registrar->id,
            ]);
            $encounter = InpatientLocationMutationScope::run(static fn (): Encounter => Encounter::query()->create([
                'patient_id' => $patient->id,
                'care_setting' => Encounter::CARE_SETTING_INPATIENT,
                'status' => Encounter::STATUS_REGISTERED,
                'clinic_name' => $ward->display_name,
                'ward_name' => $ward->display_name,
                'ward_class' => $bed->service_class,
                'bed_code' => $bed->code,
                'inpatient_bed_id' => $bed->id,
                'continue_from' => Encounter::CONTINUE_LANGSUNG,
                'visit_date' => now()->toDateString(),
                'payer_type' => Encounter::PAYER_UMUM,
                'queue_date' => now()->toDateString(),
                'queue_number' => $queue,
                'registered_at' => now(),
                'registered_by_user_id' => $registrar->id,
            ]));
            if ($recordAdmission) {
                app(InpatientBedTransferService::class)->recordAdmission($encounter, $ward, $bed, $registrar, null);
            }
            return $encounter;
        });
    }

    function prepareFixture(string $token): array
    {
        $registrar = User::query()->create([
            'name' => 'Registrar Portabilitas Ringkasan',
            'email' => 'registrar.'.$token.'@example.invalid',
            'password' => bin2hex(random_bytes(24)),
            'status' => 'ACTIVE',
        ]);
        $admin = User::query()->create([
            'name' => 'Admin Portabilitas Ringkasan',
            'email' => 'admin.'.$token.'@example.invalid',
            'password' => bin2hex(random_bytes(24)),
            'status' => 'ACTIVE',
        ]);
        $physician = User::query()->create([
            'name' => 'Dokter Portabilitas Ringkasan',
            'email' => 'physician.'.$token.'@example.invalid',
            'password' => bin2hex(random_bytes(24)),
            'status' => 'ACTIVE',
        ]);
        $registrar->roles()->sync([Role::query()->where('slug', RoleCapabilityMatrix::ROLE_REGISTRAR)->sole()->id]);
        $admin->roles()->sync([Role::query()->where('slug', RoleCapabilityMatrix::ROLE_ADMIN)->sole()->id]);
        $physician->roles()->sync([Role::query()->where('slug', RoleCapabilityMatrix::ROLE_PHYSICIAN)->sole()->id]);

        $master = app(InpatientMasterService::class);
        $ward = $master->createWard(
            $admin,
            'RIDS-'.strtoupper(substr($token, 0, 8)),
            'Bangsal Portabilitas Ringkasan Pulang',
            InpatientMasterService::REASON_INITIAL_SETUP,
            'discharge-ward-'.$token,
            null,
        )->master;
        must($ward instanceof InpatientWard, 'ward created');

        $bed = static function (string $suffix, string $label) use ($master, $admin, $ward, $token): InpatientBed {
            $result = $master->createBed(
                $admin,
                $ward->public_id,
                'DS-'.strtoupper(substr($token, 0, 6)).'-'.$suffix,
                $label,
                'Ruang Ringkasan Pulang',
                'Kelas 1',
                InpatientMasterService::REASON_INITIAL_SETUP,
                'discharge-bed-'.$suffix.'-'.$token,
                null,
            );
            must($result->master instanceof InpatientBed, 'bed created '.$suffix);
            return $result->master;
        };
        $beds = [
            'main' => $bed('00', 'Tempat Tidur Utama'),
            'replay' => $bed('10', 'Tempat Tidur Replay'),
            'missing' => $bed('11', 'Ringkasan Tidak Ada'),
            'draft' => $bed('12', 'Ringkasan Masih Draf'),
            'stale_summary' => $bed('13', 'Versi Ringkasan Lama'),
            'stale_placement' => $bed('14', 'Penempatan Lama'),
            'corrupt' => $bed('15', 'Replay Korup'),
            'discharge_race' => $bed('20', 'Lomba Pemulangan'),
            'final_first_source' => $bed('30', 'Final Dulu Sumber'),
            'final_first_target' => $bed('31', 'Final Dulu Tujuan'),
            'transfer_first_source' => $bed('40', 'Transfer Dulu Sumber'),
            'transfer_first_target' => $bed('41', 'Transfer Dulu Tujuan'),
            'reuse_source' => $bed('50', 'Dilepas dan Dipakai Ulang'),
            'audit_failure' => $bed('60', 'Kegagalan Audit'),
            'receipt_failure' => $bed('70', 'Kegagalan Receipt'),
        ];
        $encounters = [];
        foreach (['main', 'replay', 'missing', 'draft', 'stale_summary', 'stale_placement', 'corrupt', 'discharge_race', 'final_first_source', 'transfer_first_source', 'reuse_source', 'audit_failure', 'receipt_failure'] as $index => $name) {
            $encounters[$name] = createEncounter($registrar, $ward, $beds[$name], $index + 1, $token);
        }

        return [
            'registrar' => $registrar->public_id,
            'admin' => $admin->public_id,
            'physician' => $physician->public_id,
            'ward' => $ward->public_id,
            'main_encounter' => $encounters['main']->public_id,
            'main_bed' => $beds['main']->public_id,
            'replay_encounter' => $encounters['replay']->public_id,
            'replay_bed' => $beds['replay']->public_id,
            'missing_encounter' => $encounters['missing']->public_id,
            'missing_bed' => $beds['missing']->public_id,
            'draft_encounter' => $encounters['draft']->public_id,
            'draft_bed' => $beds['draft']->public_id,
            'stale_summary_encounter' => $encounters['stale_summary']->public_id,
            'stale_summary_bed' => $beds['stale_summary']->public_id,
            'stale_placement_encounter' => $encounters['stale_placement']->public_id,
            'stale_placement_bed' => $beds['stale_placement']->public_id,
            'corrupt_encounter' => $encounters['corrupt']->public_id,
            'corrupt_bed' => $beds['corrupt']->public_id,
            'discharge_race_encounter' => $encounters['discharge_race']->public_id,
            'discharge_race_bed' => $beds['discharge_race']->public_id,
            'final_first_encounter' => $encounters['final_first_source']->public_id,
            'final_first_source' => $beds['final_first_source']->public_id,
            'final_first_target' => $beds['final_first_target']->public_id,
            'transfer_first_encounter' => $encounters['transfer_first_source']->public_id,
            'transfer_first_source' => $beds['transfer_first_source']->public_id,
            'transfer_first_target' => $beds['transfer_first_target']->public_id,
            'reuse_encounter' => $encounters['reuse_source']->public_id,
            'reuse_bed' => $beds['reuse_source']->public_id,
            'audit_failure_encounter' => $encounters['audit_failure']->public_id,
            'audit_failure_bed' => $beds['audit_failure']->public_id,
            'receipt_failure_encounter' => $encounters['receipt_failure']->public_id,
            'receipt_failure_bed' => $beds['receipt_failure']->public_id,
        ];
    }

    function saveDraft(string $encounter, User $physician, int $version, array $fields, string $key): mixed
    {
        return app(InpatientDischargeSummaryService::class)->saveDraft(
            $encounter,
            $physician,
            InpatientDischargeSummary::DEFINITION_VERSION,
            $version,
            $fields,
            $key,
            null,
        );
    }

    function finalizeSummary(string $encounter, User $physician, int $version, string $key): mixed
    {
        return app(InpatientDischargeSummaryService::class)->finalize(
            $encounter,
            $physician,
            InpatientDischargeSummary::DEFINITION_VERSION,
            $version,
            $key,
            null,
        );
    }

    function finalizeCompleteSummary(string $encounter, User $physician, string $prefix, string $token): mixed
    {
        $draft = saveDraft($encounter, $physician, 0, completeFields($prefix), $prefix.'-draft-'.$token);
        return finalizeSummary($encounter, $physician, $draft->summary->version, $prefix.'-final-'.$token);
    }

    function executeDischarge(
        string $encounter,
        User $physician,
        int $summaryVersion,
        int $locationSequence,
        string $bed,
        string $key,
    ): mixed {
        return app(InpatientDischargeService::class)->execute(
            $encounter, $physician, $summaryVersion, $locationSequence, $bed, $key, null,
        );
    }

    function dischargeDigest(string $encounter, int $summaryVersion, int $locationSequence, string $bed): string
    {
        return hash('sha256', CanonicalJson::encode([
            'operation' => InpatientDischargeOperationReceipt::OPERATION_EXECUTE,
            'encounter_public_id' => $encounter,
            'disposition_code' => InpatientDischarge::DISPOSITION_ROUTINE_HOME,
            'expected_summary_version' => $summaryVersion,
            'expected_location_sequence' => $locationSequence,
            'source_bed_public_id' => $bed,
        ]));
    }

    function runSequential(array $fixture, string $token): array
    {
        $physician = userByPublicId($fixture['physician']);
        $mainFinal = finalizeCompleteSummary($fixture['main_encounter'], $physician, 'main', $token);
        $main = executeDischarge(
            $fixture['main_encounter'], $physician, $mainFinal->summary->version, 1,
            $fixture['main_bed'], 'main-discharge-'.$token,
        );
        must(! $main->replayed, 'routine discharge applied');
        $mainEncounter = Encounter::query()->where('public_id', $fixture['main_encounter'])->sole();
        must($mainEncounter->status === Encounter::STATUS_READY_FOR_RM, 'encounter is READY_FOR_RM');
        must($mainEncounter->inpatient_bed_id !== null && $mainEncounter->bed_code !== null, 'last placement identifiers retained');
        DB::transaction(fn () => app(InpatientBedClaimGuard::class)->assertAvailable($mainEncounter->bed_code, $mainEncounter->inpatient_bed_id));

        $replay = executeDischarge(
            $fixture['main_encounter'], $physician, $mainFinal->summary->version, 1,
            $fixture['main_bed'], 'MAIN-DISCHARGE-'.$token,
        );
        must($replay->replayed && $replay->discharge->public_id === $main->discharge->public_id, 'exact discharge replay');
        $conflict = false;
        try {
            executeDischarge(
                $fixture['main_encounter'], $physician, $mainFinal->summary->version + 1, 1,
                $fixture['main_bed'], 'main-discharge-'.$token,
            );
        } catch (InpatientDischargeDenied $denial) {
            $conflict = $denial->reason === 'idempotency_key_conflict';
        }
        must($conflict, 'changed payload key conflict');

        $missingDenied = false;
        try {
            executeDischarge($fixture['missing_encounter'], $physician, 1, 1, $fixture['missing_bed'], 'missing-'.$token);
        } catch (InpatientDischargeDenied $denial) {
            $missingDenied = $denial->reason === 'summary_missing';
        }
        must($missingDenied, 'missing summary denied');

        $draft = saveDraft($fixture['draft_encounter'], $physician, 0, completeFields('draft'), 'draft-only-'.$token);
        $draftDenied = false;
        try {
            executeDischarge($fixture['draft_encounter'], $physician, $draft->summary->version, 1, $fixture['draft_bed'], 'draft-denied-'.$token);
        } catch (InpatientDischargeDenied $denial) {
            $draftDenied = $denial->reason === 'summary_not_final';
        }
        must($draftDenied, 'Draft summary denied');

        $staleFinal = finalizeCompleteSummary($fixture['stale_summary_encounter'], $physician, 'stale-summary', $token);
        $staleSummaryDenied = false;
        try {
            executeDischarge($fixture['stale_summary_encounter'], $physician, $staleFinal->summary->version - 1, 1, $fixture['stale_summary_bed'], 'stale-summary-'.$token);
        } catch (InpatientDischargeDenied $denial) {
            $staleSummaryDenied = $denial->reason === 'stale_summary';
        }
        must($staleSummaryDenied, 'stale summary denied');

        $placementFinal = finalizeCompleteSummary($fixture['stale_placement_encounter'], $physician, 'stale-placement', $token);
        $staleLocationDenied = false;
        try {
            executeDischarge($fixture['stale_placement_encounter'], $physician, $placementFinal->summary->version, 0, $fixture['stale_placement_bed'], 'stale-location-'.$token);
        } catch (InpatientDischargeDenied $denial) {
            $staleLocationDenied = $denial->reason === 'stale_location';
        }
        must($staleLocationDenied, 'stale location sequence denied');

        $corruptFinal = finalizeCompleteSummary($fixture['corrupt_encounter'], $physician, 'corrupt', $token);
        $corruptEncounter = Encounter::query()->where('public_id', $fixture['corrupt_encounter'])->sole();
        $corruptKey = 'corrupt-replay-'.$token;
        $corruptDigest = dischargeDigest($fixture['corrupt_encounter'], $corruptFinal->summary->version, 1, $fixture['corrupt_bed']);
        $corruptVersion = $corruptFinal->resultVersion;
        $corruptDenied = false;
        try {
            DB::transaction(function () use (
                $corruptEncounter, $physician, $corruptVersion, $corruptKey, $corruptDigest,
                $fixture, $corruptFinal, &$corruptDenied,
            ): never {
                InpatientDischargeMutationScope::run(fn () => InpatientDischargeOperationReceipt::query()->create([
                    'encounter_id' => $corruptEncounter->id,
                    'actor_user_id' => $physician->id,
                    'inpatient_discharge_summary_version_id' => $corruptVersion->id,
                    'operation' => InpatientDischargeOperationReceipt::OPERATION_EXECUTE,
                    'idempotency_key' => $corruptKey,
                    'payload_digest' => $corruptDigest,
                    'result_discharge_public_id' => (string) \Illuminate\Support\Str::ulid(),
                    'discharge_summary_version_public_id' => $corruptVersion->public_id,
                    'discharge_summary_content_digest' => str_repeat('0', 64),
                    'discharge_summary_provenance_digest' => str_repeat('0', 64),
                    'request_correlation_id' => null,
                    'completed_at' => now(),
                ]));
                try {
                    executeDischarge(
                        $fixture['corrupt_encounter'], $physician, $corruptFinal->summary->version, 1,
                        $fixture['corrupt_bed'], $corruptKey,
                    );
                } catch (InpatientDischargeDenied $denial) {
                    $corruptDenied = $denial->reason === 'receipt_binding_invalid';
                }
                throw new RuntimeException('ROLLBACK_CORRUPT_REPLAY_PROBE');
            });
        } catch (RuntimeException $rollback) {
            must($rollback->getMessage() === 'ROLLBACK_CORRUPT_REPLAY_PROBE', 'corrupt replay probe rollback marker');
        }
        must($corruptDenied, 'corrupt replay binding denied');
        must(InpatientDischargeOperationReceipt::query()->where('idempotency_key', $corruptKey)->doesntExist(), 'corrupt replay probe rolled back');

        $raceFinal = finalizeCompleteSummary($fixture['discharge_race_encounter'], $physician, 'discharge-race', $token);
        $finalFirstDraft = saveDraft($fixture['final_first_encounter'], $physician, 0, completeFields('final-first'), 'final-first-draft-'.$token);
        $transferFirstDraft = saveDraft($fixture['transfer_first_encounter'], $physician, 0, completeFields('transfer-first'), 'transfer-first-draft-'.$token);
        $reuseFinal = finalizeCompleteSummary($fixture['reuse_encounter'], $physician, 'reuse', $token);
        $auditFinal = finalizeCompleteSummary($fixture['audit_failure_encounter'], $physician, 'audit-failure', $token);
        $receiptFinal = finalizeCompleteSummary($fixture['receipt_failure_encounter'], $physician, 'receipt-failure', $token);

        return [
            'successful_routine_discharge' => true,
            'missing_summary_denial' => true,
            'draft_summary_denial' => true,
            'stale_summary_denial' => true,
            'stale_placement_denial' => true,
            'exact_idempotent_replay' => true,
            'changed_payload_key_conflict' => true,
            'corrupt_replay_binding_denial' => true,
            'main_discharge_public_id' => $main->discharge->public_id,
            'race_summary_version' => $raceFinal->summary->version,
            'final_first_draft_version' => $finalFirstDraft->summary->version,
            'transfer_first_draft_version' => $transferFirstDraft->summary->version,
            'reuse_summary_version' => $reuseFinal->summary->version,
            'audit_failure_summary_version' => $auditFinal->summary->version,
            'receipt_failure_summary_version' => $receiptFinal->summary->version,
        ];
    }

    function runSummaryLegacyUnused(array $fixture, string $token): array
    {
        $physician = userByPublicId($fixture['physician']);
        setFailureStage('sequential_initial_draft');
        $first = saveDraft($fixture['main_encounter'], $physician, 0, ['admission_reason' => 'Keluhan awal'], 'main-draft-'.$token);
        must(! $first->replayed && $first->summary->version === 1, 'initial Draft applied');
        setFailureStage('sequential_draft_replay');
        $replay = saveDraft($fixture['main_encounter'], $physician, 0, ['admission_reason' => 'Keluhan awal'], 'MAIN-DRAFT-'.$token);
        must($replay->replayed && $replay->resultVersion->version === 1, 'identical retry replayed');
        $conflict = false;
        try {
            saveDraft($fixture['main_encounter'], $physician, 0, ['admission_reason' => 'Payload berbeda'], 'main-draft-'.$token);
        } catch (InpatientDischargeSummaryDenied $denial) {
            $conflict = $denial->reason === 'idempotency_key_conflict';
        }
        must($conflict, 'changed payload key conflict');
        setFailureStage('sequential_complete_draft');
        $second = saveDraft($fixture['main_encounter'], $physician, 1, completeFields('utama'), 'main-complete-'.$token);
        setFailureStage('sequential_finalize');
        $final = finalizeSummary($fixture['main_encounter'], $physician, 2, 'main-final-'.$token);
        must($second->summary->version === 2 && $final->summary->version === 3, 'Draft then Final chain');
        must($final->summary->summary_state === InpatientDischargeSummary::STATE_FINAL, 'terminal Final persisted');

        setFailureStage('sequential_race_drafts');
        foreach (['final_race_encounter', 'summary_first_encounter', 'transfer_first_encounter'] as $name) {
            saveDraft($fixture[$name], $physician, 0, completeFields($name), 'race-draft-'.$name.'-'.$token);
        }

        setFailureStage('sequential_final_replay');
        $finalReplay = finalizeSummary($fixture['main_encounter'], $physician, 2, 'MAIN-FINAL-'.$token);
        must($finalReplay->replayed, 'identical Final replay returned');
        must($finalReplay->summary->public_id === $final->summary->public_id, 'Final replay summary binding');
        must($finalReplay->resultVersion->public_id === $final->resultVersion->public_id, 'Final replay version binding');
        must($finalReplay->resultVersion->version === 3, 'Final replay version number binding');
        $finalReceipt = InpatientDischargeSummaryOperationReceipt::query()
            ->where('actor_user_id', $physician->id)
            ->where('operation', InpatientDischargeSummaryOperationReceipt::OPERATION_FINALIZE)
            ->where('idempotency_key', 'main-final-'.$token)
            ->sole();
        must($finalReceipt->encounter_id === $final->summary->encounter_id, 'Final receipt encounter binding');
        must($finalReceipt->result_summary_public_id === $final->summary->public_id, 'Final receipt summary binding');
        must($finalReceipt->result_version === $final->resultVersion->version, 'Final receipt version binding');
        $crossEncounterFinalConflict = false;
        try {
            finalizeSummary($fixture['final_race_encounter'], $physician, 1, 'main-final-'.$token);
        } catch (InpatientDischargeSummaryDenied $denial) {
            $crossEncounterFinalConflict = $denial->reason === 'idempotency_key_conflict';
        }
        must($crossEncounterFinalConflict, 'Final replay key cannot cross encounter binding');

        $terminalReasons = [];
        foreach (['draft', 'final'] as $operation) {
            try {
                if ($operation === 'draft') {
                    saveDraft($fixture['main_encounter'], $physician, 3, completeFields('terlambat'), 'terminal-draft-'.$token);
                } else {
                    finalizeSummary($fixture['main_encounter'], $physician, 3, 'terminal-final-'.$token);
                }
            } catch (InpatientDischargeSummaryDenied $denial) {
                $terminalReasons[] = $denial->reason;
            }
        }
        must($terminalReasons === ['summary_final', 'summary_final'], 'Final rejects later mutation');

        setFailureStage('sequential_legacy');
        $legacy = saveDraft($fixture['legacy_encounter'], $physician, 0, ['admission_reason' => 'Baseline lama'], 'legacy-draft-'.$token);
        must($legacy->resultVersion->location_sequence === 0, 'legacy baseline has sequence zero');
        must($legacy->resultVersion->location_event_public_id === null, 'legacy baseline has no event identity');
        must($legacy->resultVersion->location_event_type === null, 'legacy baseline has no event type');
        must($legacy->resultVersion->history_baseline === InpatientDischargeSummary::HISTORY_BASELINE_LEGACY_CURRENT_PLACEMENT, 'legacy baseline is explicit');
        must($legacy->resultVersion->history_complete === false, 'legacy baseline is incomplete');
        must($legacy->resultVersion->bed_public_id === $fixture['legacy_bed'], 'legacy current bed snapshot coherent');

        setFailureStage('sequential_cancelled');
        $cancelledDenied = false;
        try {
            saveDraft($fixture['cancelled_encounter'], $physician, 0, completeFields('cancelled'), 'cancelled-draft-'.$token);
        } catch (InpatientDischargeSummaryDenied $denial) {
            $cancelledDenied = $denial->reason === 'encounter_cancelled';
        }
        must($cancelledDenied, 'retained cancellation denied summary mutation');
        must(InpatientDischargeSummary::query()->whereHas('encounter', fn ($query) => $query->where('public_id', $fixture['cancelled_encounter']))->doesntExist(), 'cancelled episode has no summary');

        setFailureStage('complete');
        $main = InpatientDischargeSummary::query()->whereHas('encounter', fn ($query) => $query->where('public_id', $fixture['main_encounter']))->sole();
        return [
            'draft_then_final' => true,
            'terminal_final_refusal' => true,
            'exact_idempotent_replay' => true,
            'changed_payload_key_conflict' => true,
            'final_replay_result_binding' => true,
            'final_cross_encounter_conflict' => true,
            'legacy_baseline_provenance' => true,
            'retained_cancellation_denial' => true,
            'main_summary_public_id' => $main->public_id,
            'main_version_rows' => $main->versions()->count(),
            'main_receipt_rows' => InpatientDischargeSummaryOperationReceipt::query()->where('encounter_id', $main->encounter_id)->count(),
            'main_success_audit_rows' => AuditEvent::query()->where('resource_id', $main->public_id)->where('outcome', 'SUCCESS')->count(),
        ];
    }

    function installDischargeReceiptFailureTrigger(): void
    {
        $table = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_discharge_operation_receipts'));
        InpatientDischargeSchemaMutationScope::run(static function () use ($table): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("CREATE OR REPLACE FUNCTION laravel.fail_idcor_insert() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN RAISE EXCEPTION 'receipt insertion unavailable'; END; \$f\$");
                DB::statement("CREATE TRIGGER idcor_fail_insert_trg BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION laravel.fail_idcor_insert()");
            } else {
                DB::statement("CREATE TRIGGER idcor_fail_insert_trg BEFORE INSERT ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'receipt insertion unavailable'");
            }
        });
    }

    function dropDischargeReceiptFailureTrigger(): void
    {
        $table = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_discharge_operation_receipts'));
        InpatientDischargeSchemaMutationScope::run(static function () use ($table): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("DROP TRIGGER IF EXISTS idcor_fail_insert_trg ON {$table}");
                DB::statement('DROP FUNCTION IF EXISTS laravel.fail_idcor_insert()');
            } else {
                DB::statement('DROP TRIGGER IF EXISTS idcor_fail_insert_trg');
            }
        });
    }

    function runAtomicFailures(array $fixture, string $token): array
    {
        $physician = userByPublicId($fixture['physician']);
        $nullAudit = new class extends AuditRecorder {
            public function record(
                string $action,
                string $resourceType,
                ?string $resourceId = null,
                ?User $actor = null,
                string $outcome = 'SUCCESS',
                ?string $reason = null,
                array $metadata = [],
                ?Request $request = null,
                bool $includeRequestFingerprint = true,
            ): ?AuditEvent {
                return null;
            }
        };
        $service = new InpatientDischargeService(
            $nullAudit,
            app(InpatientDischargeActorPolicy::class),
            app(CanonicalInpatientBedOperationLockCoordinator::class),
            app(InpatientBedClaimGuard::class),
            app(InpatientDischargeSummaryEvidenceDigest::class),
        );
        $auditFailed = false;
        try {
            $service->execute(
                $fixture['audit_failure_encounter'], $physician, $fixture['audit_failure_summary_version'], 1,
                $fixture['audit_failure_bed'], 'audit-failure-discharge-'.$token, null,
            );
        } catch (InpatientDischargeAuditUnavailable) {
            $auditFailed = true;
        }
        must($auditFailed, 'discharge audit failure surfaced');
        $auditEncounter = Encounter::query()->where('public_id', $fixture['audit_failure_encounter'])->sole();
        must($auditEncounter->status === Encounter::STATUS_REGISTERED && $auditEncounter->occupiesInpatientBed(), 'audit failure retained bed claim');
        must(InpatientDischarge::query()->where('encounter_id', $auditEncounter->id)->doesntExist(), 'audit failure rolled back discharge evidence');

        installDischargeReceiptFailureTrigger();
        $receiptFailed = false;
        try {
            executeDischarge(
                $fixture['receipt_failure_encounter'], $physician, $fixture['receipt_failure_summary_version'], 1,
                $fixture['receipt_failure_bed'], 'receipt-failure-discharge-'.$token,
            );
        } catch (Throwable) {
            $receiptFailed = true;
        } finally {
            dropDischargeReceiptFailureTrigger();
        }
        must($receiptFailed, 'discharge receipt failure surfaced');
        $receiptEncounter = Encounter::query()->where('public_id', $fixture['receipt_failure_encounter'])->sole();
        must($receiptEncounter->status === Encounter::STATUS_REGISTERED && $receiptEncounter->occupiesInpatientBed(), 'receipt failure retained bed claim');
        must(InpatientDischarge::query()->where('encounter_id', $receiptEncounter->id)->doesntExist(), 'receipt failure rolled back discharge evidence');
        must(InpatientDischargeOperationReceipt::query()->whereIn('encounter_id', [$auditEncounter->id, $receiptEncounter->id])->doesntExist(), 'failure receipts rolled back');
        must(AuditEvent::query()->where('action', 'clinical.inpatient.discharge.execute')->where('outcome', 'SUCCESS')
            ->whereIn('resource_id', [$fixture['audit_failure_encounter'], $fixture['receipt_failure_encounter']])->doesntExist(), 'failure success audits rolled back');

        return [
            'audit_failure_atomic_rollback' => true,
            'receipt_failure_atomic_rollback' => true,
            'failure_discharge_rows' => 0,
            'failure_receipt_rows' => 0,
            'failure_success_audit_rows' => 0,
            'failure_bed_claims_retained' => true,
        ];
    }

    function installReceiptFailureTrigger(): void
    {
        $table = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_discharge_summary_operation_receipts'));
        InpatientDischargeSummarySchemaMutationScope::run(static function () use ($table): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("CREATE OR REPLACE FUNCTION laravel.fail_idsor_insert() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN RAISE EXCEPTION 'receipt insertion unavailable'; END; \$f\$");
                DB::statement("CREATE TRIGGER idsor_fail_insert_trg BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION laravel.fail_idsor_insert()");
            } else {
                DB::statement("CREATE TRIGGER idsor_fail_insert_trg BEFORE INSERT ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'receipt insertion unavailable'");
            }
        });
    }

    function dropReceiptFailureTrigger(): void
    {
        $table = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_discharge_summary_operation_receipts'));
        InpatientDischargeSummarySchemaMutationScope::run(static function () use ($table): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("DROP TRIGGER IF EXISTS idsor_fail_insert_trg ON {$table}");
                DB::statement('DROP FUNCTION IF EXISTS laravel.fail_idsor_insert()');
            } else {
                DB::statement('DROP TRIGGER IF EXISTS idsor_fail_insert_trg');
            }
        });
    }

    function runSummaryAtomicFailuresUnused(array $fixture, string $token): array
    {
        $physician = userByPublicId($fixture['physician']);
        $nullAudit = new class extends AuditRecorder {
            public function record(
                string $action,
                string $resourceType,
                ?string $resourceId = null,
                ?User $actor = null,
                string $outcome = 'SUCCESS',
                ?string $reason = null,
                array $metadata = [],
                ?Request $request = null,
                bool $includeRequestFingerprint = true,
            ): ?AuditEvent {
                return null;
            }
        };
        $service = new InpatientDischargeSummaryService(
            $nullAudit,
            app(InpatientDischargeSummaryActorPolicy::class),
            app(CanonicalInpatientBedOperationLockCoordinator::class),
        );
        $auditFailed = false;
        setFailureStage('audit_service_save');
        try {
            $service->saveDraft(
                $fixture['audit_failure_encounter'], $physician,
                InpatientDischargeSummary::DEFINITION_VERSION, 0, completeFields('audit'),
                'audit-failure-'.$token, null,
            );
        } catch (InpatientDischargeSummaryAuditUnavailable) {
            $auditFailed = true;
        }
        must($auditFailed, 'audit failure surfaced');
        setFailureStage('audit_rollback_verification');
        must(InpatientDischargeSummary::query()->whereHas('encounter', fn ($q) => $q->where('public_id', $fixture['audit_failure_encounter']))->doesntExist(), 'audit failure rolled back head');

        setFailureStage('receipt_trigger_create');
        installReceiptFailureTrigger();
        $receiptFailed = false;
        setFailureStage('receipt_service_save');
        try {
            saveDraft($fixture['receipt_failure_encounter'], $physician, 0, completeFields('receipt'), 'receipt-failure-'.$token);
        } catch (Throwable) {
            $receiptFailed = true;
        } finally {
            setFailureStage('receipt_trigger_drop');
            dropReceiptFailureTrigger();
        }
        must($receiptFailed, 'receipt failure surfaced');
        setFailureStage('receipt_rollback_verification');
        must(InpatientDischargeSummary::query()->whereHas('encounter', fn ($q) => $q->where('public_id', $fixture['receipt_failure_encounter']))->doesntExist(), 'receipt failure rolled back head');
        must(AuditEvent::query()->where('action', 'like', 'clinical.inpatient.discharge-summary.%')
            ->whereIn('resource_id', [$fixture['audit_failure_encounter'], $fixture['receipt_failure_encounter']])
            ->where('outcome', 'SUCCESS')->doesntExist(), 'failure success audits rolled back');
        setFailureStage('complete');

        return [
            'audit_failure_atomic_rollback' => true,
            'receipt_failure_atomic_rollback' => true,
            'failure_head_rows' => 0,
            'failure_version_rows' => 0,
            'failure_receipt_rows' => 0,
            'failure_success_audit_rows' => 0,
        ];
    }

    function runAppendOnly(array $fixture): array
    {
        $discharge = InpatientDischarge::query()->where('public_id', $fixture['main_discharge_public_id'])->sole();
        $receipt = InpatientDischargeOperationReceipt::query()->where('result_discharge_public_id', $discharge->public_id)->sole();
        $dischargeTable = SchemaQualifier::table('inpatient_discharges');
        $receiptTable = SchemaQualifier::table('inpatient_discharge_operation_receipts');
        $attempts = [
            'discharge_update' => static fn () => DB::table($dischargeTable)->where('id', $discharge->id)->update(['source_bed_code' => 'MUTATED']),
            'discharge_delete' => static fn () => DB::table($dischargeTable)->where('id', $discharge->id)->delete(),
            'receipt_update' => static fn () => DB::table($receiptTable)->where('id', $receipt->id)->update(['payload_digest' => str_repeat('0', 64)]),
            'receipt_delete' => static fn () => DB::table($receiptTable)->where('id', $receipt->id)->delete(),
            'discharge_truncate' => static fn () => DB::statement('TRUNCATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($dischargeTable)),
            'receipt_truncate' => static fn () => DB::statement('TRUNCATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($receiptTable)),
        ];
        $refused = [];
        foreach ($attempts as $label => $attempt) {
            try {
                InpatientDischargeMutationScope::run($attempt);
            } catch (Throwable) {
                $refused[] = $label;
            }
        }
        sort($refused);
        $expected = array_keys($attempts);
        sort($expected);
        must($refused === $expected, 'runtime cannot mutate discharge evidence');
        must(InpatientDischarge::query()->whereKey($discharge->id)->exists(), 'discharge retained after refusals');
        must(InpatientDischargeOperationReceipt::query()->whereKey($receipt->id)->exists(), 'receipt retained after refusals');
        return [
            'runtime_destructive_refusals' => $refused,
            'runtime_refusal_kind' => 'LEAST_PRIVILEGE_OR_DATABASE_TRIGGER',
            'discharge_chain_unchanged' => true,
            'receipt_chain_unchanged' => true,
        ];
    }

    function runSummaryAppendOnlyUnused(array $fixture): array
    {
        $summary = InpatientDischargeSummary::query()->where('public_id', $fixture['main_summary_public_id'])->sole();
        $version = $summary->versions()->firstOrFail();
        $receipt = InpatientDischargeSummaryOperationReceipt::query()->where('encounter_id', $summary->encounter_id)->firstOrFail();
        $tables = [
            'version' => SchemaQualifier::table('inpatient_discharge_summary_versions'),
            'receipt' => SchemaQualifier::table('inpatient_discharge_summary_operation_receipts'),
        ];
        $attempts = [
            'version_update' => static fn () => DB::table($tables['version'])->where('id', $version->id)->update(['version' => 99]),
            'version_delete' => static fn () => DB::table($tables['version'])->where('id', $version->id)->delete(),
            'receipt_update' => static fn () => DB::table($tables['receipt'])->where('id', $receipt->id)->update(['result_version' => 99]),
            'receipt_delete' => static fn () => DB::table($tables['receipt'])->where('id', $receipt->id)->delete(),
            'version_truncate' => static fn () => DB::statement('TRUNCATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($tables['version'])),
            'receipt_truncate' => static fn () => DB::statement('TRUNCATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($tables['receipt'])),
        ];
        $refused = [];
        foreach ($attempts as $label => $attempt) {
            try {
                InpatientDischargeSummaryMutationScope::run($attempt);
            } catch (Throwable) {
                $refused[] = $label;
            }
        }
        sort($refused);
        $expected = array_keys($attempts);
        sort($expected);
        must($refused === $expected, 'runtime cannot mutate immutable evidence');
        must($summary->versions()->count() === 3, 'version chain unchanged');
        must(InpatientDischargeSummaryOperationReceipt::query()->where('encounter_id', $summary->encounter_id)->count() === 3, 'receipt chain unchanged');
        return [
            'runtime_destructive_refusals' => $refused,
            'runtime_refusal_kind' => 'LEAST_PRIVILEGE_OR_DATABASE_TRIGGER',
            'version_chain_unchanged' => true,
            'receipt_chain_unchanged' => true,
        ];
    }

    function runOwnerDatabaseTriggers(array $fixture): array
    {
        $discharge = InpatientDischarge::query()->where('public_id', $fixture['main_discharge_public_id'])->sole();
        $receipt = InpatientDischargeOperationReceipt::query()->where('result_discharge_public_id', $discharge->public_id)->sole();
        $dischargeTable = SchemaQualifier::table('inpatient_discharges');
        $receiptTable = SchemaQualifier::table('inpatient_discharge_operation_receipts');
        $attempts = [
            'discharge_update' => static fn () => DB::table($dischargeTable)->where('id', $discharge->id)->update(['source_bed_code' => 'MUTATED']),
            'discharge_delete' => static fn () => DB::table($dischargeTable)->where('id', $discharge->id)->delete(),
            'receipt_update' => static fn () => DB::table($receiptTable)->where('id', $receipt->id)->update(['payload_digest' => str_repeat('0', 64)]),
            'receipt_delete' => static fn () => DB::table($receiptTable)->where('id', $receipt->id)->delete(),
        ];
        if (DB::connection()->getDriverName() === 'pgsql') {
            $attempts['discharge_truncate'] = static fn () => DB::statement('TRUNCATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($dischargeTable));
            $attempts['receipt_truncate'] = static fn () => DB::statement('TRUNCATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($receiptTable));
        }
        $refused = [];
        foreach ($attempts as $label => $attempt) {
            try {
                InpatientDischargeMutationScope::run($attempt);
            } catch (Throwable) {
                $refused[] = $label;
            }
        }
        must(count($refused) === (DB::connection()->getDriverName() === 'pgsql' ? 6 : 4), 'owner triggers refused discharge mutations');
        return [
            'owner_database_trigger_refusals' => $refused,
            'owner_truncate_refusal_kind' => DB::connection()->getDriverName() === 'pgsql' ? 'DATABASE_TRIGGER' : 'NOT_SUPPORTED_BY_MYSQL_TRIGGER',
        ];
    }

    function runSummaryOwnerDatabaseTriggersUnused(array $fixture): array
    {
        $summary = InpatientDischargeSummary::query()->where('public_id', $fixture['main_summary_public_id'])->sole();
        $version = $summary->versions()->firstOrFail();
        $receipt = InpatientDischargeSummaryOperationReceipt::query()->where('encounter_id', $summary->encounter_id)->firstOrFail();
        $versionTable = SchemaQualifier::table('inpatient_discharge_summary_versions');
        $receiptTable = SchemaQualifier::table('inpatient_discharge_summary_operation_receipts');
        $attempts = [
            'version_update' => static fn () => DB::table($versionTable)->where('id', $version->id)->update(['version' => 99]),
            'version_delete' => static fn () => DB::table($versionTable)->where('id', $version->id)->delete(),
            'receipt_update' => static fn () => DB::table($receiptTable)->where('id', $receipt->id)->update(['result_version' => 99]),
            'receipt_delete' => static fn () => DB::table($receiptTable)->where('id', $receipt->id)->delete(),
        ];
        if (DB::connection()->getDriverName() === 'pgsql') {
            $attempts['version_truncate'] = static fn () => DB::statement('TRUNCATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($versionTable));
            $attempts['receipt_truncate'] = static fn () => DB::statement('TRUNCATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($receiptTable));
        }
        $refused = [];
        foreach ($attempts as $label => $attempt) {
            try {
                InpatientDischargeSummaryMutationScope::run($attempt);
            } catch (Throwable) {
                $refused[] = $label;
            }
        }
        $expected = DB::connection()->getDriverName() === 'pgsql' ? 6 : 4;
        must(count($refused) === $expected, 'owner database triggers refused every engine-supported destructive operation');
        return [
            'owner_database_trigger_refusals' => $refused,
            'owner_truncate_refusal_kind' => DB::connection()->getDriverName() === 'pgsql' ? 'DATABASE_TRIGGER' : 'NOT_SUPPORTED_BY_MYSQL_TRIGGER',
        ];
    }

    function raceOperation(array $fixture, string $scenario, string $worker, string $token, int $holdMs): array
    {
        $physician = userByPublicId($fixture['physician']);
        $registrar = userByPublicId($fixture['registrar']);
        $operation = function () use ($fixture, $scenario, $worker, $token, $physician, $registrar): array {
            try {
                if ($scenario === 'same-discharge-competing-execution') {
                    $result = executeDischarge(
                        $fixture['discharge_race_encounter'], $physician, $fixture['race_summary_version'], 1,
                        $fixture['discharge_race_bed'], 'race-discharge-'.strtolower($worker).'-'.$token,
                    );
                    return ['outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED', 'reason' => null];
                }
                if ($scenario === 'final-vs-transfer-final-first') {
                    if ($worker === 'A') {
                        $result = finalizeSummary(
                            $fixture['final_first_encounter'], $physician, $fixture['final_first_draft_version'],
                            'final-first-final-'.$token,
                        );
                        return ['outcome' => 'APPLIED', 'reason' => null, 'result_version' => $result->summary->version];
                    }
                    $result = app(InpatientBedTransferService::class)->transfer(
                        $fixture['final_first_encounter'], $registrar, 1,
                        $fixture['final_first_source'], $fixture['final_first_target'],
                        'Transfer setelah Final', 'final-first-transfer-'.$token, null,
                    );
                    return ['outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED', 'reason' => null];
                }
                if ($scenario === 'final-vs-transfer-transfer-first') {
                    if ($worker === 'A') {
                        $result = app(InpatientBedTransferService::class)->transfer(
                            $fixture['transfer_first_encounter'], $registrar, 1,
                            $fixture['transfer_first_source'], $fixture['transfer_first_target'],
                            'Transfer sebelum Final', 'transfer-first-transfer-'.$token, null,
                        );
                        return ['outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED', 'reason' => null];
                    }
                    $result = finalizeSummary(
                        $fixture['transfer_first_encounter'], $physician, $fixture['transfer_first_draft_version'],
                        'transfer-first-final-'.$token,
                    );
                    return ['outcome' => 'APPLIED', 'reason' => null, 'result_version' => $result->summary->version];
                }
                if ($worker === 'A') {
                    $result = executeDischarge(
                        $fixture['reuse_encounter'], $physician, $fixture['reuse_summary_version'], 1,
                        $fixture['reuse_bed'], 'reuse-discharge-'.$token,
                    );
                    return ['outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED', 'reason' => null];
                }
                $bed = InpatientBed::query()->where('public_id', $fixture['reuse_bed'])->sole();
                $ward = $bed->ward()->sole();
                $patient = Patient::query()->create([
                    'medical_record_number' => 'RMR'.strtoupper(substr($token, 0, 10)),
                    'full_name' => 'Pasien Pengguna Ulang Tempat Tidur',
                    'date_of_birth' => '1991-01-01',
                    'sex' => Patient::SEX_LAKI_LAKI,
                    'is_synthetic' => true,
                    'created_by_user_id' => $registrar->id,
                ]);
                $newEncounter = DB::transaction(function () use ($patient, $registrar, $fixture): Encounter {
                    $managedBed = app(InpatientMasterService::class)->resolveActiveBedForAdmission($fixture['reuse_bed']);
                    $managedWard = $managedBed->ward;
                    must($managedWard instanceof InpatientWard, 'reuse ward loaded');
                    $encounter = InpatientLocationMutationScope::run(fn (): Encounter => Encounter::query()->create([
                        'patient_id' => $patient->id,
                        'care_setting' => Encounter::CARE_SETTING_INPATIENT,
                        'status' => Encounter::STATUS_REGISTERED,
                        'clinic_name' => $managedWard->display_name,
                        'ward_name' => $managedWard->display_name,
                        'ward_class' => $managedBed->service_class,
                        'bed_code' => $managedBed->code,
                        'inpatient_bed_id' => $managedBed->id,
                        'continue_from' => Encounter::CONTINUE_LANGSUNG,
                        'visit_date' => now()->toDateString(),
                        'payer_type' => Encounter::PAYER_UMUM,
                        'queue_date' => now()->toDateString(),
                        'queue_number' => 99,
                        'registered_at' => now(),
                        'registered_by_user_id' => $registrar->id,
                    ]));
                    app(InpatientBedTransferService::class)->recordAdmission($encounter, $managedWard, $managedBed, $registrar, null);
                    return $encounter;
                });
                return ['outcome' => 'APPLIED', 'reason' => null, 'new_encounter' => $newEncounter->public_id];
            } catch (InpatientDischargeDenied|InpatientDischargeSummaryDenied|\App\Support\Inpatient\InpatientBedTransferDenied $denial) {
                return ['outcome' => 'DENIED', 'reason' => $denial->reason];
            }
        };

        if ($holdMs > 0) {
            return DB::transaction(function () use ($operation, $holdMs): array {
                $result = $operation();
                protocol('HOLDING');
                usleep($holdMs * 1000);
                return $result;
            });
        }
        return $operation();
    }

    function runSummaryRaceOperationUnused(array $fixture, string $scenario, string $worker, string $token, int $holdMs): array
    {
        $physician = userByPublicId($fixture['physician']);
        $registrar = userByPublicId($fixture['registrar']);
        $operation = function () use ($fixture, $scenario, $worker, $token, $physician, $registrar): array {
            if ($scenario === 'same-summary-competing-finalization') {
                try {
                    $result = finalizeSummary(
                        $fixture['final_race_encounter'], $physician, 1,
                        'competing-final-'.strtolower($worker).'-'.$token,
                    );
                    return ['outcome' => 'APPLIED', 'reason' => null, 'result_version' => $result->summary->version];
                } catch (InpatientDischargeSummaryDenied $denial) {
                    return ['outcome' => 'DENIED', 'reason' => $denial->reason];
                }
            }
            if ($scenario === 'transfer-vs-summary-summary-first') {
                if ($worker === 'A') {
                    $result = saveDraft($fixture['summary_first_encounter'], $physician, 1, completeFields('summary-first'), 'summary-first-'.$token);
                    return ['outcome' => 'APPLIED', 'reason' => null, 'result_version' => $result->summary->version];
                }
                $result = app(InpatientBedTransferService::class)->transfer(
                    $fixture['summary_first_encounter'], $registrar, 1,
                    $fixture['summary_first_source'], $fixture['summary_first_target'],
                    'Transfer sesudah ringkasan', 'summary-first-transfer-'.$token, null,
                );
                return ['outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED', 'reason' => null];
            }
            if ($worker === 'A') {
                $result = app(InpatientBedTransferService::class)->transfer(
                    $fixture['transfer_first_encounter'], $registrar, 1,
                    $fixture['transfer_first_source'], $fixture['transfer_first_target'],
                    'Transfer sebelum ringkasan', 'transfer-first-transfer-'.$token, null,
                );
                return ['outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED', 'reason' => null];
            }
            $result = saveDraft($fixture['transfer_first_encounter'], $physician, 1, completeFields('transfer-first'), 'transfer-first-summary-'.$token);
            return ['outcome' => 'APPLIED', 'reason' => null, 'result_version' => $result->summary->version];
        };

        if ($holdMs > 0) {
            return DB::transaction(function () use ($operation, $holdMs): array {
                $result = $operation();
                protocol('HOLDING');
                usleep($holdMs * 1000);
                return $result;
            });
        }
        return $operation();
    }

    function verifyRace(array $fixture, string $scenario): array
    {
        $physician = userByPublicId($fixture['physician']);
        if ($scenario === 'same-discharge-competing-execution') {
            $encounter = Encounter::query()->where('public_id', $fixture['discharge_race_encounter'])->sole();
            must($encounter->status === Encounter::STATUS_READY_FOR_RM, 'one race discharge released bed');
            must(InpatientDischarge::query()->where('encounter_id', $encounter->id)->count() === 1, 'one race discharge evidence');
            must(InpatientDischargeOperationReceipt::query()->where('encounter_id', $encounter->id)->count() === 1, 'one race receipt');
            return ['one_discharge' => true, 'one_receipt' => true, 'bed_released' => true];
        }
        if ($scenario === 'final-vs-transfer-final-first') {
            $encounter = Encounter::query()->where('public_id', $fixture['final_first_encounter'])->sole();
            $summary = $encounter->inpatientDischargeSummary()->sole();
            must($summary->summary_state === InpatientDischargeSummary::STATE_FINAL, 'Final won terminal rule');
            must($encounter->inpatient_bed_id === InpatientBed::query()->where('public_id', $fixture['final_first_source'])->value('id'), 'Final-first transfer denied');
            must(InpatientLocationEvent::query()->where('encounter_id', $encounter->id)->count() === 1, 'Final-first retained admission location');
            executeDischarge($encounter->public_id, $physician, $summary->version, 1, $fixture['final_first_source'], 'final-first-discharge-'.(string) getenv('SIMRS_DISCHARGE_RUN_TOKEN'));
            return ['final_terminal_rule' => true, 'transfer_denied' => true, 'discharge_after_final' => true];
        }
        if ($scenario === 'final-vs-transfer-transfer-first') {
            $encounter = Encounter::query()->where('public_id', $fixture['transfer_first_encounter'])->sole();
            $summary = $encounter->inpatientDischargeSummary()->sole();
            $version = $summary->versions()->where('version', $summary->version)->sole();
            must($summary->summary_state === InpatientDischargeSummary::STATE_FINAL, 'transfer-first then Final');
            must($version->location_sequence === 2 && $version->bed_public_id === $fixture['transfer_first_target'], 'Final bound transferred placement');
            executeDischarge($encounter->public_id, $physician, $summary->version, 2, $fixture['transfer_first_target'], 'transfer-first-discharge-'.(string) getenv('SIMRS_DISCHARGE_RUN_TOKEN'));
            return ['transfer_first' => true, 'final_at_new_bed' => true, 'discharge_after_transfer' => true];
        }
        $original = Encounter::query()->where('public_id', $fixture['reuse_encounter'])->sole();
        $bed = InpatientBed::query()->where('public_id', $fixture['reuse_bed'])->sole();
        $claims = Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->where('inpatient_bed_id', $bed->id)->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)->get();
        must($original->status === Encounter::STATUS_READY_FOR_RM, 'original encounter released bed');
        must($claims->count() === 1 && $claims->first()->id !== $original->id, 'released bed reused by one new encounter');
        return ['released_bed_reused' => true, 'new_claim_count' => 1, 'original_ready_for_rm' => true];
    }

    function verifySummaryRaceUnused(array $fixture, string $scenario): array
    {
        if ($scenario === 'same-summary-competing-finalization') {
            $summary = InpatientDischargeSummary::query()->whereHas('encounter', fn ($q) => $q->where('public_id', $fixture['final_race_encounter']))->sole();
            must($summary->summary_state === InpatientDischargeSummary::STATE_FINAL, 'one terminal Final');
            must($summary->version === 2 && $summary->versions()->count() === 2, 'one competing Final version');
            return ['one_terminal_final' => true, 'version_chain_length' => 2];
        }
        $prefix = $scenario === 'transfer-vs-summary-summary-first' ? 'summary_first' : 'transfer_first';
        $summary = InpatientDischargeSummary::query()->whereHas('encounter', fn ($q) => $q->where('public_id', $fixture[$prefix.'_encounter']))->sole();
        $version = $summary->versions()->where('version', 2)->sole();
        $event = InpatientLocationEvent::query()->where('encounter_public_id', $fixture[$prefix.'_encounter'])->orderByDesc('sequence')->firstOrFail();
        if ($prefix === 'summary_first') {
            must($version->location_sequence === 1, 'summary-first version uses pre-transfer sequence');
            must($version->bed_public_id === $fixture['summary_first_source'], 'summary-first source snapshot coherent');
            must($version->location_event_public_id !== null, 'summary-first real event identity persisted');
            must($version->location_event_type === InpatientLocationEvent::TYPE_ADMISSION, 'summary-first admission event type persisted');
            must($version->history_baseline === null && $version->history_complete === true, 'summary-first history provenance complete');
            must($event->sequence === 2 && $event->to_bed_public_id === $fixture['summary_first_target'], 'summary-first transfer committed after snapshot');
            return ['coherent_pre_transfer_snapshot' => true, 'real_event_provenance_complete' => true, 'summary_location_sequence' => 1, 'final_location_sequence' => 2];
        }
        must($version->location_sequence === 2, 'transfer-first version uses post-transfer sequence');
        must($version->bed_public_id === $fixture['transfer_first_target'], 'transfer-first target snapshot coherent');
        must($version->location_event_public_id === $event->public_id, 'transfer-first event identity persisted');
        must($version->location_event_type === InpatientLocationEvent::TYPE_TRANSFER, 'transfer-first event type persisted');
        must($version->history_baseline === null && $version->history_complete === true, 'transfer-first history provenance complete');
        must($event->sequence === 2 && $event->to_bed_public_id === $fixture['transfer_first_target'], 'transfer-first event coherent');
        return ['coherent_post_transfer_snapshot' => true, 'real_event_provenance_complete' => true, 'summary_location_sequence' => 2, 'final_location_sequence' => 2];
    }

    function verifyInvariants(array $fixture): array
    {
        $discharges = InpatientDischarge::query()->orderBy('id')->get();
        $receipts = InpatientDischargeOperationReceipt::query()->orderBy('id')->get();
        must($discharges->count() === 5, 'five committed routine discharges');
        must($receipts->count() === 5, 'five committed discharge receipts');
        must($discharges->pluck('encounter_id')->unique()->count() === 5, 'one discharge per encounter');
        must($receipts->pluck('result_discharge_public_id')->unique()->count() === 5, 'one receipt per discharge');
        foreach ($discharges as $discharge) {
            $encounter = $discharge->encounter()->sole();
            $summary = $discharge->summary()->sole();
            $version = $discharge->summaryVersion()->sole();
            must($encounter->status === Encounter::STATUS_READY_FOR_RM, 'discharged encounter READY_FOR_RM');
            must(! $encounter->occupiesInpatientBed(), 'discharged encounter does not occupy bed');
            must($encounter->inpatient_bed_id !== null && $encounter->bed_code === $discharge->source_bed_code, 'last placement retained');
            must($summary->summary_state === InpatientDischargeSummary::STATE_FINAL, 'discharge consumes Final summary');
            must($version->id === $discharge->inpatient_discharge_summary_version_id, 'exact Final version FK');
            must($version->public_id === $discharge->discharge_summary_version_public_id, 'exact Final version public ID');
            must(strlen($discharge->discharge_summary_content_digest) === 64, 'content digest retained');
            must(strlen($discharge->discharge_summary_provenance_digest) === 64, 'provenance digest retained');
        }
        $duplicateClaims = Encounter::query()->select('inpatient_bed_id')->whereNotNull('inpatient_bed_id')
            ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)->groupBy('inpatient_bed_id')->havingRaw('COUNT(*) > 1')->count();
        $orphanReceipts = InpatientDischargeOperationReceipt::query()
            ->whereNotIn('result_discharge_public_id', InpatientDischarge::query()->select('public_id'))->count();
        $successAudits = AuditEvent::query()->where('action', 'clinical.inpatient.discharge.execute')->where('outcome', 'SUCCESS')->count();
        must($duplicateClaims === 0, 'no duplicate active bed claims');
        must($orphanReceipts === 0, 'no orphan discharge receipts');
        must($successAudits === 5, 'one success audit per discharge');
        return [
            'discharge_rows' => 5,
            'receipt_rows' => 5,
            'success_audit_rows' => 5,
            'duplicate_active_bed_claims' => 0,
            'orphan_receipts' => 0,
            'exact_final_version_bindings' => true,
            'ready_for_rm_bed_release_semantics' => true,
            'durable_third_connection_assertions' => true,
        ];
    }

    function verifySummaryInvariantsUnused(array $fixture): array
    {
        $heads = InpatientDischargeSummary::query()->count();
        $versions = InpatientDischargeSummaryVersion::query()->count();
        $receipts = InpatientDischargeSummaryOperationReceipt::query()->count();
        $duplicateHeads = DB::table(SchemaQualifier::table('inpatient_discharge_summaries'))
            ->select('encounter_id')->groupBy('encounter_id')->havingRaw('COUNT(*) > 1')->count();
        $orphanVersions = InpatientDischargeSummaryVersion::query()
            ->whereDoesntHave('summary')->count();
        must($heads === 5, 'five successful summary heads before reset');
        must($versions === 10, 'closed immutable version count before reset');
        must($receipts === 10, 'closed receipt count before reset');
        must($duplicateHeads === 0 && $orphanVersions === 0, 'no duplicate heads or orphan versions');
        $finals = InpatientDischargeSummary::query()->where('summary_state', InpatientDischargeSummary::STATE_FINAL)->count();
        must($finals === 2, 'exact terminal Final count');
        $legacy = InpatientDischargeSummaryVersion::query()->where('encounter_public_id', $fixture['legacy_encounter'])->sole();
        must($legacy->location_sequence === 0 && $legacy->history_complete === false, 'legacy provenance remains explicit');
        must($legacy->history_baseline === InpatientDischargeSummary::HISTORY_BASELINE_LEGACY_CURRENT_PLACEMENT, 'legacy provenance label retained');
        must(InpatientDischargeSummary::query()->whereHas('encounter', fn ($query) => $query->where('public_id', $fixture['cancelled_encounter']))->doesntExist(), 'cancelled episode remains denied');
        return [
            'summary_heads' => $heads,
            'version_rows' => $versions,
            'receipt_rows' => $receipts,
            'terminal_final_heads' => $finals,
            'duplicate_episode_heads' => $duplicateHeads,
            'orphan_version_rows' => $orphanVersions,
            'legacy_baseline_provenance' => true,
            'retained_cancellation_denial' => true,
            'durable_third_connection_assertions' => true,
        ];
    }

    function runReset(array $fixture): array
    {
        app(SyntheticResetService::class)->reset([
            'actor' => userByPublicId($fixture['admin']),
            'reason' => 'bounded_inpatient_discharge_portability_reset',
        ]);
        must(InpatientDischarge::query()->count() === 0, 'discharges removed');
        must(InpatientDischargeOperationReceipt::query()->count() === 0, 'discharge receipts removed');
        must(InpatientDischargeSummary::query()->count() === 0, 'summary heads removed');
        must(InpatientDischargeSummaryVersion::query()->count() === 0, 'summary versions removed');
        must(Patient::query()->where('is_synthetic', true)->count() === 0, 'synthetic patients removed');
        must(AuditEvent::query()->where('action', 'clinical.inpatient.discharge.execute')->exists(), 'discharge audit retained');
        must(AuditEvent::query()->where('action', 'teaching.reset.completed')->exists(), 'reset audit retained');
        return [
            'bounded_synthetic_deletion' => true,
            'discharge_rows_removed' => true,
            'discharge_receipts_removed' => true,
            'summary_rows_removed' => true,
            'discharge_audit_rows_retained' => true,
            'reset_audit_retained' => true,
        ];
    }

    function runSummaryResetUnused(array $fixture): array
    {
        app(SyntheticResetService::class)->reset([
            'actor' => userByPublicId($fixture['admin']),
            'reason' => 'bounded_discharge_summary_portability_reset',
        ]);
        must(InpatientDischargeSummary::query()->count() === 0, 'summary heads removed');
        must(InpatientDischargeSummaryVersion::query()->count() === 0, 'summary versions removed');
        must(InpatientDischargeSummaryOperationReceipt::query()->count() === 0, 'summary receipts removed');
        must(Patient::query()->where('is_synthetic', true)->count() === 0, 'synthetic patients removed');
        must(AuditEvent::query()->where('action', 'teaching.reset.completed')->exists(), 'reset audit retained');
        must(AuditEvent::query()->where('action', 'like', 'clinical.inpatient.discharge-summary.%')->exists(), 'summary audit retained');
        return [
            'bounded_synthetic_deletion' => true,
            'summary_heads_removed' => true,
            'summary_versions_removed' => true,
            'summary_receipts_removed' => true,
            'summary_audit_rows_retained' => true,
            'reset_audit_retained' => true,
        ];
    }

    $action = (string) getenv('SIMRS_DISCHARGE_ACTION');
    $scenario = (string) getenv('SIMRS_DISCHARGE_SCENARIO');
    $worker = (string) getenv('SIMRS_DISCHARGE_WORKER');
    $token = (string) getenv('SIMRS_DISCHARGE_RUN_TOKEN');
    $holdMs = (int) getenv('SIMRS_DISCHARGE_HOLD_MS');

    $GLOBALS['simrs_discharge_failure_stage'] = 'bootstrap';
    try {
        $root = realpath((string) getenv('SIMRS_REHEARSAL_ROOT'));
        if ($root === false || $root === '' || ! is_file($root.'/artisan')) {
            throw new RuntimeException('repository root refused');
        }
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        must(config('simulation.mode') === 'SIMULATION', 'SIMULATION mode');
        must(config('simulation.synthetic_only') === true, 'synthetic-only mode');
        must(InpatientDischargeSummary::DEFINITION_VERSION === 'ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1', 'exact definition identifier');
        must(InpatientDischargeSummaryOperationReceipt::OPERATION_DRAFT_SAVE === 'DISCHARGE_SUMMARY_DRAFT_SAVE', 'exact Draft operation identifier');
        must(InpatientDischargeSummaryOperationReceipt::OPERATION_FINALIZE === 'DISCHARGE_SUMMARY_FINALIZE', 'exact Final operation identifier');
        must(InpatientDischargeOperationReceipt::OPERATION_EXECUTE === 'INPATIENT_DISCHARGE_EXECUTE', 'exact discharge operation identifier');
        must(InpatientDischarge::DISPOSITION_ROUTINE_HOME === 'PULANG_ATAS_IZIN_DOKTER', 'exact routine disposition identifier');
        foreach (['BPJS_INTEGRATION_ENABLED', 'VCLAIM_ENABLED', 'SATUSEHAT_ENABLED'] as $flag) {
            must(getenv($flag) === 'false', $flag.' disabled');
        }
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        $fixture = fixture();
        setFailureStage('dispatch');
        $result = match ($action) {
            'prepare' => ['fixture' => prepareFixture($token)],
            'sequential' => runSequential($fixture, $token),
            'atomic_failures' => runAtomicFailures($fixture, $token),
            'append_only' => runAppendOnly($fixture),
            'owner_database_triggers' => runOwnerDatabaseTriggers($fixture),
            'race' => raceOperation($fixture, $scenario, $worker, $token, $holdMs),
            'verify_race' => verifyRace($fixture, $scenario),
            'verify' => verifyInvariants($fixture),
            'reset' => runReset($fixture),
            default => throw new RuntimeException('unsupported action'),
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
            'sql_state' => $exception instanceof \Illuminate\Database\QueryException
                ? (string) ($exception->errorInfo[0] ?? '')
                : '',
            'driver_code' => $exception instanceof \Illuminate\Database\QueryException
                ? (string) ($exception->errorInfo[1] ?? '')
                : '',
            'query_head' => $exception instanceof \Illuminate\Database\QueryException
                ? mb_substr((string) preg_replace('/\s+/', ' ', $exception->getSql()), 0, 240)
                : '',
            'failure_stage' => currentFailureStage(),
        ], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
  PHP

  def initialize(engine:, environment: ENV.to_h, runner: Runner.new, monotonic_clock: nil)
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
    @command_catalog = []
    @result_catalog = []
    @protocol_catalog = []
    @worker_tempfile = nil
    @runtime_application_environment = nil
    @reset_application_environment = nil
  end

  def run!
    assert_contract!
    bindings = current_discharge_bindings
    engine_binding = prepare_engine!
    @run_token = database_run_token
    create_worker_file!

    migration_started = @clock.call
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(migration_started)
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    assert_no_non_synthetic_patients!
    recorded_artisan!('migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    recorded_artisan!('migrate', '--path='+MIGRATION_PATH, '--force', '--no-interaction')

    prepared = run_worker_command!(action: 'prepare', scenario: 'fresh-migration', worker: 'PREPARE')
    fixture = prepared.fetch('fixture')
    provision_runtime_identities!
    sequential = run_worker_command!(action: 'sequential', scenario: 'successful-routine-discharge', worker: 'SEQUENTIAL', fixture: fixture)
    %w[
      main_discharge_public_id race_summary_version final_first_draft_version
      transfer_first_draft_version reuse_summary_version audit_failure_summary_version
      receipt_failure_summary_version
    ].each { |key| fixture[key] = sequential.fetch(key) }
    failures = run_worker_command!(
      action: 'atomic_failures', scenario: 'audit-failure-atomic-rollback', worker: 'FAILURES', fixture: fixture,
      connection_environment: application_environment
    )
    append_only = run_worker_command!(action: 'append_only', scenario: 'append-only-engine-refusal', worker: 'APPEND_ONLY', fixture: fixture)
    owner_triggers = run_worker_command!(
      action: 'owner_database_triggers', scenario: 'append-only-engine-refusal', worker: 'OWNER', fixture: fixture,
      connection_environment: application_environment
    )
    races = RACE_SCENARIOS.to_h { |scenario| [scenario, run_race!(fixture, scenario)] }
    invariants = run_worker_command!(action: 'verify', scenario: 'invariant-verification', worker: 'VERIFY', fixture: fixture)
    reset = run_worker_command!(
      action: 'reset', scenario: 'bounded-synthetic-reset', worker: 'RESET', fixture: fixture,
      connection_environment: @reset_application_environment
    )
    assert_no_non_synthetic_patients!
    down_refusal = expect_rollback_refusal!('correlated audit evidence remains')

    scenarios = {
      'fresh-migration' => { 'status' => 'PASS', 'fresh_apply' => true },
      'empty-down-reapply' => { 'status' => 'PASS', 'empty_down' => true, 'reapply' => true },
      'successful-routine-discharge' => slice_result(sequential, %w[successful_routine_discharge main_discharge_public_id]),
      'missing-draft-stale-summary-denials' => slice_result(sequential, %w[missing_summary_denial draft_summary_denial stale_summary_denial]),
      'stale-placement-denial' => slice_result(sequential, %w[stale_placement_denial]),
      'exact-idempotent-replay' => slice_result(sequential, %w[exact_idempotent_replay]),
      'changed-payload-key-conflict' => slice_result(sequential, %w[changed_payload_key_conflict]),
      'corrupt-replay-binding-denial' => slice_result(sequential, %w[corrupt_replay_binding_denial]),
      'same-discharge-competing-execution' => races.fetch('same-discharge-competing-execution'),
      'final-vs-transfer-final-first' => races.fetch('final-vs-transfer-final-first'),
      'final-vs-transfer-transfer-first' => races.fetch('final-vs-transfer-transfer-first'),
      'released-bed-reuse' => races.fetch('released-bed-reuse'),
      'audit-failure-atomic-rollback' => slice_result(failures, %w[audit_failure_atomic_rollback failure_discharge_rows failure_receipt_rows failure_success_audit_rows failure_bed_claims_retained]),
      'receipt-failure-atomic-rollback' => slice_result(failures, %w[receipt_failure_atomic_rollback failure_discharge_rows failure_receipt_rows failure_success_audit_rows failure_bed_claims_retained]),
      'append-only-engine-refusal' => slice_result(append_only.merge(owner_triggers), %w[runtime_destructive_refusals runtime_refusal_kind owner_database_trigger_refusals owner_truncate_refusal_kind discharge_chain_unchanged receipt_chain_unchanged]),
      'least-privilege-runtime' => { 'status' => 'PASS', 'runtime_grant_profile' => 'DML_EXCEPT_IMMUTABLE_HISTORY_SELECT_INSERT_ONLY', 'reset_identity_separate' => true },
      'bounded-synthetic-reset' => slice_result(reset, %w[bounded_synthetic_deletion discharge_rows_removed discharge_receipts_removed summary_rows_removed discharge_audit_rows_retained reset_audit_retained]),
      'populated-evidence-down-refusal' => { 'status' => 'PASS', 'refusal' => down_refusal },
      'invariant-verification' => slice_result(invariants, %w[discharge_rows receipt_rows success_audit_rows duplicate_active_bed_claims orphan_receipts exact_final_version_bindings ready_for_rm_bed_release_semantics durable_third_connection_assertions]),
    }
    raise CommandFailed, 'Inpatient discharge portability scenario catalogue drifted.' unless scenarios.keys == SCENARIOS

    assert_unchanged_binding!('Inpatient discharge execution bindings', bindings, current_discharge_bindings)
    cleanup!(strict: true)
    remove_worker_file!
    evidence_path = write_discharge_evidence!(
      bindings: bindings,
      engine_binding: engine_binding,
      migration_duration_ms: migration_duration_ms,
      scenarios: scenarios
    )
    {
      'status' => 'PASS',
      'claim' => 'LOCAL_DISPOSABLE_INPATIENT_DISCHARGE_PORTABILITY_ONLY',
      'engine' => @engine,
      'scenario_count' => scenarios.length,
      'evidence_path' => evidence_path,
    }
  ensure
    terminate_workers!
    remove_worker_file!
    cleanup!
  end

  def assert_contract!
    unless @operator_environment[CONFIRMATION_ENV] == CONFIRMATION
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize the disposable local rehearsal."
    end
    rejected = FORBIDDEN_ENVIRONMENT.select { |name| !@operator_environment.fetch(name, '').to_s.strip.empty? }
    unless rejected.empty?
      raise CommandFailed, "Inpatient discharge rehearsal refuses inherited overrides: #{rejected.join(', ')}."
    end
    super
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    unless SCENARIOS.length == 19 && SCENARIOS.uniq.length == 19
      raise CommandFailed, 'Inpatient discharge portability catalogue must contain exactly nineteen unique scenarios.'
    end
    raise CommandFailed, 'Embedded inpatient discharge worker source is unexpectedly small.' unless WORKER_SOURCE.bytesize > 10_000
  end

  def application_environment
    super.merge(
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false',
      'VCLAIM_ENABLED' => 'false',
      'SATUSEHAT_ENABLED' => 'false'
    )
  end

  def current_discharge_bindings
    files = SOURCE_PATHS.to_h { |path| [path, Digest::SHA256.file(safe_source_path(path)).hexdigest] }
    {
      'files' => files,
      'aggregate_sha256' => Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h)),
      'worker_source_sha256' => Digest::SHA256.hexdigest(WORKER_SOURCE),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)),
    }
  end

  private

  def create_worker_file!
    @worker_tempfile = Tempfile.new(['simrs-inpatient-discharge-', '.php'])
    @worker_tempfile.binmode
    @worker_tempfile.write(WORKER_SOURCE)
    @worker_tempfile.flush
    @worker_tempfile.chmod(0o600)
    @worker_tempfile.close
  end

  def remove_worker_file!
    @worker_tempfile&.close!
    @worker_tempfile = nil
  rescue StandardError
    nil
  end

  def database_run_token
    database = @engine == 'postgresql17' ? @postgres_database : @mysql_database
    database.to_s[/[0-9a-f]{12}\z/] || raise(CommandFailed, 'Disposable database lacks a run-token binding.')
  end

  def recorded_artisan!(*arguments)
    @command_catalog << ['artisan', *arguments]
    run_artisan!(*arguments)
    @result_catalog << [['artisan', *arguments], 'PASS']
  end

  def expect_rollback_refusal!(expected)
    arguments = ['migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(
      @runner.process_environment(application_environment),
      @php_binary, File.join(ROOT, 'artisan'), *arguments,
      unsetenv_others: true
    )
    raise CommandFailed, 'Populated inpatient discharge migration unexpectedly rolled back.' if status.success?
    combined = stdout+stderr
    unless combined.gsub(/\s+/, '').include?(expected.gsub(/\s+/, ''))
      raise CommandFailed, "Rollback failed for an unexpected reason: #{@runner.sanitize(combined)}"
    end
    @result_catalog << [['artisan', *arguments], 'EXPECTED_REFUSAL']
    'PASS'
  end

  def provision_runtime_identities!
    @engine == 'postgresql17' ? provision_postgres_runtime_identities! : provision_mysql_runtime_identities!
  end

  def provision_postgres_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
      raise CommandFailed, 'Generated PostgreSQL runtime identities failed the closed pattern.'
    end
    tables = @runner.run!(
      postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command',
        "SELECT tablename FROM pg_tables WHERE schemaname='laravel' ORDER BY tablename"],
      env: postgres_tool_environment
    ).lines.map(&:strip).reject(&:empty?)
    sequences = @runner.run!(
      postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command',
        "SELECT sequencename FROM pg_sequences WHERE schemaname='laravel' ORDER BY sequencename"],
      env: postgres_tool_environment
    ).lines.map(&:strip).reject(&:empty?)
    assert_closed_catalogue!(tables + sequences)
    statements = [
      %(CREATE ROLE "#{runtime}" LOGIN), %(CREATE ROLE "#{reset}" LOGIN),
      %(GRANT CONNECT ON DATABASE "#{@postgres_database}" TO "#{runtime}", "#{reset}"),
      %(GRANT USAGE ON SCHEMA "laravel" TO "#{runtime}", "#{reset}"),
    ]
    tables.each do |table|
      runtime_privileges = IMMUTABLE_HISTORY_TABLES.include?(table) ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'
      statements << %(GRANT #{runtime_privileges} ON TABLE "laravel"."#{table}" TO "#{runtime}")
      statements << %(GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE "laravel"."#{table}" TO "#{reset}")
    end
    sequences.each do |sequence|
      statements << %(GRANT USAGE, SELECT, UPDATE ON SEQUENCE "laravel"."#{sequence}" TO "#{runtime}", "#{reset}")
    end
    @runner.run!(postgres_psql_arguments(@postgres_database) + ['--set', 'ON_ERROR_STOP=1', '--command', statements.join(";\n")+';'], env: postgres_tool_environment)
    IMMUTABLE_HISTORY_TABLES.each do |table|
      privileges = @runner.run!(
        postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command',
          "SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name='#{table}'"],
        env: postgres_tool_environment
      ).strip
      raise CommandFailed, "PostgreSQL runtime history grant drifted for #{table}." unless privileges == 'INSERT,SELECT'
    end
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => '')
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => '')
    record_runtime_profile!('postgresql-owner', tables.length, sequences.length)
  end

  def provision_mysql_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
      raise CommandFailed, 'Generated MySQL runtime identities failed the closed pattern.'
    end
    runtime_password = SecureRandom.hex(24)
    reset_password = SecureRandom.hex(24)
    tables = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='#{@mysql_database}' ORDER BY TABLE_NAME;").lines.map(&:strip).reject(&:empty?)
    assert_closed_catalogue!(tables)
    statements = [
      "CREATE USER '#{runtime}'@'127.0.0.1' IDENTIFIED BY '#{runtime_password}'",
      "CREATE USER '#{reset}'@'127.0.0.1' IDENTIFIED BY '#{reset_password}'",
    ]
    tables.each do |table|
      runtime_privileges = IMMUTABLE_HISTORY_TABLES.include?(table) ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'
      statements << "GRANT #{runtime_privileges} ON `#{@mysql_database}`.`#{table}` TO '#{runtime}'@'127.0.0.1'"
      statements << "GRANT SELECT, INSERT, UPDATE, DELETE ON `#{@mysql_database}`.`#{table}` TO '#{reset}'@'127.0.0.1'"
    end
    statements << 'FLUSH PRIVILEGES'
    @runner.run!(mysql_root_arguments, stdin_data: statements.join(";\n")+';')
    grants = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SHOW GRANTS FOR '#{runtime}'@'127.0.0.1';").upcase
    forbidden = %w[DROP ALTER TRIGGER CREATE].select { |privilege| grants.match?(/\b#{privilege}\b/) }
    raise CommandFailed, "Reduced MySQL runtime identity retained forbidden grants: #{forbidden.join(', ')}." unless forbidden.empty?
    IMMUTABLE_HISTORY_TABLES.each do |table|
      expected = "GRANT SELECT, INSERT ON `#{@mysql_database.upcase}`.`#{table.upcase}`"
      raise CommandFailed, "MySQL runtime history grant drifted for #{table}." unless grants.include?(expected)
    end
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => runtime_password)
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => reset_password)
    record_runtime_profile!('mysql-root', tables.length, 0)
  end

  def assert_closed_catalogue!(names)
    unless names.is_a?(Array) && !names.empty? && names.all? { |name| name.match?(/\A[a-z0-9_]+\z/) }
      raise CommandFailed, 'Disposable database catalogue failed its closed identifier contract.'
    end
  end

  def record_runtime_profile!(authority, table_count, sequence_count)
    @command_catalog << [authority, 'provision-and-read-back-disposable-runtime-identities']
    @result_catalog << [[authority, 'provision-and-read-back-disposable-runtime-identities'], {
      'status' => 'PASS',
      'runtime_grant_profile' => 'DML_EXCEPT_IMMUTABLE_HISTORY_SELECT_INSERT_ONLY',
      'reset_identity_separate' => true,
      'forbidden_runtime_grants' => [],
      'table_grant_count' => table_count,
      'sequence_grant_count' => sequence_count,
    }]
  end

  def run_worker_command!(action:, scenario:, worker:, fixture: nil, hold_ms: 0, connection_environment: nil)
    @command_catalog << ['inpatient-discharge-worker', action, "--scenario=#{scenario}", "--worker=#{worker}"]
    stdout, stderr, status = Open3.capture3(
      @runner.process_environment(worker_environment(
        action: action, scenario: scenario, worker: worker, fixture: fixture,
        hold_ms: hold_ms, connection_environment: connection_environment
      )),
      @php_binary, @worker_tempfile.path, unsetenv_others: true
    )
    document = stdout.lines.reverse_each.map { |line| parse_worker_line(line) }.compact.first
    unless status.success? && document.is_a?(Hash) && document['status'] == 'PASS' && document['protocol_state'] == 'COMMITTED'
      diagnostic = if document.is_a?(Hash)
                     "class=#{document['exception_class']}, fingerprint=#{document['exception_fingerprint']}, stage=#{document['failure_stage']}, sqlstate=#{document['sql_state']}, driver=#{document['driver_code']}, query=#{document['query_head']}"
                   else
                     @runner.sanitize(stderr)
                   end
      raise CommandFailed, "Inpatient discharge #{action} worker failed#{diagnostic.to_s.empty? ? '' : ": #{diagnostic}"}"
    end
    safe = document.reject { |key, _value| %w[fixture backend_connection_id].include?(key) }
    @protocol_catalog << safe
    @result_catalog << [[action, scenario, worker], safe]
    document
  end

  def start_race_worker!(fixture:, scenario:, worker:, hold_ms:)
    @command_catalog << ['inpatient-discharge-worker', 'race', "--scenario=#{scenario}", "--worker=#{worker}"]
    stdin, stdout, stderr, wait_thread = Open3.popen3(
      @runner.process_environment(worker_environment(
        action: 'race', scenario: scenario, worker: worker, fixture: fixture, hold_ms: hold_ms
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

  def worker_environment(action:, scenario:, worker:, fixture:, hold_ms:, connection_environment: nil)
    (connection_environment || @runtime_application_environment || application_environment).merge(
      'SIMRS_REHEARSAL_ROOT' => ROOT,
      'SIMRS_DISCHARGE_ACTION' => action,
      'SIMRS_DISCHARGE_SCENARIO' => scenario,
      'SIMRS_DISCHARGE_WORKER' => worker,
      'SIMRS_DISCHARGE_RUN_TOKEN' => @run_token,
      'SIMRS_DISCHARGE_HOLD_MS' => hold_ms.to_s,
      'SIMRS_DISCHARGE_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {}))
    )
  end

  def run_race!(fixture, scenario)
    first = start_race_worker!(fixture: fixture, scenario: scenario, worker: 'A', hold_ms: HOLD_MS)
    await_protocol!(first, 'STARTED')
    await_protocol!(first, 'HOLDING')
    second = start_race_worker!(fixture: fixture, scenario: scenario, worker: 'B', hold_ms: 0)
    second_started = await_protocol!(second, 'STARTED')
    wait_observed = observe_real_database_wait!(Integer(second_started.fetch('backend_connection_id')))
    first_final = await_final!(first)
    second_final = await_final!(second)
    outcomes = [first_final.fetch('outcome'), second_final.fetch('outcome')].sort
    expected = %w[same-discharge-competing-execution final-vs-transfer-final-first].include?(scenario) ? %w[APPLIED DENIED].sort : %w[APPLIED APPLIED]
    raise CommandFailed, "Unexpected race outcomes for #{scenario}." unless outcomes == expected
    verified = run_worker_command!(action: 'verify_race', scenario: scenario, worker: 'VERIFY', fixture: fixture)
    result = {
      'status' => 'PASS',
      'proof_kind' => 'OBSERVED_DATABASE_RACE',
      'independent_application_processes' => 2,
      'real_database_wait_observed' => wait_observed,
      'harness_bed_prelock' => false,
      'deadlock_observed' => false,
      'durable_third_connection_assertions' => true,
      'outcomes' => outcomes,
      'durable_assertions' => verified.fetch('result'),
    }
    @result_catalog << [[scenario, 'race-proof'], result]
    result
  ensure
    terminate_workers!
  end

  def await_protocol!(worker, state)
    deadline = @clock.call + WAIT_TIMEOUT_SECONDS
    loop do
      remaining = deadline - @clock.call
      raise CommandFailed, "#{worker.scenario}/#{worker.label} did not reach #{state}." if remaining <= 0
      next unless IO.select([worker.stdout], nil, nil, [remaining, 0.25].min)
      line = worker.stdout.gets
      raise_worker_failure!(worker, "Worker exited before #{state}.") if line.nil?
      document = parse_worker_line(line)
      next unless document
      if document['status'] == 'BLOCKED'
        raise CommandFailed, "#{worker.scenario}/#{worker.label} blocked (#{document['exception_class']}, #{document['exception_fingerprint']})."
      end
      return document if document['protocol_state'] == state
    end
  end

  def await_final!(worker)
    final = await_protocol!(worker, 'COMMITTED')
    status = worker.wait_thread.value
    stderr = worker.stderr_reader.value
    raise CommandFailed, "Race worker failed: #{@runner.sanitize(stderr)}" unless status.success?
    close_worker!(worker)
    final
  end

  def parse_worker_line(line)
    document = JSON.parse(line.strip)
    document if document.is_a?(Hash) && document['schema_version'] == 1
  rescue JSON::ParserError
    nil
  end

  def close_worker!(worker)
    worker.stdout.close unless worker.stdout.closed?
    worker.stderr.close unless worker.stderr.closed?
    @workers.delete(worker)
  end

  def raise_worker_failure!(worker, message)
    status = worker.wait_thread.value
    reason = @runner.sanitize(worker.stderr_reader.value)
    raise CommandFailed, "#{message} exit=#{status.exitstatus}#{reason.empty? ? '' : ": #{reason}"}"
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

  def observe_real_database_wait!(connection_id)
    deadline = @clock.call + 5.0
    loop do
      observed = @engine == 'postgresql17' ? postgres_wait_observed?(connection_id) : mysql_wait_observed?(connection_id)
      return true if observed
      raise CommandFailed, 'No native database lock wait was observed.' if @clock.call >= deadline
      sleep 0.05
    end
  end

  def postgres_wait_observed?(id)
    output = @runner.run!(
      postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command',
        "SELECT CASE WHEN wait_event_type='Lock' AND cardinality(pg_blocking_pids(pid))>0 THEN '1' ELSE '0' END FROM pg_stat_activity WHERE pid=#{id}"],
      env: postgres_tool_environment
    ).strip
    output == '1'
  end

  def mysql_wait_observed?(id)
    output = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: <<~SQL).strip
      SELECT COUNT(*) FROM performance_schema.data_lock_waits AS waits
      INNER JOIN performance_schema.threads AS threads ON threads.thread_id = waits.requesting_thread_id
      WHERE threads.processlist_id = #{id};
    SQL
    Integer(output, 10).positive?
  rescue ArgumentError
    false
  end

  def slice_result(document, keys)
    { 'status' => 'PASS' }.merge(document.slice(*keys))
  end

  def write_discharge_evidence!(bindings:, engine_binding:, migration_duration_ms:, scenarios:)
    assert_evidence_directory!
    path = File.join(
      EVIDENCE_DIRECTORY,
      "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-inpatient-discharge-#{SecureRandom.hex(6)}.json"
    )
    evidence = {
      'schema_version' => 1,
      'kind' => EVIDENCE_KIND,
      'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_INPATIENT_DISCHARGE_PORTABILITY_ONLY',
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
        'This is local disposable-engine evidence, not hosted migration, deployment or production evidence.',
        'Passing engineering checks does not establish clinical, RMIK, product-owner or parity acceptance.',
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
    unless ARGV.length == 1 && LocalInpatientDischargePortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalInpatientDischargePortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalInpatientDischargePortabilityRehearsal::CommandFailed => e
    warn "inpatient discharge portability rehearsal failed: #{e.message}"
    exit 1
  end
end
