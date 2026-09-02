<?php

namespace App\Support\Laboratory;

use App\Models\EmergencyTriageAssessment;
use App\Models\Encounter;
use App\Models\LaboratoryCriticalCommunication;
use App\Models\LaboratoryExaminationMaster;
use App\Models\LaboratoryExaminationMasterVersion;
use App\Models\LaboratoryOperationReceipt;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderCancellation;
use App\Models\LaboratoryResultAcknowledgement;
use App\Models\LaboratoryResultVersion;
use App\Models\LaboratorySpecimenAttempt;
use App\Models\LaboratorySpecimenEvent;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Emergency\EmergencyCanonicalJson;
use App\Support\Emergency\EmergencyDiagnosticFollowUpService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @phpstan-type LaboratoryRecord LaboratoryOrder|LaboratoryOrderCancellation|LaboratorySpecimenAttempt|LaboratorySpecimenEvent|LaboratoryResultVersion|LaboratoryResultAcknowledgement
 */
final class LaboratoryWorkflowService
{
    public const CANCELLATION_REASONS = ['DUPLICATE_ORDER', 'CLINICAL_PLAN_CHANGED', 'ORDERING_ERROR', 'OTHER'];

    public const REJECTION_REASONS = ['MISLABELLED', 'INSUFFICIENT_VOLUME', 'WRONG_CONTAINER', 'HEMOLYSED', 'CONTAMINATED', 'TRANSPORT_DELAY', 'OTHER'];

    public const CORRECTION_REASONS = ['TRANSCRIPTION_CORRECTION', 'TECHNICAL_CORRECTION', 'VERIFIER_CLARIFICATION', 'OTHER'];

    public const COMMUNICATION_METHODS = ['TELEPHONE', 'DIRECT', 'SECURE_INTERNAL_CHANNEL'];

    public const COMMUNICATION_OUTCOMES = ['COMMUNICATED', 'ESCALATED'];

    public function __construct(
        private readonly LaboratoryActorPolicy $policy,
        private readonly AuditRecorder $audit,
        private readonly LaboratoryEvidenceFingerprint $fingerprints,
        private readonly EmergencyDiagnosticFollowUpService $emergencyFollowUp,
    ) {}

    public function createOrder(string $encounterPublicId, string $masterPublicId, User $actor, string $priority, string $clinicalQuestion, string $key): LaboratoryMutationResult
    {
        return $this->audited($actor, 'LABORATORY_ORDER_CREATE', $encounterPublicId, fn () => $this->createOrderOperation($encounterPublicId, $masterPublicId, $actor, $priority, $clinicalQuestion, $key));
    }

    private function createOrderOperation(string $encounterPublicId, string $masterPublicId, User $actor, string $priority, string $clinicalQuestion, string $key): LaboratoryMutationResult
    {
        $this->policy->order($actor);
        $priority = mb_strtoupper(trim($priority));
        $clinicalQuestion = trim($clinicalQuestion);
        $this->key($key);
        if (! in_array($priority, ['ROUTINE', 'URGENT'], true) || mb_strlen($clinicalQuestion) < 3 || mb_strlen($clinicalQuestion) > 2000) {
            throw new LaboratoryDenied('validation_failed', 'Data pesanan laboratorium tidak valid.');
        }

        return $this->mutate($actor, 'LABORATORY_ORDER_CREATE', $key, compact('encounterPublicId', 'masterPublicId', 'priority', 'clinicalQuestion'), function (callable $replay) use ($encounterPublicId, $masterPublicId, $actor, $priority, $clinicalQuestion) {
            $encounter = Encounter::query()->where('public_id', $encounterPublicId)->lockForUpdate()->firstOrFail();
            if (! $encounter->patient()->where('is_synthetic', true)->exists() || ! in_array($encounter->care_setting, Encounter::CARE_SETTINGS, true) || $encounter->status !== Encounter::STATUS_IN_EXAMINATION || $encounter->cancellation()->exists()) {
                throw new LaboratoryDenied($encounter->isCancelled() ? 'encounter_cancelled' : 'encounter_not_eligible', 'Episode tidak memenuhi syarat.');
            }
            if ($encounter->care_setting === Encounter::CARE_SETTING_EMERGENCY
                && ! EmergencyTriageAssessment::query()->where('encounter_id', $encounter->id)->where('assessment_type', EmergencyTriageAssessment::INITIAL)->exists()) {
                throw new LaboratoryDenied('initial_triage_required', 'Triase awal Final wajib tersedia sebelum membuat pesanan laboratorium IGD.');
            }
            $master = LaboratoryExaminationMaster::query()->where('public_id', $masterPublicId)->lockForUpdate()->firstOrFail();
            if ($result = $replay()) {
                return $result;
            }
            if ($master->state !== LaboratoryExaminationMaster::ACTIVE) {
                throw new LaboratoryDenied('master_not_active', 'Master tidak aktif.');
            }
            $versionQuery = LaboratoryExaminationMasterVersion::query()->where('laboratory_examination_master_id', $master->id)->where('version', $master->version);
            if (DB::connection()->getDriverName() === 'mysql') {
                $versionQuery->sharedLock();
            }
            $version = $versionQuery->firstOrFail();
            $this->fingerprints->verifyMasterVersion($version);

            return LaboratoryOrder::query()->create([
                'encounter_id' => $encounter->id, 'master_id' => $master->id, 'ordered_by_user_id' => $actor->id,
                'master_version' => $master->version, 'master_version_public_id' => $version->public_id,
                'master_content_digest' => $version->content_digest, 'master_code' => $master->examination_code,
                'master_display_name' => $master->display_name, 'specimen_type_snapshot' => $master->specimen_type,
                'collection_instruction_snapshot' => $master->collection_instruction, 'components_snapshot' => $master->components,
                'care_setting' => $encounter->care_setting, 'encounter_status_snapshot' => $encounter->status,
                'encounter_number_snapshot' => $encounter->public_id,
                'care_location_label_snapshot' => $encounter->ward_name ?: ($encounter->clinic_name ?: 'Lokasi tidak tersedia'),
                'priority' => $priority, 'clinical_question' => $clinicalQuestion,
                'status' => LaboratoryOrder::ORDERED, 'version' => 1, 'ordered_at' => now(),
            ]);
        });
    }

    public function cancel(string $orderPublicId, User $actor, int $expectedVersion, string $reasonCode, ?string $note, string $key): LaboratoryMutationResult
    {
        return $this->audited($actor, 'LABORATORY_ORDER_CANCEL', $orderPublicId, fn () => $this->cancelOperation($orderPublicId, $actor, $expectedVersion, $reasonCode, $note, $key));
    }

    private function cancelOperation(string $orderPublicId, User $actor, int $expectedVersion, string $reasonCode, ?string $note, string $key): LaboratoryMutationResult
    {
        $this->policy->cancel($actor);
        $reasonCode = mb_strtoupper(trim($reasonCode));
        $note = $this->optional($note, 500);
        $this->key($key);
        if (! in_array($reasonCode, self::CANCELLATION_REASONS, true) || ($reasonCode === 'OTHER' && $note === null)) {
            throw new LaboratoryDenied('validation_failed', 'Alasan pembatalan tidak valid.');
        }

        return $this->mutate($actor, 'LABORATORY_ORDER_CANCEL', $key, compact('orderPublicId', 'expectedVersion', 'reasonCode', 'note'), function (callable $replay) use ($orderPublicId, $actor, $expectedVersion, $reasonCode, $note) {
            $order = $this->lockOrder($orderPublicId);
            if ($result = $replay()) {
                return $result;
            }
            if ($order->ordered_by_user_id !== $actor->id) {
                throw new LaboratoryDenied('cancellation_not_permitted', 'Hanya dokter pemesan dapat membatalkan.');
            }
            if ($order->version !== $expectedVersion) {
                throw new LaboratoryDenied('stale_version', 'Versi pesanan berubah.');
            }
            if ($order->status !== LaboratoryOrder::ORDERED) {
                throw new LaboratoryDenied('order_not_ordered', 'Pesanan tidak dapat dibatalkan.');
            }
            if ($this->attempts($order)->exists() || $this->results($order)->exists()) {
                throw new LaboratoryDenied('specimen_evidence_exists', 'Bukti spesimen sudah ada.');
            }
            $cancellation = LaboratoryOrderCancellation::query()->create(['laboratory_order_id' => $order->id, 'actor_user_id' => $actor->id, 'reason_code' => $reasonCode, 'note' => $note, 'cancelled_at' => now(), 'created_at' => now()]);
            $order->update(['status' => LaboratoryOrder::CANCELLED, 'version' => $order->version + 1]);

            return $cancellation;
        });
    }

    public function collectSpecimen(string $orderPublicId, User $actor, int $expectedOrderVersion, ?string $note, string $key): LaboratoryMutationResult
    {
        return $this->audited($actor, 'LABORATORY_SPECIMEN_COLLECT', $orderPublicId, fn () => $this->collectOperation($orderPublicId, $actor, $expectedOrderVersion, $note, $key));
    }

    private function collectOperation(string $orderPublicId, User $actor, int $expectedOrderVersion, ?string $note, string $key): LaboratoryMutationResult
    {
        $this->policy->collect($actor);
        $note = $this->optional($note, 500);
        $this->key($key);

        return $this->mutate($actor, 'LABORATORY_SPECIMEN_COLLECT', $key, compact('orderPublicId', 'expectedOrderVersion', 'note'), function (callable $replay) use ($orderPublicId, $actor, $expectedOrderVersion, $note) {
            $order = $this->lockOrder($orderPublicId);
            if ($result = $replay()) {
                return $result;
            }
            if ($order->version !== $expectedOrderVersion) {
                throw new LaboratoryDenied('stale_version', 'Versi pesanan berubah.');
            }
            if ($order->status !== LaboratoryOrder::ORDERED || $this->acceptedAttempt($order)) {
                throw new LaboratoryDenied('specimen_not_collectable', 'Spesimen tidak dapat dikoleksi.');
            }
            $latest = $this->attempts($order)->orderByDesc('attempt_number')->first();
            if ($latest && $latest->state !== LaboratorySpecimenAttempt::REJECTED) {
                throw new LaboratoryDenied('specimen_not_collectable', 'Upaya spesimen sebelumnya belum ditolak.');
            }

            return LaboratorySpecimenAttempt::query()->create(['laboratory_order_id' => $order->id, 'attempt_number' => ($latest ? $latest->attempt_number : 0) + 1, 'label_identifier' => 'LAB-'.Str::ulid(), 'collector_user_id' => $actor->id, 'collected_at' => now(), 'collection_note' => $note, 'state' => LaboratorySpecimenAttempt::COLLECTED, 'version' => 1, 'created_at' => now()]);
        });
    }

    public function receiveSpecimen(string $attemptPublicId, User $actor, string $key): LaboratoryMutationResult
    {
        return $this->audited($actor, 'LABORATORY_SPECIMEN_RECEIVE', $attemptPublicId, fn () => $this->receiveOperation($attemptPublicId, $actor, $key));
    }

    private function receiveOperation(string $attemptPublicId, User $actor, string $key): LaboratoryMutationResult
    {
        $this->policy->receive($actor);
        $this->key($key);

        return $this->mutate($actor, 'LABORATORY_SPECIMEN_RECEIVE', $key, compact('attemptPublicId'), function (callable $replay) use ($attemptPublicId, $actor) {
            [$order, $attempt] = $this->lockAttempt($attemptPublicId);
            if ($result = $replay()) {
                return $result;
            }
            if ($order->status !== LaboratoryOrder::ORDERED || $attempt->state !== LaboratorySpecimenAttempt::COLLECTED) {
                throw new LaboratoryDenied('specimen_not_collected', 'Spesimen belum siap diterima.');
            }
            $event = $this->event($attempt, $actor, LaboratorySpecimenEvent::RECEIVED, null, null);
            $attempt->update(['state' => LaboratorySpecimenAttempt::RECEIVED, 'version' => $attempt->version + 1]);

            return $event;
        });
    }

    public function acceptSpecimen(string $attemptPublicId, User $actor, int $expectedOrderVersion, string $key): LaboratoryMutationResult
    {
        return $this->audited($actor, 'LABORATORY_SPECIMEN_ACCEPT', $attemptPublicId, fn () => $this->acceptOperation($attemptPublicId, $actor, $expectedOrderVersion, $key));
    }

    private function acceptOperation(string $attemptPublicId, User $actor, int $expectedOrderVersion, string $key): LaboratoryMutationResult
    {
        $this->policy->assess($actor);
        $this->key($key);

        return $this->mutate($actor, 'LABORATORY_SPECIMEN_ACCEPT', $key, compact('attemptPublicId', 'expectedOrderVersion'), function (callable $replay) use ($attemptPublicId, $actor, $expectedOrderVersion) {
            [$order, $attempt] = $this->lockAttempt($attemptPublicId);
            if ($result = $replay()) {
                return $result;
            }
            if ($order->version !== $expectedOrderVersion) {
                throw new LaboratoryDenied('stale_version', 'Versi pesanan berubah.');
            }
            if ($order->status !== LaboratoryOrder::ORDERED || $attempt->state !== LaboratorySpecimenAttempt::RECEIVED) {
                throw new LaboratoryDenied('specimen_not_received', 'Spesimen belum siap dinilai.');
            }
            if ($this->acceptedAttempt($order)) {
                throw new LaboratoryDenied('accepted_specimen_exists', 'Spesimen diterima sudah ada.');
            }
            $event = $this->event($attempt, $actor, LaboratorySpecimenEvent::ACCEPTED, null, null);
            $attempt->update(['state' => LaboratorySpecimenAttempt::ACCEPTED, 'version' => $attempt->version + 1]);
            $order->update(['status' => LaboratoryOrder::SPECIMEN_ACCEPTED, 'version' => $order->version + 1]);

            return $event;
        });
    }

    public function rejectSpecimen(string $attemptPublicId, User $actor, int $expectedOrderVersion, string $reasonCode, ?string $note, string $key): LaboratoryMutationResult
    {
        return $this->audited($actor, 'LABORATORY_SPECIMEN_REJECT', $attemptPublicId, fn () => $this->rejectOperation($attemptPublicId, $actor, $expectedOrderVersion, $reasonCode, $note, $key));
    }

    private function rejectOperation(string $attemptPublicId, User $actor, int $expectedOrderVersion, string $reasonCode, ?string $note, string $key): LaboratoryMutationResult
    {
        $this->policy->assess($actor);
        $reasonCode = mb_strtoupper(trim($reasonCode));
        $note = $this->optional($note, 500);
        $this->key($key);
        if (! in_array($reasonCode, self::REJECTION_REASONS, true) || ($reasonCode === 'OTHER' && $note === null)) {
            throw new LaboratoryDenied('validation_failed', 'Alasan penolakan spesimen tidak valid.');
        }

        return $this->mutate($actor, 'LABORATORY_SPECIMEN_REJECT', $key, compact('attemptPublicId', 'expectedOrderVersion', 'reasonCode', 'note'), function (callable $replay) use ($attemptPublicId, $actor, $expectedOrderVersion, $reasonCode, $note) {
            [$order, $attempt] = $this->lockAttempt($attemptPublicId);
            if ($result = $replay()) {
                return $result;
            }
            if ($order->version !== $expectedOrderVersion) {
                throw new LaboratoryDenied('stale_version', 'Versi pesanan berubah.');
            }
            if ($order->status !== LaboratoryOrder::ORDERED || $attempt->state !== LaboratorySpecimenAttempt::RECEIVED) {
                throw new LaboratoryDenied('specimen_not_received', 'Spesimen belum siap ditolak.');
            }
            $event = $this->event($attempt, $actor, LaboratorySpecimenEvent::REJECTED, $reasonCode, $note);
            $attempt->update(['state' => LaboratorySpecimenAttempt::REJECTED, 'version' => $attempt->version + 1]);

            return $event;
        });
    }

    /** @param array<int, mixed> $results */
    public function saveDraft(string $orderPublicId, User $actor, int $expectedResultVersion, array $results, string $key): LaboratoryMutationResult
    {
        return $this->audited($actor, 'LABORATORY_RESULT_DRAFT_SAVE', $orderPublicId, fn () => $this->saveDraftOperation($orderPublicId, $actor, $expectedResultVersion, $results, $key));
    }

    /** @param array<int, mixed> $results */
    private function saveDraftOperation(string $orderPublicId, User $actor, int $expectedResultVersion, array $results, string $key): LaboratoryMutationResult
    {
        $this->policy->write($actor);
        $this->key($key);

        return $this->mutate($actor, 'LABORATORY_RESULT_DRAFT_SAVE', $key, compact('orderPublicId', 'expectedResultVersion', 'results'), function (callable $replay) use ($orderPublicId, $actor, $expectedResultVersion, $results) {
            $order = $this->lockOrder($orderPublicId);
            if ($result = $replay()) {
                return $result;
            }
            $accepted = $this->acceptedAttempt($order) ?? throw new LaboratoryDenied('accepted_specimen_required', 'Spesimen diterima wajib tersedia.');
            if ($order->status !== LaboratoryOrder::SPECIMEN_ACCEPTED) {
                throw new LaboratoryDenied('accepted_specimen_required', 'Pesanan belum siap dihasilkan.');
            }
            $latest = $this->latest($order);
            $actual = $latest ? $latest->version : 0;
            if ($actual !== $expectedResultVersion) {
                throw new LaboratoryDenied('stale_version', 'Versi hasil berubah.');
            }
            if ($latest && $latest->state !== LaboratoryResultVersion::DRAFT) {
                throw new LaboratoryDenied('result_already_verified', 'Hasil sudah terverifikasi.');
            }
            $normalized = $this->normalizeResults($order, $results);

            return $this->result($order, $accepted, $actor, $actual + 1, LaboratoryResultVersion::DRAFT, $normalized);
        });
    }

    /** @param array<string,mixed>|null $criticalCommunication */
    public function verify(string $orderPublicId, User $actor, int $expectedResultVersion, ?array $criticalCommunication, string $key): LaboratoryMutationResult
    {
        return $this->audited($actor, 'LABORATORY_RESULT_VERIFY', $orderPublicId, fn () => $this->verifyOperation($orderPublicId, $actor, $expectedResultVersion, $criticalCommunication, $key));
    }

    /** @param array<string, mixed>|null $criticalCommunication */
    private function verifyOperation(string $orderPublicId, User $actor, int $expectedResultVersion, ?array $criticalCommunication, string $key): LaboratoryMutationResult
    {
        $this->policy->verify($actor);
        $this->key($key);

        return $this->mutate($actor, 'LABORATORY_RESULT_VERIFY', $key, compact('orderPublicId', 'expectedResultVersion', 'criticalCommunication'), function (callable $replay) use ($orderPublicId, $actor, $expectedResultVersion, $criticalCommunication) {
            $order = $this->lockOrder($orderPublicId);
            if ($result = $replay()) {
                return $result;
            }
            $accepted = $this->acceptedAttempt($order) ?? throw new LaboratoryDenied('accepted_specimen_required', 'Spesimen diterima wajib tersedia.');
            $latest = $this->latest($order);
            if (! $latest || $latest->version !== $expectedResultVersion) {
                throw new LaboratoryDenied('stale_version', 'Versi hasil berubah.');
            }
            if ($latest->state !== LaboratoryResultVersion::DRAFT) {
                throw new LaboratoryDenied('verification_not_permitted', 'Draf tidak dapat diverifikasi.');
            }
            $results = $this->normalizeResults($order, $latest->results ?? []);
            $hasCritical = collect($results)->contains(fn (array $result): bool => $result['interpretation'] === 'CRITICAL');
            $communication = $this->normalizeCommunication($criticalCommunication, $hasCritical, $order, $accepted, $latest);
            $verified = $this->result($order, $accepted, $actor, $latest->version + 1, LaboratoryResultVersion::VERIFIED, $results, null, null, null);
            $this->createCommunication($verified, $actor, $communication);
            $order->update(['status' => LaboratoryOrder::REPORTED_VERIFIED, 'version' => $order->version + 1]);

            return $verified;
        });
    }

    /** @param array<int,mixed> $results
     * @param  array<string,mixed>|null  $criticalCommunication
     */
    public function amendVerified(string $orderPublicId, User $actor, int $expectedResultVersion, string $reasonCode, array $results, ?array $criticalCommunication, string $key): LaboratoryMutationResult
    {
        return $this->audited($actor, 'LABORATORY_RESULT_AMEND_VERIFIED', $orderPublicId, fn () => $this->amendOperation($orderPublicId, $actor, $expectedResultVersion, $reasonCode, $results, $criticalCommunication, $key));
    }

    /**
     * @param  array<int, mixed>  $results
     * @param  array<string, mixed>|null  $criticalCommunication
     */
    private function amendOperation(string $orderPublicId, User $actor, int $expectedResultVersion, string $reasonCode, array $results, ?array $criticalCommunication, string $key): LaboratoryMutationResult
    {
        $this->policy->verify($actor);
        $reasonCode = mb_strtoupper(trim($reasonCode));
        $this->key($key);
        if (! in_array($reasonCode, self::CORRECTION_REASONS, true)) {
            throw new LaboratoryDenied('validation_failed', 'Alasan koreksi tidak valid.');
        }

        return $this->mutate($actor, 'LABORATORY_RESULT_AMEND_VERIFIED', $key, compact('orderPublicId', 'expectedResultVersion', 'reasonCode', 'results', 'criticalCommunication'), function (callable $replay) use ($orderPublicId, $actor, $expectedResultVersion, $reasonCode, $results, $criticalCommunication) {
            $order = $this->lockOrder($orderPublicId);
            if ($result = $replay()) {
                return $result;
            }
            if ($order->status !== LaboratoryOrder::REPORTED_VERIFIED) {
                throw new LaboratoryDenied('result_not_verified', 'Hasil belum terverifikasi.');
            }
            $accepted = $this->acceptedAttempt($order) ?? throw new LaboratoryDenied('accepted_specimen_required', 'Spesimen diterima tidak ditemukan.');
            $latest = $this->latest($order);
            if (! $latest || $latest->version !== $expectedResultVersion) {
                throw new LaboratoryDenied('stale_version', 'Versi hasil berubah.');
            }
            if (! in_array($latest->state, [LaboratoryResultVersion::VERIFIED, LaboratoryResultVersion::AMENDED_VERIFIED], true)) {
                throw new LaboratoryDenied('amendment_base_stale', 'Amandemen harus mengikuti hasil terverifikasi terbaru.');
            }
            $baseId = $latest->base_verified_version_id ?? $latest->id;
            $baseDigest = $latest->base_verified_digest ?? $latest->content_digest;
            $priorDigest = $latest->state === LaboratoryResultVersion::AMENDED_VERIFIED ? $latest->content_digest : null;
            $normalized = $this->normalizeResults($order, $results);
            $isCritical = collect($normalized)->contains(fn (array $result): bool => $result['interpretation'] === 'CRITICAL');
            $communication = $this->normalizeCommunication($criticalCommunication, $isCritical, $order, $accepted, $latest);
            $amendment = $this->result($order, $accepted, $actor, $latest->version + 1, LaboratoryResultVersion::AMENDED_VERIFIED, $normalized, $reasonCode, $baseId, $baseDigest, $priorDigest, true);
            $this->createCommunication($amendment, $actor, $communication);

            return $amendment;
        });
    }

    public function acknowledge(string $orderPublicId, User $actor, int $expectedOrderVersion, int $expectedResultVersion, string $key): LaboratoryMutationResult
    {
        return $this->audited($actor, 'LABORATORY_RESULT_ACKNOWLEDGE', $orderPublicId, fn () => $this->acknowledgeOperation($orderPublicId, $actor, $expectedOrderVersion, $expectedResultVersion, $key));
    }

    private function acknowledgeOperation(string $orderPublicId, User $actor, int $expectedOrderVersion, int $expectedResultVersion, string $key): LaboratoryMutationResult
    {
        $this->policy->acknowledge($actor);
        $this->key($key);

        return $this->mutate($actor, 'LABORATORY_RESULT_ACKNOWLEDGE', $key, compact('orderPublicId', 'expectedOrderVersion', 'expectedResultVersion'), function (callable $replay) use ($orderPublicId, $actor, $expectedOrderVersion, $expectedResultVersion) {
            $order = $this->lockOrder($orderPublicId);
            if ($result = $replay()) {
                return $result;
            }
            if ($order->version !== $expectedOrderVersion) {
                throw new LaboratoryDenied('stale_version', 'Versi pesanan berubah.');
            }
            $latest = $this->latest($order);
            if (! $latest || $latest->version !== $expectedResultVersion || ! in_array($latest->state, [LaboratoryResultVersion::VERIFIED, LaboratoryResultVersion::AMENDED_VERIFIED], true)) {
                throw new LaboratoryDenied('stale_version', 'Versi hasil berubah.');
            }
            if ($this->current(LaboratoryResultAcknowledgement::query()->where('laboratory_result_version_id', $latest->id))->exists()) {
                throw new LaboratoryDenied('already_acknowledged', 'Versi hasil sudah diakui.');
            }

            $resultFingerprint = $this->fingerprints->current($latest);
            $acceptance = null;
            if ($order->ordered_by_user_id !== $actor->id) {
                $encounter = Encounter::query()->whereKey($order->encounter_id)->firstOrFail();
                $diagnosticFingerprint = EmergencyCanonicalJson::digest([
                    'LABORATORY', $order->public_id, $order->version, $order->status, $resultFingerprint,
                ]);
                $acceptance = $this->emergencyFollowUp->acceptedAssignmentForAcknowledgement(
                    $encounter,
                    'LABORATORY',
                    $order->public_id,
                    $actor,
                    $diagnosticFingerprint,
                );
                if ($acceptance === null) {
                    throw new LaboratoryDenied('acknowledgement_not_permitted', 'Dokter bukan pemesan dan tidak memiliki penugasan tindak lanjut yang diterima.');
                }
            }

            return LaboratoryResultAcknowledgement::query()->create([
                'laboratory_result_version_id' => $latest->id,
                'actor_user_id' => $actor->id,
                'emergency_follow_up_acceptance_id' => $acceptance?->id,
                'result_fingerprint' => $resultFingerprint,
                'acknowledged_at' => now(),
                'created_at' => now(),
            ]);
        });
    }

    /** @return array<string,mixed> */
    public function projection(LaboratoryOrder $order): array
    {
        $order->load(['specimenAttempts.events', 'cancellation', 'resultVersions.acknowledgement', 'resultVersions.criticalCommunication']);
        $latest = $order->resultVersions->sortByDesc('version')->first();
        $accepted = $order->specimenAttempts->firstWhere('state', LaboratorySpecimenAttempt::ACCEPTED);

        return [
            'public_id' => $order->public_id, 'status' => $order->status, 'version' => $order->version,
            'specimens' => $order->specimenAttempts
                ->sortBy('attempt_number')
                ->map(fn (LaboratorySpecimenAttempt $attempt): array => [
                    'public_id' => $attempt->public_id,
                    'attempt_number' => $attempt->attempt_number,
                    'label_identifier' => $attempt->label_identifier,
                    'state' => $attempt->state,
                    'collected_at' => $attempt->collected_at->toIso8601String(),
                    'events' => $attempt->events
                        ->sortBy('occurred_at')
                        ->map(fn (LaboratorySpecimenEvent $event): array => [
                            'public_id' => $event->public_id,
                            'event_type' => $event->event_type,
                            'reason_code' => $event->reason_code,
                            'note' => $event->note,
                            'occurred_at' => $event->occurred_at->toIso8601String(),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'accepted_specimen_public_id' => $accepted?->public_id,
            'result' => $latest ? ['public_id' => $latest->public_id, 'version' => $latest->version, 'state' => $latest->state, 'components' => $latest->results, 'critical_communication' => $latest->criticalCommunication ? $this->communicationProjection($latest->criticalCommunication) : null, 'acknowledgement' => $latest->acknowledgement ? ['public_id' => $latest->acknowledgement->public_id, 'acknowledged_at' => $latest->acknowledgement->acknowledged_at->toIso8601String()] : null] : null,
        ];
    }

    private function lockOrder(string $publicId): LaboratoryOrder
    {
        $candidate = LaboratoryOrder::query()->where('public_id', $publicId)->firstOrFail();
        $encounter = Encounter::query()->whereKey($candidate->encounter_id)->lockForUpdate()->firstOrFail();
        $cancelQuery = $encounter->cancellation();
        if (DB::connection()->getDriverName() === 'mysql') {
            $cancelQuery->sharedLock();
        }
        if (in_array($encounter->status, [Encounter::STATUS_CLOSED, Encounter::STATUS_CANCELLED], true) || $cancelQuery->exists()) {
            throw new LaboratoryDenied($encounter->status === Encounter::STATUS_CANCELLED ? 'encounter_cancelled' : 'encounter_closed', 'Episode sudah terminal.');
        }
        $order = LaboratoryOrder::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
        if ($order->encounter_id !== $encounter->id) {
            throw new LaboratoryDenied('encounter_not_eligible', 'Pesanan tidak konsisten.');
        }

        return $order;
    }

    /** @return array{LaboratoryOrder,LaboratorySpecimenAttempt} */
    private function lockAttempt(string $publicId): array
    {
        $candidate = LaboratorySpecimenAttempt::query()->where('public_id', $publicId)->firstOrFail();
        $orderCandidate = LaboratoryOrder::query()->whereKey($candidate->laboratory_order_id)->firstOrFail();
        $order = $this->lockOrder($orderCandidate->public_id);
        $attempt = LaboratorySpecimenAttempt::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
        if ($attempt->laboratory_order_id !== $order->id) {
            throw new LaboratoryDenied('resource_not_found', 'Spesimen tidak konsisten.');
        }

        return [$order, $attempt];
    }

    /** @return Builder<LaboratorySpecimenAttempt> */
    private function attempts(LaboratoryOrder $order): Builder
    {
        return $this->current(LaboratorySpecimenAttempt::query()->where('laboratory_order_id', $order->id));
    }

    /** @return Builder<LaboratoryResultVersion> */
    private function results(LaboratoryOrder $order): Builder
    {
        return $this->current(LaboratoryResultVersion::query()->where('laboratory_order_id', $order->id));
    }

    private function acceptedAttempt(LaboratoryOrder $order): ?LaboratorySpecimenAttempt
    {
        return $this->attempts($order)->where('state', LaboratorySpecimenAttempt::ACCEPTED)->first();
    }

    private function latest(LaboratoryOrder $order): ?LaboratoryResultVersion
    {
        return $this->results($order)->orderByDesc('version')->first();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function current(Builder $query): Builder
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $query->sharedLock();
        }

        return $query;
    }

    private function event(LaboratorySpecimenAttempt $attempt, User $actor, string $type, ?string $reason, ?string $note): LaboratorySpecimenEvent
    {
        return LaboratorySpecimenEvent::query()->create(['laboratory_specimen_attempt_id' => $attempt->id, 'actor_user_id' => $actor->id, 'event_type' => $type, 'reason_code' => $reason, 'note' => $note, 'occurred_at' => now(), 'created_at' => now()]);
    }

    /** @param array<int,array<string,mixed>> $results */
    private function result(LaboratoryOrder $order, LaboratorySpecimenAttempt $attempt, User $actor, int $version, string $state, array $results, ?string $reason = null, ?int $baseId = null, ?string $baseDigest = null, ?string $priorDigest = null, bool $verified = false): LaboratoryResultVersion
    {
        return LaboratoryResultVersion::query()->create(['laboratory_order_id' => $order->id, 'laboratory_specimen_attempt_id' => $attempt->id, 'author_user_id' => $actor->id, 'base_verified_version_id' => $baseId, 'version' => $version, 'state' => $state, 'results' => $results, 'correction_reason' => $reason, 'base_verified_digest' => $baseDigest, 'prior_amendment_digest' => $priorDigest, 'content_digest' => $this->fingerprints->digest($state, $results, $reason, $baseId, $baseDigest, $priorDigest), 'verified_at' => $verified || $state === LaboratoryResultVersion::VERIFIED ? now() : null, 'created_at' => now()]);
    }

    /**
     * @param  array<int, mixed>  $input
     * @return array<int, array<string, mixed>>
     */
    private function normalizeResults(LaboratoryOrder $order, array $input): array
    {
        $byCode = [];
        foreach ($input as $item) {
            if (! is_array($item)) {
                throw new LaboratoryDenied('validation_failed', 'Nilai komponen tidak valid.');
            }
            $code = mb_strtoupper(trim((string) ($item['code'] ?? $item['component_code'] ?? '')));
            if ($code === '' || isset($byCode[$code])) {
                throw new LaboratoryDenied('validation_failed', 'Komponen hasil duplikat atau kosong.');
            }
            $byCode[$code] = $item;
        }
        $normalized = [];
        foreach ($order->components_snapshot ?? [] as $component) {
            $code = (string) $component['code'];
            $item = $byCode[$code] ?? null;
            if (! is_array($item)) {
                throw new LaboratoryDenied('validation_failed', 'Semua komponen wajib diisi.');
            }
            unset($byCode[$code]);
            $value = trim((string) ($item['value'] ?? ''));
            $note = $this->optional(isset($item['note']) ? (string) $item['note'] : null, 500);
            $interpretation = mb_strtoupper(trim((string) ($item['interpretation'] ?? '')));
            if ($value === '' || mb_strlen($value) > 2000 || ! in_array($interpretation, ['NORMAL', 'ABNORMAL', 'CRITICAL'], true) || ($interpretation === 'CRITICAL' && ! ($component['critical_allowed'] ?? false))) {
                throw new LaboratoryDenied('validation_failed', 'Nilai atau interpretasi komponen tidak valid.');
            }
            if (($component['value_kind'] ?? null) === 'NUMERIC' && ! preg_match('/\A[+-]?(?:\d+(?:\.\d+)?|\.\d+)\z/', $value)) {
                throw new LaboratoryDenied('validation_failed', 'Nilai numerik harus berupa teks desimal.');
            }
            $normalized[] = ['code' => $code, 'display_name' => $component['display_name'], 'value_kind' => $component['value_kind'], 'unit_text' => $component['unit_text'] ?? null, 'reference_text' => $component['reference_text'] ?? null, 'critical_allowed' => (bool) ($component['critical_allowed'] ?? false), 'value' => $value, 'note' => $note, 'interpretation' => $interpretation];
        }
        if ($byCode !== []) {
            throw new LaboratoryDenied('validation_failed', 'Komponen hasil tidak dikenal.');
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>|null
     */
    private function normalizeCommunication(?array $input, bool $required, LaboratoryOrder $order, LaboratorySpecimenAttempt $accepted, LaboratoryResultVersion $source): ?array
    {
        if (! $required) {
            if ($input !== null) {
                throw new LaboratoryDenied('validation_failed', 'Komunikasi kritis hanya untuk hasil kritis.');
            }

            return null;
        }
        if ($input === null) {
            throw new LaboratoryDenied('critical_communication_required', 'Komunikasi hasil kritis wajib dicatat.');
        }
        $recipientPublicId = trim((string) ($input['recipient_user_public_id'] ?? ''));
        $recipient = User::query()->where('public_id', $recipientPublicId)->first();
        $method = mb_strtoupper(trim((string) ($input['communication_method'] ?? '')));
        $outcome = mb_strtoupper(trim((string) ($input['outcome'] ?? '')));
        $note = $this->optional(isset($input['note']) ? (string) $input['note'] : null, 1000);
        $communicatedAt = trim((string) ($input['communicated_at'] ?? ''));
        if ($communicatedAt === '') {
            throw new LaboratoryDenied('validation_failed', 'Waktu komunikasi wajib diisi.');
        }
        try {
            $at = CarbonImmutable::parse($communicatedAt);
        } catch (\Throwable) {
            throw new LaboratoryDenied('validation_failed', 'Waktu komunikasi tidak valid.');
        }
        if (! in_array($method, self::COMMUNICATION_METHODS, true) || ! in_array($outcome, self::COMMUNICATION_OUTCOMES, true) || $at->isFuture()) {
            throw new LaboratoryDenied('validation_failed', 'Data komunikasi kritis tidak valid.');
        }
        $recipientIsExactPhysician = $recipient instanceof User
            && $recipient->status === 'ACTIVE'
            && $this->policy->can($recipient, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::LABORATORY_ORDER_CREATE);
        if (! $recipientIsExactPhysician
            || ($outcome === 'COMMUNICATED' && $recipient->id !== $order->ordered_by_user_id)
            || ($outcome === 'ESCALATED' && $note === null)) {
            throw new LaboratoryDenied('critical_recipient_mismatch', 'Penerima komunikasi kritis tidak sesuai.');
        }
        $acceptedAt = $this->current(LaboratorySpecimenEvent::query()
            ->where('laboratory_specimen_attempt_id', $accepted->id)
            ->where('event_type', LaboratorySpecimenEvent::ACCEPTED))->value('occurred_at');
        $notBefore = CarbonImmutable::instance($source->created_at);
        if ($acceptedAt !== null && CarbonImmutable::parse($acceptedAt)->isAfter($notBefore)) {
            $notBefore = CarbonImmutable::parse($acceptedAt);
        }
        if ($at->isBefore($notBefore)) {
            throw new LaboratoryDenied('validation_failed', 'Waktu komunikasi mendahului bukti spesimen atau hasil terbaru.');
        }

        return ['recipient_user_id' => $recipient->id, 'communication_method' => $method, 'outcome' => $outcome, 'note' => $note, 'communicated_at' => $at];
    }

    /** @param array<string,mixed>|null $communication */
    private function createCommunication(LaboratoryResultVersion $version, User $actor, ?array $communication): ?LaboratoryCriticalCommunication
    {
        if ($communication === null) {
            return null;
        }

        $record = new LaboratoryCriticalCommunication(['laboratory_result_version_id' => $version->id, 'actor_user_id' => $actor->id, ...$communication, 'created_at' => now()]);
        $record->public_id = (string) Str::ulid();
        $record->content_digest = $this->fingerprints->communicationDigest($record);
        $record->save();

        return $record;
    }

    /** @return array<string, mixed> */
    private function communicationProjection(LaboratoryCriticalCommunication $communication): array
    {
        return ['public_id' => $communication->public_id, 'communication_method' => $communication->communication_method, 'outcome' => $communication->outcome, 'note' => $communication->note, 'communicated_at' => $communication->communicated_at->toIso8601String()];
    }

    private function optional(?string $value, int $max): ?string
    {
        $value = $value === null ? null : trim($value);
        if ($value !== null && mb_strlen($value) > $max) {
            throw new LaboratoryDenied('validation_failed', 'Teks terlalu panjang.');
        }

        return $value === '' ? null : $value;
    }

    private function key(string $key): void
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/', $key)) {
            throw new LaboratoryDenied('validation_failed', 'Kunci idempotensi tidak valid.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(callable(): ?LaboratoryMutationResult): (LaboratoryRecord|LaboratoryMutationResult)  $write
     */
    private function mutate(User $actor, string $operation, string $key, array $payload, callable $write): LaboratoryMutationResult
    {
        $key = mb_strtolower(trim($key));
        $payloadDigest = LaboratoryCanonicalJson::digest([$operation, $payload, 'CROSS_SETTING_LABORATORY_SPECIMEN_RESULT_V1']);
        try {
            return LaboratoryMutationScope::run(fn () => DB::transaction(function () use ($actor, $operation, $key, $payloadDigest, $write): LaboratoryMutationResult {
                if ($replay = $this->replay($actor, $operation, $key, $payloadDigest)) {
                    return $replay;
                }
                $written = $write(fn () => $this->replay($actor, $operation, $key, $payloadDigest, true));
                if ($written instanceof LaboratoryMutationResult) {
                    return $written;
                }
                $state = $this->resultState($written);
                $version = (int) ($written->version ?? 1);
                if ($this->audit->record('laboratory.workflow.mutate', 'laboratory_record', $written->public_id, $actor, 'SUCCESS', metadata: ['operation' => $operation, 'state' => $state, 'version' => $version]) === null) {
                    throw new LaboratoryAuditUnavailable('Audit laboratorium gagal.');
                }
                LaboratoryOperationReceipt::query()->create(['actor_user_id' => $actor->id, 'operation' => $operation, 'idempotency_key' => $key, 'payload_digest' => $payloadDigest, 'result_type' => $this->resultType($written), 'result_public_id' => $written->public_id, 'result_version' => $version, 'result_state' => $state, 'result_digest' => $this->resultDigest($written), 'request_correlation_id' => request()->attributes->get('request_id'), 'completed_at' => now()]);

                return new LaboratoryMutationResult($written, false);
            }, 3));
        } catch (UniqueConstraintViolationException) {
            if ($replay = $this->replay($actor, $operation, $key, $payloadDigest, true)) {
                return $replay;
            }
            throw new LaboratoryDenied('concurrent_state_conflict', 'Operasi bersamaan telah mengubah keadaan.');
        }
    }

    private function replay(User $actor, string $operation, string $key, string $payloadDigest, bool $current = false): ?LaboratoryMutationResult
    {
        $query = LaboratoryOperationReceipt::query()->where('actor_user_id', $actor->id)->where('operation', $operation)->where('idempotency_key', $key);
        if ($current && DB::connection()->getDriverName() === 'mysql') {
            $query->sharedLock();
        }
        $receipt = $query->first();
        if (! $receipt) {
            return null;
        }
        if (! hash_equals((string) $receipt->payload_digest, $payloadDigest)) {
            throw new LaboratoryDenied('idempotency_key_conflict', 'Kunci idempotensi sudah dipakai.');
        }

        return new LaboratoryMutationResult($this->resolve($receipt, $current), true);
    }

    private function resolve(LaboratoryOperationReceipt $receipt, bool $current): Model
    {
        $map = [
            LaboratoryOperationReceipt::RESULT_ORDER => LaboratoryOrder::class,
            LaboratoryOperationReceipt::RESULT_CANCELLATION => LaboratoryOrderCancellation::class,
            LaboratoryOperationReceipt::RESULT_SPECIMEN_ATTEMPT => LaboratorySpecimenAttempt::class,
            LaboratoryOperationReceipt::RESULT_SPECIMEN_EVENT => LaboratorySpecimenEvent::class,
            LaboratoryOperationReceipt::RESULT_RESULT_VERSION => LaboratoryResultVersion::class,
            LaboratoryOperationReceipt::RESULT_ACKNOWLEDGEMENT => LaboratoryResultAcknowledgement::class,
        ];
        $class = $map[$receipt->result_type] ?? throw new LaboratoryDenied('receipt_corrupt', 'Jenis bukti operasi tidak dikenal.');
        $query = $class::query()->where('public_id', $receipt->result_public_id);
        if ($current && DB::connection()->getDriverName() === 'mysql') {
            $query->sharedLock();
        }
        $record = $query->firstOrFail();
        if ($receipt->result_type !== $this->resultType($record) || ! hash_equals((string) $receipt->result_digest, $this->resultDigest($record)) || $receipt->result_state !== $this->receiptState($record, $receipt)) {
            throw new LaboratoryDenied('receipt_corrupt', 'Ikatan bukti operasi tidak valid.');
        }

        return $record;
    }

    private function resultType(Model $record): string
    {
        return match (true) {
            $record instanceof LaboratoryOrder => LaboratoryOperationReceipt::RESULT_ORDER,
            $record instanceof LaboratoryOrderCancellation => LaboratoryOperationReceipt::RESULT_CANCELLATION,
            $record instanceof LaboratorySpecimenAttempt => LaboratoryOperationReceipt::RESULT_SPECIMEN_ATTEMPT,
            $record instanceof LaboratorySpecimenEvent => LaboratoryOperationReceipt::RESULT_SPECIMEN_EVENT,
            $record instanceof LaboratoryResultVersion => LaboratoryOperationReceipt::RESULT_RESULT_VERSION,
            $record instanceof LaboratoryResultAcknowledgement => LaboratoryOperationReceipt::RESULT_ACKNOWLEDGEMENT,
            default => throw new LaboratoryDenied('receipt_corrupt', 'Jenis hasil operasi tidak didukung.'),
        };
    }

    private function resultState(Model $record): string
    {
        return match (true) {
            $record instanceof LaboratoryOrder => $record->status,
            $record instanceof LaboratoryOrderCancellation => 'CANCELLED',
            $record instanceof LaboratorySpecimenAttempt => $record->state,
            $record instanceof LaboratorySpecimenEvent => $record->event_type,
            $record instanceof LaboratoryResultVersion => $record->state,
            $record instanceof LaboratoryResultAcknowledgement => 'ACKNOWLEDGED',
            default => throw new LaboratoryDenied('receipt_corrupt', 'Status hasil operasi tidak didukung.'),
        };
    }

    private function receiptState(Model $record, LaboratoryOperationReceipt $receipt): string
    {
        // A collection or order head may legally advance after its receipt was
        // written. Their receipts retain the original operation state.
        if ($record instanceof LaboratoryOrder && $receipt->operation === 'LABORATORY_ORDER_CREATE') {
            return LaboratoryOrder::ORDERED;
        }
        if ($record instanceof LaboratorySpecimenAttempt && $receipt->operation === 'LABORATORY_SPECIMEN_COLLECT') {
            return LaboratorySpecimenAttempt::COLLECTED;
        }

        return $this->resultState($record);
    }

    private function resultDigest(Model $record): string
    {
        $payload = match (true) {
            $record instanceof LaboratoryOrder => $this->orderPayload($record),
            $record instanceof LaboratoryOrderCancellation => $this->cancellationPayload($record),
            $record instanceof LaboratorySpecimenAttempt => $this->attemptPayload($record),
            $record instanceof LaboratorySpecimenEvent => $this->eventPayload($record),
            $record instanceof LaboratoryResultVersion => $this->resultVersionPayload($record),
            $record instanceof LaboratoryResultAcknowledgement => $this->acknowledgementPayload($record),
            default => throw new LaboratoryDenied('receipt_corrupt', 'Hasil operasi tidak dapat diikat.'),
        };

        return LaboratoryCanonicalJson::digest($payload);
    }

    /** @return list<mixed> */
    private function orderPayload(LaboratoryOrder $order): array
    {
        $this->fingerprints->verifyOrderSnapshot($order);

        return [$order->public_id, $order->encounter_id, $order->master_id, $order->ordered_by_user_id, $order->master_version, $order->master_version_public_id, $order->master_content_digest, $order->master_code, $order->master_display_name, $order->specimen_type_snapshot, $order->collection_instruction_snapshot, $order->components_snapshot, $order->care_setting, $order->encounter_status_snapshot, $order->encounter_number_snapshot, $order->care_location_label_snapshot, $order->priority, $order->clinical_question, $order->ordered_at->toJSON(), $order->created_at->toJSON()];
    }

    /** @return list<mixed> */
    private function cancellationPayload(LaboratoryOrderCancellation $cancellation): array
    {
        $order = $this->current(LaboratoryOrder::query()->whereKey($cancellation->laboratory_order_id))->firstOrFail();
        $this->fingerprints->verifyOrderSnapshot($order);

        return [$cancellation->public_id, $cancellation->laboratory_order_id, $cancellation->actor_user_id, $cancellation->reason_code, $cancellation->note, $cancellation->cancelled_at->toJSON(), $cancellation->created_at->toJSON()];
    }

    /** @return list<mixed> */
    private function attemptPayload(LaboratorySpecimenAttempt $attempt): array
    {
        $order = $this->current(LaboratoryOrder::query()->whereKey($attempt->laboratory_order_id))->firstOrFail();
        $this->fingerprints->verifyOrderSnapshot($order);
        $this->fingerprints->verifySpecimenEventChain($attempt);

        return [$attempt->public_id, $attempt->laboratory_order_id, $attempt->attempt_number, $attempt->label_identifier, $attempt->collector_user_id, $attempt->collected_at->toJSON(), $attempt->collection_note, $attempt->created_at->toJSON()];
    }

    /** @return list<mixed> */
    private function acknowledgementPayload(LaboratoryResultAcknowledgement $acknowledgement): array
    {
        $version = $this->current(LaboratoryResultVersion::query()->whereKey($acknowledgement->laboratory_result_version_id))->firstOrFail();
        $current = $this->fingerprints->current($version);
        if (! hash_equals($current, (string) $acknowledgement->result_fingerprint)) {
            throw new LaboratoryDenied('receipt_corrupt', 'Sidik hasil pengakuan tidak cocok.');
        }

        return [
            $acknowledgement->public_id,
            $acknowledgement->laboratory_result_version_id,
            $acknowledgement->actor_user_id,
            $acknowledgement->emergency_follow_up_acceptance_id,
            $acknowledgement->result_fingerprint,
            $acknowledgement->acknowledged_at->toJSON(),
            $acknowledgement->created_at->toJSON(),
        ];
    }

    /** @return list<mixed> */
    private function eventPayload(LaboratorySpecimenEvent $event): array
    {
        $attempt = $this->current(LaboratorySpecimenAttempt::query()->whereKey($event->laboratory_specimen_attempt_id))->firstOrFail();
        $this->fingerprints->verifySpecimenEventChain($attempt);

        return [$event->public_id, $event->laboratory_specimen_attempt_id, $event->actor_user_id, $event->event_type, $event->reason_code, $event->note, $event->occurred_at->toJSON(), $event->created_at->toJSON()];
    }

    /** @return list<mixed> */
    private function resultVersionPayload(LaboratoryResultVersion $result): array
    {
        $order = $this->current(LaboratoryOrder::query()->whereKey($result->laboratory_order_id))->firstOrFail();
        $attempt = $this->current(LaboratorySpecimenAttempt::query()->whereKey($result->laboratory_specimen_attempt_id))->firstOrFail();
        $this->fingerprints->verifyOrderSnapshot($order);
        $this->fingerprints->verifySpecimenEventChain($attempt);
        $computed = $this->fingerprints->contentDigest($result);
        if (! hash_equals($computed, (string) $result->content_digest)) {
            throw new LaboratoryDenied('receipt_corrupt', 'Digest versi hasil tidak cocok.');
        }
        $chain = in_array($result->state, [LaboratoryResultVersion::VERIFIED, LaboratoryResultVersion::AMENDED_VERIFIED], true) ? $this->fingerprints->current($result) : null;

        return [$result->public_id, $result->laboratory_order_id, $result->laboratory_specimen_attempt_id, $result->author_user_id, $result->version, $result->state, $computed, $chain, $result->verified_at?->toJSON(), $result->created_at->toJSON()];
    }

    /** @param callable(): LaboratoryMutationResult $callback */
    private function audited(User $actor, string $operation, ?string $resource, callable $callback): LaboratoryMutationResult
    {
        try {
            return $callback();
        } catch (AuthorizationException $e) {
            $this->auditDenial($actor, $operation, $resource, 'role_not_permitted');
            throw $e;
        } catch (ModelNotFoundException $e) {
            $this->auditDenial($actor, $operation, $resource, 'resource_not_found');
            throw $e;
        } catch (LaboratoryDenied $e) {
            $this->auditDenial($actor, $operation, $resource, $e->reason);
            throw $e;
        }
    }

    private function auditDenial(User $actor, string $operation, ?string $resource, string $reason): void
    {
        $resource = is_string($resource) && strlen($resource) === 26 ? $resource : null;
        if ($this->audit->record('laboratory.workflow.mutate', 'laboratory_record', $resource, $actor, 'DENIED', $reason, ['operation' => $operation]) === null) {
            throw new LaboratoryAuditUnavailable('Audit penolakan laboratorium gagal.');
        }
    }
}
