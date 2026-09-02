<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\InpatientMasterCodeReservation;
use App\Models\InpatientMasterOperationReceipt;
use App\Models\InpatientWard;
use App\Models\InpatientWardVersion;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\CanonicalJson;
use App\Support\Registration\InpatientBedClaimGuard;
use App\Support\Registration\InpatientBedUnavailable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InpatientMasterService
{
    public const REASON_INITIAL_SETUP = 'INITIAL_SETUP';

    public const REASON_DATA_CORRECTION = 'DATA_CORRECTION';

    public const REASON_OPERATIONAL_CHANGE = 'OPERATIONAL_CHANGE';

    public const REASON_RETIREMENT = 'RETIREMENT';

    /** @var list<string> */
    public const REASON_CODES = [
        self::REASON_INITIAL_SETUP,
        self::REASON_DATA_CORRECTION,
        self::REASON_OPERATIONAL_CHANGE,
        self::REASON_RETIREMENT,
    ];

    /** @var array<string, string> */
    public const REASON_LABELS = [
        self::REASON_INITIAL_SETUP => 'Penyiapan awal',
        self::REASON_DATA_CORRECTION => 'Koreksi data',
        self::REASON_OPERATIONAL_CHANGE => 'Perubahan operasional',
        self::REASON_RETIREMENT => 'Pensiun master',
    ];

    private const OP_WARD_CREATE = 'WARD_CREATE';

    private const OP_WARD_UPDATE = 'WARD_UPDATE';

    private const OP_WARD_RETIRE = 'WARD_RETIRE';

    private const OP_BED_CREATE = 'BED_CREATE';

    private const OP_BED_UPDATE = 'BED_UPDATE';

    private const OP_BED_RETIRE = 'BED_RETIRE';

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly InpatientBedClaimGuard $bedClaimGuard,
        private readonly InpatientMasterActorPolicy $actorPolicy,
        private readonly CanonicalInpatientBedOperationLockCoordinator $locks,
    ) {}

    public function createWard(User $actor, string $code, string $displayName, string $reasonCode, string $key, ?string $correlation): InpatientMasterMutationResult
    {
        $this->actorPolicy->authorizeManage($actor);
        $code = InpatientWard::normalizeCode($code);
        $payload = compact('code', 'displayName', 'reasonCode');

        return $this->mutate($actor, self::OP_WARD_CREATE, $key, $payload, InpatientMasterOperationReceipt::RESULT_WARD, $correlation, function () use ($actor, $code, $displayName, $reasonCode, $correlation): InpatientWard {
            $this->reserveCode($actor, InpatientMasterCodeReservation::TYPE_WARD, $code);
            if (InpatientWard::query()->where('code', $code)->exists()) {
                throw new InpatientMasterDenied('duplicate_code', 'Kode bangsal sudah digunakan.');
            }
            $ward = InpatientWard::query()->create([
                'code' => $code,
                'display_name' => trim($displayName),
                'state' => InpatientWard::STATE_ACTIVE,
                'version' => 1,
            ]);
            $after = $this->wardDigest($ward);
            InpatientWardVersion::query()->create($this->wardVersionAttributes($ward, $actor, $reasonCode, null, $after, $correlation));
            $this->recordSuccess('master.inpatient.ward.create', $ward, $actor, $reasonCode, null, $after, $correlation);

            return $ward;
        });
    }

    public function updateWard(User $actor, string $publicId, string $displayName, int $expectedVersion, string $reasonCode, string $key, ?string $correlation): InpatientMasterMutationResult
    {
        $this->actorPolicy->authorizeManage($actor);
        $payload = compact('publicId', 'displayName', 'expectedVersion', 'reasonCode');

        return $this->mutate($actor, self::OP_WARD_UPDATE, $key, $payload, InpatientMasterOperationReceipt::RESULT_WARD, $correlation, function () use ($actor, $publicId, $displayName, $expectedVersion, $reasonCode, $correlation): InpatientWard {
            $ward = InpatientWard::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $this->assertActiveVersion($ward->state, $ward->version, $expectedVersion, 'bangsal');
            $before = $this->wardDigest($ward);
            $ward->display_name = trim($displayName);
            $ward->version++;
            $ward->save();
            $after = $this->wardDigest($ward);
            InpatientWardVersion::query()->create($this->wardVersionAttributes($ward, $actor, $reasonCode, $before, $after, $correlation));
            $this->recordSuccess('master.inpatient.ward.update', $ward, $actor, $reasonCode, $before, $after, $correlation);

            return $ward;
        });
    }

    public function retireWard(User $actor, string $publicId, int $expectedVersion, string $reasonCode, string $key, ?string $correlation): InpatientMasterMutationResult
    {
        $this->actorPolicy->authorizeManage($actor);
        $payload = compact('publicId', 'expectedVersion', 'reasonCode');

        return $this->mutate($actor, self::OP_WARD_RETIRE, $key, $payload, InpatientMasterOperationReceipt::RESULT_WARD, $correlation, function () use ($actor, $publicId, $expectedVersion, $reasonCode, $correlation): InpatientWard {
            $candidateWard = InpatientWard::query()->where('public_id', $publicId)->firstOrFail();
            $candidateBeds = InpatientBed::query()->where('ward_id', $candidateWard->id)->orderBy('id')->get();
            $this->locks->lockMutexes(array_values($candidateBeds->pluck('code')->map(static fn (mixed $code): string => (string) $code)->all()));
            $candidateEncounterIds = Encounter::query()
                ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
                ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
                ->whereIn('inpatient_bed_id', $candidateBeds->pluck('id'))
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values()
                ->all();
            $this->locks->lockEncounters(array_values($candidateEncounterIds));
            $ward = $this->locks->lockWards([$candidateWard->id])->get($candidateWard->id);
            $beds = $this->locks->lockBeds(array_values($candidateBeds->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all()));
            if (! $ward instanceof InpatientWard) {
                throw new InpatientMasterDenied('ward_changed', 'Bangsal berubah saat operasi. Muat ulang dan ulangi.');
            }
            $currentBedIds = InpatientBed::query()->where('ward_id', $ward->id)->orderBy('id')
                ->lockForUpdate()->get(['id'])->pluck('id')->all();
            $candidateBedIds = $candidateBeds->pluck('id')->all();
            sort($currentBedIds, SORT_NUMERIC);
            sort($candidateBedIds, SORT_NUMERIC);
            if ($currentBedIds !== $candidateBedIds || $beds->keys()->values()->all() !== $candidateBedIds) {
                throw new InpatientMasterDenied('ward_changed', 'Daftar tempat tidur berubah saat operasi. Muat ulang dan ulangi.');
            }
            $this->assertActiveVersion($ward->state, $ward->version, $expectedVersion, 'bangsal');
            if ($beds->contains(fn (InpatientBed $bed): bool => $bed->state !== InpatientBed::STATE_RETIRED)) {
                throw new InpatientMasterDenied('active_beds_remain', 'Semua tempat tidur harus dipensiunkan lebih dahulu.');
            }
            $currentClaims = Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
                ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
                ->whereIn('inpatient_bed_id', $beds->pluck('id'))
                ->lockForUpdate()->get(['id']);
            if ($currentClaims->isNotEmpty()) {
                throw new InpatientMasterDenied('ward_occupied', 'Bangsal masih memiliki kunjungan rawat inap aktif.');
            }
            $before = $this->wardDigest($ward);
            $ward->state = InpatientWard::STATE_RETIRED;
            $ward->version++;
            $ward->save();
            $after = $this->wardDigest($ward);
            InpatientWardVersion::query()->create($this->wardVersionAttributes($ward, $actor, $reasonCode, $before, $after, $correlation));
            $this->recordSuccess('master.inpatient.ward.retire', $ward, $actor, $reasonCode, $before, $after, $correlation);

            return $ward;
        });
    }

    public function createBed(User $actor, string $wardPublicId, string $code, string $displayName, string $roomLabel, string $serviceClass, string $reasonCode, string $key, ?string $correlation): InpatientMasterMutationResult
    {
        $this->actorPolicy->authorizeManage($actor);
        $code = InpatientWard::normalizeCode($code);
        $payload = compact('wardPublicId', 'code', 'displayName', 'roomLabel', 'serviceClass', 'reasonCode');

        return $this->mutate($actor, self::OP_BED_CREATE, $key, $payload, InpatientMasterOperationReceipt::RESULT_BED, $correlation, function () use ($actor, $wardPublicId, $code, $displayName, $roomLabel, $serviceClass, $reasonCode, $correlation): InpatientBed {
            $ward = InpatientWard::query()->where('public_id', $wardPublicId)->lockForUpdate()->firstOrFail();
            if ($ward->state !== InpatientWard::STATE_ACTIVE) {
                throw new InpatientMasterDenied('ward_retired', 'Bangsal yang dipensiunkan tidak dapat menerima tempat tidur baru.');
            }
            $this->reserveCode($actor, InpatientMasterCodeReservation::TYPE_BED, $code);
            if (InpatientBed::query()->where('code', $code)->exists()) {
                throw new InpatientMasterDenied('duplicate_code', 'Kode tempat tidur sudah digunakan.');
            }
            $bed = InpatientBed::query()->create([
                'ward_id' => $ward->id,
                'code' => $code,
                'display_name' => trim($displayName),
                'room_label' => trim($roomLabel),
                'service_class' => trim($serviceClass),
                'state' => InpatientBed::STATE_ACTIVE,
                'version' => 1,
            ]);
            $after = $this->bedDigest($bed);
            InpatientBedVersion::query()->create($this->bedVersionAttributes($bed, $actor, $reasonCode, null, $after, $correlation));
            $this->recordSuccess('master.inpatient.bed.create', $bed, $actor, $reasonCode, null, $after, $correlation, $ward);

            return $bed;
        });
    }

    public function updateBed(User $actor, string $publicId, string $displayName, string $roomLabel, string $serviceClass, int $expectedVersion, string $reasonCode, string $key, ?string $correlation): InpatientMasterMutationResult
    {
        $this->actorPolicy->authorizeManage($actor);
        $payload = compact('publicId', 'displayName', 'roomLabel', 'serviceClass', 'expectedVersion', 'reasonCode');

        return $this->mutate($actor, self::OP_BED_UPDATE, $key, $payload, InpatientMasterOperationReceipt::RESULT_BED, $correlation, function () use ($actor, $publicId, $displayName, $roomLabel, $serviceClass, $expectedVersion, $reasonCode, $correlation): InpatientBed {
            [$ward, $bed] = $this->lockWardAndBed($publicId);
            $this->assertActiveVersion($bed->state, $bed->version, $expectedVersion, 'tempat tidur');
            $before = $this->bedDigest($bed);
            $bed->fill(['display_name' => trim($displayName), 'room_label' => trim($roomLabel), 'service_class' => trim($serviceClass)]);
            $bed->version++;
            $bed->save();
            $after = $this->bedDigest($bed);
            InpatientBedVersion::query()->create($this->bedVersionAttributes($bed, $actor, $reasonCode, $before, $after, $correlation));
            $this->recordSuccess('master.inpatient.bed.update', $bed, $actor, $reasonCode, $before, $after, $correlation, $ward);

            return $bed;
        });
    }

    public function retireBed(User $actor, string $publicId, int $expectedVersion, string $reasonCode, string $key, ?string $correlation): InpatientMasterMutationResult
    {
        $this->actorPolicy->authorizeManage($actor);
        $payload = compact('publicId', 'expectedVersion', 'reasonCode');

        return $this->mutate($actor, self::OP_BED_RETIRE, $key, $payload, InpatientMasterOperationReceipt::RESULT_BED, $correlation, function () use ($actor, $publicId, $expectedVersion, $reasonCode, $correlation): InpatientBed {
            $candidate = InpatientBed::query()->where('public_id', $publicId)->firstOrFail();
            $this->locks->lockMutexes([$candidate->code]);
            $claims = $this->bedClaimGuard->lockClaimsAfterCanonicalMutexes($candidate->code, $candidate->id);
            $ward = $this->locks->lockWards([$candidate->ward_id])->get($candidate->ward_id);
            $bed = $this->locks->lockBeds([$candidate->id])->get($candidate->id);
            if (! $ward instanceof InpatientWard || ! $bed instanceof InpatientBed || $bed->ward_id !== $ward->id || $bed->code !== $candidate->code) {
                throw new InpatientMasterDenied('bed_changed', 'Tempat tidur berubah saat operasi. Muat ulang dan ulangi.');
            }
            $this->assertActiveVersion($bed->state, $bed->version, $expectedVersion, 'tempat tidur');
            if ($claims->isNotEmpty()) {
                throw new InpatientMasterDenied('bed_occupied', 'Tempat tidur masih dipakai kunjungan rawat inap aktif.');
            }
            $before = $this->bedDigest($bed);
            $bed->state = InpatientBed::STATE_RETIRED;
            $bed->version++;
            $bed->save();
            $after = $this->bedDigest($bed);
            InpatientBedVersion::query()->create($this->bedVersionAttributes($bed, $actor, $reasonCode, $before, $after, $correlation));
            $this->recordSuccess('master.inpatient.bed.retire', $bed, $actor, $reasonCode, $before, $after, $correlation, $ward);

            return $bed;
        });
    }

    public function resolveActiveBedForAdmission(string $publicId): InpatientBed
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new InpatientMasterDenied('transaction_required', 'Pemilihan tempat tidur harus berada dalam transaksi.');
        }
        $candidate = InpatientBed::query()->where('public_id', $publicId)->firstOrFail();
        $this->locks->lockMutexes([$candidate->code]);
        $claims = $this->bedClaimGuard->lockClaimsAfterCanonicalMutexes($candidate->code, $candidate->id);
        $ward = $this->locks->lockWards([$candidate->ward_id])->get($candidate->ward_id);
        $bed = $this->locks->lockBeds([$candidate->id])->get($candidate->id);
        if (! $ward instanceof InpatientWard || ! $bed instanceof InpatientBed
            || $bed->ward_id !== $ward->id || $bed->code !== $candidate->code
            || $bed->state !== InpatientBed::STATE_ACTIVE || $ward->state !== InpatientWard::STATE_ACTIVE) {
            throw new InpatientMasterDenied('bed_retired', 'Tempat tidur tidak aktif dan tidak dapat dipilih.');
        }
        if ($claims->isNotEmpty()) {
            throw new InpatientBedUnavailable('Tempat tidur sudah dipakai kunjungan rawat inap aktif.');
        }
        $bed->setRelation('ward', $ward);

        return $bed;
    }

    /** @param array<string, mixed> $payload */
    private function mutate(User $actor, string $operation, string $key, array $payload, string $resultType, ?string $correlation, callable $callback): InpatientMasterMutationResult
    {
        $key = mb_strtolower(trim($key));
        $digest = hash('sha256', CanonicalJson::encode($payload));

        try {
            return InpatientMasterMutationScope::run(fn (): InpatientMasterMutationResult => DB::transaction(function () use ($actor, $operation, $key, $digest, $resultType, $correlation, $callback): InpatientMasterMutationResult {
                $receipt = InpatientMasterOperationReceipt::query()
                    ->where('actor_user_id', $actor->id)->where('operation', $operation)->where('idempotency_key', $key)
                    ->lockForUpdate()->first();
                if ($receipt instanceof InpatientMasterOperationReceipt) {
                    if (! hash_equals($receipt->payload_digest, $digest) || $receipt->result_type !== $resultType) {
                        throw new InpatientMasterDenied('idempotency_key_conflict', 'Kunci idempotensi telah digunakan untuk permintaan berbeda.');
                    }
                    $master = $resultType === InpatientMasterOperationReceipt::RESULT_WARD
                        ? InpatientWard::query()->where('public_id', $receipt->result_public_id)->firstOrFail()
                        : InpatientBed::query()->where('public_id', $receipt->result_public_id)->firstOrFail();

                    return new InpatientMasterMutationResult($master, true);
                }

                $master = $callback($digest);
                InpatientMasterOperationReceipt::query()->create([
                    'actor_user_id' => $actor->id,
                    'operation' => $operation,
                    'idempotency_key' => $key,
                    'payload_digest' => $digest,
                    'result_type' => $resultType,
                    'result_public_id' => $master->public_id,
                    'request_correlation_id' => $correlation,
                    'completed_at' => now(),
                ]);

                return new InpatientMasterMutationResult($master, false);
            }, 3));
        } catch (InpatientMasterDenied $denial) {
            if (in_array($denial->reasonCode, ['duplicate_code', 'stale_version', 'master_retired'], true)) {
                $reconciled = $this->reconcileReceipt($actor, $operation, $key, $digest, $resultType);
                if ($reconciled instanceof InpatientMasterMutationResult) {
                    return $reconciled;
                }
            }

            throw $denial;
        } catch (QueryException $exception) {
            if (Str::contains(strtolower($exception->getMessage()), ['unique', 'duplicate'])) {
                $reconciled = $this->reconcileReceipt($actor, $operation, $key, $digest, $resultType);
                if ($reconciled instanceof InpatientMasterMutationResult) {
                    return $reconciled;
                }

                throw new InpatientMasterDenied('duplicate_code', 'Kode master sudah digunakan.');
            }
            throw $exception;
        }
    }

    private function reconcileReceipt(User $actor, string $operation, string $key, string $digest, string $resultType): ?InpatientMasterMutationResult
    {
        return DB::transaction(function () use ($actor, $operation, $key, $digest, $resultType): ?InpatientMasterMutationResult {
            $receipt = InpatientMasterOperationReceipt::query()
                ->where('actor_user_id', $actor->id)
                ->where('operation', $operation)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if (! $receipt instanceof InpatientMasterOperationReceipt) {
                return null;
            }
            if (! hash_equals($receipt->payload_digest, $digest) || $receipt->result_type !== $resultType) {
                throw new InpatientMasterDenied('idempotency_key_conflict', 'Kunci idempotensi telah digunakan untuk permintaan berbeda.');
            }
            $master = $resultType === InpatientMasterOperationReceipt::RESULT_WARD
                ? InpatientWard::query()->where('public_id', $receipt->result_public_id)->firstOrFail()
                : InpatientBed::query()->where('public_id', $receipt->result_public_id)->firstOrFail();

            return new InpatientMasterMutationResult($master, true);
        }, 3);
    }

    /** @return array{InpatientWard, InpatientBed} */
    private function lockWardAndBed(string $bedPublicId): array
    {
        $candidate = InpatientBed::query()->where('public_id', $bedPublicId)->firstOrFail();
        $ward = InpatientWard::query()->whereKey($candidate->ward_id)->lockForUpdate()->firstOrFail();
        $bed = InpatientBed::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
        $bed->setRelation('ward', $ward);

        return [$ward, $bed];
    }

    private function assertActiveVersion(string $state, int $version, int $expected, string $label): void
    {
        if ($state !== InpatientWard::STATE_ACTIVE) {
            throw new InpatientMasterDenied('master_retired', ucfirst($label).' sudah dipensiunkan.');
        }
        if ($version !== $expected) {
            throw new InpatientMasterDenied('stale_version', 'Versi '.$label.' sudah berubah.');
        }
    }

    private function reserveCode(User $actor, string $masterType, string $code): void
    {
        InpatientMasterCodeReservation::query()->create([
            'actor_user_id' => $actor->id,
            'master_type' => $masterType,
            'normalized_code' => $code,
        ]);
    }

    private function wardDigest(InpatientWard $ward): string
    {
        return hash('sha256', CanonicalJson::encode(['code' => $ward->code, 'display_name' => $ward->display_name, 'state' => $ward->state, 'version' => $ward->version]));
    }

    private function bedDigest(InpatientBed $bed): string
    {
        return hash('sha256', CanonicalJson::encode(['code' => $bed->code, 'display_name' => $bed->display_name, 'room_label' => $bed->room_label, 'service_class' => $bed->service_class, 'state' => $bed->state, 'version' => $bed->version]));
    }

    /** @return array<string, mixed> */
    private function wardVersionAttributes(InpatientWard $ward, User $actor, string $reason, ?string $before, string $after, ?string $correlation): array
    {
        return ['ward_id' => $ward->id, 'actor_user_id' => $actor->id, 'version' => $ward->version, 'display_name' => $ward->display_name, 'state' => $ward->state, 'reason_code' => $reason, 'before_digest' => $before, 'after_digest' => $after, 'request_correlation_id' => $correlation];
    }

    /** @return array<string, mixed> */
    private function bedVersionAttributes(InpatientBed $bed, User $actor, string $reason, ?string $before, string $after, ?string $correlation): array
    {
        return ['bed_id' => $bed->id, 'actor_user_id' => $actor->id, 'version' => $bed->version, 'display_name' => $bed->display_name, 'room_label' => $bed->room_label, 'service_class' => $bed->service_class, 'state' => $bed->state, 'reason_code' => $reason, 'before_digest' => $before, 'after_digest' => $after, 'request_correlation_id' => $correlation];
    }

    private function recordSuccess(string $action, InpatientWard|InpatientBed $master, User $actor, string $reasonCode, ?string $before, string $after, ?string $correlation, ?InpatientWard $ward = null): void
    {
        $event = $this->auditRecorder->record(
            action: $action,
            resourceType: $master instanceof InpatientWard ? 'inpatient_ward' : 'inpatient_bed',
            resourceId: $master->public_id,
            actor: $actor,
            metadata: [
                'code' => $master->code,
                'version' => $master->version,
                'state' => $master->state,
                'reason_code' => $reasonCode,
                'before_digest' => $before,
                'after_digest' => $after,
                'ward_id' => $master instanceof InpatientBed ? $ward?->public_id : null,
            ],
            request: $this->auditRequest($correlation),
        );
        if ($event === null) {
            throw new InpatientMasterAuditUnavailable('Perubahan master dibatalkan karena audit tidak dapat direkam.');
        }
    }

    private function auditRequest(?string $correlation): Request
    {
        $auditRequest = request();
        if ($correlation === null || $auditRequest->attributes->get('request_id') === $correlation) {
            return $auditRequest;
        }

        $copy = $auditRequest->duplicate();
        $copy->attributes->set('request_id', $correlation);

        return $copy;
    }
}
