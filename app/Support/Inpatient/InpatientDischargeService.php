<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientDischarge;
use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeCodingSourceVersion;
use App\Models\InpatientDischargeOperationReceipt;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientDischargeSummaryVersion;
use App\Models\InpatientLocationEvent;
use App\Models\InpatientWard;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\CanonicalJson;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
use App\Support\Registration\InpatientBedClaimGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class InpatientDischargeService
{
    private const ACTION = 'clinical.inpatient.discharge.execute';

    private const IDEMPOTENCY_KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/';

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly InpatientDischargeActorPolicy $actorPolicy,
        private readonly CanonicalInpatientBedOperationLockCoordinator $locks,
        private readonly InpatientBedClaimGuard $claims,
        private readonly InpatientDischargeSummaryEvidenceDigest $summaryEvidenceDigest,
        private readonly InpatientDischargeCodingSourceEvidenceDigest $codingSourceEvidenceDigest,
        private readonly PharmacyEncounterLifecycleGate $pharmacy,
    ) {}

    public function execute(
        string $encounterPublicId,
        User $actor,
        int $expectedSummaryVersion,
        int $expectedLocationSequence,
        string $sourceBedPublicId,
        string $idempotencyKey,
        ?string $requestCorrelationId = null,
    ): InpatientDischargeResult {
        $this->authorizeOrAudit($actor, $encounterPublicId);

        try {
            $this->validateInput($encounterPublicId, $expectedSummaryVersion, $expectedLocationSequence, $sourceBedPublicId, $idempotencyKey, $requestCorrelationId);
            $canonicalKey = mb_strtolower($idempotencyKey);
            $digest = $this->digest($encounterPublicId, $expectedSummaryVersion, $expectedLocationSequence, $sourceBedPublicId);
            if ($replay = $this->replayIfPresent($actor, $canonicalKey, $digest)) {
                return $replay;
            }

            return $this->mutate($encounterPublicId, $actor, $expectedSummaryVersion, $expectedLocationSequence, $sourceBedPublicId, $canonicalKey, $digest, $requestCorrelationId);
        } catch (UniqueConstraintViolationException) {
            $digest = $this->digest($encounterPublicId, $expectedSummaryVersion, $expectedLocationSequence, $sourceBedPublicId);
            if ($replay = $this->replayIfPresent($actor, mb_strtolower($idempotencyKey), $digest)) {
                return $replay;
            }
            $denial = new InpatientDischargeDenied('concurrent_change', 'Pemulangan berubah bersamaan. Muat ulang sebelum melanjutkan.');
            $this->recordDenialOrFail($encounterPublicId, $actor, $denial);
            throw $denial;
        } catch (InpatientDischargeDenied $denial) {
            $this->recordDenialOrFail($encounterPublicId, $actor, $denial);
            throw $denial;
        } catch (InvalidArgumentException $invalid) {
            $denial = new InpatientDischargeDenied('validation_failed', $invalid->getMessage());
            $this->recordDenialOrFail($encounterPublicId, $actor, $denial);
            throw $denial;
        } catch (QueryException) {
            $denial = new InpatientDischargeDenied('persistence_unavailable', 'Pemulangan belum dapat disimpan. Silakan coba kembali.', 503);
            $this->recordDenialOrFail($encounterPublicId, $actor, $denial);
            throw $denial;
        }
    }

    private function mutate(
        string $encounterPublicId,
        User $actor,
        int $expectedSummaryVersion,
        int $expectedLocationSequence,
        string $sourceBedPublicId,
        string $canonicalKey,
        string $digest,
        ?string $requestCorrelationId,
    ): InpatientDischargeResult {
        return DB::transaction(function () use ($encounterPublicId, $actor, $expectedSummaryVersion, $expectedLocationSequence, $sourceBedPublicId, $canonicalKey, $digest, $requestCorrelationId): InpatientDischargeResult {
            $candidate = Encounter::query()->where('public_id', $encounterPublicId)->first();
            if (! $candidate instanceof Encounter) {
                throw new InpatientDischargeDenied('encounter_missing', 'Episode rawat inap tidak ditemukan.', 404);
            }
            $candidateBed = $candidate->inpatient_bed_id === null ? null : InpatientBed::query()->whereKey($candidate->inpatient_bed_id)->first();
            if (! $candidateBed instanceof InpatientBed || $candidate->bed_code === null) {
                throw new InpatientDischargeDenied('placement_missing', 'Penempatan rawat inap saat ini tidak tersedia.');
            }

            $this->locks->lockPatientClaimMutexes([(int) $candidate->patient_id]);
            $this->pharmacy->lockInventoryForEncounter((int) $candidate->id);
            $this->locks->lockMutexes([$candidate->bed_code]);
            $encounter = $this->locks->lockEncounters([$candidate->id])->get($candidate->id);
            if (! $encounter instanceof Encounter) {
                throw new InpatientDischargeDenied('encounter_missing', 'Episode rawat inap tidak ditemukan.', 404);
            }
            $ward = $this->locks->lockWards([$candidateBed->ward_id])->get($candidateBed->ward_id);
            $bed = $this->locks->lockBeds([$candidateBed->id])->get($candidateBed->id);

            if ($replay = $this->replayIfPresent($actor, $canonicalKey, $digest)) {
                return $replay;
            }
            $this->assertEncounterAndPlacement($encounter, $candidateBed, $bed, $ward, $sourceBedPublicId);

            $summary = InpatientDischargeSummary::query()->where('encounter_id', $encounter->id)->lockForUpdate()->first();
            $this->assertSummary($summary, $encounter, $actor, $expectedSummaryVersion);
            assert($summary instanceof InpatientDischargeSummary);
            $versionQuery = InpatientDischargeSummaryVersion::query()
                ->where('inpatient_discharge_summary_id', $summary->id)
                ->where('version', $expectedSummaryVersion);
            $version = DB::connection()->getDriverName() === 'mysql'
                ? $versionQuery->sharedLock()->first()
                : $versionQuery->first();
            if (! $version instanceof InpatientDischargeSummaryVersion
                || $version->summary_state !== InpatientDischargeSummary::STATE_FINAL
                || $version->definition_version !== InpatientDischargeSummary::DEFINITION_VERSION
                || $version->encounter_public_id !== $encounter->public_id) {
                throw new InpatientDischargeDenied('summary_binding_invalid', 'Versi Final ringkasan pulang tidak terikat pada episode ini.', 503);
            }

            $codingSource = InpatientDischargeCodingSource::query()->where('encounter_id', $encounter->id)->lockForUpdate()->first();
            if (! $codingSource instanceof InpatientDischargeCodingSource
                || $codingSource->source_state !== InpatientDischargeCodingSource::STATE_FINAL
                || $codingSource->definition_version !== InpatientDischargeCodingSource::DEFINITION_VERSION
                || $codingSource->assigned_physician_user_id !== $actor->id
                || $codingSource->finalized_by_user_id !== $actor->id) {
                throw new InpatientDischargeDenied('discharge_coding_source_not_final', 'Diagnosis dan prosedur akhir wajib berstatus Final sebelum pemulangan.');
            }
            $codingVersionQuery = InpatientDischargeCodingSourceVersion::query()->where('inpatient_discharge_coding_source_id', $codingSource->id)->where('version', $codingSource->version);
            $codingVersion = DB::connection()->getDriverName() === 'mysql' ? $codingVersionQuery->sharedLock()->first() : $codingVersionQuery->first();
            if (! $codingVersion instanceof InpatientDischargeCodingSourceVersion
                || $codingVersion->source_state !== InpatientDischargeCodingSource::STATE_FINAL
                || $codingVersion->definition_version !== InpatientDischargeCodingSource::DEFINITION_VERSION
                || $codingVersion->encounter_public_id !== $encounter->public_id) {
                throw new InpatientDischargeDenied('discharge_coding_source_binding_invalid', 'Versi Final diagnosis dan prosedur akhir tidak terikat pada episode ini.', 503);
            }

            $locationQuery = InpatientLocationEvent::query()
                ->where('encounter_id', $encounter->id)
                ->orderByDesc('sequence');
            $location = DB::connection()->getDriverName() === 'mysql'
                ? $locationQuery->sharedLock()->first()
                : $locationQuery->first();
            $currentSequence = $location instanceof InpatientLocationEvent ? $location->sequence : 0;
            if ($currentSequence !== $expectedLocationSequence) {
                throw new InpatientDischargeDenied('stale_location', 'Lokasi rawat inap telah berubah.', metadata: ['current_location_sequence' => $currentSequence]);
            }
            $this->assertSummaryPlacementProvenance($version, $ward, $bed, $location, $currentSequence, $encounter->status);
            $this->assertCodingSourcePlacementProvenance($codingVersion, $ward, $bed, $location, $currentSequence, $encounter->status);
            $claims = $this->claims->lockClaimsAfterCanonicalMutexes($candidateBed->code, $candidateBed->id);
            if ($claims->count() !== 1 || (int) $claims->first()->id !== $encounter->id) {
                throw new InpatientDischargeDenied('duplicate_current_claim', 'Klaim tempat tidur saat ini tidak konsisten.');
            }

            $before = $encounter->status;
            $now = now((string) config('app.timezone', 'Asia/Jakarta'));
            $summaryContentDigest = $this->summaryEvidenceDigest->content($version);
            $summaryProvenanceDigest = $this->summaryEvidenceDigest->provenance($summary, $version);
            $codingContentDigest = $this->codingSourceEvidenceDigest->content($codingVersion);
            $codingProvenanceDigest = $this->codingSourceEvidenceDigest->provenance($codingSource, $codingVersion);
            $discharge = InpatientDischargeMutationScope::run(fn (): InpatientDischarge => InpatientDischarge::query()->create([
                'encounter_id' => $encounter->id,
                'inpatient_discharge_summary_id' => $summary->id,
                'inpatient_discharge_summary_version_id' => $version->id,
                'actor_user_id' => $actor->id,
                'disposition_code' => InpatientDischarge::DISPOSITION_ROUTINE_HOME,
                'disposition_label' => InpatientDischarge::DISPOSITION_ROUTINE_HOME_LABEL,
                'discharge_summary_public_id' => $summary->public_id,
                'discharge_summary_version' => $summary->version,
                'discharge_summary_version_public_id' => $version->public_id,
                'discharge_summary_content_digest' => $summaryContentDigest,
                'discharge_summary_provenance_digest' => $summaryProvenanceDigest,
                'inpatient_discharge_coding_source_version_id' => $codingVersion->id,
                'discharge_coding_source_public_id' => $codingSource->public_id,
                'discharge_coding_source_version' => $codingVersion->version,
                'discharge_coding_source_version_public_id' => $codingVersion->public_id,
                'discharge_coding_source_content_digest' => $codingContentDigest,
                'discharge_coding_source_provenance_digest' => $codingProvenanceDigest,
                'location_sequence' => $currentSequence,
                'source_ward_public_id' => $ward->public_id,
                'source_ward_code' => $ward->code,
                'source_bed_public_id' => $bed->public_id,
                'source_bed_code' => $bed->code,
                'encounter_status_before' => $before,
                'encounter_status_after' => Encounter::STATUS_READY_FOR_RM,
                'payload_digest' => $digest,
                'request_correlation_id' => $requestCorrelationId,
                'discharged_at' => $now,
            ]));

            $encounter->status = Encounter::STATUS_READY_FOR_RM;
            $encounter->save();

            $audit = $this->auditRecorder->record(
                action: self::ACTION,
                resourceType: 'inpatient_discharge',
                resourceId: $discharge->public_id,
                actor: $actor,
                outcome: 'SUCCESS',
                metadata: [
                    'encounter_public_id' => $encounter->public_id,
                    'disposition_code' => $discharge->disposition_code,
                    'discharge_summary_public_id' => $summary->public_id,
                    'discharge_summary_version' => $summary->version,
                    'discharge_summary_version_public_id' => $version->public_id,
                    'discharge_summary_content_digest' => $summaryContentDigest,
                    'discharge_summary_provenance_digest' => $summaryProvenanceDigest,
                    'discharge_coding_source_public_id' => $codingSource->public_id,
                    'discharge_coding_source_version' => $codingVersion->version,
                    'discharge_coding_source_version_public_id' => $codingVersion->public_id,
                    'discharge_coding_source_content_digest' => $codingContentDigest,
                    'discharge_coding_source_provenance_digest' => $codingProvenanceDigest,
                    'location_sequence' => $currentSequence,
                    'source_ward_public_id' => $ward->public_id,
                    'source_ward_code' => $ward->code,
                    'source_bed_public_id' => $bed->public_id,
                    'source_bed_code' => $bed->code,
                    'encounter_status_before' => $before,
                    'encounter_status_after' => Encounter::STATUS_READY_FOR_RM,
                    'inpatient_bed_released' => true,
                    'payload_digest' => $digest,
                ],
            );
            if ($audit === null) {
                throw new InpatientDischargeAuditUnavailable('Pemulangan dibatalkan karena audit wajib tidak dapat direkam.');
            }

            InpatientDischargeMutationScope::run(fn () => InpatientDischargeOperationReceipt::query()->create([
                'encounter_id' => $encounter->id,
                'actor_user_id' => $actor->id,
                'inpatient_discharge_summary_version_id' => $version->id,
                'inpatient_discharge_coding_source_version_id' => $codingVersion->id,
                'operation' => InpatientDischargeOperationReceipt::OPERATION_EXECUTE,
                'idempotency_key' => $canonicalKey,
                'payload_digest' => $digest,
                'result_discharge_public_id' => $discharge->public_id,
                'discharge_summary_version_public_id' => $version->public_id,
                'discharge_summary_content_digest' => $summaryContentDigest,
                'discharge_summary_provenance_digest' => $summaryProvenanceDigest,
                'discharge_coding_source_public_id' => $codingSource->public_id,
                'discharge_coding_source_version' => $codingVersion->version,
                'discharge_coding_source_version_public_id' => $codingVersion->public_id,
                'discharge_coding_source_content_digest' => $codingContentDigest,
                'discharge_coding_source_provenance_digest' => $codingProvenanceDigest,
                'request_correlation_id' => $requestCorrelationId,
                'completed_at' => $now,
            ]));

            return new InpatientDischargeResult($discharge, false);
        }, 3);
    }

    private function assertEncounterAndPlacement(Encounter $encounter, InpatientBed $candidateBed, mixed $bed, mixed $ward, string $sourceBedPublicId): void
    {
        if ($encounter->care_setting !== Encounter::CARE_SETTING_INPATIENT) {
            throw new InpatientDischargeDenied('not_inpatient', 'Episode bukan rawat inap.');
        }
        if (! in_array($encounter->status, Encounter::BED_OCCUPYING_STATUSES, true)) {
            throw new InpatientDischargeDenied($encounter->status === Encounter::STATUS_READY_FOR_RM ? 'already_discharged' : 'encounter_not_active', 'Episode tidak dapat dipulangkan.');
        }
        if ($encounter->cancellation()->exists()) {
            throw new InpatientDischargeDenied('encounter_cancelled', 'Episode telah dibatalkan.');
        }
        if (! $encounter->patient()->where('is_synthetic', true)->exists()) {
            throw new InpatientDischargeDenied('synthetic_only', 'Episode tidak berada dalam batas data yang diizinkan.');
        }
        if ($this->pharmacy->inspect($encounter)['active_prescription_public_ids'] !== []) {
            throw new InpatientDischargeDenied('active_pharmacy_prescriptions', 'Pasien belum dapat dipulangkan karena masih ada resep obat aktif.');
        }
        if (! $bed instanceof InpatientBed || ! $ward instanceof InpatientWard
            || $encounter->inpatient_bed_id !== $candidateBed->id || $encounter->bed_code !== $candidateBed->code
            || $bed->public_id !== $sourceBedPublicId) {
            throw new InpatientDischargeDenied('source_bed_changed', 'Tempat tidur sumber telah berubah.');
        }
        if ($bed->state !== InpatientBed::STATE_ACTIVE || $ward->state !== InpatientWard::STATE_ACTIVE) {
            throw new InpatientDischargeDenied('placement_inactive', 'Penempatan rawat inap tidak aktif.');
        }
    }

    private function assertSummary(mixed $summary, Encounter $encounter, User $actor, int $expectedVersion): void
    {
        if (! $summary instanceof InpatientDischargeSummary) {
            throw new InpatientDischargeDenied('summary_missing', 'Ringkasan pulang Final belum tersedia.');
        }
        if ($summary->assigned_physician_user_id !== $actor->id) {
            throw new InpatientDischargeDenied('physician_assignment_mismatch', 'Hanya dokter yang ditetapkan pada ringkasan pulang dapat memulangkan pasien.', 403);
        }
        if ($summary->definition_version !== InpatientDischargeSummary::DEFINITION_VERSION
            || $summary->summary_state !== InpatientDischargeSummary::STATE_FINAL
            || $summary->finalized_by_user_id !== $actor->id) {
            throw new InpatientDischargeDenied('summary_not_final', 'Ringkasan pulang yang tepat harus berstatus Final.');
        }
        if ($summary->version !== $expectedVersion || $summary->encounter_id !== $encounter->id) {
            throw new InpatientDischargeDenied('stale_summary', 'Versi ringkasan pulang telah berubah.', metadata: ['current_summary_version' => $summary->version]);
        }
    }

    private function assertSummaryPlacementProvenance(
        InpatientDischargeSummaryVersion $version,
        InpatientWard $ward,
        InpatientBed $bed,
        ?InpatientLocationEvent $location,
        int $currentSequence,
        string $expectedEncounterStatus,
    ): void {
        if ($version->encounter_status !== $expectedEncounterStatus
            || $version->location_sequence !== $currentSequence
            || $version->ward_public_id !== $ward->public_id
            || $version->ward_code !== $ward->code
            || $version->bed_public_id !== $bed->public_id
            || $version->bed_code !== $bed->code) {
            throw new InpatientDischargeDenied('summary_placement_stale', 'Provenans lokasi ringkasan pulang tidak lagi sama dengan penempatan aktif.');
        }
        if ($currentSequence === 0) {
            if ($location !== null
                || $version->location_event_public_id !== null
                || $version->location_event_type !== null
                || $version->history_baseline !== InpatientDischargeSummary::HISTORY_BASELINE_LEGACY_CURRENT_PLACEMENT
                || $version->history_complete !== false) {
                throw new InpatientDischargeDenied('summary_placement_stale', 'Baseline lokasi lama pada ringkasan pulang tidak koheren.');
            }

            return;
        }
        if (! $location instanceof InpatientLocationEvent
            || $location->sequence !== $currentSequence
            || $version->location_event_public_id !== $location->public_id
            || $version->location_event_type !== $location->event_type
            || $version->history_baseline !== null
            || $location->to_ward_public_id !== $ward->public_id
            || $location->to_ward_code !== $ward->code
            || $location->to_bed_public_id !== $bed->public_id
            || $location->to_bed_code !== $bed->code) {
            throw new InpatientDischargeDenied('summary_placement_stale', 'Peristiwa lokasi ringkasan pulang tidak sama dengan lokasi aktif.');
        }
    }

    private function assertCodingSourcePlacementProvenance(InpatientDischargeCodingSourceVersion $version, InpatientWard $ward, InpatientBed $bed, ?InpatientLocationEvent $location, int $sequence, string $status): void
    {
        if ($version->encounter_status !== $status || $version->location_sequence !== $sequence || $version->ward_public_id !== $ward->public_id || $version->ward_code !== $ward->code || $version->bed_public_id !== $bed->public_id || $version->bed_code !== $bed->code) {
            throw new InpatientDischargeDenied('discharge_coding_source_placement_stale', 'Provenans lokasi diagnosis akhir tidak lagi sama dengan penempatan aktif.');
        }
        if ($sequence === 0) {
            if ($location !== null || $version->location_event_public_id !== null || $version->location_event_type !== null || $version->history_baseline !== InpatientDischargeSummary::HISTORY_BASELINE_LEGACY_CURRENT_PLACEMENT || $version->history_complete !== false) {
                throw new InpatientDischargeDenied('discharge_coding_source_placement_stale', 'Baseline lokasi diagnosis akhir tidak koheren.');
            }

            return;
        }
        if (! $location instanceof InpatientLocationEvent || $version->location_event_public_id !== $location->public_id || $version->location_event_type !== $location->event_type || $version->history_baseline !== null || $location->to_ward_public_id !== $ward->public_id || $location->to_bed_public_id !== $bed->public_id) {
            throw new InpatientDischargeDenied('discharge_coding_source_placement_stale', 'Peristiwa lokasi diagnosis akhir tidak sama dengan lokasi aktif.');
        }
    }

    private function replayIfPresent(User $actor, string $canonicalKey, string $digest): ?InpatientDischargeResult
    {
        $receiptQuery = InpatientDischargeOperationReceipt::query()
            ->where('actor_user_id', $actor->id)
            ->where('operation', InpatientDischargeOperationReceipt::OPERATION_EXECUTE)
            ->where('idempotency_key', $canonicalKey);
        $receipt = DB::connection()->getDriverName() === 'mysql'
            ? $receiptQuery->sharedLock()->first()
            : $receiptQuery->first();
        if (! $receipt instanceof InpatientDischargeOperationReceipt) {
            return null;
        }
        if (! hash_equals($receipt->payload_digest, $digest)) {
            throw new InpatientDischargeDenied('idempotency_key_conflict', 'Kunci operasi telah dipakai untuk permintaan berbeda.');
        }
        $dischargeQuery = InpatientDischarge::query()->where('public_id', $receipt->result_discharge_public_id);
        $discharge = DB::connection()->getDriverName() === 'mysql'
            ? $dischargeQuery->sharedLock()->first()
            : $dischargeQuery->first();
        if (! $discharge instanceof InpatientDischarge) {
            throw new InpatientDischargeDenied('receipt_binding_invalid', 'Bukti idempotensi tidak terikat pada hasil pemulangan yang benar.', 503);
        }
        $encounter = Encounter::query()->whereKey($receipt->encounter_id)->first();
        $summary = InpatientDischargeSummary::query()->whereKey($discharge->inpatient_discharge_summary_id)->first();
        $versionQuery = InpatientDischargeSummaryVersion::query()
            ->whereKey($discharge->inpatient_discharge_summary_version_id);
        $version = DB::connection()->getDriverName() === 'mysql'
            ? $versionQuery->sharedLock()->first()
            : $versionQuery->first();
        if (! $encounter instanceof Encounter
            || ! $summary instanceof InpatientDischargeSummary
            || ! $version instanceof InpatientDischargeSummaryVersion) {
            throw new InpatientDischargeDenied('receipt_binding_invalid', 'Rantai hasil pemulangan tidak lengkap.', 503);
        }
        $codingSource = InpatientDischargeCodingSource::query()->where('public_id', $discharge->discharge_coding_source_public_id)->first();
        $codingVersionQuery = InpatientDischargeCodingSourceVersion::query()->whereKey($discharge->inpatient_discharge_coding_source_version_id);
        $codingVersion = DB::connection()->getDriverName() === 'mysql' ? $codingVersionQuery->sharedLock()->first() : $codingVersionQuery->first();
        if (! $codingSource instanceof InpatientDischargeCodingSource || ! $codingVersion instanceof InpatientDischargeCodingSourceVersion) {
            throw new InpatientDischargeDenied('receipt_binding_invalid', 'Rantai diagnosis akhir hasil pemulangan tidak lengkap.', 503);
        }
        $retainedDigest = $this->digest(
            $encounter->public_id,
            $discharge->discharge_summary_version,
            $discharge->location_sequence,
            $discharge->source_bed_public_id,
        );
        $contentDigest = $this->summaryEvidenceDigest->content($version);
        $provenanceDigest = $this->summaryEvidenceDigest->provenance($summary, $version);
        $codingContentDigest = $this->codingSourceEvidenceDigest->content($codingVersion);
        $codingProvenanceDigest = $this->codingSourceEvidenceDigest->provenance($codingSource, $codingVersion);
        $bed = InpatientBed::query()->where('public_id', $discharge->source_bed_public_id)->first();
        $ward = $bed instanceof InpatientBed ? InpatientWard::query()->whereKey($bed->ward_id)->first() : null;
        $locationQuery = InpatientLocationEvent::query()
            ->where('encounter_id', $encounter->id)
            ->orderByDesc('sequence');
        $location = DB::connection()->getDriverName() === 'mysql'
            ? $locationQuery->sharedLock()->first()
            : $locationQuery->first();
        if (! $bed instanceof InpatientBed || ! $ward instanceof InpatientWard) {
            throw new InpatientDischargeDenied('receipt_binding_invalid', 'Penempatan hasil pemulangan tidak lagi terkelola.', 503);
        }
        try {
            $this->assertSummaryPlacementProvenance(
                $version,
                $ward,
                $bed,
                $location,
                $discharge->location_sequence,
                $discharge->encounter_status_before,
            );
            $this->assertCodingSourcePlacementProvenance($codingVersion, $ward, $bed, $location, $discharge->location_sequence, $discharge->encounter_status_before);
        } catch (InpatientDischargeDenied) {
            throw new InpatientDischargeDenied('receipt_binding_invalid', 'Provenans pemulangan tersimpan tidak valid.', 503);
        }
        if ($discharge->encounter_id !== $receipt->encounter_id
            || $discharge->actor_user_id !== $receipt->actor_user_id
            || $encounter->status !== Encounter::STATUS_READY_FOR_RM
            || $encounter->inpatient_bed_id === null
            || $encounter->inpatient_bed_id !== $bed->id
            || $encounter->bed_code !== $discharge->source_bed_code
            || $summary->encounter_id !== $encounter->id
            || $summary->public_id !== $discharge->discharge_summary_public_id
            || $summary->summary_state !== InpatientDischargeSummary::STATE_FINAL
            || $summary->definition_version !== InpatientDischargeSummary::DEFINITION_VERSION
            || $summary->version !== $discharge->discharge_summary_version
            || $version->inpatient_discharge_summary_id !== $summary->id
            || $discharge->inpatient_discharge_summary_version_id !== $receipt->inpatient_discharge_summary_version_id
            || $version->id !== $receipt->inpatient_discharge_summary_version_id
            || $version->public_id !== $discharge->discharge_summary_version_public_id
            || $version->public_id !== $receipt->discharge_summary_version_public_id
            || $version->version !== $discharge->discharge_summary_version
            || $version->summary_state !== InpatientDischargeSummary::STATE_FINAL
            || ! hash_equals($contentDigest, $discharge->discharge_summary_content_digest)
            || ! hash_equals($contentDigest, $receipt->discharge_summary_content_digest)
            || ! hash_equals($provenanceDigest, $discharge->discharge_summary_provenance_digest)
            || ! hash_equals($provenanceDigest, $receipt->discharge_summary_provenance_digest)
            || $codingSource->encounter_id !== $encounter->id
            || $codingSource->source_state !== InpatientDischargeCodingSource::STATE_FINAL
            || $codingVersion->inpatient_discharge_coding_source_id !== $codingSource->id
            || $codingVersion->id !== $discharge->inpatient_discharge_coding_source_version_id
            || $codingVersion->id !== $receipt->inpatient_discharge_coding_source_version_id
            || $codingVersion->public_id !== $discharge->discharge_coding_source_version_public_id
            || $codingVersion->public_id !== $receipt->discharge_coding_source_version_public_id
            || $codingVersion->version !== $discharge->discharge_coding_source_version
            || $codingVersion->version !== $receipt->discharge_coding_source_version
            || ! hash_equals($codingContentDigest, (string) $discharge->discharge_coding_source_content_digest)
            || ! hash_equals($codingContentDigest, (string) $receipt->discharge_coding_source_content_digest)
            || ! hash_equals($codingProvenanceDigest, (string) $discharge->discharge_coding_source_provenance_digest)
            || ! hash_equals($codingProvenanceDigest, (string) $receipt->discharge_coding_source_provenance_digest)
            || ! hash_equals($retainedDigest, $digest)
            || ! hash_equals($retainedDigest, $discharge->payload_digest)
            || ! hash_equals($retainedDigest, $receipt->payload_digest)
            || $discharge->disposition_code !== InpatientDischarge::DISPOSITION_ROUTINE_HOME
            || $discharge->disposition_label !== InpatientDischarge::DISPOSITION_ROUTINE_HOME_LABEL
            || ! in_array($discharge->encounter_status_before, Encounter::BED_OCCUPYING_STATUSES, true)
            || $discharge->encounter_status_after !== Encounter::STATUS_READY_FOR_RM) {
            throw new InpatientDischargeDenied('receipt_binding_invalid', 'Bukti idempotensi tidak terikat pada hasil pemulangan yang benar.', 503);
        }

        return new InpatientDischargeResult($discharge, true);
    }

    private function validateInput(string $encounter, int $summaryVersion, int $locationSequence, string $bed, string $key, ?string $correlation): void
    {
        if (! Str::isUlid($encounter) || ! Str::isUlid($bed)) {
            throw new InvalidArgumentException('Identitas pemulangan tidak valid.');
        }
        if ($summaryVersion < 1 || $locationSequence < 0) {
            throw new InvalidArgumentException('Versi ringkasan atau urutan lokasi tidak valid.');
        }
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('Kunci idempotensi tidak valid.');
        }
        if ($correlation !== null && ! Str::isUlid($correlation)) {
            throw new InvalidArgumentException('Identitas korelasi tidak valid.');
        }
    }

    private function digest(string $encounter, int $summaryVersion, int $locationSequence, string $bed): string
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

    private function recordDenialOrFail(string $encounter, User $actor, InpatientDischargeDenied $denial): void
    {
        if ($this->auditRecorder->record(
            action: self::ACTION,
            resourceType: 'encounter',
            resourceId: $encounter,
            actor: $actor,
            outcome: 'DENIED',
            reason: $denial->reason,
            metadata: $denial->metadata,
        ) === null) {
            throw new InpatientDischargeAuditUnavailable('Penolakan pemulangan tidak dapat direkam dalam audit.');
        }
    }

    private function authorizeOrAudit(User $actor, string $encounter): void
    {
        if ($this->actorPolicy->can($actor)) {
            return;
        }
        $denial = new InpatientDischargeDenied('unauthorized_actor', 'Aktor tidak diizinkan menjalankan pemulangan.', 403);
        $this->recordDenialOrFail($encounter, $actor, $denial);
        throw new AuthorizationException;
    }
}
