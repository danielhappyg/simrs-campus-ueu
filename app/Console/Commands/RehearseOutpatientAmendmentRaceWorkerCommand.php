<?php

namespace App\Console\Commands;

use App\Models\Encounter;
use App\Models\OutpatientAmendmentOperationReceipt;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientClinicalDocumentAddendum;
use App\Models\OutpatientClinicalDocumentAddendumVersion;
use App\Models\OutpatientClinicalDocumentVersion;
use App\Models\OutpatientPostClosureAmendmentRequest;
use App\Models\OutpatientRmCompletenessReview;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Clinical\OutpatientAmendmentDenied;
use App\Support\Clinical\OutpatientPostClosureAmendmentService;
use App\Support\Database\SchemaQualifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

final class RehearseOutpatientAmendmentRaceWorkerCommand extends Command
{
    public const CONFIRMATION = 'YES_DISPOSABLE_LOCAL_OUTPATIENT_AMENDMENT_CONCURRENCY';

    public const POSTGRES_DATABASE_PATTERN = '/\Asimrs_portability_pg_[0-9a-f]{12}\z/';

    public const MYSQL_DATABASE_PATTERN = '/\Asimrs_portability_my_[0-9a-f]{12}\z/';

    /** @var list<string> */
    public const SCENARIOS = [
        'same-key-same-digest',
        'same-key-different-digest-across-encounters',
        'competing-decisions',
        'competing-addendum-writes',
        'competing-finalization',
    ];

    protected $signature = 'ops:rehearse-outpatient-amendment-race-worker
        {--run-token= : Twelve-hex token bound to the harness-owned disposable database}
        {--action= : prepare, operate, or verify}
        {--scenario= : One closed outpatient amendment race scenario}
        {--worker=A : A or B}
        {--hold-ms=0 : Outer-transaction hold after an applied operation, at most 5000 ms}
        {--confirm-local-synthetic : Confirm the disposable local synthetic boundary}';

    protected $description = 'Internal worker for the disposable cross-engine outpatient amendment race rehearsal';

    public function handle(OutpatientPostClosureAmendmentService $service): int
    {
        try {
            [$runToken, $action, $scenario, $worker, $holdMs] = $this->validatedOptions();
            $this->assertSafeBoundary($runToken);

            if ($action === 'prepare') {
                $result = $this->prepareScenario($service, $runToken, $scenario);
            } elseif ($action === 'verify') {
                $result = $this->verifyScenario($runToken, $scenario);
            } else {
                $result = $this->operate($service, $runToken, $scenario, $worker, $holdMs);
            }

            $this->emit($result);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->emitBlockedResult($exception);

            return self::FAILURE;
        }
    }

    /** @return array{string, string, string, string, int} */
    private function validatedOptions(): array
    {
        if ($this->option('confirm-local-synthetic') !== true
            || getenv('SIMRS_OUTPATIENT_AMENDMENT_RACE_CONFIRM') !== self::CONFIRMATION) {
            throw new RuntimeException('Outpatient amendment race worker requires both explicit local confirmations.');
        }

        $runToken = trim((string) $this->option('run-token'));
        if (preg_match('/\A[0-9a-f]{12}\z/', $runToken) !== 1) {
            throw new RuntimeException('Outpatient amendment race run token is invalid.');
        }

        $action = trim((string) $this->option('action'));
        if (! in_array($action, ['prepare', 'operate', 'verify'], true)) {
            throw new RuntimeException('Outpatient amendment race action is invalid.');
        }

        $scenario = trim((string) $this->option('scenario'));
        if (! in_array($scenario, self::SCENARIOS, true)) {
            throw new RuntimeException('Outpatient amendment race scenario is invalid.');
        }

        $worker = trim((string) $this->option('worker'));
        if (! in_array($worker, ['A', 'B'], true)) {
            throw new RuntimeException('Outpatient amendment race worker label is invalid.');
        }

        $holdValue = trim((string) $this->option('hold-ms'));
        if (preg_match('/\A[0-9]{1,4}\z/', $holdValue) !== 1 || (int) $holdValue > 5000) {
            throw new RuntimeException('Outpatient amendment race hold duration is invalid.');
        }

        return [$runToken, $action, $scenario, $worker, (int) $holdValue];
    }

    private function assertSafeBoundary(string $runToken): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Outpatient amendment race worker requires SIMULATION synthetic-only mode.');
        }
        if (config('mail.default') !== 'array' || config('queue.default') !== 'sync') {
            throw new RuntimeException('Outpatient amendment race worker requires non-egress mail and queue drivers.');
        }
        if (config('break_glass.mode') !== 'off'
            || config('break_glass.global_disabled') !== true) {
            throw new RuntimeException('Outpatient amendment race worker requires break-glass to remain disabled.');
        }
        foreach (['BPJS_INTEGRATION_ENABLED', 'VCLAIM_ENABLED', 'SATUSEHAT_ENABLED'] as $key) {
            if (getenv($key) !== 'false') {
                throw new RuntimeException('Outpatient amendment race worker refuses live integration configuration.');
            }
        }

        $driver = DB::connection()->getDriverName();
        $database = (string) DB::connection()->getDatabaseName();
        if ($driver === 'pgsql') {
            $version = (string) DB::scalar('SHOW server_version_num');
            $local = filter_var(DB::scalar(<<<'SQL'
                SELECT inet_server_addr() IS NULL
                    OR inet_server_addr() <<= inet '127.0.0.0/8'
                    OR inet_server_addr() = inet '::1'
                SQL), FILTER_VALIDATE_BOOLEAN) === true;
            if ($version !== '170010'
                || preg_match(self::POSTGRES_DATABASE_PATTERN, $database) !== 1
                || $database !== 'simrs_portability_pg_'.$runToken
                || ! $local
                || SchemaQualifier::primarySchema() !== 'laravel') {
                throw new RuntimeException('Outpatient amendment race worker refused the PostgreSQL boundary.');
            }
        } elseif ($driver === 'mysql') {
            $version = (string) DB::scalar('SELECT VERSION()');
            if (! str_starts_with($version, '8.4.11')
                || preg_match(self::MYSQL_DATABASE_PATTERN, $database) !== 1
                || $database !== 'simrs_portability_my_'.$runToken) {
                throw new RuntimeException('Outpatient amendment race worker refused the MySQL boundary.');
            }
        } else {
            throw new RuntimeException('Outpatient amendment race worker requires an approved disposable engine.');
        }

        if (Patient::query()->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Outpatient amendment race worker refuses non-synthetic patient data.');
        }
    }

    /** @return array<string, mixed> */
    private function prepareScenario(
        OutpatientPostClosureAmendmentService $service,
        string $runToken,
        string $scenario,
    ): array {
        DB::transaction(function () use ($service, $runToken, $scenario): void {
            $this->assertScenarioAbsent($runToken, $scenario);
            $actors = $this->actors($runToken);
            $primary = $this->createClosedEncounterWithEvidence($runToken, $scenario, 'A', $actors['requester'], $actors['rmik']);

            if ($scenario === 'same-key-different-digest-across-encounters') {
                $this->createClosedEncounterWithEvidence($runToken, $scenario, 'B', $actors['requester'], $actors['rmik']);
            }

            if (in_array($scenario, ['competing-decisions', 'competing-addendum-writes', 'competing-finalization'], true)) {
                $request = $service->submit(
                    encounter: $primary['encounter'],
                    actor: $actors['requester'],
                    originalDocumentPublicId: $primary['document']->public_id,
                    originalDocumentVersion: $primary['document']->version,
                    reasonCode: OutpatientPostClosureAmendmentRequest::REASON_CLINICAL_CORRECTION,
                    note: 'Persiapan lokal sintetis untuk rehearsal konkurensi.',
                    idempotencyKey: $this->preparationKey($runToken, $scenario, 'submit'),
                    requestCorrelationId: null,
                )->request;

                if (in_array($scenario, ['competing-addendum-writes', 'competing-finalization'], true)) {
                    $request = $service->decide(
                        amendmentRequest: $request,
                        actor: $actors['approver_a'],
                        decision: OutpatientPostClosureAmendmentRequest::STATE_APPROVED,
                        decisionNote: 'Persetujuan persiapan rehearsal lokal sintetis.',
                        expectedVersion: 1,
                        idempotencyKey: $this->preparationKey($runToken, $scenario, 'decide'),
                        requestCorrelationId: null,
                    )->request;
                }

                if ($scenario === 'competing-finalization') {
                    $service->saveAddendum(
                        amendmentRequest: $request,
                        actor: $actors['requester'],
                        fields: ['addendum_text' => 'Draf sintetis stabil sebelum finalisasi bersaing.'],
                        expectedVersion: 0,
                        idempotencyKey: $this->preparationKey($runToken, $scenario, 'write'),
                        requestCorrelationId: null,
                    );
                }
            }
        }, 1);

        return [
            'schema_version' => 1,
            'status' => 'PASS',
            'protocol_state' => 'PREPARED',
            'scenario' => $scenario,
            'synthetic_only' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function operate(
        OutpatientPostClosureAmendmentService $service,
        string $runToken,
        string $scenario,
        string $worker,
        int $holdMs,
    ): array {
        $backendConnectionId = $this->backendConnectionId();
        $this->setConnectionLabel($runToken, $scenario, $worker);
        $this->emit([
            'schema_version' => 1,
            'status' => 'PASS',
            'protocol_state' => 'STARTED',
            'scenario' => $scenario,
            'worker' => $worker,
            'backend_connection_id' => $backendConnectionId,
        ]);

        $started = hrtime(true);
        $result = DB::transaction(function () use ($service, $runToken, $scenario, $worker, $holdMs, $backendConnectionId): array {
            try {
                $operation = $this->performOperation($service, $runToken, $scenario, $worker);
            } catch (OutpatientAmendmentDenied $denial) {
                return [
                    'outcome' => 'DENIED',
                    'reason' => $denial->reason,
                    'replayed' => false,
                ];
            }

            if ($holdMs > 0 && $operation['outcome'] === 'APPLIED') {
                $this->emit([
                    'schema_version' => 1,
                    'status' => 'PASS',
                    'protocol_state' => 'HOLDING',
                    'scenario' => $scenario,
                    'worker' => $worker,
                    'backend_connection_id' => $backendConnectionId,
                    'outcome' => $operation['outcome'],
                ]);
                $this->engineNativeSleep($holdMs);
            }

            return $operation;
        }, 1);

        return [
            'schema_version' => 1,
            'status' => 'PASS',
            'protocol_state' => 'COMMITTED',
            'scenario' => $scenario,
            'worker' => $worker,
            'backend_connection_id' => $backendConnectionId,
            'outcome' => $result['outcome'],
            'reason' => $result['reason'],
            'replayed' => $result['replayed'],
            'elapsed_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ];
    }

    /** @return array{outcome: string, reason: string|null, replayed: bool} */
    private function performOperation(
        OutpatientPostClosureAmendmentService $service,
        string $runToken,
        string $scenario,
        string $worker,
    ): array {
        $actors = $this->actors($runToken, create: false);
        $encounterWorker = $scenario === 'same-key-different-digest-across-encounters' ? $worker : 'A';
        $encounter = $this->encounter($runToken, $scenario, $encounterWorker);
        $document = OutpatientClinicalDocument::query()->where('encounter_id', $encounter->id)->sole();

        if (in_array($scenario, ['same-key-same-digest', 'same-key-different-digest-across-encounters'], true)) {
            $note = $scenario === 'same-key-same-digest'
                ? 'Muatan sintetis identik untuk replay.'
                : 'Muatan sintetis berbeda untuk encounter '.$worker.'.';
            $result = $service->submit(
                encounter: $encounter,
                actor: $actors['requester'],
                originalDocumentPublicId: $document->public_id,
                originalDocumentVersion: $document->version,
                reasonCode: OutpatientPostClosureAmendmentRequest::REASON_CLINICAL_CORRECTION,
                note: $note,
                idempotencyKey: $this->submissionRaceKey($runToken, $scenario),
                requestCorrelationId: null,
            );

            return [
                'outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED',
                'reason' => null,
                'replayed' => $result->replayed,
            ];
        }

        $request = OutpatientPostClosureAmendmentRequest::query()
            ->where('encounter_id', $encounter->id)
            ->sole();

        if ($scenario === 'competing-decisions') {
            $result = $service->decide(
                amendmentRequest: $request,
                actor: $worker === 'A' ? $actors['approver_a'] : $actors['approver_b'],
                decision: $worker === 'A'
                    ? OutpatientPostClosureAmendmentRequest::STATE_APPROVED
                    : OutpatientPostClosureAmendmentRequest::STATE_DENIED,
                decisionNote: $worker === 'A'
                    ? 'Keputusan A sintetis disetujui.'
                    : 'Keputusan B sintetis ditolak.',
                expectedVersion: 1,
                idempotencyKey: 'race-'.$runToken.'-decision-'.strtolower($worker),
                requestCorrelationId: null,
            );

            return ['outcome' => 'APPLIED', 'reason' => null, 'replayed' => $result->replayed];
        }

        if ($scenario === 'competing-addendum-writes') {
            $result = $service->saveAddendum(
                amendmentRequest: $request,
                actor: $actors['requester'],
                fields: ['addendum_text' => 'Isi addendum sintetis dari worker '.$worker.'.'],
                expectedVersion: 0,
                idempotencyKey: 'race-'.$runToken.'-write-'.strtolower($worker),
                requestCorrelationId: null,
            );

            return ['outcome' => 'APPLIED', 'reason' => null, 'replayed' => $result->replayed];
        }

        $result = $service->finalizeAddendum(
            amendmentRequest: $request,
            actor: $actors['requester'],
            expectedVersion: 1,
            idempotencyKey: 'race-'.$runToken.'-finalize-'.strtolower($worker),
            requestCorrelationId: null,
        );

        return ['outcome' => 'APPLIED', 'reason' => null, 'replayed' => $result->replayed];
    }

    /** @return array<string, mixed> */
    private function verifyScenario(string $runToken, string $scenario): array
    {
        $encounterA = $this->encounter($runToken, $scenario, 'A');

        if ($scenario === 'same-key-same-digest') {
            $request = OutpatientPostClosureAmendmentRequest::query()->where('encounter_id', $encounterA->id)->sole();
            $this->assertCount(1, OutpatientPostClosureAmendmentRequest::query()->where('encounter_id', $encounterA->id)->count(), 'same-digest request');
            $this->assertCount(1, $this->receiptCount(
                OutpatientPostClosureAmendmentService::OPERATION_SUBMIT,
                [$this->submissionRaceKey($runToken, $scenario)],
            ), 'same-digest receipt');
            $this->assertCount(1, $this->auditCount('clinical.outpatient.amendment.request.submit', 'SUCCESS', [$request->public_id]), 'same-digest success audit');
            $this->assertCount(0, $this->auditCount('clinical.outpatient.amendment.request.submit', 'DENIED', [$request->public_id, $encounterA->public_id]), 'same-digest denial audit');
        } elseif ($scenario === 'same-key-different-digest-across-encounters') {
            $encounterB = $this->encounter($runToken, $scenario, 'B');
            $request = OutpatientPostClosureAmendmentRequest::query()->where('encounter_id', $encounterA->id)->sole();
            $this->assertCount(1, OutpatientPostClosureAmendmentRequest::query()->where('encounter_id', $encounterA->id)->count(), 'conflict first request');
            $this->assertCount(0, OutpatientPostClosureAmendmentRequest::query()->where('encounter_id', $encounterB->id)->count(), 'conflict second request');
            $this->assertCount(1, $this->receiptCount(
                OutpatientPostClosureAmendmentService::OPERATION_SUBMIT,
                [$this->submissionRaceKey($runToken, $scenario)],
            ), 'conflict receipt');
            $this->assertCount(1, $this->auditCount('clinical.outpatient.amendment.request.submit', 'SUCCESS', [$request->public_id]), 'conflict success audit');
            $this->assertCount(1, $this->auditCount('clinical.outpatient.amendment.request.submit', 'DENIED', [$encounterB->public_id], 'idempotency_key_conflict'), 'conflict denial audit');
        } else {
            $request = OutpatientPostClosureAmendmentRequest::query()->where('encounter_id', $encounterA->id)->sole();
            if ($scenario === 'competing-decisions') {
                $this->assertValue(OutpatientPostClosureAmendmentRequest::STATE_APPROVED, $request->request_state, 'decision state');
                $this->assertValue(2, $request->version, 'decision version');
                $this->assertCount(1, $this->receiptCount(
                    OutpatientPostClosureAmendmentService::OPERATION_DECIDE,
                    ['race-'.$runToken.'-decision-a', 'race-'.$runToken.'-decision-b'],
                ), 'decision receipt');
                $this->assertCount(1, $this->auditCount('clinical.outpatient.amendment.request.decide', 'SUCCESS', [$request->public_id]), 'decision success audit');
                $this->assertCount(1, $this->auditCount('clinical.outpatient.amendment.request.decide', 'DENIED', [$request->public_id], 'request_not_submitted'), 'decision denial audit');
            } elseif ($scenario === 'competing-addendum-writes') {
                $addendum = OutpatientClinicalDocumentAddendum::query()->where('amendment_request_id', $request->id)->sole();
                $this->assertValue(OutpatientClinicalDocumentAddendum::STATE_DRAFT, $addendum->addendum_state, 'write state');
                $this->assertValue(1, $addendum->version, 'write version');
                $this->assertCount(1, OutpatientClinicalDocumentAddendumVersion::query()->where('addendum_id', $addendum->id)->count(), 'write version rows');
                $this->assertCount(1, $this->receiptCount(
                    OutpatientPostClosureAmendmentService::OPERATION_ADDENDUM_WRITE,
                    ['race-'.$runToken.'-write-a', 'race-'.$runToken.'-write-b'],
                ), 'write receipt');
                $this->assertCount(1, $this->auditCount('clinical.outpatient.amendment.addendum.write', 'SUCCESS', [$addendum->public_id]), 'write success audit');
                $this->assertCount(1, $this->auditCount('clinical.outpatient.amendment.addendum.write', 'DENIED', [$request->public_id], 'stale_version'), 'write denial audit');
            } else {
                $addendum = OutpatientClinicalDocumentAddendum::query()->where('amendment_request_id', $request->id)->sole();
                $this->assertValue(OutpatientPostClosureAmendmentRequest::STATE_CONSUMED, $request->request_state, 'final request state');
                $this->assertValue(3, $request->version, 'final request version');
                $this->assertValue(OutpatientClinicalDocumentAddendum::STATE_FINAL, $addendum->addendum_state, 'final addendum state');
                $this->assertValue(2, $addendum->version, 'final addendum version');
                $this->assertCount(2, OutpatientClinicalDocumentAddendumVersion::query()->where('addendum_id', $addendum->id)->count(), 'final version rows');
                $this->assertCount(1, $this->receiptCount(
                    OutpatientPostClosureAmendmentService::OPERATION_ADDENDUM_FINALIZE,
                    ['race-'.$runToken.'-finalize-a', 'race-'.$runToken.'-finalize-b'],
                ), 'final receipt');
                $this->assertCount(1, $this->auditCount('clinical.outpatient.amendment.addendum.finalize', 'SUCCESS', [$addendum->public_id]), 'final success audit');
                $this->assertCount(1, $this->auditCount('clinical.outpatient.amendment.addendum.finalize', 'DENIED', [$request->public_id], 'request_already_consumed'), 'final denial audit');
            }
        }

        if (Patient::query()->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Durable assertion detected non-synthetic patient data.');
        }

        return [
            'schema_version' => 1,
            'status' => 'PASS',
            'protocol_state' => 'VERIFIED',
            'scenario' => $scenario,
            'durable_third_connection_assertions' => true,
        ];
    }

    /** @return array{requester: User, approver_a: User, approver_b: User, rmik: User} */
    private function actors(string $runToken, bool $create = true): array
    {
        $definitions = [
            'requester' => ['physician', RoleCapabilityMatrix::ROLE_PHYSICIAN],
            'approver_a' => ['approver-a', RoleCapabilityMatrix::ROLE_PHYSICIAN],
            'approver_b' => ['approver-b', RoleCapabilityMatrix::ROLE_PHYSICIAN],
            'rmik' => ['rmik', RoleCapabilityMatrix::ROLE_RMIK],
        ];
        $actors = [];
        foreach ($definitions as $key => [$label, $roleSlug]) {
            $email = sprintf('amendment-race-%s-%s@example.invalid', $runToken, $label);
            $user = User::query()->where('email', $email)->first();
            if (! $user instanceof User && $create) {
                $user = User::query()->create([
                    'name' => 'Aktor Rehearsal Addendum '.strtoupper($label),
                    'email' => $email,
                    'password' => Str::random(64),
                    'status' => 'ACTIVE',
                    'is_system_administrator' => false,
                ]);
                $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
                $user->roles()->sync([$role->id]);
            }
            if (! $user instanceof User || ! $user->hasRole($roleSlug) || $user->is_system_administrator) {
                throw new RuntimeException('Required closed rehearsal actor is unavailable.');
            }
            $actors[$key] = $user;
        }

        /** @var array{requester: User, approver_a: User, approver_b: User, rmik: User} $actors */
        return $actors;
    }

    /** @return array{encounter: Encounter, document: OutpatientClinicalDocument} */
    private function createClosedEncounterWithEvidence(
        string $runToken,
        string $scenario,
        string $worker,
        User $requester,
        User $rmik,
    ): array {
        $code = $this->scenarioCode($scenario).$worker;
        $scenarioIndex = array_search($scenario, self::SCENARIOS, true);
        if (! is_int($scenarioIndex)) {
            throw new RuntimeException('Outpatient amendment race scenario queue binding is invalid.');
        }
        $queueNumber = ($scenarioIndex * 2) + ($worker === 'A' ? 1 : 2);
        $patient = Patient::query()->create([
            'medical_record_number' => 'SYN-AMR-'.$runToken.'-'.$code,
            'full_name' => 'Pasien Sintetis Rehearsal '.$code,
            'date_of_birth' => '1990-01-01',
            'sex' => Patient::SEX_TIDAK_DIKETAHUI,
            'is_synthetic' => true,
            'created_by_user_id' => $requester->id,
        ]);
        $encounter = Encounter::query()->create([
            'patient_id' => $patient->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_CLOSED,
            'clinic_name' => 'Poliklinik Rehearsal Addendum',
            'visit_date' => '2030-03-01',
            'queue_date' => '2030-03-01',
            'queue_number' => $queueNumber,
            'admission_mode' => Encounter::ADMISSION_DATANG_SENDIRI,
            'payer_type' => Encounter::PAYER_UMUM,
            'booking_code' => $this->bookingCode($runToken, $scenario, $worker),
            'registered_at' => '2030-03-01 09:00:00',
            'registered_by_user_id' => $requester->id,
            'chief_complaint' => 'Data sintetis rehearsal konkurensi addendum.',
        ]);
        $finalizedAt = now();
        $document = OutpatientClinicalDocument::query()->create([
            'encounter_id' => $encounter->id,
            'author_user_id' => $requester->id,
            'finalized_by_user_id' => $requester->id,
            'document_type' => OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT,
            'document_state' => OutpatientClinicalDocument::STATE_FINAL,
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'version' => 1,
            'fields' => [
                'anamnesis' => 'Sintetis',
                'objective_examination' => 'Sintetis',
                'clinical_assessment' => 'Sintetis',
                'care_plan' => 'Sintetis',
            ],
            'finalized_at' => $finalizedAt,
        ]);
        OutpatientClinicalDocumentVersion::query()->create([
            'outpatient_clinical_document_id' => $document->id,
            'actor_user_id' => $requester->id,
            'version' => 1,
            'document_state' => OutpatientClinicalDocument::STATE_FINAL,
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'fields' => $document->fields,
            'finalized_at' => $finalizedAt,
        ]);
        OutpatientRmCompletenessReview::query()->create([
            'encounter_id' => $encounter->id,
            'reviewed_by_user_id' => $rmik->id,
            'signed_off_by_user_id' => $rmik->id,
            'definition_version' => OutpatientRmCompletenessReview::DEFINITION_VERSION,
            'version' => 1,
            'source_fingerprint' => hash('sha256', $runToken.'|'.$scenario.'|'.$worker),
            'review_state' => OutpatientRmCompletenessReview::STATE_SIGNED_OFF,
            'reviewed_at' => $finalizedAt,
            'signed_off_at' => $finalizedAt,
        ]);

        return ['encounter' => $encounter, 'document' => $document];
    }

    private function encounter(string $runToken, string $scenario, string $worker): Encounter
    {
        return Encounter::query()
            ->where('booking_code', $this->bookingCode($runToken, $scenario, $worker))
            ->sole();
    }

    private function assertScenarioAbsent(string $runToken, string $scenario): void
    {
        if (Encounter::query()->where('booking_code', 'like', 'SYN-AMR-'.$runToken.'-'.$this->scenarioCode($scenario).'%')->exists()) {
            throw new RuntimeException('Outpatient amendment race fixture already exists.');
        }
    }

    private function bookingCode(string $runToken, string $scenario, string $worker): string
    {
        return 'SYN-AMR-'.$runToken.'-'.$this->scenarioCode($scenario).$worker;
    }

    private function scenarioCode(string $scenario): string
    {
        return match ($scenario) {
            'same-key-same-digest' => 'SD',
            'same-key-different-digest-across-encounters' => 'CD',
            'competing-decisions' => 'DC',
            'competing-addendum-writes' => 'AW',
            'competing-finalization' => 'FN',
            default => throw new RuntimeException('Unknown outpatient amendment race scenario.'),
        };
    }

    private function preparationKey(string $runToken, string $scenario, string $operation): string
    {
        return 'prep-'.$runToken.'-'.$this->scenarioCode($scenario).'-'.$operation;
    }

    private function submissionRaceKey(string $runToken, string $scenario): string
    {
        return 'race-'.$runToken.'-submit-'.($scenario === 'same-key-same-digest' ? 'same' : 'cross');
    }

    /** @param list<string> $keys */
    private function receiptCount(string $operation, array $keys): int
    {
        return OutpatientAmendmentOperationReceipt::query()
            ->where('operation', $operation)
            ->whereIn('idempotency_key', $keys)
            ->count();
    }

    /** @param list<string> $resourceIds */
    private function auditCount(
        string $action,
        string $outcome,
        array $resourceIds,
        ?string $reason = null,
    ): int {
        $query = AuditEvent::query()
            ->where('action', $action)
            ->where('outcome', $outcome)
            ->whereIn('resource_id', $resourceIds);
        if ($reason !== null) {
            $query->where('reason', $reason);
        }

        return $query->count();
    }

    private function backendConnectionId(): int
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? (int) DB::scalar('SELECT pg_backend_pid()')
            : (int) DB::scalar('SELECT CONNECTION_ID()');
    }

    private function setConnectionLabel(string $runToken, string $scenario, string $worker): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::selectOne("SELECT set_config('application_name', ?, false)", [
                'simrs_amendment_race_'.$runToken.'_'.$this->scenarioCode($scenario).'_'.strtolower($worker),
            ]);
        }
    }

    private function engineNativeSleep(int $holdMs): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::selectOne('SELECT pg_sleep(?)', [$holdMs / 1000]);
        } else {
            DB::selectOne('SELECT SLEEP(?)', [$holdMs / 1000]);
        }
    }

    private function assertCount(int $expected, int $actual, string $label): void
    {
        if ($actual !== $expected) {
            throw new RuntimeException("Durable {$label} assertion failed.");
        }
    }

    private function assertValue(mixed $expected, mixed $actual, string $label): void
    {
        if ($actual !== $expected) {
            throw new RuntimeException("Durable {$label} assertion failed.");
        }
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload): void
    {
        fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
        fflush(STDOUT);
    }

    private function emitBlockedResult(Throwable $exception): void
    {
        try {
            $this->emit([
                'schema_version' => 1,
                'status' => 'BLOCKED',
                'error_code' => 'OUTPATIENT_AMENDMENT_RACE_WORKER_FAILED',
                'exception_class' => $exception::class,
                'exception_fingerprint' => hash('sha256', implode('|', [
                    $exception::class,
                    $exception->getFile(),
                    (string) $exception->getLine(),
                ])),
            ]);
        } catch (JsonException) {
            $this->error('BLOCKED outpatient amendment race worker.');
        }
    }
}
