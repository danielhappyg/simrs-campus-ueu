<?php

namespace App\Support\Radiology;

use App\Models\EmergencyTriageAssessment;
use App\Models\Encounter;
use App\Models\RadiologyExaminationMaster;
use App\Models\RadiologyExaminationMasterVersion;
use App\Models\RadiologyOperationReceipt;
use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderCancellation;
use App\Models\RadiologyPerformance;
use App\Models\RadiologyReportAcknowledgement;
use App\Models\RadiologyReportVersion;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Emergency\EmergencyCanonicalJson;
use App\Support\Emergency\EmergencyDiagnosticFollowUpService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class RadiologyWorkflowService
{
    public const CANCELLATION_REASONS = ['DUPLICATE_ORDER', 'CLINICAL_PLAN_CHANGED', 'ORDERING_ERROR', 'OTHER'];

    public const AMENDMENT_REASONS = ['TYPOGRAPHICAL_CORRECTION', 'CLINICAL_CLARIFICATION', 'ADDITIONAL_FINDING', 'OTHER'];

    public function __construct(
        private readonly RadiologyActorPolicy $policy,
        private readonly AuditRecorder $audit,
        private readonly EmergencyDiagnosticFollowUpService $emergencyFollowUp,
    ) {}

    public function createOrder(string $encounterPublicId, string $masterPublicId, User $actor, string $indication, string $key): RadiologyMutationResult
    {
        return $this->audited($actor, 'RADIOLOGY_ORDER_CREATE', $encounterPublicId, fn () => $this->createOrderOperation($encounterPublicId, $masterPublicId, $actor, $indication, $key));
    }

    private function createOrderOperation(string $encounterPublicId, string $masterPublicId, User $actor, string $indication, string $key): RadiologyMutationResult
    {
        $this->policy->order($actor);
        $indication = trim($indication);
        $this->text($indication, 5, 2000);
        $this->key($key);

        return $this->mutate($actor, 'RADIOLOGY_ORDER_CREATE', $key, compact('encounterPublicId', 'masterPublicId', 'indication'), function (callable $replay) use ($encounterPublicId, $masterPublicId, $actor, $indication) {
            $e = Encounter::query()->where('public_id', $encounterPublicId)->lockForUpdate()->firstOrFail();
            if (! $e->patient()->where('is_synthetic', true)->exists() || ! in_array($e->care_setting, Encounter::CARE_SETTINGS, true) || $e->status !== Encounter::STATUS_IN_EXAMINATION || $e->cancellation()->exists()) {
                throw new RadiologyDenied($e->isCancelled() ? 'encounter_cancelled' : 'encounter_not_eligible', 'Episode tidak memenuhi syarat.');
            }
            if ($e->care_setting === Encounter::CARE_SETTING_EMERGENCY
                && ! EmergencyTriageAssessment::query()->where('encounter_id', $e->id)->where('assessment_type', EmergencyTriageAssessment::INITIAL)->exists()) {
                throw new RadiologyDenied('initial_triage_required', 'Triase awal Final wajib tersedia sebelum membuat pesanan radiologi IGD.');
            }
            $m = RadiologyExaminationMaster::query()->where('public_id', $masterPublicId)->lockForUpdate()->firstOrFail();
            if ($result = $replay()) {
                return $result;
            }
            if ($m->state !== RadiologyExaminationMaster::ACTIVE) {
                throw new RadiologyDenied('master_not_active', 'Master tidak aktif.');
            }
            $masterVersionQuery = RadiologyExaminationMasterVersion::query()
                ->where('radiology_examination_master_id', $m->id)
                ->where('version', $m->version);
            // The preceding master-head lock is a current read. Under MySQL
            // REPEATABLE READ, the immutable version must also be a current
            // read so a transaction that waited for a revision cannot retain
            // an older snapshot. PostgreSQL deliberately uses a plain read.
            if (DB::connection()->getDriverName() === 'mysql') {
                $masterVersionQuery->sharedLock();
            }
            $mv = $masterVersionQuery->firstOrFail();

            return RadiologyOrder::query()->create(['encounter_id' => $e->id, 'master_id' => $m->id, 'ordered_by_user_id' => $actor->id, 'master_version' => $m->version, 'master_version_public_id' => $mv->public_id, 'master_content_digest' => $mv->content_digest, 'master_code' => $m->examination_code, 'master_display_name' => $m->display_name, 'master_preparation_instruction' => $m->preparation_instruction, 'care_setting' => $e->care_setting, 'encounter_status_snapshot' => $e->status, 'encounter_number_snapshot' => $e->public_id, 'care_location_label_snapshot' => $e->ward_name ?: ($e->clinic_name ?: 'Lokasi tidak tersedia'), 'clinical_indication' => $indication, 'status' => RadiologyOrder::ORDERED, 'version' => 1, 'ordered_at' => now()]);
        });
    }

    public function cancel(string $orderPublicId, User $actor, int $expectedVersion, string $reasonCode, ?string $note, string $key): RadiologyMutationResult
    {
        return $this->audited($actor, 'RADIOLOGY_ORDER_CANCEL', $orderPublicId, fn () => $this->cancelOperation($orderPublicId, $actor, $expectedVersion, $reasonCode, $note, $key));
    }

    private function cancelOperation(string $orderPublicId, User $actor, int $expectedVersion, string $reasonCode, ?string $note, string $key): RadiologyMutationResult
    {
        $this->policy->cancel($actor);
        $reasonCode = mb_strtoupper(trim($reasonCode));
        $note = $this->optional($note, 500);
        if (! in_array($reasonCode, self::CANCELLATION_REASONS, true) || ($reasonCode === 'OTHER' && $note === null)) {
            throw new RadiologyDenied('validation_failed', 'Kode alasan atau catatan tidak valid.');
        }$this->key($key);

        return $this->mutate($actor, 'RADIOLOGY_ORDER_CANCEL', $key, compact('orderPublicId', 'expectedVersion', 'reasonCode', 'note'), function (callable $replay) use ($orderPublicId, $actor, $expectedVersion, $reasonCode, $note) {
            $o = $this->lockOrder($orderPublicId);
            if ($result = $replay()) {
                return $result;
            }
            if ($o->ordered_by_user_id !== $actor->id) {
                throw new RadiologyDenied('cancellation_not_permitted', 'Hanya dokter pemesan dapat membatalkan.');
            }if ($o->version !== $expectedVersion) {
                throw new RadiologyDenied('stale_version', 'Versi pesanan berubah.');
            }if ($o->status !== RadiologyOrder::ORDERED || $this->performanceExists($o) || $this->reportsExist($o)) {
                throw new RadiologyDenied('order_not_ordered', 'Pesanan tidak dapat dibatalkan.');
            }$c = RadiologyOrderCancellation::query()->create(['radiology_order_id' => $o->id, 'actor_user_id' => $actor->id, 'reason_code' => $reasonCode, 'note' => $note, 'cancelled_at' => now(), 'created_at' => now()]);
            $o->update(['status' => RadiologyOrder::CANCELLED, 'version' => $o->version + 1]);

            return $c;
        });
    }

    public function perform(string $orderPublicId, User $actor, int $expectedVersion, string $key): RadiologyMutationResult
    {
        return $this->audited($actor, 'RADIOLOGY_ORDER_PERFORM', $orderPublicId, fn () => $this->performOperation($orderPublicId, $actor, $expectedVersion, $key));
    }

    private function performOperation(string $orderPublicId, User $actor, int $expectedVersion, string $key): RadiologyMutationResult
    {
        $this->policy->perform($actor);
        $this->key($key);

        return $this->mutate($actor, 'RADIOLOGY_ORDER_PERFORM', $key, compact('orderPublicId', 'expectedVersion'), function (callable $replay) use ($orderPublicId, $actor, $expectedVersion) {
            $o = $this->lockOrder($orderPublicId);
            if ($result = $replay()) {
                return $result;
            }
            if ($o->version !== $expectedVersion) {
                throw new RadiologyDenied('stale_version', 'Versi pesanan berubah.');
            }if ($o->status !== RadiologyOrder::ORDERED) {
                throw new RadiologyDenied('order_not_ordered', 'Pesanan tidak aktif.');
            }if ($this->performanceExists($o)) {
                throw new RadiologyDenied('already_performed', 'Pelaksanaan sudah dicatat.');
            }$p = RadiologyPerformance::query()->create(['radiology_order_id' => $o->id, 'performed_by_user_id' => $actor->id, 'performed_at' => now(), 'created_at' => now()]);
            $o->update(['status' => RadiologyOrder::PERFORMED, 'version' => $o->version + 1]);

            return $p;
        });
    }

    public function saveDraft(string $orderPublicId, User $actor, int $expectedReportVersion, ?string $findings, ?string $impression, ?string $recommendation, string $key): RadiologyMutationResult
    {
        return $this->audited($actor, 'RADIOLOGY_REPORT_DRAFT_SAVE', $orderPublicId, fn () => $this->saveDraftOperation($orderPublicId, $actor, $expectedReportVersion, $findings, $impression, $recommendation, $key));
    }

    private function saveDraftOperation(string $orderPublicId, User $actor, int $expectedReportVersion, ?string $findings, ?string $impression, ?string $recommendation, string $key): RadiologyMutationResult
    {
        $this->policy->write($actor);
        $findings = $this->optional($findings, 10000);
        $impression = $this->optional($impression, 10000);
        $recommendation = $this->optional($recommendation, 10000);
        $this->key($key);

        return $this->mutate($actor, 'RADIOLOGY_REPORT_DRAFT_SAVE', $key, compact('orderPublicId', 'expectedReportVersion', 'findings', 'impression', 'recommendation'), function (callable $replay) use ($orderPublicId, $actor, $expectedReportVersion, $findings, $impression, $recommendation) {
            $o = $this->lockOrder($orderPublicId);
            if ($result = $replay()) {
                return $result;
            }
            if ($o->status !== RadiologyOrder::PERFORMED || ! $this->performanceExists($o)) {
                throw new RadiologyDenied('performance_required', 'Pelaksanaan wajib dicatat.');
            }$latest = $this->latest($o);
            $actual = $latest instanceof RadiologyReportVersion ? $latest->version : 0;
            if ($actual !== $expectedReportVersion) {
                throw new RadiologyDenied('stale_version', 'Versi laporan berubah.');
            }if ($latest && $latest->state !== RadiologyReportVersion::DRAFT) {
                throw new RadiologyDenied('report_already_verified', 'Mulai amandemen untuk laporan terverifikasi.');
            }if ($latest && $latest->author_user_id !== $actor->id) {
                throw new RadiologyDenied('report_author_mismatch', 'Draf hanya dapat dilanjutkan penulisnya.');
            }

            return $this->report($o, $actor, $actual + 1, RadiologyReportVersion::DRAFT, $findings, $impression, $recommendation, null, null, null, null);
        });
    }

    public function amendVerified(string $orderPublicId, User $actor, int $expectedReportVersion, string $reasonCode, string $amendedStatement, string $key): RadiologyMutationResult
    {
        return $this->audited($actor, 'RADIOLOGY_REPORT_AMEND_VERIFIED', $orderPublicId, fn () => $this->amendVerifiedOperation($orderPublicId, $actor, $expectedReportVersion, $reasonCode, $amendedStatement, $key));
    }

    private function amendVerifiedOperation(string $orderPublicId, User $actor, int $expectedReportVersion, string $reasonCode, string $amendedStatement, string $key): RadiologyMutationResult
    {
        $this->policy->verify($actor);
        $reasonCode = mb_strtoupper(trim($reasonCode));
        $amendedStatement = trim($amendedStatement);
        if (! in_array($reasonCode, self::AMENDMENT_REASONS, true)) {
            throw new RadiologyDenied('validation_failed', 'Alasan amandemen tidak valid.');
        }$this->text($amendedStatement, 5, 10000);
        $this->key($key);

        return $this->mutate($actor, 'RADIOLOGY_REPORT_AMEND_VERIFIED', $key, compact('orderPublicId', 'expectedReportVersion', 'reasonCode', 'amendedStatement'), function (callable $replay) use ($orderPublicId, $actor, $expectedReportVersion, $reasonCode, $amendedStatement) {
            $o = $this->lockOrder($orderPublicId);
            if ($result = $replay()) {
                return $result;
            }
            if ($o->status !== RadiologyOrder::REPORTED_VERIFIED) {
                throw new RadiologyDenied('report_not_verified', 'Laporan belum terverifikasi.');
            }$latest = $this->latest($o);
            if (! $latest || $latest->version !== $expectedReportVersion) {
                throw new RadiologyDenied('stale_version', 'Versi laporan berubah.');
            }if (! in_array($latest->state, [RadiologyReportVersion::VERIFIED, RadiologyReportVersion::AMENDED_VERIFIED], true)) {
                throw new RadiologyDenied('amendment_base_stale', 'Amandemen harus mengikuti versi terverifikasi terbaru.');
            }$prior = $latest->state === RadiologyReportVersion::AMENDED_VERIFIED ? $latest->content_digest : null;
            $baseId = $latest->base_verified_version_id ?? $latest->id;
            $baseDigest = $latest->base_verified_digest ?? $latest->content_digest;

            return $this->report($o, $actor, $latest->version + 1, RadiologyReportVersion::AMENDED_VERIFIED, $latest->findings, $latest->impression, $latest->recommendation, $reasonCode, $baseId, $amendedStatement, $baseDigest, $prior, true);
        });
    }

    public function verify(string $orderPublicId, User $actor, int $expectedReportVersion, string $key): RadiologyMutationResult
    {
        return $this->audited($actor, 'RADIOLOGY_REPORT_VERIFY', $orderPublicId, fn () => $this->verifyOperation($orderPublicId, $actor, $expectedReportVersion, $key));
    }

    private function verifyOperation(string $orderPublicId, User $actor, int $expectedReportVersion, string $key): RadiologyMutationResult
    {
        $this->policy->verify($actor);
        $this->key($key);

        return $this->mutate($actor, 'RADIOLOGY_REPORT_VERIFY', $key, compact('orderPublicId', 'expectedReportVersion'), function (callable $replay) use ($orderPublicId, $actor, $expectedReportVersion) {
            $o = $this->lockOrder($orderPublicId);
            if ($result = $replay()) {
                return $result;
            }
            $latest = $this->latest($o);
            if (! $latest || $latest->version !== $expectedReportVersion) {
                throw new RadiologyDenied('stale_version', 'Versi laporan berubah.');
            }if ($latest->state !== RadiologyReportVersion::DRAFT || $latest->author_user_id !== $actor->id) {
                throw new RadiologyDenied('verification_not_permitted', 'Draf tidak dapat diverifikasi.');
            }if (trim((string) $latest->findings) === '' || trim((string) $latest->impression) === '') {
                throw new RadiologyDenied('validation_failed', 'Temuan dan kesan wajib diisi.');
            }$v = $this->report($o, $actor, $latest->version + 1, RadiologyReportVersion::VERIFIED, $latest->findings, $latest->impression, $latest->recommendation, null, null, null, null, null, true);
            $o->update(['status' => RadiologyOrder::REPORTED_VERIFIED, 'version' => $o->version + 1]);

            return $v;
        });
    }

    public function acknowledge(string $orderPublicId, User $actor, int $expectedOrderVersion, int $expectedReportVersion, string $key): RadiologyMutationResult
    {
        return $this->audited($actor, 'RADIOLOGY_REPORT_ACKNOWLEDGE', $orderPublicId, fn () => $this->acknowledgeOperation($orderPublicId, $actor, $expectedOrderVersion, $expectedReportVersion, $key));
    }

    private function acknowledgeOperation(string $orderPublicId, User $actor, int $expectedOrderVersion, int $expectedReportVersion, string $key): RadiologyMutationResult
    {
        $this->policy->acknowledge($actor);
        $this->key($key);

        return $this->mutate($actor, 'RADIOLOGY_REPORT_ACKNOWLEDGE', $key, compact('orderPublicId', 'expectedOrderVersion', 'expectedReportVersion'), function (callable $replay) use ($orderPublicId, $expectedOrderVersion, $expectedReportVersion, $actor) {
            $o = $this->lockOrder($orderPublicId);
            if ($result = $replay()) {
                return $result;
            }
            if ($o->version !== $expectedOrderVersion) {
                throw new RadiologyDenied('stale_version', 'Versi pesanan berubah.');
            }$latest = $this->latest($o);
            if (! $latest || $latest->version !== $expectedReportVersion || ! in_array($latest->state, [RadiologyReportVersion::VERIFIED, RadiologyReportVersion::AMENDED_VERIFIED], true)) {
                throw new RadiologyDenied('stale_version', 'Versi laporan berubah.');
            }if ($this->acknowledgementExists($latest)) {
                throw new RadiologyDenied('already_acknowledged', 'Versi laporan sudah diakui.');
            }$fingerprint = $this->fingerprint($latest);
            $acceptance = null;
            if ($o->ordered_by_user_id !== $actor->id) {
                $encounter = Encounter::query()->whereKey($o->encounter_id)->firstOrFail();
                $diagnosticFingerprint = EmergencyCanonicalJson::digest([
                    'RADIOLOGY', $o->public_id, $o->version, $o->status, $fingerprint,
                ]);
                $acceptance = $this->emergencyFollowUp->acceptedAssignmentForAcknowledgement(
                    $encounter,
                    'RADIOLOGY',
                    $o->public_id,
                    $actor,
                    $diagnosticFingerprint,
                );
                if ($acceptance === null) {
                    throw new RadiologyDenied('acknowledgement_not_permitted', 'Dokter bukan pemesan dan tidak memiliki penugasan tindak lanjut yang diterima.');
                }
            }

            return RadiologyReportAcknowledgement::query()->create([
                'radiology_report_version_id' => $latest->id,
                'actor_user_id' => $actor->id,
                'emergency_follow_up_acceptance_id' => $acceptance?->id,
                'report_fingerprint' => $fingerprint,
                'acknowledged_at' => now(),
                'created_at' => now(),
            ]);
        });
    }

    /** @return array<string,mixed> */
    public function projection(RadiologyOrder $order): array
    {
        $order->load(['performance', 'cancellation', 'reportVersions.acknowledgement']);
        $latest = $order->reportVersions->isEmpty()
            ? null
            : $order->reportVersions->sortByDesc('version')->first();

        return ['public_id' => $order->public_id, 'status' => $order->status, 'version' => $order->version, 'worklist_state' => $order->cancellation ? 'CANCELLED' : ($order->performance ? 'PERFORMED' : 'QUEUED'), 'report_state' => $latest->state ?? 'NONE', 'report_version' => $latest->version ?? 0, 'current_verified_public_id' => $latest && in_array($latest->state, [RadiologyReportVersion::VERIFIED, RadiologyReportVersion::AMENDED_VERIFIED], true) ? $latest->public_id : null, 'verified_at' => $latest?->verified_at?->toIso8601String(), 'findings' => $latest?->findings, 'impression' => $latest?->impression, 'recommendation' => $latest?->recommendation, 'acknowledgement_state' => $latest && in_array($latest->state, [RadiologyReportVersion::VERIFIED, RadiologyReportVersion::AMENDED_VERIFIED], true) ? ($latest->acknowledgement ? 'ACKNOWLEDGED' : 'UNACKNOWLEDGED') : null, 'can_cancel' => $order->status === RadiologyOrder::ORDERED && ! $order->performance && ! $latest];
    }

    private function lockOrder(string $id): RadiologyOrder
    {
        $candidate = RadiologyOrder::query()->where('public_id', $id)->firstOrFail();
        $encounter = Encounter::query()->whereKey($candidate->encounter_id)->lockForUpdate()->firstOrFail();
        $cancellationQuery = $encounter->cancellation();
        if (DB::connection()->getDriverName() === 'mysql') {
            $cancellationQuery->sharedLock();
        }
        if (in_array($encounter->status, [Encounter::STATUS_CLOSED, Encounter::STATUS_CANCELLED], true) || $cancellationQuery->exists()) {
            throw new RadiologyDenied($encounter->status === Encounter::STATUS_CANCELLED ? 'encounter_cancelled' : 'encounter_closed', 'Episode sudah terminal.');
        }$locked = RadiologyOrder::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
        if ($locked->encounter_id !== $encounter->id) {
            throw new RadiologyDenied('encounter_not_eligible', 'Pesanan tidak konsisten.');
        }

        return $locked;
    }

    private function latest(RadiologyOrder $o): ?RadiologyReportVersion
    {
        $query = RadiologyReportVersion::query()->where('radiology_order_id', $o->id)->orderByDesc('version');
        if (DB::connection()->getDriverName() === 'mysql') {
            $query->sharedLock();
        }

        return $query->first();
    }

    private function performanceExists(RadiologyOrder $order): bool
    {
        $query = $order->performance();
        if (DB::connection()->getDriverName() === 'mysql') {
            $query->sharedLock();
        }

        return $query->exists();
    }

    private function reportsExist(RadiologyOrder $order): bool
    {
        $query = $order->reportVersions();
        if (DB::connection()->getDriverName() === 'mysql') {
            $query->sharedLock();
        }

        return $query->exists();
    }

    private function acknowledgementExists(RadiologyReportVersion $version): bool
    {
        $query = $version->acknowledgement();
        if (DB::connection()->getDriverName() === 'mysql') {
            $query->sharedLock();
        }

        return $query->exists();
    }

    private function report(RadiologyOrder $o, User $a, int $v, string $s, ?string $f, ?string $i, ?string $recommendation, ?string $r, ?int $base, ?string $statement = null, ?string $baseDigest = null, ?string $priorDigest = null, bool $verified = false): RadiologyReportVersion
    {
        $digest = app(RadiologyEvidenceFingerprint::class)->digest($s, $f, $i, $recommendation, $r, $base, $statement, $baseDigest, $priorDigest);

        return RadiologyReportVersion::query()->create(['radiology_order_id' => $o->id, 'author_user_id' => $a->id, 'base_verified_version_id' => $base, 'version' => $v, 'state' => $s, 'findings' => $f, 'impression' => $i, 'recommendation' => $recommendation, 'amendment_reason' => $r, 'amended_statement' => $statement, 'base_verified_digest' => $baseDigest, 'prior_amendment_digest' => $priorDigest, 'content_digest' => $digest, 'verified_at' => $verified ? now() : null, 'created_at' => now()]);
    }

    private function text(string $v, int $min, int $max): void
    {
        if (mb_strlen($v) < $min || mb_strlen($v) > $max) {
            throw new RadiologyDenied('validation_failed', 'Teks tidak valid.');
        }
    }

    private function optional(?string $v, int $max): ?string
    {
        $v = $v === null ? null : trim($v);
        if ($v !== null && mb_strlen($v) > $max) {
            throw new RadiologyDenied('validation_failed', 'Teks terlalu panjang.');
        }

        return $v === '' ? null : $v;
    }

    private function key(string $k): void
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/', $k)) {
            throw new RadiologyDenied('validation_failed', 'Kunci idempotensi tidak valid.');
        }
    }

    private function fingerprint(RadiologyReportVersion $v): string
    {
        return app(RadiologyEvidenceFingerprint::class)->current($v);
    }

    /** @param array<string, mixed> $payload */
    private function mutate(User $a, string $op, string $key, array $payload, callable $write): RadiologyMutationResult
    {
        $key = mb_strtolower(trim($key));
        $digest = hash('sha256', json_encode([$op, $payload, 'CROSS_SETTING_RADIOLOGY_ORDER_REPORT_V1'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        try {
            return RadiologyMutationScope::run(fn () => DB::transaction(function () use ($a, $op, $key, $digest, $write): RadiologyMutationResult {
                if ($replayed = $this->replay($a, $op, $key, $digest)) {
                    return $replayed;
                }
                $recheck = fn (): ?RadiologyMutationResult => $this->replay($a, $op, $key, $digest, true);
                $written = $write($recheck);
                if ($written instanceof RadiologyMutationResult) {
                    return $written;
                }
                $record = $written;
                $state = match (true) {
                    $record instanceof RadiologyReportVersion => $record->state,
                    $record instanceof RadiologyOrder => $record->status,
                    $record instanceof RadiologyPerformance => 'PERFORMED',
                    $record instanceof RadiologyOrderCancellation => 'CANCELLED',
                    $record instanceof RadiologyReportAcknowledgement => 'ACKNOWLEDGED',
                    default => 'RECORDED',
                };
                $version = (int) ($record->version ?? 1);
                $type = $this->resultType($record);
                $resultDigest = $this->resultDigest($record);
                $event = $this->audit->record('radiology.workflow.mutate', 'radiology_record', $record->public_id, $a, 'SUCCESS', metadata: ['operation' => $op, 'state' => $state, 'version' => $version]);
                if ($event === null) {
                    throw new RadiologyAuditUnavailable('Audit radiologi gagal.');
                }
                RadiologyOperationReceipt::query()->create(['actor_user_id' => $a->id, 'operation' => $op, 'idempotency_key' => $key, 'payload_digest' => $digest, 'result_type' => $type, 'result_public_id' => $record->public_id, 'result_version' => $version, 'result_state' => $state, 'result_digest' => $resultDigest, 'request_correlation_id' => request()->attributes->get('request_id'), 'completed_at' => now()]);

                return new RadiologyMutationResult($record, false);
            }, 3));
        } catch (UniqueConstraintViolationException) {
            if ($replayed = $this->replay($a, $op, $key, $digest, true)) {
                return $replayed;
            }
            throw new RadiologyDenied('concurrent_state_conflict', 'Operasi bersamaan telah mengubah keadaan.');
        }
    }

    private function replay(User $actor, string $operation, string $key, string $digest, bool $currentRead = false): ?RadiologyMutationResult
    {
        $query = RadiologyOperationReceipt::query()
            ->where('actor_user_id', $actor->id)
            ->where('operation', $operation)
            ->where('idempotency_key', $key);
        if ($currentRead && DB::connection()->getDriverName() === 'mysql') {
            $query->sharedLock();
        }
        $receipt = $query->first();
        if (! $receipt instanceof RadiologyOperationReceipt) {
            return null;
        }
        if (! hash_equals($receipt->payload_digest, $digest)) {
            throw new RadiologyDenied('idempotency_key_conflict', 'Kunci idempotensi sudah dipakai.');
        }

        return new RadiologyMutationResult($this->resolve($receipt, $currentRead), true);
    }

    private function resolve(RadiologyOperationReceipt $r, bool $currentRead): Model
    {
        $map = [
            RadiologyOperationReceipt::RESULT_ORDER => RadiologyOrder::class,
            RadiologyOperationReceipt::RESULT_PERFORMANCE => RadiologyPerformance::class,
            RadiologyOperationReceipt::RESULT_CANCELLATION => RadiologyOrderCancellation::class,
            RadiologyOperationReceipt::RESULT_REPORT_VERSION => RadiologyReportVersion::class,
            RadiologyOperationReceipt::RESULT_ACKNOWLEDGEMENT => RadiologyReportAcknowledgement::class,
        ];
        $c = $map[$r->result_type] ?? throw new RadiologyDenied('receipt_corrupt', 'Bukti operasi tidak dapat diselesaikan.');

        $query = $c::query()->where('public_id', $r->result_public_id);
        if ($currentRead && DB::connection()->getDriverName() === 'mysql') {
            $query->sharedLock();
        }
        $record = $query->firstOrFail();
        if ($record instanceof RadiologyReportVersion && $r->result_version !== $record->version) {
            throw new RadiologyDenied('receipt_corrupt', 'Versi bukti operasi tidak cocok.');
        }
        if ($this->resultType($record) !== $r->result_type
            || ! hash_equals($this->resultDigest($record), (string) $r->result_digest)) {
            throw new RadiologyDenied('receipt_corrupt', 'Ikatan bukti operasi tidak cocok.');
        }

        return $record;
    }

    private function resultType(Model $record): string
    {
        return match (true) {
            $record instanceof RadiologyOrder => RadiologyOperationReceipt::RESULT_ORDER,
            $record instanceof RadiologyPerformance => RadiologyOperationReceipt::RESULT_PERFORMANCE,
            $record instanceof RadiologyOrderCancellation => RadiologyOperationReceipt::RESULT_CANCELLATION,
            $record instanceof RadiologyReportVersion => RadiologyOperationReceipt::RESULT_REPORT_VERSION,
            $record instanceof RadiologyReportAcknowledgement => RadiologyOperationReceipt::RESULT_ACKNOWLEDGEMENT,
            default => throw new RadiologyDenied('receipt_corrupt', 'Jenis hasil operasi tidak didukung.'),
        };
    }

    private function resultDigest(Model $record): string
    {
        $payload = match (true) {
            $record instanceof RadiologyOrder => [
                $record->public_id, $record->encounter_id, $record->master_id, $record->ordered_by_user_id,
                $record->master_version, $record->master_version_public_id, $record->master_content_digest,
                $record->master_code, $record->master_display_name, $record->master_preparation_instruction,
                $record->care_setting, $record->encounter_status_snapshot, $record->encounter_number_snapshot,
                $record->care_location_label_snapshot, $record->clinical_indication,
                $record->ordered_at->toJSON(), $record->created_at->toJSON(),
            ],
            $record instanceof RadiologyReportVersion => $this->reportResultPayload($record),
            $record instanceof RadiologyPerformance => [$record->public_id, $record->radiology_order_id, $record->performed_by_user_id, $record->performed_at->toJSON()],
            $record instanceof RadiologyOrderCancellation => [$record->public_id, $record->radiology_order_id, $record->actor_user_id, $record->reason_code, $record->note, $record->cancelled_at->toJSON()],
            $record instanceof RadiologyReportAcknowledgement => [
                $record->public_id,
                $record->radiology_report_version_id,
                $record->actor_user_id,
                $record->emergency_follow_up_acceptance_id,
                $record->report_fingerprint,
                $record->acknowledged_at->toJSON(),
            ],
            default => throw new RadiologyDenied('receipt_corrupt', 'Hasil operasi tidak dapat diikat.'),
        };

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array<int,mixed> */
    private function reportResultPayload(RadiologyReportVersion $record): array
    {
        $computed = app(RadiologyEvidenceFingerprint::class)->contentDigest($record);
        if (! hash_equals($computed, (string) $record->content_digest)) {
            throw new RadiologyDenied('receipt_corrupt', 'Digest hasil laporan tidak cocok.');
        }
        $chain = in_array($record->state, [RadiologyReportVersion::VERIFIED, RadiologyReportVersion::AMENDED_VERIFIED], true)
            ? app(RadiologyEvidenceFingerprint::class)->current($record)
            : null;

        return [
            $record->public_id,
            $record->radiology_order_id,
            $record->author_user_id,
            $record->version,
            $record->state,
            $computed,
            $chain,
            $record->verified_at?->toJSON(),
            $record->created_at->toJSON(),
        ];
    }

    private function audited(User $actor, string $operation, ?string $resource, callable $callback): RadiologyMutationResult
    {
        try {
            return $callback();
        } catch (AuthorizationException $denial) {
            $this->auditDenial($actor, $operation, $resource, 'role_not_permitted');
            throw $denial;
        } catch (ModelNotFoundException $denial) {
            $this->auditDenial($actor, $operation, $resource, 'resource_not_found');
            throw $denial;
        } catch (RadiologyDenied $denial) {
            $this->auditDenial($actor, $operation, $resource, $denial->reason);
            throw $denial;
        }
    }

    private function auditDenial(User $actor, string $operation, ?string $resource, string $reason): void
    {
        $resource = is_string($resource) && strlen($resource) === 26 ? $resource : null;
        if ($this->audit->record('radiology.workflow.mutate', 'radiology_record', $resource, $actor, 'DENIED', $reason, ['operation' => $operation]) === null) {
            throw new RadiologyAuditUnavailable('Audit penolakan radiologi gagal.');
        }
    }
}
