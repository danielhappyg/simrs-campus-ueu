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

# Disposable PostgreSQL/MySQL proof for the inpatient bed-transfer slice.
# It intentionally creates its own local database and never accepts an external
# connection. Public synthetic fixture identifiers are passed to short-lived
# worker processes but are not retained in the evidence file.
class LocalInpatientTransferPortabilityRehearsal < LocalPortabilityFullSuiteRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_INPATIENT_TRANSFER_PORTABILITY'
  CONFIRMATION_ENV = 'SIMRS_INPATIENT_TRANSFER_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-inpatient-transfer-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalInpatientTransferPortabilityHarnessContractTest.rb'
  FOUNDATION_PATH = 'scripts/rehearse-local-portability-full-suite.rb'
  MIGRATION_PATH = 'database/migrations/2026_08_30_000500_create_inpatient_location_history_tables.php'
  FEATURE_PATH = 'tests/Feature/Inpatient/InpatientBedTransferTest.php'
  EVIDENCE_KIND = 'SIMRS_LOCAL_INPATIENT_TRANSFER_PORTABILITY'
  HOLD_MS = 3_500
  WAIT_TIMEOUT_SECONDS = 24
  IDENTITY_PATTERN = /\Asimrs_(?:runtime|reset)_[0-9a-f]{12}\z/
  IMMUTABLE_HISTORY_TABLES = %w[
    inpatient_location_events
    inpatient_location_operation_receipts
  ].freeze

  RACE_SCENARIOS = %w[
    same-encounter-competing-transfer
    two-encounters-same-target
    admission-vs-transfer-transfer-first
    admission-vs-transfer-admission-first
    target-retirement-transfer-first
    target-retirement-retirement-first
    whole-ward-retirement-vs-transfer
    whole-ward-retirement-vs-documentation
    source-retirement-during-claim
    opposite-direction-swaps
    documentation-vs-transfer-document-first
    documentation-vs-transfer-transfer-first
  ].freeze

  SCENARIOS = (%w[
    fresh-migration
    empty-down-reapply
    populated-event-down-refusal
    populated-receipt-down-refusal
    successful-atomic-transfer
    exact-idempotent-replay
    occupied-target-denial
    append-only-database-trigger-refusal
    truthful-legacy-baseline
    audit-failure-atomic-rollback
    receipt-failure-atomic-rollback
  ].freeze + RACE_SCENARIOS + %w[
    bounded-synthetic-reset
    correlated-audit-down-refusal
    invariant-verification
  ]).freeze

  SOURCE_PATHS = %w[
    scripts/rehearse-local-inpatient-transfer-portability.rb
    scripts/rehearse-local-portability-full-suite.rb
    tests/Documentation/LocalInpatientTransferPortabilityHarnessContractTest.rb
    tests/Feature/Inpatient/InpatientBedTransferTest.php
    database/migrations/2026_08_30_000500_create_inpatient_location_history_tables.php
    app/Support/Inpatient/InpatientBedTransferService.php
    app/Support/Inpatient/InpatientBedTransferActorPolicy.php
    app/Support/Inpatient/InpatientBedTransferResult.php
    app/Support/Inpatient/InpatientBedTransferDenied.php
    app/Support/Inpatient/CanonicalInpatientBedOperationLockCoordinator.php
    app/Support/Inpatient/InpatientDocumentationService.php
    app/Support/Inpatient/InpatientDocumentationSchemaMutationScope.php
    app/Support/Inpatient/InpatientDocumentationPlacementChanged.php
    app/Support/Inpatient/InpatientMasterService.php
    app/Support/Inpatient/InpatientLocationHistoryProjection.php
    app/Support/Inpatient/InpatientLocationMutationScope.php
    app/Support/Inpatient/InpatientLocationSqlWriteGuard.php
    app/Support/Inpatient/InpatientLocationSchemaMutationScope.php
    app/Models/InpatientLocationEvent.php
    app/Models/InpatientLocationOperationReceipt.php
    app/Models/InpatientClinicalDocument.php
    app/Models/InpatientClinicalDocumentVersion.php
    app/Support/Registration/InpatientBedClaimGuard.php
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

  # This source is written to a mode-0600 temporary file and removed in ensure.
  # Failures emit only class names and message fingerprints, never raw messages.
  WORKER_SOURCE = <<~'PHP'
    <?php
    declare(strict_types=1);

    use App\Models\Encounter;
    use App\Models\InpatientBed;
    use App\Models\InpatientClinicalDocument;
    use App\Models\InpatientClinicalDocumentVersion;
    use App\Models\InpatientLocationEvent;
    use App\Models\InpatientLocationOperationReceipt;
    use App\Models\InpatientWard;
    use App\Models\Patient;
    use App\Models\Role;
    use App\Models\User;
    use App\Support\Audit\AuditEvent;
    use App\Support\Audit\AuditRecorder;
    use App\Support\Authorization\RoleCapabilityMatrix;
    use App\Support\Database\SchemaQualifier;
    use App\Support\Inpatient\CanonicalInpatientBedOperationLockCoordinator;
    use App\Support\Inpatient\InpatientBedTransferDenied;
    use App\Support\Inpatient\InpatientBedTransferActorPolicy;
    use App\Support\Inpatient\InpatientBedTransferAuditUnavailable;
    use App\Support\Inpatient\InpatientBedTransferService;
    use App\Support\Inpatient\InpatientDocumentationService;
    use App\Support\Inpatient\InpatientDocumentationSchemaMutationScope;
    use App\Support\Inpatient\InpatientLocationHistoryProjection;
    use App\Support\Inpatient\InpatientLocationMutationScope;
    use App\Support\Inpatient\InpatientLocationSchemaMutationScope;
    use App\Support\Inpatient\InpatientMasterDenied;
    use App\Support\Inpatient\InpatientMasterService;
    use App\Support\Registration\InpatientBedUnavailable;
    use App\Support\Simulation\SyntheticResetService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;

    function protocol(string $state, array $extra = []): void
    {
        echo json_encode(array_merge([
            'schema_version' => 1,
            'status' => 'PASS',
            'protocol_state' => $state,
            'scenario' => (string) getenv('SIMRS_TRANSFER_SCENARIO'),
            'worker' => (string) getenv('SIMRS_TRANSFER_WORKER'),
        ], $extra), JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
    }

    function must(bool $condition, string $label): void
    {
        if (! $condition) {
            throw new RuntimeException('assertion failed: '.$label);
        }
    }

    function backendConnectionId(): int
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? (int) data_get(DB::selectOne('SELECT pg_backend_pid() AS id'), 'id')
            : (int) data_get(DB::selectOne('SELECT CONNECTION_ID() AS id'), 'id');
    }

    function fixture(): array
    {
        $encoded = (string) getenv('SIMRS_TRANSFER_FIXTURE');
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

    function actor(array $fixture): User
    {
        return User::query()->where('public_id', $fixture['registrar'])->firstOrFail();
    }

    function createLegacyEncounter(User $registrar, InpatientWard $ward, InpatientBed $bed, int $queue, string $token): Encounter
    {
        $patient = Patient::query()->create([
            'medical_record_number' => 'RMTR'.strtoupper($token).str_pad((string) $queue, 2, '0', STR_PAD_LEFT),
            'full_name' => 'Pasien Portabilitas '.$queue,
            'date_of_birth' => '1990-01-01',
            'sex' => Patient::SEX_PEREMPUAN,
            'is_synthetic' => true,
            'created_by_user_id' => $registrar->id,
        ]);

        return InpatientLocationMutationScope::run(static fn (): Encounter => Encounter::query()->create([
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
    }

    function prepareFixture(string $token, string $label): array
    {
        $fixtureToken = substr(hash('sha256', $token.'|'.$label), 0, 12);
        $registrar = User::query()->create([
            'name' => 'Registrar Portabilitas',
            'email' => 'registrar.'.$fixtureToken.'@example.invalid',
            'password' => bin2hex(random_bytes(24)),
            'status' => 'ACTIVE',
        ]);
        $admin = User::query()->create([
            'name' => 'Admin Portabilitas',
            'email' => 'admin.'.$fixtureToken.'@example.invalid',
            'password' => bin2hex(random_bytes(24)),
            'status' => 'ACTIVE',
        ]);
        $nurse = User::query()->create([
            'name' => 'Perawat Portabilitas',
            'email' => 'nurse.'.$fixtureToken.'@example.invalid',
            'password' => bin2hex(random_bytes(24)),
            'status' => 'ACTIVE',
        ]);
        $registrarRole = Role::query()->where('slug', RoleCapabilityMatrix::ROLE_REGISTRAR)->firstOrFail();
        $adminRole = Role::query()->where('slug', RoleCapabilityMatrix::ROLE_ADMIN)->firstOrFail();
        $nurseRole = Role::query()->where('slug', RoleCapabilityMatrix::ROLE_NURSE)->firstOrFail();
        $registrar->roles()->sync([$registrarRole->id]);
        $admin->roles()->sync([$adminRole->id]);
        $nurse->roles()->sync([$nurseRole->id]);

        $master = app(InpatientMasterService::class);
        $wardResult = $master->createWard(
            $admin,
            'RITR-'.strtoupper(substr($fixtureToken, 0, 8)),
            'Bangsal Portabilitas Transfer',
            InpatientMasterService::REASON_INITIAL_SETUP,
            'transfer-ward-'.$fixtureToken,
            null,
        );
        $ward = $wardResult->master;
        must($ward instanceof InpatientWard, 'ward created');

        $bed = static function (string $suffix, string $label) use ($master, $admin, $ward, $fixtureToken): InpatientBed {
            $result = $master->createBed(
                $admin,
                $ward->public_id,
                'TR-'.strtoupper(substr($fixtureToken, 0, 6)).'-'.$suffix,
                $label,
                'Ruang Portabilitas',
                'Kelas 1',
                InpatientMasterService::REASON_INITIAL_SETUP,
                'transfer-bed-'.$suffix.'-'.$fixtureToken,
                null,
            );
            must($result->master instanceof InpatientBed, 'bed created '.$suffix);
            return $result->master;
        };

        $beds = [
            'success_source' => $bed('00', 'Tempat Tidur Sukses Sumber'),
            'success_target' => $bed('01', 'Tempat Tidur Sukses Tujuan'),
            'race_source' => $bed('10', 'Tempat Tidur Lomba Sumber'),
            'race_target_a' => $bed('11', 'Tempat Tidur Lomba Tujuan A'),
            'race_target_b' => $bed('12', 'Tempat Tidur Lomba Tujuan B'),
            'occupied_source' => $bed('20', 'Tempat Tidur Terisi Sumber'),
            'occupied_target' => $bed('21', 'Tempat Tidur Terisi Tujuan'),
            'reset_source' => $bed('30', 'Tempat Tidur Reset Sumber'),
            'same_target_source_a' => $bed('40', 'Target Bersama Sumber A'),
            'same_target_source_b' => $bed('41', 'Target Bersama Sumber B'),
            'same_target' => $bed('42', 'Target Bersama'),
            'admission_tf_source' => $bed('50', 'Admission Transfer Sumber 1'),
            'admission_tf_target' => $bed('51', 'Admission Transfer Target 1'),
            'admission_af_source' => $bed('52', 'Admission Transfer Sumber 2'),
            'admission_af_target' => $bed('53', 'Admission Transfer Target 2'),
            'retire_tf_source' => $bed('60', 'Retire Transfer Sumber 1'),
            'retire_tf_target' => $bed('61', 'Retire Transfer Target 1'),
            'retire_rf_source' => $bed('62', 'Retire Transfer Sumber 2'),
            'retire_rf_target' => $bed('63', 'Retire Transfer Target 2'),
            'source_retire_source' => $bed('70', 'Retire Sumber Klaim'),
            'source_retire_target' => $bed('71', 'Retire Sumber Tujuan'),
            'swap_a' => $bed('80', 'Swap A'),
            'swap_b' => $bed('81', 'Swap B'),
            'document_df_source' => $bed('90', 'Dokumentasi Dulu Sumber'),
            'document_df_target' => $bed('91', 'Dokumentasi Dulu Tujuan'),
            'document_tf_source' => $bed('92', 'Transfer Dulu Sumber'),
            'document_tf_target' => $bed('93', 'Transfer Dulu Tujuan'),
            'ward_transfer_source' => $bed('A0', 'Ward Race Transfer Sumber'),
            'ward_transfer_target' => $bed('A1', 'Ward Race Transfer Tujuan'),
            'ward_document_source' => $bed('A2', 'Ward Race Dokumentasi Sumber'),
            'failure_source' => $bed('B0', 'Rollback Sumber'),
            'failure_target' => $bed('B1', 'Rollback Tujuan'),
            'replay_source' => $bed('C0', 'Idempotensi Sumber'),
            'replay_target' => $bed('C1', 'Idempotensi Tujuan'),
            'claim_order_x' => $bed('D0', 'Urutan Klaim X'),
            'claim_order_y' => $bed('D1', 'Urutan Klaim Y'),
        ];

        $success = createLegacyEncounter($registrar, $ward, $beds['success_source'], 1, $fixtureToken);
        $race = createLegacyEncounter($registrar, $ward, $beds['race_source'], 2, $fixtureToken);
        $occupied = createLegacyEncounter($registrar, $ward, $beds['occupied_source'], 3, $fixtureToken);
        createLegacyEncounter($registrar, $ward, $beds['occupied_target'], 4, $fixtureToken);
        createLegacyEncounter($registrar, $ward, $beds['reset_source'], 5, $fixtureToken);
        $sameTargetA = createLegacyEncounter($registrar, $ward, $beds['same_target_source_a'], 6, $fixtureToken);
        $sameTargetB = createLegacyEncounter($registrar, $ward, $beds['same_target_source_b'], 7, $fixtureToken);
        $admissionTf = createLegacyEncounter($registrar, $ward, $beds['admission_tf_source'], 8, $fixtureToken);
        $admissionAf = createLegacyEncounter($registrar, $ward, $beds['admission_af_source'], 9, $fixtureToken);
        $retireTf = createLegacyEncounter($registrar, $ward, $beds['retire_tf_source'], 10, $fixtureToken);
        $retireRf = createLegacyEncounter($registrar, $ward, $beds['retire_rf_source'], 11, $fixtureToken);
        $sourceRetire = createLegacyEncounter($registrar, $ward, $beds['source_retire_source'], 12, $fixtureToken);
        $swapA = createLegacyEncounter($registrar, $ward, $beds['swap_a'], 13, $fixtureToken);
        $swapB = createLegacyEncounter($registrar, $ward, $beds['swap_b'], 14, $fixtureToken);
        $documentDf = createLegacyEncounter($registrar, $ward, $beds['document_df_source'], 15, $fixtureToken);
        $documentTf = createLegacyEncounter($registrar, $ward, $beds['document_tf_source'], 16, $fixtureToken);
        $wardTransfer = createLegacyEncounter($registrar, $ward, $beds['ward_transfer_source'], 17, $fixtureToken);
        $wardDocument = createLegacyEncounter($registrar, $ward, $beds['ward_document_source'], 18, $fixtureToken);
        $failure = createLegacyEncounter($registrar, $ward, $beds['failure_source'], 19, $fixtureToken);
        $replay = createLegacyEncounter($registrar, $ward, $beds['replay_source'], 20, $fixtureToken);
        $claimOrder = createLegacyEncounter($registrar, $ward, $beds['claim_order_y'], 21, $fixtureToken);
        InpatientLocationMutationScope::run(
            static fn () => $claimOrder->update(['bed_code' => $beds['claim_order_x']->code]),
        );

        return [
            'registrar' => $registrar->public_id,
            'admin' => $admin->public_id,
            'nurse' => $nurse->public_id,
            'ward' => $ward->public_id,
            'success_encounter' => $success->public_id,
            'success_source' => $beds['success_source']->public_id,
            'success_target' => $beds['success_target']->public_id,
            'race_encounter' => $race->public_id,
            'race_source' => $beds['race_source']->public_id,
            'race_target_a' => $beds['race_target_a']->public_id,
            'race_target_b' => $beds['race_target_b']->public_id,
            'occupied_encounter' => $occupied->public_id,
            'occupied_source' => $beds['occupied_source']->public_id,
            'occupied_target' => $beds['occupied_target']->public_id,
            'same_target_encounter_a' => $sameTargetA->public_id,
            'same_target_encounter_b' => $sameTargetB->public_id,
            'same_target_source_a' => $beds['same_target_source_a']->public_id,
            'same_target_source_b' => $beds['same_target_source_b']->public_id,
            'same_target' => $beds['same_target']->public_id,
            'admission_tf_encounter' => $admissionTf->public_id,
            'admission_tf_source' => $beds['admission_tf_source']->public_id,
            'admission_tf_target' => $beds['admission_tf_target']->public_id,
            'admission_af_encounter' => $admissionAf->public_id,
            'admission_af_source' => $beds['admission_af_source']->public_id,
            'admission_af_target' => $beds['admission_af_target']->public_id,
            'retire_tf_encounter' => $retireTf->public_id,
            'retire_tf_source' => $beds['retire_tf_source']->public_id,
            'retire_tf_target' => $beds['retire_tf_target']->public_id,
            'retire_rf_encounter' => $retireRf->public_id,
            'retire_rf_source' => $beds['retire_rf_source']->public_id,
            'retire_rf_target' => $beds['retire_rf_target']->public_id,
            'source_retire_encounter' => $sourceRetire->public_id,
            'source_retire_source' => $beds['source_retire_source']->public_id,
            'source_retire_target' => $beds['source_retire_target']->public_id,
            'swap_encounter_a' => $swapA->public_id,
            'swap_encounter_b' => $swapB->public_id,
            'swap_a' => $beds['swap_a']->public_id,
            'swap_b' => $beds['swap_b']->public_id,
            'document_df_encounter' => $documentDf->public_id,
            'document_df_source' => $beds['document_df_source']->public_id,
            'document_df_target' => $beds['document_df_target']->public_id,
            'document_tf_encounter' => $documentTf->public_id,
            'document_tf_source' => $beds['document_tf_source']->public_id,
            'document_tf_target' => $beds['document_tf_target']->public_id,
            'ward_transfer_encounter' => $wardTransfer->public_id,
            'ward_transfer_source' => $beds['ward_transfer_source']->public_id,
            'ward_transfer_target' => $beds['ward_transfer_target']->public_id,
            'ward_document_encounter' => $wardDocument->public_id,
            'ward_document_source' => $beds['ward_document_source']->public_id,
            'failure_encounter' => $failure->public_id,
            'failure_source' => $beds['failure_source']->public_id,
            'failure_target' => $beds['failure_target']->public_id,
            'replay_encounter' => $replay->public_id,
            'replay_source' => $beds['replay_source']->public_id,
            'replay_target' => $beds['replay_target']->public_id,
            'claim_order_encounter' => $claimOrder->public_id,
            'claim_order_x' => $beds['claim_order_x']->public_id,
            'claim_order_y' => $beds['claim_order_y']->public_id,
            'claim_order_x_version' => $beds['claim_order_x']->version,
            'claim_order_y_version' => $beds['claim_order_y']->version,
        ];
    }

    function runSequential(array $fixture, string $token): array
    {
        $registrar = actor($fixture);
        $service = app(InpatientBedTransferService::class);
        $key = 'successful-transfer-'.$token;
        $first = $service->transfer(
            $fixture['success_encounter'], $registrar, 0,
            $fixture['success_source'], $fixture['success_target'],
            'Kebutuhan penempatan layanan', $key, null,
        );
        must($first->replayed === false, 'first transfer applied');

        $occupiedDenied = false;
        try {
            $service->transfer(
                $fixture['occupied_encounter'], $registrar, 0,
                $fixture['occupied_source'], $fixture['occupied_target'],
                'Tujuan ditempati episode lain', 'occupied-transfer-'.$token, null,
            );
        } catch (InpatientBedTransferDenied $denial) {
            $occupiedDenied = $denial->reason === 'target_occupied';
        }
        must($occupiedDenied, 'occupied target denied deterministically');

        $event = InpatientLocationEvent::query()->where('public_id', $first->event->public_id)->sole();
        $receipt = InpatientLocationOperationReceipt::query()->where('result_event_public_id', $event->public_id)->sole();
        $tables = [
            'event' => SchemaQualifier::table('inpatient_location_events'),
            'receipt' => SchemaQualifier::table('inpatient_location_operation_receipts'),
        ];
        $refusals = [];
        foreach ([
            'event_update' => static fn () => DB::table($tables['event'])->where('id', $event->id)->update(['sequence' => 99]),
            'event_delete' => static fn () => DB::table($tables['event'])->where('id', $event->id)->delete(),
            'receipt_update' => static fn () => DB::table($tables['receipt'])->where('id', $receipt->id)->update(['result_sequence' => 99]),
            'receipt_delete' => static fn () => DB::table($tables['receipt'])->where('id', $receipt->id)->delete(),
            'event_truncate' => static fn () => DB::statement(
                'TRUNCATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($tables['event'])
            ),
        ] as $label => $attempt) {
            $refused = false;
            try {
                InpatientLocationMutationScope::run($attempt);
            } catch (Throwable) {
                $refused = true;
            }
            must($refused, $label.' refused for runtime identity');
            $refusals[] = $label;
        }
        $driver = DB::connection()->getDriverName();
        $triggerRemovalRefused = false;
        try {
            InpatientLocationSchemaMutationScope::run(static function () use ($driver, $tables): void {
                if ($driver === 'mysql') {
                    DB::statement('DROP TRIGGER ile_immutable_update_trg');
                    return;
                }
                DB::statement('DROP TRIGGER ile_immutable_update_trg ON '.DB::connection()->getQueryGrammar()->wrapTable($tables['event']));
            });
        } catch (Throwable) {
            $triggerRemovalRefused = true;
        }
        must($triggerRemovalRefused, 'runtime app user cannot remove append-only trigger');
        $refusals[] = 'event_trigger_drop';

        $markerForgeryRefused = false;
        try {
            if ($driver === 'mysql') {
                DB::statement('SET @simrs_synthetic_reset = 1');
            } else {
                DB::statement("SELECT set_config('simrs.synthetic_reset', '1', false)");
            }
            try {
                InpatientLocationMutationScope::run(
                    static fn () => DB::table($tables['event'])->where('id', $event->id)->delete(),
                );
            } catch (Throwable) {
                $markerForgeryRefused = true;
            }
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET @simrs_synthetic_reset = 0');
            } else {
                DB::statement("SELECT set_config('simrs.synthetic_reset', '0', false)");
            }
        }
        must($markerForgeryRefused, 'runtime app user cannot forge the reset escape');
        $refusals[] = 'event_delete_after_reset_marker_forgery';
        must((int) InpatientLocationEvent::query()->whereKey($event->id)->value('sequence') === 1, 'event unchanged');
        must((int) InpatientLocationOperationReceipt::query()->whereKey($receipt->id)->value('result_sequence') === 1, 'receipt unchanged');

        $successAudit = AuditEvent::query()
            ->where('action', 'inpatient.bed.transfer')
            ->where('outcome', 'SUCCESS')
            ->where('resource_id', $fixture['success_encounter'])
            ->sole();
        $metadata = is_array($successAudit->metadata) ? $successAudit->metadata : [];
        must(! array_key_exists('reason', $metadata), 'reason absent from success audit metadata');
        must($successAudit->reason === null, 'success audit reason null');

        return [
            'atomic_transfer' => true,
            'event_rows_after_transfer' => 1,
            'receipt_rows_after_transfer' => 1,
            'success_audit_rows_after_transfer' => 1,
            'occupied_target_denied' => true,
            'runtime_destructive_refusals' => $refusals,
            'runtime_destructive_refusal_kind' => 'RUNTIME_PRIVILEGE',
            'runtime_trigger_removal_refused' => $triggerRemovalRefused,
            'reset_marker_forgery_refused' => $markerForgeryRefused,
            'reason_absent_from_success_audit' => true,
        ];
    }

    function runIdempotentRace(array $fixture, string $token): array
    {
        $result = app(InpatientBedTransferService::class)->transfer(
            $fixture['replay_encounter'], actor($fixture), 0,
            $fixture['replay_source'], $fixture['replay_target'],
            'Rehearsal idempotensi serentak', 'same-key-race-'.$token, null,
        );

        return [
            'outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED',
            'denial_code' => null,
            'operation' => 'TRANSFER',
        ];
    }

    function verifyIdempotentRace(array $fixture): array
    {
        $event = InpatientLocationEvent::query()
            ->where('encounter_public_id', $fixture['replay_encounter'])->sole();
        $receipt = InpatientLocationOperationReceipt::query()
            ->where('encounter_id', $event->encounter_id)->sole();
        $auditCount = AuditEvent::query()
            ->where('action', 'inpatient.bed.transfer')
            ->where('outcome', 'SUCCESS')
            ->where('resource_id', $fixture['replay_encounter'])->count();
        must($event->to_bed_public_id === $fixture['replay_target'], 'idempotent race target persisted');
        must($receipt->result_event_public_id === $event->public_id, 'idempotent race receipt references event');
        must($auditCount === 1, 'idempotent race emitted one success audit');

        return [
            'durable_third_connection_assertions' => true,
            'event_rows_after_replay' => 1,
            'receipt_rows_after_replay' => 1,
            'success_audit_rows_after_replay' => 1,
            'assertion_catalog' => [
                'one_event_for_same_key_race',
                'one_receipt_for_same_key_race',
                'one_success_audit_for_same_key_race',
            ],
        ];
    }

    function delayTarget(array $fixture, string $scenario): array
    {
        return match ($scenario) {
            'exact-idempotent-replay' => ['inpatient_location_events', $fixture['replay_encounter']],
            'documentation-vs-transfer-document-first' => ['inpatient_clinical_document_versions', $fixture['document_df_encounter']],
            'documentation-vs-transfer-transfer-first' => ['inpatient_location_events', $fixture['document_tf_encounter']],
            default => throw new RuntimeException('delay scenario refused'),
        };
    }

    function installDelayTrigger(array $fixture, string $scenario): array
    {
        [$tableName, $encounterPublicId] = delayTarget($fixture, $scenario);
        must(Str::isUlid($encounterPublicId), 'delay encounter is a ULID');
        $driver = DB::connection()->getDriverName();
        $table = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table($tableName));
        $mutation = static function () use ($driver, $table, $encounterPublicId): void {
            if ($driver === 'pgsql') {
                $schema = SchemaQualifier::primarySchema() ?? 'public';
                $function = '"'.str_replace('"', '""', $schema).'"."itr_rehearsal_delay_insert"';
                DB::unprepared("CREATE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.encounter_public_id = '{$encounterPublicId}' THEN PERFORM pg_sleep(3.5); END IF; RETURN NEW; END; \$\$");
                DB::unprepared("CREATE TRIGGER itr_rehearsal_delay_insert BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION {$function}()");
                return;
            }
            DB::unprepared("CREATE TRIGGER itr_rehearsal_delay_insert BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.encounter_public_id = '{$encounterPublicId}' THEN DO SLEEP(3.5); END IF; END");
        };
        str_starts_with($tableName, 'inpatient_clinical_document')
            ? InpatientDocumentationSchemaMutationScope::run($mutation)
            : InpatientLocationSchemaMutationScope::run($mutation);
        return ['owner_installed_service_delay_trigger' => true];
    }

    function dropDelayTrigger(array $fixture, string $scenario): array
    {
        [$tableName] = delayTarget($fixture, $scenario);
        $driver = DB::connection()->getDriverName();
        $table = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table($tableName));
        $mutation = static function () use ($driver, $table): void {
            if ($driver === 'pgsql') {
                $schema = SchemaQualifier::primarySchema() ?? 'public';
                $function = '"'.str_replace('"', '""', $schema).'"."itr_rehearsal_delay_insert"';
                DB::unprepared("DROP TRIGGER IF EXISTS itr_rehearsal_delay_insert ON {$table}");
                DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
                return;
            }
            DB::unprepared('DROP TRIGGER IF EXISTS itr_rehearsal_delay_insert');
        };
        str_starts_with($tableName, 'inpatient_clinical_document')
            ? InpatientDocumentationSchemaMutationScope::run($mutation)
            : InpatientLocationSchemaMutationScope::run($mutation);
        return ['owner_removed_service_delay_trigger' => true];
    }

    function proveOwnerPostgresTriggers(array $fixture, string $token): array
    {
        must(DB::connection()->getDriverName() === 'pgsql', 'owner trigger proof is PostgreSQL only');
        $result = app(InpatientBedTransferService::class)->transfer(
            $fixture['failure_encounter'], actor($fixture), 0,
            $fixture['failure_source'], $fixture['failure_target'],
            'Bukti trigger append-only PostgreSQL', 'owner-trigger-proof-'.$token, null,
        );
        $event = $result->event->fresh();
        $receipt = InpatientLocationOperationReceipt::query()
            ->where('result_event_public_id', $event->public_id)->sole();
        $eventTable = SchemaQualifier::table('inpatient_location_events');
        $receiptTable = SchemaQualifier::table('inpatient_location_operation_receipts');
        $refusals = [];
        foreach ([
            'owner_event_update_trigger' => static fn () => DB::table($eventTable)->where('id', $event->id)->update(['sequence' => 99]),
            'owner_event_delete_trigger' => static fn () => DB::table($eventTable)->where('id', $event->id)->delete(),
            'owner_receipt_update_trigger' => static fn () => DB::table($receiptTable)->where('id', $receipt->id)->update(['result_sequence' => 99]),
            'owner_receipt_delete_trigger' => static fn () => DB::table($receiptTable)->where('id', $receipt->id)->delete(),
            'owner_event_truncate_trigger' => static fn () => DB::statement(
                'TRUNCATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($eventTable)
            ),
        ] as $label => $attempt) {
            try {
                InpatientLocationMutationScope::run($attempt);
            } catch (Throwable) {
                $refusals[] = $label;
            }
        }
        must(count($refusals) === 5, 'owner PostgreSQL append-only triggers refused every mutation');
        must((int) $event->fresh()->sequence === 1, 'owner trigger proof event unchanged');
        must((int) $receipt->fresh()->result_sequence === 1, 'owner trigger proof receipt unchanged');
        return [
            'owner_postgresql_database_trigger_refusals' => $refusals,
            'owner_postgresql_truncate_refusal_kind' => 'DATABASE_TRIGGER',
        ];
    }

    function runInconsistentClaimRace(array $fixture, string $token, string $worker, int $holdMs): array
    {
        if ($worker === 'A') {
            return DB::transaction(function () use ($fixture, $holdMs): array {
                try {
                    app(InpatientMasterService::class)->resolveActiveBedForAdmission($fixture['claim_order_x']);
                } catch (InpatientBedUnavailable) {
                    protocol('HOLDING');
                    if ($holdMs > 0) {
                        usleep($holdMs * 1000);
                    }
                    return [
                        'outcome' => 'DENIED',
                        'denial_code' => 'target_occupied',
                        'operation' => 'AVAILABILITY_X',
                    ];
                }
                throw new RuntimeException('inconsistent X availability unexpectedly succeeded');
            }, 1);
        }

        $result = retirementOperation(
            $fixture, 'claim_order_y',
            'inconsistent-claim-retire-'.substr(hash('sha256', $token), 0, 20),
        );
        must($result['outcome'] === 'DENIED' && $result['denial_code'] === 'bed_occupied', 'inconsistent Y retirement denied');
        return $result;
    }

    function verifyInconsistentClaimRace(array $fixture): array
    {
        $encounter = Encounter::query()->where('public_id', $fixture['claim_order_encounter'])->sole();
        $x = InpatientBed::query()->where('public_id', $fixture['claim_order_x'])->sole();
        $y = InpatientBed::query()->where('public_id', $fixture['claim_order_y'])->sole();
        must($encounter->inpatient_bed_id === $y->id, 'inconsistent claim retained Y id');
        must($encounter->bed_code === $x->code, 'inconsistent claim retained X code');
        must($x->state === InpatientBed::STATE_ACTIVE && $y->state === InpatientBed::STATE_ACTIVE, 'both claim-order beds remain active');
        must($x->version === $fixture['claim_order_x_version'], 'claim-order X version unchanged');
        must($y->version === $fixture['claim_order_y_version'], 'claim-order Y version unchanged');
        return [
            'durable_third_connection_assertions' => true,
            'assertion_catalog' => [
                'inconsistent_claim_y_id_preserved',
                'inconsistent_claim_x_code_preserved',
                'claim_order_bed_x_unchanged',
                'claim_order_bed_y_unchanged',
            ],
        ];
    }

    function transferOperation(array $fixture, string $encounter, string $source, string $target, string $key): array
    {
        try {
            $result = app(InpatientBedTransferService::class)->transfer(
                $fixture[$encounter], actor($fixture), 0, $fixture[$source], $fixture[$target],
                'Rehearsal perpindahan serentak', $key, null,
            );
            must($result->replayed === false, 'race transfer is fresh');
            return ['outcome' => 'APPLIED', 'denial_code' => null, 'operation' => 'TRANSFER'];
        } catch (InpatientBedTransferDenied $denial) {
            return ['outcome' => 'DENIED', 'denial_code' => $denial->reason, 'operation' => 'TRANSFER'];
        }
    }

    function retirementOperation(array $fixture, string $bedKey, string $key): array
    {
        $admin = User::query()->where('public_id', $fixture['admin'])->firstOrFail();
        $bed = InpatientBed::query()->where('public_id', $fixture[$bedKey])->firstOrFail();
        try {
            app(InpatientMasterService::class)->retireBed(
                $admin, $bed->public_id, $bed->version, InpatientMasterService::REASON_RETIREMENT, $key, null,
            );
            return ['outcome' => 'APPLIED', 'denial_code' => null, 'operation' => 'BED_RETIREMENT'];
        } catch (InpatientMasterDenied $denial) {
            return ['outcome' => 'DENIED', 'denial_code' => $denial->reasonCode, 'operation' => 'BED_RETIREMENT'];
        }
    }

    function wardRetirementOperation(array $fixture, string $key): array
    {
        $admin = User::query()->where('public_id', $fixture['admin'])->firstOrFail();
        $ward = InpatientWard::query()->where('public_id', $fixture['ward'])->firstOrFail();
        try {
            app(InpatientMasterService::class)->retireWard(
                $admin, $ward->public_id, $ward->version, InpatientMasterService::REASON_RETIREMENT, $key, null,
            );
            return ['outcome' => 'APPLIED', 'denial_code' => null, 'operation' => 'WARD_RETIREMENT'];
        } catch (InpatientMasterDenied $denial) {
            return ['outcome' => 'DENIED', 'denial_code' => $denial->reasonCode, 'operation' => 'WARD_RETIREMENT'];
        }
    }

    function documentationOperation(array $fixture, string $encounterKey, string $key): array
    {
        $nurse = User::query()->where('public_id', $fixture['nurse'])->firstOrFail();
        $result = app(InpatientDocumentationService::class)->saveDraft(
            $fixture[$encounterKey], $nurse,
            InpatientClinicalDocument::TYPE_NURSING_DAILY,
            InpatientClinicalDocument::DEFINITION_VERSION,
            0,
            [
                'nursing_observation' => 'Observasi portabilitas',
                'nursing_intervention' => 'Intervensi portabilitas',
                'nursing_evaluation' => 'Evaluasi portabilitas',
            ],
            $key,
            null,
        );
        must($result->replayed === false, 'documentation write is fresh');
        return ['outcome' => 'APPLIED', 'denial_code' => null, 'operation' => 'DOCUMENTATION'];
    }

    function admissionOperation(array $fixture, string $targetKey, string $token, string $key): array
    {
        $registrar = actor($fixture);
        try {
            return DB::transaction(function () use ($fixture, $targetKey, $token, $key, $registrar): array {
                $bed = app(InpatientMasterService::class)->resolveActiveBedForAdmission($fixture[$targetKey]);
                $patient = Patient::query()->create([
                    'medical_record_number' => 'RMADM'.strtoupper(substr(hash('sha256', $token.'|'.$key), 0, 12)),
                    'full_name' => 'Pasien Admission Race',
                    'date_of_birth' => '1991-01-01',
                    'sex' => Patient::SEX_LAKI_LAKI,
                    'is_synthetic' => true,
                    'created_by_user_id' => $registrar->id,
                ]);
                $queue = 1000 + (abs(crc32($key)) % 8000);
                $encounter = InpatientLocationMutationScope::run(fn (): Encounter => Encounter::query()->create([
                    'patient_id' => $patient->id,
                    'care_setting' => Encounter::CARE_SETTING_INPATIENT,
                    'status' => Encounter::STATUS_REGISTERED,
                    'clinic_name' => $bed->ward->display_name,
                    'ward_name' => $bed->ward->display_name,
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
                app(InpatientBedTransferService::class)->recordAdmission(
                    $encounter, $bed->ward, $bed, $registrar, null,
                );
                return ['outcome' => 'APPLIED', 'denial_code' => null, 'operation' => 'ADMISSION'];
            }, 1);
        } catch (InpatientBedUnavailable) {
            return ['outcome' => 'DENIED', 'denial_code' => 'target_occupied', 'operation' => 'ADMISSION'];
        } catch (InpatientMasterDenied $denial) {
            return ['outcome' => 'DENIED', 'denial_code' => $denial->reasonCode, 'operation' => 'ADMISSION'];
        }
    }

    function raceLockCodes(array $fixture, string $scenario): array
    {
        $keys = match ($scenario) {
            'same-encounter-competing-transfer' => ['race_source'],
            'two-encounters-same-target' => ['same_target'],
            'admission-vs-transfer-transfer-first' => ['admission_tf_target'],
            'admission-vs-transfer-admission-first' => ['admission_af_target'],
            'target-retirement-transfer-first' => ['retire_tf_target'],
            'target-retirement-retirement-first' => ['retire_rf_target'],
            'source-retirement-during-claim' => ['source_retire_source'],
            'opposite-direction-swaps' => ['swap_a', 'swap_b'],
            'whole-ward-retirement-vs-transfer', 'whole-ward-retirement-vs-documentation' => [],
            default => throw new RuntimeException('race scenario refused'),
        };
        if ($keys === []) {
            $ward = InpatientWard::query()->where('public_id', $fixture['ward'])->firstOrFail();
            return InpatientBed::query()->where('ward_id', $ward->id)->orderBy('code')->pluck('code')->all();
        }
        return InpatientBed::query()->whereIn('public_id', array_map(static fn (string $key): string => $fixture[$key], $keys))
            ->orderBy('code')->pluck('code')->all();
    }

    function runRace(array $fixture, string $token, string $scenario, string $worker, int $holdMs): array
    {
        $operation = static function () use ($fixture, $token, $scenario, $worker): array {
            $key = 'matrix-'.substr(hash('sha256', $scenario.'|'.$worker.'|'.$token), 0, 24);
            return match ($scenario) {
                'same-encounter-competing-transfer' => transferOperation(
                    $fixture, 'race_encounter', 'race_source', $worker === 'A' ? 'race_target_a' : 'race_target_b', $key,
                ),
                'two-encounters-same-target' => transferOperation(
                    $fixture, $worker === 'A' ? 'same_target_encounter_a' : 'same_target_encounter_b',
                    $worker === 'A' ? 'same_target_source_a' : 'same_target_source_b', 'same_target', $key,
                ),
                'admission-vs-transfer-transfer-first' => $worker === 'A'
                    ? transferOperation($fixture, 'admission_tf_encounter', 'admission_tf_source', 'admission_tf_target', $key)
                    : admissionOperation($fixture, 'admission_tf_target', $token, $key),
                'admission-vs-transfer-admission-first' => $worker === 'A'
                    ? admissionOperation($fixture, 'admission_af_target', $token, $key)
                    : transferOperation($fixture, 'admission_af_encounter', 'admission_af_source', 'admission_af_target', $key),
                'target-retirement-transfer-first' => $worker === 'A'
                    ? transferOperation($fixture, 'retire_tf_encounter', 'retire_tf_source', 'retire_tf_target', $key)
                    : retirementOperation($fixture, 'retire_tf_target', $key),
                'target-retirement-retirement-first' => $worker === 'A'
                    ? retirementOperation($fixture, 'retire_rf_target', $key)
                    : transferOperation($fixture, 'retire_rf_encounter', 'retire_rf_source', 'retire_rf_target', $key),
                'whole-ward-retirement-vs-transfer' => $worker === 'A'
                    ? wardRetirementOperation($fixture, $key)
                    : transferOperation($fixture, 'ward_transfer_encounter', 'ward_transfer_source', 'ward_transfer_target', $key),
                'whole-ward-retirement-vs-documentation' => $worker === 'A'
                    ? wardRetirementOperation($fixture, $key)
                    : documentationOperation($fixture, 'ward_document_encounter', $key),
                'source-retirement-during-claim' => $worker === 'A'
                    ? retirementOperation($fixture, 'source_retire_source', $key)
                    : transferOperation($fixture, 'source_retire_encounter', 'source_retire_source', 'source_retire_target', $key),
                'opposite-direction-swaps' => $worker === 'A'
                    ? transferOperation($fixture, 'swap_encounter_a', 'swap_a', 'swap_b', $key)
                    : transferOperation($fixture, 'swap_encounter_b', 'swap_b', 'swap_a', $key),
                'documentation-vs-transfer-document-first' => $worker === 'A'
                    ? documentationOperation($fixture, 'document_df_encounter', $key)
                    : transferOperation($fixture, 'document_df_encounter', 'document_df_source', 'document_df_target', $key),
                'documentation-vs-transfer-transfer-first' => $worker === 'A'
                    ? transferOperation($fixture, 'document_tf_encounter', 'document_tf_source', 'document_tf_target', $key)
                    : documentationOperation($fixture, 'document_tf_encounter', $key),
                default => throw new RuntimeException('race scenario refused'),
            };
        };

        if (str_starts_with($scenario, 'documentation-vs-transfer')) {
            return $operation();
        }

        return DB::transaction(function () use ($fixture, $scenario, $holdMs, $operation): array {
            app(CanonicalInpatientBedOperationLockCoordinator::class)->lockMutexes(raceLockCodes($fixture, $scenario));
            protocol('HOLDING');
            if ($holdMs > 0) {
                usleep($holdMs * 1000);
            }
            return $operation();
        }, 1);
    }

    function verifyRace(array $fixture, string $scenario): array
    {
        $eventCount = static fn (string $encounterKey): int => InpatientLocationEvent::query()
            ->where('encounter_public_id', $fixture[$encounterKey])->count();
        $bedId = static fn (string $bedKey): int => (int) InpatientBed::query()
            ->where('public_id', $fixture[$bedKey])->value('id');
        $encounterBed = static fn (string $encounterKey): int => (int) Encounter::query()
            ->where('public_id', $fixture[$encounterKey])->value('inpatient_bed_id');
        $activeClaims = static fn (string $bedKey): int => Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
            ->where('inpatient_bed_id', $bedId($bedKey))->count();

        $assertions = match ($scenario) {
            'same-encounter-competing-transfer' => [
                'at_most_one_next_event' => $eventCount('race_encounter') === 1,
            ],
            'two-encounters-same-target' => [
                'one_target_claim' => $activeClaims('same_target') === 1,
                'one_transfer_event' => $eventCount('same_target_encounter_a') + $eventCount('same_target_encounter_b') === 1,
            ],
            'admission-vs-transfer-transfer-first' => [
                'one_target_claim' => $activeClaims('admission_tf_target') === 1,
                'transfer_won' => $eventCount('admission_tf_encounter') === 1,
            ],
            'admission-vs-transfer-admission-first' => [
                'one_target_claim' => $activeClaims('admission_af_target') === 1,
                'transfer_lost' => $eventCount('admission_af_encounter') === 0,
            ],
            'target-retirement-transfer-first' => [
                'transfer_won' => $encounterBed('retire_tf_encounter') === $bedId('retire_tf_target'),
                'occupied_target_remains_active' => InpatientBed::query()->where('public_id', $fixture['retire_tf_target'])->value('state') === InpatientBed::STATE_ACTIVE,
            ],
            'target-retirement-retirement-first' => [
                'retirement_won' => InpatientBed::query()->where('public_id', $fixture['retire_rf_target'])->value('state') === InpatientBed::STATE_RETIRED,
                'transfer_preserved_source' => $encounterBed('retire_rf_encounter') === $bedId('retire_rf_source'),
            ],
            'whole-ward-retirement-vs-transfer' => [
                'ward_retirement_denied_on_recheck' => InpatientWard::query()->where('public_id', $fixture['ward'])->value('state') === InpatientWard::STATE_ACTIVE,
                'transfer_completed' => $eventCount('ward_transfer_encounter') === 1,
            ],
            'whole-ward-retirement-vs-documentation' => [
                'ward_retirement_denied_on_recheck' => InpatientWard::query()->where('public_id', $fixture['ward'])->value('state') === InpatientWard::STATE_ACTIVE,
                'documentation_completed' => InpatientClinicalDocument::query()->where('encounter_id', Encounter::query()->where('public_id', $fixture['ward_document_encounter'])->value('id'))->count() === 1,
            ],
            'source-retirement-during-claim' => [
                'source_retirement_denied_while_claimed' => InpatientBed::query()->where('public_id', $fixture['source_retire_source'])->value('state') === InpatientBed::STATE_ACTIVE,
                'transfer_completed_after_denial' => $eventCount('source_retire_encounter') === 1,
            ],
            'opposite-direction-swaps' => [
                'no_swap_event' => $eventCount('swap_encounter_a') + $eventCount('swap_encounter_b') === 0,
                'original_claims_preserved' => $encounterBed('swap_encounter_a') === $bedId('swap_a') && $encounterBed('swap_encounter_b') === $bedId('swap_b'),
            ],
            'documentation-vs-transfer-document-first' => [
                'coherent_source_snapshot' => InpatientClinicalDocumentVersion::query()
                    ->where('encounter_public_id', $fixture['document_df_encounter'])->value('bed_public_id') === $fixture['document_df_source'],
                'transfer_completed' => $eventCount('document_df_encounter') === 1,
            ],
            'documentation-vs-transfer-transfer-first' => [
                'coherent_target_snapshot' => InpatientClinicalDocumentVersion::query()
                    ->where('encounter_public_id', $fixture['document_tf_encounter'])->value('bed_public_id') === $fixture['document_tf_target'],
                'transfer_completed' => $eventCount('document_tf_encounter') === 1,
            ],
            default => throw new RuntimeException('verify race scenario refused'),
        };
        foreach ($assertions as $label => $passed) {
            must($passed, $label);
        }
        return [
            'durable_third_connection_assertions' => true,
            'assertion_catalog' => array_keys($assertions),
        ];
    }

    function proveLegacyBaseline(array $fixture): array
    {
        $encounter = Encounter::query()->where('public_id', $fixture['success_encounter'])->firstOrFail();
        $projection = app(InpatientLocationHistoryProjection::class)->forEncounter($encounter, actor($fixture));
        must(InpatientLocationEvent::query()->where('encounter_id', $encounter->id)->count() === 0, 'legacy has no fabricated event');
        must($projection['history_baseline'] === 'LEGACY_CURRENT_PLACEMENT', 'legacy baseline is explicit');
        must($projection['history_complete'] === false, 'legacy history remains incomplete');
        must($projection['current_sequence'] === 0, 'legacy sequence begins at zero without backfill');
        return [
            'legacy_baseline' => 'LEGACY_CURRENT_PLACEMENT',
            'history_complete' => false,
            'fabricated_event_rows' => 0,
            'backfill_performed' => false,
        ];
    }

    function installReceiptFailureTrigger(): void
    {
        $driver = DB::connection()->getDriverName();
        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable(SchemaQualifier::table('inpatient_location_operation_receipts'));
        InpatientLocationSchemaMutationScope::run(static function () use ($driver, $table): void {
            if ($driver === 'pgsql') {
                $schema = SchemaQualifier::primarySchema() ?? 'public';
                $qualifiedFunction = '"'.str_replace('"', '""', $schema).'"."fail_inpatient_transfer_receipt_rehearsal"';
                DB::unprepared("CREATE FUNCTION {$qualifiedFunction}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'receipt rehearsal refusal' USING ERRCODE='55000'; END; \$\$");
                DB::unprepared("CREATE TRIGGER ilor_rehearsal_fail_insert BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION {$qualifiedFunction}()");
            } else {
                DB::unprepared("CREATE TRIGGER ilor_rehearsal_fail_insert BEFORE INSERT ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='receipt rehearsal refusal'");
            }
        });
    }

    function dropReceiptFailureTrigger(): void
    {
        $driver = DB::connection()->getDriverName();
        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable(SchemaQualifier::table('inpatient_location_operation_receipts'));
        InpatientLocationSchemaMutationScope::run(static function () use ($driver, $table): void {
            if ($driver === 'pgsql') {
                $schema = SchemaQualifier::primarySchema() ?? 'public';
                $qualifiedFunction = '"'.str_replace('"', '""', $schema).'"."fail_inpatient_transfer_receipt_rehearsal"';
                DB::unprepared("DROP TRIGGER IF EXISTS ilor_rehearsal_fail_insert ON {$table}");
                DB::unprepared("DROP FUNCTION IF EXISTS {$qualifiedFunction}()");
            } else {
                DB::unprepared('DROP TRIGGER IF EXISTS ilor_rehearsal_fail_insert');
            }
        });
    }

    function proveAtomicFailures(array $fixture, string $token): array
    {
        $encounter = Encounter::query()->where('public_id', $fixture['failure_encounter'])->firstOrFail();
        $sourceId = $encounter->inpatient_bed_id;
        $eventsBefore = InpatientLocationEvent::query()->count();
        $receiptsBefore = InpatientLocationOperationReceipt::query()->count();
        $successAuditsBefore = AuditEvent::query()->where('action', 'inpatient.bed.transfer')->where('outcome', 'SUCCESS')->count();

        $nullRecorder = new class extends AuditRecorder {
            public function record(
                string $action, string $resourceType, ?string $resourceId = null, ?User $actor = null,
                string $outcome = 'SUCCESS', ?string $reason = null, array $metadata = [],
                ?Request $request = null, bool $includeRequestFingerprint = true,
            ): ?AuditEvent { return null; }
        };
        $auditFailed = false;
        try {
            (new InpatientBedTransferService(
                $nullRecorder,
                app(InpatientBedTransferActorPolicy::class),
                app(CanonicalInpatientBedOperationLockCoordinator::class),
            ))->transfer(
                $fixture['failure_encounter'], actor($fixture), 0, $fixture['failure_source'], $fixture['failure_target'],
                'Audit harus menggagalkan transfer', 'audit-failure-'.$token, null,
            );
        } catch (InpatientBedTransferAuditUnavailable) {
            $auditFailed = true;
        }
        must($auditFailed, 'audit failure surfaced');
        must($encounter->fresh()->inpatient_bed_id === $sourceId, 'audit failure placement rollback');
        must(InpatientLocationEvent::query()->count() === $eventsBefore, 'audit failure event rollback');
        must(InpatientLocationOperationReceipt::query()->count() === $receiptsBefore, 'audit failure receipt rollback');

        $receiptFailed = false;
        installReceiptFailureTrigger();
        try {
            app(InpatientBedTransferService::class)->transfer(
                $fixture['failure_encounter'], actor($fixture), 0, $fixture['failure_source'], $fixture['failure_target'],
                'Receipt harus menggagalkan transfer', 'receipt-failure-'.$token, null,
            );
        } catch (Throwable) {
            $receiptFailed = true;
        } finally {
            dropReceiptFailureTrigger();
        }
        must($receiptFailed, 'receipt failure surfaced');
        must($encounter->fresh()->inpatient_bed_id === $sourceId, 'receipt failure placement rollback');
        must(InpatientLocationEvent::query()->count() === $eventsBefore, 'receipt failure event rollback');
        must(InpatientLocationOperationReceipt::query()->count() === $receiptsBefore, 'receipt failure receipt rollback');
        must(AuditEvent::query()->where('action', 'inpatient.bed.transfer')->where('outcome', 'SUCCESS')->count() === $successAuditsBefore, 'receipt failure audit rollback');

        return [
            'audit_failure_atomic_rollback' => true,
            'receipt_failure_atomic_rollback' => true,
            'placement_preserved' => true,
            'event_receipt_audit_counts_preserved' => true,
        ];
    }

    function createDownFixture(array $fixture, string $kind, string $token): array
    {
        $encounter = Encounter::query()->where('public_id', $fixture['success_encounter'])->firstOrFail();
        if ($kind === 'event') {
            DB::transaction(function () use ($encounter, $fixture): void {
                $bed = InpatientBed::query()->where('public_id', $fixture['success_source'])->firstOrFail();
                $ward = $bed->ward()->firstOrFail();
                app(InpatientBedTransferService::class)->recordAdmission($encounter, $ward, $bed, actor($fixture), null);
            });
            return ['populated_event_rows' => 1];
        }
        if ($kind === 'receipt') {
            InpatientLocationMutationScope::run(function () use ($encounter, $fixture, $token): void {
                InpatientLocationOperationReceipt::query()->create([
                    'encounter_id' => $encounter->id,
                    'actor_user_id' => actor($fixture)->id,
                    'operation' => InpatientLocationOperationReceipt::OPERATION_TRANSFER,
                    'idempotency_key' => 'down-receipt-'.$token,
                    'payload_digest' => hash('sha256', 'down-receipt-'.$token),
                    'result_event_public_id' => (string) Str::ulid(),
                    'result_sequence' => 1,
                    'request_correlation_id' => null,
                    'completed_at' => now(),
                ]);
            });
            return ['populated_receipt_rows' => 1];
        }
        throw new RuntimeException('down fixture kind refused');
    }

    function cleanupFixture(array $fixture): array
    {
        app(SyntheticResetService::class)->reset([
            'actor' => actor($fixture),
            'reason' => 'inpatient_transfer_portability_fixture_cleanup',
        ]);
        must(InpatientLocationEvent::query()->count() === 0, 'fixture cleanup removed events');
        must(InpatientLocationOperationReceipt::query()->count() === 0, 'fixture cleanup removed receipts');
        return ['fixture_cleanup' => true];
    }

    function verifyInvariants(array $fixture): array
    {
        $active = Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES);
        $activeCount = (clone $active)->count();
        $withBedCount = (clone $active)->whereNotNull('inpatient_bed_id')->count();
        $duplicateClaims = (clone $active)->select('inpatient_bed_id')
            ->groupBy('inpatient_bed_id')->havingRaw('COUNT(*) > 1')->count();
        must($activeCount === $withBedCount, 'each active inpatient has one current bed');
        must($duplicateClaims === 0, 'no duplicate active bed claim');

        $events = InpatientLocationEvent::query()->orderBy('encounter_id')->orderBy('sequence')->get();
        foreach ($events->groupBy('encounter_id') as $encounterEvents) {
            $sequences = $encounterEvents->pluck('sequence')->map(static fn ($value): int => (int) $value)->values()->all();
            must($sequences === range(1, count($sequences)), 'location sequence is monotonic and gapless');
        }

        $receipts = InpatientLocationOperationReceipt::query()->get();
        $successAudits = AuditEvent::query()->where('action', 'inpatient.bed.transfer')->where('outcome', 'SUCCESS')->get();
        $transferEvents = $events->where('event_type', InpatientLocationEvent::TYPE_TRANSFER)->values();
        must($transferEvents->count() > 1, 'expanded transfer event evidence exists');
        must($receipts->count() === $transferEvents->count(), 'one receipt per transfer event');
        must($successAudits->count() === $transferEvents->count(), 'one success audit per transfer event');
        foreach ($receipts as $receipt) {
            $event = $events->first(static fn (InpatientLocationEvent $candidate): bool =>
                $candidate->public_id === $receipt->result_event_public_id
                && $candidate->encounter_id === $receipt->encounter_id
                && $candidate->sequence === $receipt->result_sequence
                && $candidate->payload_digest === $receipt->payload_digest
            );
            must($event instanceof InpatientLocationEvent, 'receipt references exact event');
            $matching = $successAudits->filter(static function (AuditEvent $audit) use ($event): bool {
                $metadata = is_array($audit->metadata) ? $audit->metadata : [];
                return ($metadata['event_public_id'] ?? null) === $event->public_id
                    && (int) ($metadata['sequence'] ?? 0) === $event->sequence
                    && ($metadata['payload_digest'] ?? null) === $event->payload_digest
                    && ! array_key_exists('reason', $metadata)
                    && $audit->reason === null;
            });
            must($matching->count() === 1, 'event receipt audit consistency');
        }

        $raceEncounter = Encounter::query()->where('public_id', $fixture['race_encounter'])->firstOrFail();
        $raceTargetIds = InpatientBed::query()
            ->whereIn('public_id', [$fixture['race_target_a'], $fixture['race_target_b']])
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        must(in_array((int) $raceEncounter->inpatient_bed_id, $raceTargetIds, true), 'race winner placement persisted');

        return [
            'one_active_bed_claim_per_episode' => true,
            'duplicate_active_bed_claims' => 0,
            'monotonic_gapless_location_sequences' => true,
            'location_event_rows' => $events->count(),
            'operation_receipt_rows' => $receipts->count(),
            'success_audit_rows' => $successAudits->count(),
            'event_receipt_audit_consistency' => true,
            'reason_absent_from_success_audit' => true,
        ];
    }

    function runReset(array $fixture): array
    {
        $registrar = actor($fixture);
        $beforeEvents = InpatientLocationEvent::query()->count();
        $beforeReceipts = InpatientLocationOperationReceipt::query()->count();
        $beforeSuccessAudits = AuditEvent::query()->where('action', 'inpatient.bed.transfer')->where('outcome', 'SUCCESS')->count();
        must($beforeEvents > 0 && $beforeReceipts > 0 && $beforeSuccessAudits > 0, 'reset precondition evidence exists');

        app(SyntheticResetService::class)->reset([
            'actor' => $registrar,
            'reason' => 'inpatient_transfer_portability_reset',
        ]);

        must(InpatientLocationEvent::query()->count() === 0, 'reset removed location events');
        must(InpatientLocationOperationReceipt::query()->count() === 0, 'reset removed location receipts');
        must(Patient::query()->where('is_synthetic', true)->count() === 0, 'reset removed synthetic patients');
        $afterSuccessAudits = AuditEvent::query()->where('action', 'inpatient.bed.transfer')->where('outcome', 'SUCCESS')->count();
        must($afterSuccessAudits === $beforeSuccessAudits, 'reset retained transfer audit evidence');

        $driver = DB::connection()->getDriverName();
        $markerClear = $driver === 'mysql'
            ? (int) data_get(DB::selectOne('SELECT COALESCE(@simrs_synthetic_reset, 0) AS marker'), 'marker') === 0
            : data_get(DB::selectOne("SELECT current_setting('simrs.synthetic_reset', true) AS marker"), 'marker') !== '1';
        must($markerClear, 'bounded reset marker cleared');

        return [
            'bounded_synthetic_deletion' => true,
            'location_events_removed' => $beforeEvents,
            'operation_receipts_removed' => $beforeReceipts,
            'transfer_audit_rows_retained' => $afterSuccessAudits,
            'reset_marker_cleared' => true,
        ];
    }

    try {
        $root = realpath((string) getenv('SIMRS_REHEARSAL_ROOT'));
        if ($root === false || $root === '' || ! is_file($root.'/artisan')) {
            throw new RuntimeException('repository root refused');
        }
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $runToken = (string) getenv('SIMRS_TRANSFER_RUN_TOKEN');
        if (preg_match('/\A[0-9a-f]{12}\z/', $runToken) !== 1) {
            throw new RuntimeException('run token refused');
        }
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('simulation boundary refused');
        }
        foreach (['BPJS_INTEGRATION_ENABLED', 'VCLAIM_ENABLED', 'SATUSEHAT_ENABLED'] as $key) {
            if (getenv($key) !== 'false') {
                throw new RuntimeException('live integration boundary refused');
            }
        }

        $action = (string) getenv('SIMRS_TRANSFER_ACTION');
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        $result = match ($action) {
            'prepare' => ['fixture' => prepareFixture($runToken, (string) getenv('SIMRS_TRANSFER_WORKER'))],
            'legacy' => proveLegacyBaseline(fixture()),
            'sequential' => runSequential(fixture(), $runToken),
            'idempotent_race' => runIdempotentRace(fixture(), $runToken),
            'verify_idempotent_race' => verifyIdempotentRace(fixture()),
            'install_delay_trigger' => installDelayTrigger(fixture(), (string) getenv('SIMRS_TRANSFER_SCENARIO')),
            'drop_delay_trigger' => dropDelayTrigger(fixture(), (string) getenv('SIMRS_TRANSFER_SCENARIO')),
            'owner_postgres_triggers' => proveOwnerPostgresTriggers(fixture(), $runToken),
            'inconsistent_claim_race' => runInconsistentClaimRace(
                fixture(), $runToken, (string) getenv('SIMRS_TRANSFER_WORKER'),
                max(0, min(10_000, (int) getenv('SIMRS_TRANSFER_HOLD_MS'))),
            ),
            'verify_inconsistent_claim_race' => verifyInconsistentClaimRace(fixture()),
            'race' => runRace(
                fixture(), $runToken, (string) getenv('SIMRS_TRANSFER_SCENARIO'),
                (string) getenv('SIMRS_TRANSFER_WORKER'),
                max(0, min(10_000, (int) getenv('SIMRS_TRANSFER_HOLD_MS'))),
            ),
            'verify_race' => verifyRace(fixture(), (string) getenv('SIMRS_TRANSFER_SCENARIO')),
            'atomic_failures' => proveAtomicFailures(fixture(), $runToken),
            'down_event' => createDownFixture(fixture(), 'event', $runToken),
            'down_receipt' => createDownFixture(fixture(), 'receipt', $runToken),
            'cleanup_fixture' => cleanupFixture(fixture()),
            'verify' => verifyInvariants(fixture()),
            'reset' => runReset(fixture()),
            default => throw new RuntimeException('worker action refused'),
        };
        protocol('COMMITTED', $result);
    } catch (Throwable $exception) {
        echo json_encode([
            'schema_version' => 1,
            'status' => 'BLOCKED',
            'protocol_state' => 'BLOCKED',
            'scenario' => (string) getenv('SIMRS_TRANSFER_SCENARIO'),
            'worker' => (string) getenv('SIMRS_TRANSFER_WORKER'),
            'exception_class' => get_class($exception),
            'exception_fingerprint' => hash('sha256', get_class($exception)."\0".$exception->getMessage()),
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
    bindings = current_transfer_bindings
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

    event_refusal = run_down_refusal_cycle!('event')
    receipt_refusal = run_down_refusal_cycle!('receipt')

    prepared = run_worker_command!(action: 'prepare', scenario: 'fresh-migration', worker: 'MAIN')
    fixture = prepared.fetch('fixture')
    legacy = run_worker_command!(action: 'legacy', scenario: 'truthful-legacy-baseline', worker: 'LEGACY', fixture: fixture)
    failures = run_worker_command!(
      action: 'atomic_failures', scenario: 'audit-failure-atomic-rollback', worker: 'ROLLBACK', fixture: fixture
    )
    owner_trigger_proof = if @engine == 'postgresql17'
      run_worker_command!(
        action: 'owner_postgres_triggers', scenario: 'append-only-database-trigger-refusal',
        worker: 'OWNER', fixture: fixture, connection_environment: application_environment
      )
    else
      {
        'owner_postgresql_database_trigger_refusals' => [],
        'owner_postgresql_truncate_refusal_kind' => 'NOT_APPLICABLE',
      }
    end
    provision_runtime_identities!
    sequential = run_worker_command!(
      action: 'sequential', scenario: 'successful-atomic-transfer', worker: 'SEQUENTIAL', fixture: fixture
    )
    idempotent_replay = run_unassisted_idempotent_race!(fixture)
    races = RACE_SCENARIOS.to_h { |scenario| [scenario, run_competing_transfer_race!(fixture, scenario)] }
    inconsistent_claim_order = run_inconsistent_claim_order_race!(fixture)
    invariants = run_worker_command!(
      action: 'verify', scenario: 'invariant-verification', worker: 'VERIFY', fixture: fixture
    )
    reset = run_worker_command!(
      action: 'reset', scenario: 'bounded-synthetic-reset', worker: 'RESET', fixture: fixture,
      connection_environment: @reset_application_environment
    )
    assert_no_non_synthetic_patients!
    correlated_refusal = expect_rollback_refusal!('correlated audit evidence remains')

    scenarios = {
      'fresh-migration' => { 'status' => 'PASS', 'fresh_apply' => true },
      'empty-down-reapply' => { 'status' => 'PASS', 'empty_down' => true, 'reapply' => true },
      'populated-event-down-refusal' => event_refusal,
      'populated-receipt-down-refusal' => receipt_refusal,
      'successful-atomic-transfer' => slice_result(sequential, %w[atomic_transfer event_rows_after_transfer receipt_rows_after_transfer success_audit_rows_after_transfer]),
      'exact-idempotent-replay' => idempotent_replay,
      'occupied-target-denial' => slice_result(sequential, %w[occupied_target_denied]),
      'append-only-database-trigger-refusal' => slice_result(
        sequential.merge(owner_trigger_proof),
        %w[runtime_destructive_refusals runtime_destructive_refusal_kind runtime_trigger_removal_refused reset_marker_forgery_refused owner_postgresql_database_trigger_refusals owner_postgresql_truncate_refusal_kind]
      ),
      'truthful-legacy-baseline' => slice_result(legacy, %w[legacy_baseline history_complete fabricated_event_rows backfill_performed]),
      'audit-failure-atomic-rollback' => slice_result(failures, %w[audit_failure_atomic_rollback placement_preserved event_receipt_audit_counts_preserved]),
      'receipt-failure-atomic-rollback' => slice_result(failures, %w[receipt_failure_atomic_rollback placement_preserved event_receipt_audit_counts_preserved]),
    }
    races.each { |scenario, result| scenarios[scenario] = result }
    scenarios['source-retirement-during-claim'] = scenarios.fetch('source-retirement-during-claim').merge(
      'inconsistent_claim_lock_order_subproof' => inconsistent_claim_order
    )
    scenarios.merge!({
      'bounded-synthetic-reset' => slice_result(reset, %w[bounded_synthetic_deletion location_events_removed operation_receipts_removed transfer_audit_rows_retained reset_marker_cleared]),
      'correlated-audit-down-refusal' => { 'status' => 'PASS', 'refusal' => correlated_refusal },
      'invariant-verification' => slice_result(invariants, %w[one_active_bed_claim_per_episode duplicate_active_bed_claims monotonic_gapless_location_sequences location_event_rows operation_receipt_rows success_audit_rows event_receipt_audit_consistency reason_absent_from_success_audit]),
    })
    raise CommandFailed, 'Transfer portability scenario catalogue drifted.' unless scenarios.keys == SCENARIOS

    assert_unchanged_binding!('Inpatient transfer execution bindings', bindings, current_transfer_bindings)
    cleanup!(strict: true)
    remove_worker_file!
    evidence_path = write_transfer_evidence!(
      bindings: bindings,
      engine_binding: engine_binding,
      migration_duration_ms: migration_duration_ms,
      scenarios: scenarios
    )

    {
      'status' => 'PASS',
      'claim' => 'LOCAL_DISPOSABLE_INPATIENT_TRANSFER_PORTABILITY_ONLY',
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
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize disposable local transfer rehearsal."
    end
    rejected = FORBIDDEN_ENVIRONMENT.select { |name| !@operator_environment.fetch(name, '').to_s.strip.empty? }
    unless rejected.empty?
      raise CommandFailed, "Transfer portability rehearsal refuses inherited overrides: #{rejected.join(', ')}."
    end
    super
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    unless SCENARIOS.length == 26 && SCENARIOS.uniq.length == 26
      raise CommandFailed, 'Transfer portability catalogue must contain exactly twenty-six unique scenarios.'
    end
    raise CommandFailed, 'Embedded transfer worker source is unexpectedly small.' unless WORKER_SOURCE.bytesize > 10_000
  end

  def application_environment
    super.merge(
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false',
      'VCLAIM_ENABLED' => 'false',
      'SATUSEHAT_ENABLED' => 'false'
    )
  end

  def current_transfer_bindings
    files = SOURCE_PATHS.to_h do |path|
      [path, Digest::SHA256.file(safe_source_path(path)).hexdigest]
    end
    {
      'files' => files,
      'aggregate_sha256' => Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h)),
      'worker_source_sha256' => Digest::SHA256.hexdigest(WORKER_SOURCE),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)),
    }
  end

  private

  def create_worker_file!
    @worker_tempfile = Tempfile.new(['simrs-inpatient-transfer-', '.php'])
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

  def run_down_refusal_cycle!(kind)
    prepared = run_worker_command!(action: 'prepare', scenario: "populated-#{kind}-down-refusal", worker: "DOWN_#{kind.upcase}")
    fixture = prepared.fetch('fixture')
    run_worker_command!(action: "down_#{kind}", scenario: "populated-#{kind}-down-refusal", worker: 'POPULATE', fixture: fixture)
    refusal = expect_rollback_refusal!('retained evidence exists')
    run_worker_command!(action: 'cleanup_fixture', scenario: "populated-#{kind}-down-refusal", worker: 'CLEANUP', fixture: fixture)
    recorded_artisan!('migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    recorded_artisan!('migrate', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    { 'status' => 'PASS', 'populated_kind' => kind, 'refusal' => refusal, 'reapply' => true }
  end

  def expect_rollback_refusal!(expected)
    arguments = ['migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(
      @runner.process_environment(application_environment),
      @php_binary, File.join(ROOT, 'artisan'), *arguments,
      unsetenv_others: true
    )
    raise CommandFailed, 'Populated transfer migration unexpectedly rolled back.' if status.success?
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
    raise CommandFailed, 'Generated PostgreSQL runtime identities failed the closed pattern.' unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)

    tables = @runner.run!(
      postgres_psql_arguments(@postgres_database) + [
        '--tuples-only', '--no-align', '--command',
        "SELECT tablename FROM pg_tables WHERE schemaname='laravel' ORDER BY tablename"
      ],
      env: postgres_tool_environment
    ).lines.map(&:strip).reject(&:empty?)
    sequences = @runner.run!(
      postgres_psql_arguments(@postgres_database) + [
        '--tuples-only', '--no-align', '--command',
        "SELECT sequencename FROM pg_sequences WHERE schemaname='laravel' ORDER BY sequencename"
      ],
      env: postgres_tool_environment
    ).lines.map(&:strip).reject(&:empty?)
    assert_closed_catalogue!(tables + sequences)

    statements = [
      %(CREATE ROLE "#{runtime}" LOGIN),
      %(CREATE ROLE "#{reset}" LOGIN),
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
    @runner.run!(
      postgres_psql_arguments(@postgres_database) + ['--set', 'ON_ERROR_STOP=1', '--command', statements.join(";\n")+';'],
      env: postgres_tool_environment
    )
    IMMUTABLE_HISTORY_TABLES.each do |table|
      privileges = @runner.run!(
        postgres_psql_arguments(@postgres_database) + [
          '--tuples-only', '--no-align', '--command',
          "SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name='#{table}'"
        ],
        env: postgres_tool_environment
      ).strip
      unless privileges == 'INSERT,SELECT'
        raise CommandFailed, "PostgreSQL runtime history grant drifted for #{table}."
      end
    end

    runtime_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => '')
    reset_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => '')
    @runtime_application_environment = runtime_environment
    @reset_application_environment = reset_environment
    record_runtime_profile!('postgresql-owner', tables.length, sequences.length)
  end

  def provision_mysql_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    raise CommandFailed, 'Generated MySQL runtime identities failed the closed pattern.' unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
    runtime_password = SecureRandom.hex(24)
    reset_password = SecureRandom.hex(24)
    tables = @runner.run!(
      mysql_root_arguments + ['--batch', '--skip-column-names'],
      stdin_data: "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='#{@mysql_database}' ORDER BY TABLE_NAME;"
    ).lines.map(&:strip).reject(&:empty?)
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

    grants = @runner.run!(
      mysql_root_arguments + ['--batch', '--skip-column-names'],
      stdin_data: "SHOW GRANTS FOR '#{runtime}'@'127.0.0.1';"
    ).upcase
    forbidden = %w[DROP ALTER TRIGGER CREATE].select { |privilege| grants.match?(/\b#{privilege}\b/) }
    raise CommandFailed, "Reduced MySQL runtime identity retained forbidden grants: #{forbidden.join(', ')}." unless forbidden.empty?
    IMMUTABLE_HISTORY_TABLES.each do |table|
      expected = "GRANT SELECT, INSERT ON `#{@mysql_database.upcase}`.`#{table.upcase}`"
      raise CommandFailed, "MySQL runtime history grant drifted for #{table}." unless grants.include?(expected)
    end

    @runtime_application_environment = application_environment.merge(
      'DB_USERNAME' => runtime, 'DB_PASSWORD' => runtime_password
    )
    @reset_application_environment = application_environment.merge(
      'DB_USERNAME' => reset, 'DB_PASSWORD' => reset_password
    )
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
    @command_catalog << ['transfer-worker', action, "--scenario=#{scenario}", "--worker=#{worker}"]
    stdout, stderr, status = Open3.capture3(
      @runner.process_environment(worker_environment(
        action: action, scenario: scenario, worker: worker, fixture: fixture, hold_ms: hold_ms,
        connection_environment: connection_environment
      )),
      @php_binary,
      @worker_tempfile.path,
      unsetenv_others: true
    )
    document = stdout.lines.reverse_each.map { |line| parse_worker_line(line) }.compact.first
    unless status.success? && document.is_a?(Hash) && document['status'] == 'PASS' && document['protocol_state'] == 'COMMITTED'
      blocked = stdout.lines.reverse_each.map { |line| parse_worker_line(line) }.compact.first
      fingerprint = blocked.is_a?(Hash) ? blocked['exception_fingerprint'] : nil
      exception_class = blocked.is_a?(Hash) ? blocked['exception_class'] : nil
      diagnostic = fingerprint ? " (class=#{exception_class}, fingerprint=#{fingerprint})" : @runner.sanitize(stderr)
      raise CommandFailed, "Transfer #{action} worker failed#{diagnostic.to_s.empty? ? '' : ": #{diagnostic}"}"
    end
    safe = document.reject { |key, _value| %w[fixture backend_connection_id].include?(key) }
    @protocol_catalog << safe
    @result_catalog << [[action, scenario, worker], safe]
    document
  end

  def start_race_worker!(fixture:, scenario:, worker:, hold_ms:, action: 'race')
    @command_catalog << ['transfer-worker', action, "--scenario=#{scenario}", "--worker=#{worker}"]
    stdin, stdout, stderr, wait_thread = Open3.popen3(
      @runner.process_environment(worker_environment(
        action: action, scenario: scenario,
        worker: worker, fixture: fixture, hold_ms: hold_ms
      )),
      @php_binary,
      @worker_tempfile.path,
      unsetenv_others: true
    )
    stdin.close
    process = Worker.new(
      stdout: stdout,
      stderr: stderr,
      wait_thread: wait_thread,
      stderr_reader: Thread.new { stderr.read },
      scenario: scenario,
      label: worker
    )
    @workers << process
    process
  end

  def worker_environment(action:, scenario:, worker:, fixture:, hold_ms:, connection_environment: nil)
    (connection_environment || @runtime_application_environment || application_environment).merge(
      'SIMRS_REHEARSAL_ROOT' => ROOT,
      'SIMRS_TRANSFER_ACTION' => action,
      'SIMRS_TRANSFER_SCENARIO' => scenario,
      'SIMRS_TRANSFER_WORKER' => worker,
      'SIMRS_TRANSFER_HOLD_MS' => hold_ms.to_s,
      'SIMRS_TRANSFER_RUN_TOKEN' => @run_token,
      'SIMRS_TRANSFER_FIXTURE' => fixture ? Base64.strict_encode64(JSON.generate(fixture)) : '',
    )
  end

  def run_competing_transfer_race!(fixture, scenario)
    return run_unassisted_documentation_race!(fixture, scenario) if scenario.start_with?('documentation-vs-transfer')

    first = start_race_worker!(fixture: fixture, scenario: scenario, worker: 'A', hold_ms: HOLD_MS)
    require_protocol!(await_protocol!(first, 'STARTED'), 'STARTED', scenario, 'A')
    require_protocol!(await_protocol!(first, 'HOLDING'), 'HOLDING', scenario, 'A')
    second = start_race_worker!(fixture: fixture, scenario: scenario, worker: 'B', hold_ms: 0)
    second_started = await_protocol!(second, 'STARTED')
    require_protocol!(second_started, 'STARTED', scenario, 'B')
    wait_observed = observe_real_database_wait!(second_started.fetch('backend_connection_id'))
    first_final = await_final!(first)
    second_final = await_final!(second)
    outcomes = [first_final.fetch('outcome'), second_final.fetch('outcome')].sort
    expected = expected_race_outcomes(scenario)
    raise CommandFailed, "Unexpected durable outcomes for #{scenario}." unless outcomes == expected.sort
    verified = run_worker_command!(action: 'verify_race', scenario: scenario, worker: 'VERIFY', fixture: fixture)
    raise CommandFailed, "Third-connection verification missing for #{scenario}." unless verified['durable_third_connection_assertions'] == true
    result = {
      'status' => 'PASS',
      'proof_kind' => 'OBSERVED_DATABASE_RACE',
      'independent_application_processes' => 2,
      'real_database_wait_observed' => wait_observed,
      'deadlock_observed' => false,
      'outcomes' => outcomes,
      'denial_codes' => [first_final['denial_code'], second_final['denial_code']].compact.sort,
      'durable_third_connection_assertions' => true,
      'durable_assertions' => verified.fetch('assertion_catalog'),
      'protocol_order' => %w[A_STARTED A_HOLDING B_STARTED NATIVE_WAIT_OBSERVED A_COMMITTED B_COMMITTED THIRD_CONNECTION_VERIFIED],
    }
    @protocol_catalog.concat([
      first_final.reject { |key, _value| key == 'backend_connection_id' },
      second_final.reject { |key, _value| key == 'backend_connection_id' },
    ])
    @result_catalog << [['race', scenario], result]
    result
  ensure
    terminate_workers!
  end

  def run_unassisted_idempotent_race!(fixture)
    scenario = 'exact-idempotent-replay'
    delay_installed = false
    run_worker_command!(
      action: 'install_delay_trigger', scenario: scenario, worker: 'OWNER_INSTALL', fixture: fixture,
      connection_environment: application_environment
    )
    delay_installed = true
    first = start_race_worker!(fixture: fixture, scenario: scenario, worker: 'A', hold_ms: 0, action: 'idempotent_race')
    first_started = await_protocol!(first, 'STARTED')
    require_protocol!(first_started, 'STARTED', scenario, 'A')
    delay_observed = observe_service_delay!(first_started.fetch('backend_connection_id'))
    second = start_race_worker!(fixture: fixture, scenario: scenario, worker: 'B', hold_ms: 0, action: 'idempotent_race')
    second_started = await_protocol!(second, 'STARTED')
    require_protocol!(second_started, 'STARTED', scenario, 'B')
    wait_observed = observe_real_database_wait!(second_started.fetch('backend_connection_id'))
    first_final = await_final!(first)
    second_final = await_final!(second)
    outcomes = [first_final.fetch('outcome'), second_final.fetch('outcome')].sort
    raise CommandFailed, 'Same-key transfer race did not produce one apply and one replay.' unless outcomes == %w[APPLIED REPLAYED]
    verified = run_worker_command!(
      action: 'verify_idempotent_race', scenario: scenario, worker: 'VERIFY', fixture: fixture
    )
    result = {
      'status' => 'PASS',
      'proof_kind' => 'UNASSISTED_TWO_PROCESS_SAME_KEY_SERVICE_RACE',
      'independent_application_processes' => 2,
      'harness_prelock' => false,
      'owner_installed_service_delay_observed' => delay_observed,
      'real_database_wait_observed' => wait_observed,
      'deadlock_observed' => false,
      'outcomes' => outcomes,
      'event_rows_after_replay' => verified.fetch('event_rows_after_replay'),
      'receipt_rows_after_replay' => verified.fetch('receipt_rows_after_replay'),
      'success_audit_rows_after_replay' => verified.fetch('success_audit_rows_after_replay'),
      'durable_third_connection_assertions' => true,
      'durable_assertions' => verified.fetch('assertion_catalog'),
      'protocol_order' => %w[A_STARTED OWNER_DELAY_OBSERVED B_STARTED NATIVE_WAIT_OBSERVED A_COMMITTED B_COMMITTED THIRD_CONNECTION_VERIFIED],
    }
    @protocol_catalog.concat([
      first_final.reject { |key, _value| key == 'backend_connection_id' },
      second_final.reject { |key, _value| key == 'backend_connection_id' },
    ])
    @result_catalog << [['idempotent_race', scenario], result]
    result
  ensure
    terminate_workers!
    if delay_installed
      run_worker_command!(
        action: 'drop_delay_trigger', scenario: scenario, worker: 'OWNER_DROP', fixture: fixture,
        connection_environment: application_environment
      )
    end
  end

  def run_inconsistent_claim_order_race!(fixture)
    scenario = 'source-retirement-during-claim'
    first = start_race_worker!(
      fixture: fixture, scenario: scenario, worker: 'A', hold_ms: HOLD_MS,
      action: 'inconsistent_claim_race'
    )
    require_protocol!(await_protocol!(first, 'STARTED'), 'STARTED', scenario, 'A')
    require_protocol!(await_protocol!(first, 'HOLDING'), 'HOLDING', scenario, 'A')
    second = start_race_worker!(
      fixture: fixture, scenario: scenario, worker: 'B', hold_ms: 0,
      action: 'inconsistent_claim_race'
    )
    second_started = await_protocol!(second, 'STARTED')
    require_protocol!(second_started, 'STARTED', scenario, 'B')
    wait_observed = observe_real_database_wait!(second_started.fetch('backend_connection_id'))
    first_final = await_final!(first)
    second_final = await_final!(second)
    outcomes = [first_final.fetch('outcome'), second_final.fetch('outcome')].sort
    raise CommandFailed, 'Inconsistent-claim lock-order race did not deny both operations.' unless outcomes == %w[DENIED DENIED]
    denial_codes = [first_final.fetch('denial_code'), second_final.fetch('denial_code')].sort
    unless denial_codes == %w[bed_occupied target_occupied]
      raise CommandFailed, 'Inconsistent-claim lock-order race returned unexpected denials.'
    end
    verified = run_worker_command!(
      action: 'verify_inconsistent_claim_race', scenario: scenario, worker: 'VERIFY_CLAIM_ORDER', fixture: fixture
    )
    result = {
      'status' => 'PASS',
      'proof_kind' => 'UNASSISTED_INCONSISTENT_CLAIM_LOCK_ORDER_RACE',
      'independent_application_processes' => 2,
      'harness_prelock' => false,
      'claim_lock_method_exercised' => 'InpatientBedClaimGuard::lockClaimsAfterCanonicalMutexes',
      'real_database_wait_observed' => wait_observed,
      'deadlock_observed' => false,
      'outcomes' => outcomes,
      'denial_codes' => denial_codes,
      'durable_third_connection_assertions' => true,
      'durable_assertions' => verified.fetch('assertion_catalog'),
      'protocol_order' => %w[X_STARTED X_DENIED_LOCKS_HELD Y_STARTED NATIVE_WAIT_OBSERVED X_COMMITTED Y_COMMITTED THIRD_CONNECTION_VERIFIED],
    }
    @protocol_catalog.concat([
      first_final.reject { |key, _value| key == 'backend_connection_id' },
      second_final.reject { |key, _value| key == 'backend_connection_id' },
    ])
    @result_catalog << [['inconsistent_claim_race', scenario], result]
    result
  ensure
    terminate_workers!
  end

  def run_unassisted_documentation_race!(fixture, scenario)
    delay_installed = false
    run_worker_command!(
      action: 'install_delay_trigger', scenario: scenario, worker: 'OWNER_INSTALL', fixture: fixture,
      connection_environment: application_environment
    )
    delay_installed = true
    first = start_race_worker!(fixture: fixture, scenario: scenario, worker: 'A', hold_ms: 0)
    first_started = await_protocol!(first, 'STARTED')
    require_protocol!(first_started, 'STARTED', scenario, 'A')
    delay_observed = observe_service_delay!(first_started.fetch('backend_connection_id'))
    second = start_race_worker!(fixture: fixture, scenario: scenario, worker: 'B', hold_ms: 0)
    second_started = await_protocol!(second, 'STARTED')
    require_protocol!(second_started, 'STARTED', scenario, 'B')
    wait_observed = observe_real_database_wait!(second_started.fetch('backend_connection_id'))
    first_final = await_final!(first)
    second_final = await_final!(second)
    outcomes = [first_final.fetch('outcome'), second_final.fetch('outcome')].sort
    raise CommandFailed, "Unexpected durable outcomes for #{scenario}." unless outcomes == %w[APPLIED APPLIED]
    verified = run_worker_command!(action: 'verify_race', scenario: scenario, worker: 'VERIFY', fixture: fixture)
    result = {
      'status' => 'PASS',
      'proof_kind' => 'UNASSISTED_DOCUMENTATION_TRANSFER_SERVICE_RACE',
      'independent_application_processes' => 2,
      'harness_prelock' => false,
      'owner_installed_service_delay_observed' => delay_observed,
      'real_database_wait_observed' => wait_observed,
      'deadlock_observed' => false,
      'outcomes' => outcomes,
      'denial_codes' => [],
      'durable_third_connection_assertions' => true,
      'durable_assertions' => verified.fetch('assertion_catalog'),
      'protocol_order' => %w[A_STARTED OWNER_DELAY_OBSERVED B_STARTED NATIVE_WAIT_OBSERVED A_COMMITTED B_COMMITTED THIRD_CONNECTION_VERIFIED],
    }
    @protocol_catalog.concat([
      first_final.reject { |key, _value| key == 'backend_connection_id' },
      second_final.reject { |key, _value| key == 'backend_connection_id' },
    ])
    @result_catalog << [['race', scenario], result]
    result
  ensure
    terminate_workers!
    if delay_installed
      run_worker_command!(
        action: 'drop_delay_trigger', scenario: scenario, worker: 'OWNER_DROP', fixture: fixture,
        connection_environment: application_environment
      )
    end
  end

  def await_protocol!(worker, state)
    deadline = @clock.call + WAIT_TIMEOUT_SECONDS
    loop do
      remaining = deadline - @clock.call
      if remaining <= 0
        raise CommandFailed, "Transfer worker #{worker.label} did not reach #{state} for #{worker.scenario}."
      end
      next unless IO.select([worker.stdout], nil, nil, [remaining, 0.25].min)
      line = worker.stdout.gets
      raise_worker_failure!(worker, "Transfer worker exited before #{state}.") if line.nil?
      document = parse_worker_line(line)
      next unless document
      if document['status'] == 'BLOCKED'
        raise CommandFailed, "Transfer worker blocked (#{document['exception_class']}, #{document['exception_fingerprint']})."
      end
      return document if document['protocol_state'] == state
    end
  end

  def await_final!(worker)
    final = await_protocol!(worker, 'COMMITTED')
    status = worker.wait_thread.value
    reason = @runner.sanitize(worker.stderr_reader.value)
    unless status.success?
      raise CommandFailed, "Transfer race worker failed#{reason.empty? ? '' : ": #{reason}"}"
    end
    close_worker!(worker)
    final
  end

  def require_protocol!(document, state, scenario, worker)
    valid = document['status'] == 'PASS' && document['protocol_state'] == state
    valid &&= document['scenario'] == scenario
    valid &&= document['worker'] == worker
    raise CommandFailed, "Transfer worker protocol mismatch at #{state}." unless valid
  end

  def expected_race_outcomes(scenario)
    return %w[DENIED DENIED] if scenario == 'opposite-direction-swaps'
    return %w[APPLIED APPLIED] if scenario.start_with?('documentation-vs-transfer')

    %w[APPLIED DENIED]
  end

  def parse_worker_line(line)
    document = JSON.parse(line.strip)
    document if document.is_a?(Hash) && document['schema_version'] == 1
  rescue JSON::ParserError
    nil
  end

  def raise_worker_failure!(worker, message)
    status = worker.wait_thread.value
    reason = @runner.sanitize(worker.stderr_reader.value)
    raise CommandFailed, "#{message} exit=#{status.exitstatus}#{reason.empty? ? '' : ": #{reason}"}"
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

  def observe_real_database_wait!(connection_id)
    id = Integer(connection_id.to_s, 10)
    raise CommandFailed, 'Transfer worker returned an invalid backend connection ID.' unless id.positive?
    deadline = @clock.call + 3.0
    loop do
      observed = @engine == 'postgresql17' ? postgres_wait_observed?(id) : mysql_wait_observed?(id)
      return true if observed
      raise CommandFailed, 'No native database wait was observed for the competing transfer.' if @clock.call >= deadline
      sleep 0.05
    end
  rescue ArgumentError, TypeError
    raise CommandFailed, 'Transfer worker returned an invalid backend connection ID.'
  end

  def observe_service_delay!(connection_id)
    id = Integer(connection_id.to_s, 10)
    raise CommandFailed, 'Transfer worker returned an invalid backend connection ID.' unless id.positive?
    deadline = @clock.call + 3.0
    loop do
      observed = @engine == 'postgresql17' ? postgres_delay_observed?(id) : mysql_delay_observed?(id)
      return true if observed
      raise CommandFailed, 'Owner-installed service delay was not observed.' if @clock.call >= deadline
      sleep 0.05
    end
  rescue ArgumentError, TypeError
    raise CommandFailed, 'Transfer worker returned an invalid backend connection ID.'
  end

  def postgres_delay_observed?(id)
    output = @runner.run!(
      postgres_psql_arguments(@postgres_database) + [
        '--tuples-only', '--no-align', '--command',
        "SELECT CASE WHEN wait_event='PgSleep' THEN '1' ELSE '0' END FROM pg_stat_activity WHERE pid=#{id}"
      ],
      env: postgres_tool_environment
    ).strip
    output == '1'
  end

  def mysql_delay_observed?(id)
    output = @runner.run!(
      mysql_root_arguments + ['--batch', '--skip-column-names'],
      stdin_data: "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=#{id} AND STATE='User sleep';"
    ).strip
    Integer(output, 10).positive?
  rescue ArgumentError
    false
  end

  def postgres_wait_observed?(id)
    output = @runner.run!(
      postgres_psql_arguments(@postgres_database) + [
        '--tuples-only', '--no-align', '--command',
        "SELECT CASE WHEN wait_event_type='Lock' AND cardinality(pg_blocking_pids(pid))>0 THEN '1' ELSE '0' END FROM pg_stat_activity WHERE pid=#{id}"
      ],
      env: postgres_tool_environment
    ).strip
    output == '1'
  end

  def mysql_wait_observed?(id)
    output = @runner.run!(
      mysql_root_arguments + ['--batch', '--skip-column-names'],
      stdin_data: <<~SQL
        SELECT COUNT(*)
        FROM performance_schema.data_lock_waits AS waits
        INNER JOIN performance_schema.threads AS threads
          ON threads.thread_id = waits.requesting_thread_id
        WHERE threads.processlist_id = #{id};
      SQL
    ).strip
    Integer(output, 10).positive?
  rescue ArgumentError
    false
  end

  def slice_result(document, keys)
    { 'status' => 'PASS' }.merge(document.slice(*keys))
  end

  def write_transfer_evidence!(bindings:, engine_binding:, migration_duration_ms:, scenarios:)
    assert_evidence_directory!
    path = File.join(
      EVIDENCE_DIRECTORY,
      "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-inpatient-transfer-#{SecureRandom.hex(6)}.json"
    )
    evidence = {
      'schema_version' => 1,
      'kind' => EVIDENCE_KIND,
      'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_INPATIENT_TRANSFER_PORTABILITY_ONLY',
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
      'migration' => {
        'fresh_apply' => 'PASS',
        'duration_ms_observed' => migration_duration_ms,
      },
      'scenarios' => scenarios,
      'cleanup' => {
        'database_removed' => true,
        'temporary_server_removed' => true,
        'temporary_user_state_removed' => true,
        'temporary_worker_removed' => true,
      },
      'open_boundaries' => [
        'This is local disposable-engine evidence, not hosted migration, hosted UAT, deployment or production evidence.',
        'Passing technical checks does not establish clinical, RMIK, product-owner or parity acceptance.',
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
    unless ARGV.length == 1 && LocalInpatientTransferPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalInpatientTransferPortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalInpatientTransferPortabilityRehearsal::CommandFailed => e
    warn "inpatient transfer portability rehearsal failed: #{e.message}"
    exit 1
  end
end
