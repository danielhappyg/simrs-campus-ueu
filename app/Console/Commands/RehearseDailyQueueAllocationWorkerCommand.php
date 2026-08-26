<?php

namespace App\Console\Commands;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Database\SchemaQualifier;
use App\Support\Operations\ExpectedQueueAllocationRehearsalRollback;
use App\Support\Registration\DailyQueueAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

class RehearseDailyQueueAllocationWorkerCommand extends Command
{
    public const CONFIRMATION = 'YES_DISPOSABLE_LOCAL_POSTGRES17_QUEUE_ALLOCATION';

    public const DATABASE_PATTERN = '/\Asimrs_queuealloc_[0-9a-f]{12}\z/';

    public const REHEARSAL_QUEUE_DATE = '2030-01-15';

    public const START_BARRIER_KEY = 8_260_015;

    protected $signature = 'ops:rehearse-daily-queue-worker
        {--run-token= : Generated 12-hex token bound to the disposable database}
        {--worker= : Positive worker number}
        {--mode=commit : commit or rollback}
        {--hold-ms=0 : Time to hold the allocator transaction, at most 5000 ms}
        {--barrier : Wait on the rehearsal start barrier before allocation}
        {--confirm-local-synthetic : Confirm this is a disposable local synthetic PostgreSQL 17 database}';

    protected $description = 'Internal worker for the fail-closed local PostgreSQL daily-queue rehearsal';

    public function handle(DailyQueueAllocator $allocator, AuditRecorder $auditRecorder): int
    {
        try {
            [$runToken, $worker, $mode, $holdMs] = $this->validatedOptions();
            $actor = $this->assertSafeBoundary($runToken);
            $result = null;

            try {
                DB::transaction(function () use (
                    $allocator,
                    $auditRecorder,
                    $actor,
                    $runToken,
                    $worker,
                    $mode,
                    $holdMs,
                    &$result,
                ): void {
                    $applicationName = sprintf('simrs_queuealloc_%s_%04d', $runToken, $worker);
                    DB::selectOne("SELECT set_config('application_name', ?, true)", [$applicationName]);

                    if ($this->option('barrier')) {
                        DB::selectOne('SELECT pg_advisory_xact_lock_shared(?)', [self::START_BARRIER_KEY]);
                    }

                    $backendPid = (int) DB::scalar('SELECT pg_backend_pid()');
                    $registeredAt = CarbonImmutable::parse(
                        self::REHEARSAL_QUEUE_DATE.' 10:00:00',
                        (string) config('app.timezone', 'Asia/Jakarta'),
                    )->addSeconds($worker);
                    $allocation = $allocator->allocate($registeredAt);

                    $patient = Patient::query()->create([
                        'medical_record_number' => sprintf('SYNTH-QA-%s-%04d', $runToken, $worker),
                        'full_name' => sprintf('Pasien Sintetis Rehearsal %04d', $worker),
                        'date_of_birth' => '1990-01-01',
                        'sex' => Patient::SEX_TIDAK_DIKETAHUI,
                        'is_synthetic' => true,
                        'created_by_user_id' => $actor->id,
                    ]);

                    $encounter = Encounter::query()->create([
                        'patient_id' => $patient->id,
                        'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
                        'status' => Encounter::STATUS_REGISTERED,
                        'clinic_name' => 'Poliklinik Rehearsal Sintetis',
                        'visit_date' => self::REHEARSAL_QUEUE_DATE,
                        'admission_mode' => Encounter::ADMISSION_DATANG_SENDIRI,
                        'payer_type' => Encounter::PAYER_UMUM,
                        'booking_code' => sprintf('SYNTH-QA-%s-%04d', $runToken, $worker),
                        'queue_date' => $allocation->queueDate,
                        'queue_number' => $allocation->queueNumber,
                        'registered_at' => $registeredAt,
                        'registered_by_user_id' => $actor->id,
                        'chief_complaint' => 'Rehearsal konkurensi sintetis.',
                    ]);

                    $event = $auditRecorder->record(
                        action: 'patient.register',
                        resourceType: 'encounter',
                        resourceId: $encounter->public_id,
                        actor: $actor,
                        outcome: 'SUCCESS',
                        metadata: [
                            'patient_public_id' => $patient->public_id,
                            'clinic_name' => $encounter->clinic_name,
                            'doctor_name' => null,
                            'schedule_label' => null,
                            'payer_type' => $encounter->payer_type,
                            'queue_date' => $encounter->queue_date,
                            'queue_number' => $encounter->queue_number,
                        ],
                        includeRequestFingerprint: false,
                    );

                    if ($event === null) {
                        throw new RuntimeException('Rehearsal audit write was rejected.');
                    }

                    $result = [
                        'schema_version' => 1,
                        'status' => 'PASS',
                        'worker' => $worker,
                        'mode' => $mode,
                        'queue_number' => $allocation->queueNumber,
                        'encounter_public_id' => $encounter->public_id,
                        'backend_pid' => $backendPid,
                        'committed' => $mode === 'commit',
                        'rolled_back' => $mode === 'rollback',
                    ];

                    if ($holdMs > 0) {
                        DB::selectOne('SELECT pg_sleep(?)', [$holdMs / 1000]);
                    }

                    if ($mode === 'rollback') {
                        throw new ExpectedQueueAllocationRehearsalRollback('Expected rehearsal rollback.');
                    }
                });
            } catch (ExpectedQueueAllocationRehearsalRollback) {
                if (! is_array($result) || $result['mode'] !== 'rollback') {
                    throw new RuntimeException('Expected rollback result was not captured.');
                }
            }

            if (! is_array($result)) {
                throw new RuntimeException('Rehearsal worker did not produce a result.');
            }

            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->emitBlockedResult();

            return self::FAILURE;
        }
    }

    /** @return array{string, int, string, int} */
    private function validatedOptions(): array
    {
        if ($this->option('confirm-local-synthetic') !== true
            || getenv('SIMRS_QUEUE_ALLOC_REHEARSAL_CONFIRM') !== self::CONFIRMATION) {
            throw new RuntimeException('Daily queue rehearsal requires both explicit local confirmations.');
        }

        $runToken = trim((string) $this->option('run-token'));
        if (preg_match('/\A[0-9a-f]{12}\z/', $runToken) !== 1) {
            throw new RuntimeException('Daily queue rehearsal run token is invalid.');
        }

        $workerValue = trim((string) $this->option('worker'));
        if (preg_match('/\A[1-9][0-9]{0,3}\z/', $workerValue) !== 1) {
            throw new RuntimeException('Daily queue rehearsal worker number is invalid.');
        }
        $worker = (int) $workerValue;

        $mode = trim((string) $this->option('mode'));
        if (! in_array($mode, ['commit', 'rollback'], true)) {
            throw new RuntimeException('Daily queue rehearsal mode is invalid.');
        }

        $holdValue = trim((string) $this->option('hold-ms'));
        if (preg_match('/\A[0-9]{1,4}\z/', $holdValue) !== 1 || (int) $holdValue > 5000) {
            throw new RuntimeException('Daily queue rehearsal hold duration is invalid.');
        }

        return [$runToken, $worker, $mode, (int) $holdValue];
    }

    private function assertSafeBoundary(string $runToken): User
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Daily queue rehearsal requires SIMULATION synthetic-only mode.');
        }

        if (config('database.default') !== 'pgsql' || DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Daily queue rehearsal requires PostgreSQL.');
        }

        $version = (int) DB::scalar('SHOW server_version_num');
        if (intdiv($version, 10_000) !== 17) {
            throw new RuntimeException('Daily queue rehearsal requires PostgreSQL major version 17.');
        }

        $database = (string) DB::scalar('SELECT current_database()');
        if (preg_match(self::DATABASE_PATTERN, $database) !== 1 || $database !== 'simrs_queuealloc_'.$runToken) {
            throw new RuntimeException('Daily queue rehearsal database is outside its generated namespace.');
        }

        if (SchemaQualifier::primarySchema() !== 'laravel') {
            throw new RuntimeException('Daily queue rehearsal requires the private laravel schema.');
        }

        $serverIsLocal = DB::scalar(<<<'SQL'
            SELECT inet_server_addr() IS NULL
                OR inet_server_addr() <<= inet '127.0.0.0/8'
                OR inet_server_addr() = inet '::1'
            SQL);
        if (filter_var($serverIsLocal, FILTER_VALIDATE_BOOLEAN) !== true) {
            throw new RuntimeException('Daily queue rehearsal refuses a non-local PostgreSQL server.');
        }

        if (Patient::query()->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Daily queue rehearsal refuses non-synthetic patient data.');
        }

        $actor = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->first();
        if (! $actor instanceof User) {
            throw new RuntimeException('Daily queue rehearsal actor is missing.');
        }

        return $actor;
    }

    private function emitBlockedResult(): void
    {
        try {
            $this->line(json_encode([
                'schema_version' => 1,
                'status' => 'BLOCKED',
                'error_code' => 'QUEUE_ALLOCATION_REHEARSAL_WORKER_FAILED',
            ], JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            $this->error('BLOCKED queue allocation rehearsal worker.');
        }
    }
}
