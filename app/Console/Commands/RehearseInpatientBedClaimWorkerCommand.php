<?php

namespace App\Console\Commands;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Database\SchemaQualifier;
use App\Support\Registration\DailyQueueAllocator;
use App\Support\Registration\InpatientBedClaimGuard;
use App\Support\Registration\InpatientBedUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

final class RehearseInpatientBedClaimWorkerCommand extends Command
{
    public const CONFIRMATION = 'YES_DISPOSABLE_OWNED_POSTGRES17_INPATIENT_BED_CLAIM';

    public const DATABASE_PATTERN = '/\Asimrs_bedclaim_[0-9a-f]{12}\z/';

    public const QUEUE_DATE = '2030-02-22';

    protected $signature = 'ops:rehearse-inpatient-bed-claim-worker
        {--run-token= : Generated 12-hex token bound to the disposable database}
        {--phase= : same, distinct, or queue}
        {--worker= : A or B}
        {--bed-code= : Closed synthetic bed code}
        {--hold-ms=0 : Time to hold the transaction after writing, at most 5000 ms}
        {--allocate-queue : Exercise the daily queue allocator inside the claim transaction}
        {--confirm-owned-local-synthetic : Confirm the harness-owned local synthetic PostgreSQL boundary}';

    protected $description = 'Internal worker for the disposable PostgreSQL inpatient bed-claim rehearsal';

    public function handle(
        InpatientBedClaimGuard $bedClaimGuard,
        DailyQueueAllocator $dailyQueueAllocator,
        AuditRecorder $auditRecorder,
    ): int {
        $started = hrtime(true);

        try {
            [$runToken, $phase, $worker, $bedCode, $holdMs, $allocateQueue] = $this->validatedOptions();
            $actor = $this->assertSafeBoundary($runToken);
            $applicationName = sprintf('simrs_bedclaim_%s_%s_%s', $runToken, $phase, strtolower($worker));
            DB::selectOne("SELECT set_config('application_name', ?, false)", [$applicationName]);
            $backendPid = (int) DB::scalar('SELECT pg_backend_pid()');
            $result = null;

            try {
                DB::transaction(function () use (
                    $bedClaimGuard,
                    $dailyQueueAllocator,
                    $auditRecorder,
                    $actor,
                    $runToken,
                    $phase,
                    $worker,
                    $bedCode,
                    $holdMs,
                    $allocateQueue,
                    $backendPid,
                    &$result,
                ): void {
                    DB::statement("SET LOCAL lock_timeout = '8s'");
                    $bedClaimGuard->assertAvailable($bedCode);

                    $registeredAt = CarbonImmutable::parse(
                        self::QUEUE_DATE.' 10:00:00',
                        (string) config('app.timezone', 'Asia/Jakarta'),
                    )->addSeconds($phase === 'queue' ? ($worker === 'A' ? 1 : 2) : 0);
                    $allocation = $allocateQueue ? $dailyQueueAllocator->allocate($registeredAt) : null;
                    $patient = Patient::query()->create([
                        'medical_record_number' => sprintf('SYNTH-BC-%s-%s-%s', $runToken, strtoupper($phase[0]), $worker),
                        'full_name' => sprintf('Pasien Sintetis Bed Claim %s %s', ucfirst($phase), $worker),
                        'date_of_birth' => '1990-01-01',
                        'sex' => Patient::SEX_TIDAK_DIKETAHUI,
                        'is_synthetic' => true,
                        'created_by_user_id' => $actor->id,
                    ]);
                    $encounter = Encounter::query()->create([
                        'patient_id' => $patient->id,
                        'care_setting' => Encounter::CARE_SETTING_INPATIENT,
                        'status' => Encounter::STATUS_REGISTERED,
                        'clinic_name' => 'Bangsal Rehearsal Sintetis',
                        'ward_name' => 'Bangsal Rehearsal Sintetis',
                        'ward_class' => 'KELAS_REHEARSAL',
                        'bed_code' => $bedCode,
                        'continue_from' => Encounter::CONTINUE_LANGSUNG,
                        'visit_date' => self::QUEUE_DATE,
                        'payer_type' => Encounter::PAYER_UMUM,
                        'queue_date' => self::QUEUE_DATE,
                        'queue_number' => $allocation?->queueNumber,
                        'registered_at' => $registeredAt,
                        'registered_by_user_id' => $actor->id,
                        'chief_complaint' => 'Rehearsal konkurensi tempat tidur sintetis.',
                        'booking_code' => sprintf('SYNTH-BC-%s-%s-%s', $runToken, strtoupper($phase[0]), $worker),
                    ]);

                    if ($allocateQueue) {
                        $event = $auditRecorder->record(
                            action: 'patient.register',
                            resourceType: 'encounter',
                            resourceId: $encounter->public_id,
                            actor: $actor,
                            outcome: 'SUCCESS',
                            metadata: [
                                'care_setting' => Encounter::CARE_SETTING_INPATIENT,
                                'patient_public_id' => $patient->public_id,
                                'ward_name' => $encounter->ward_name,
                                'ward_class' => $encounter->ward_class,
                                'bed_code' => $encounter->bed_code,
                                'continue_from' => $encounter->continue_from,
                                'payer_type' => $encounter->payer_type,
                                'queue_date' => $encounter->queue_date,
                                'queue_number' => $encounter->queue_number,
                            ],
                            includeRequestFingerprint: false,
                        );
                        if ($event === null) {
                            throw new RuntimeException('Bed-claim rehearsal audit write was rejected.');
                        }
                    }

                    $result = [
                        'schema_version' => 1,
                        'status' => 'PASS',
                        'phase' => $phase,
                        'worker' => $worker,
                        'outcome' => 'COMMITTED',
                        'backend_pid' => $backendPid,
                        'bed_code' => $bedCode,
                        'queue_number' => $allocation?->queueNumber,
                    ];

                    if ($holdMs > 0) {
                        DB::selectOne('SELECT pg_sleep(?)', [$holdMs / 1000]);
                    }
                });
            } catch (InpatientBedUnavailable) {
                $result = [
                    'schema_version' => 1,
                    'status' => 'PASS',
                    'phase' => $phase,
                    'worker' => $worker,
                    'outcome' => 'REJECTED_OCCUPIED',
                    'backend_pid' => $backendPid,
                    'bed_code' => $bedCode,
                    'queue_number' => null,
                ];
            }

            if (! is_array($result)) {
                throw new RuntimeException('Bed-claim rehearsal worker produced no result.');
            }

            $result['elapsed_ms'] = $this->elapsedMilliseconds($started);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->emitBlockedResult();

            return self::FAILURE;
        }
    }

    /** @return array{string, string, string, string, int, bool} */
    private function validatedOptions(): array
    {
        if ($this->option('confirm-owned-local-synthetic') !== true
            || getenv('SIMRS_BED_CLAIM_REHEARSAL_CONFIRM') !== self::CONFIRMATION) {
            throw new RuntimeException('Bed-claim rehearsal requires both explicit local confirmations.');
        }

        $runToken = trim((string) $this->option('run-token'));
        if (preg_match('/\A[0-9a-f]{12}\z/', $runToken) !== 1) {
            throw new RuntimeException('Bed-claim rehearsal run token is invalid.');
        }

        $phase = trim((string) $this->option('phase'));
        if (! in_array($phase, ['same', 'distinct', 'queue'], true)) {
            throw new RuntimeException('Bed-claim rehearsal phase is invalid.');
        }

        $worker = strtoupper(trim((string) $this->option('worker')));
        if (! in_array($worker, ['A', 'B'], true)) {
            throw new RuntimeException('Bed-claim rehearsal worker is invalid.');
        }

        $bedCode = trim((string) $this->option('bed-code'));
        if (preg_match('/\ASYNTH-BC-(SAME|DISTINCT|QUEUE)-[AB]\z/', $bedCode) !== 1) {
            throw new RuntimeException('Bed-claim rehearsal bed code is outside its closed synthetic namespace.');
        }

        $holdValue = trim((string) $this->option('hold-ms'));
        if (preg_match('/\A[0-9]{1,4}\z/', $holdValue) !== 1 || (int) $holdValue > 5000) {
            throw new RuntimeException('Bed-claim rehearsal hold duration is invalid.');
        }

        $allocateQueue = $this->option('allocate-queue') === true;
        if (($phase === 'queue') !== $allocateQueue) {
            throw new RuntimeException('Only the queue phase may exercise daily queue allocation.');
        }

        return [$runToken, $phase, $worker, $bedCode, (int) $holdValue, $allocateQueue];
    }

    private function assertSafeBoundary(string $runToken): User
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Bed-claim rehearsal requires SIMULATION synthetic-only mode.');
        }
        if (config('database.default') !== 'pgsql' || DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Bed-claim rehearsal requires PostgreSQL.');
        }
        if ((int) DB::scalar('SHOW server_version_num') !== 170_010) {
            throw new RuntimeException('Bed-claim rehearsal requires PostgreSQL 17.10 exactly.');
        }

        $database = (string) DB::scalar('SELECT current_database()');
        if (preg_match(self::DATABASE_PATTERN, $database) !== 1 || $database !== 'simrs_bedclaim_'.$runToken) {
            throw new RuntimeException('Bed-claim rehearsal database is outside its generated namespace.');
        }
        if (SchemaQualifier::primarySchema() !== 'laravel') {
            throw new RuntimeException('Bed-claim rehearsal requires the private laravel schema.');
        }
        if (DB::scalar('SELECT inet_server_addr() IS NULL') !== true) {
            throw new RuntimeException('Bed-claim rehearsal requires its harness-owned Unix socket.');
        }
        if ((string) DB::scalar('SHOW listen_addresses') !== '') {
            throw new RuntimeException('Bed-claim rehearsal refuses a TCP-listening PostgreSQL server.');
        }
        if (config('mail.default') !== 'array' || config('queue.default') !== 'sync') {
            throw new RuntimeException('Bed-claim rehearsal requires disabled external delivery.');
        }
        if (Patient::query()->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Bed-claim rehearsal refuses non-synthetic patient data.');
        }

        $actor = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->first();
        if (! $actor instanceof User) {
            throw new RuntimeException('Bed-claim rehearsal actor is missing.');
        }

        return $actor;
    }

    private function elapsedMilliseconds(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    private function emitBlockedResult(): void
    {
        try {
            $this->line(json_encode([
                'schema_version' => 1,
                'status' => 'BLOCKED',
                'error_code' => 'INPATIENT_BED_CLAIM_REHEARSAL_WORKER_FAILED',
            ], JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            $this->error('BLOCKED inpatient bed-claim rehearsal worker.');
        }
    }
}
