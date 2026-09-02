<?php

namespace App\Support\Laboratory;

use App\Models\LaboratoryCriticalCommunication;
use App\Models\LaboratoryExaminationMaster;
use App\Models\LaboratoryExaminationMasterVersion;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryResultVersion;
use App\Models\LaboratorySpecimenAttempt;
use App\Models\LaboratorySpecimenEvent;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class LaboratoryEvidenceFingerprint
{
    public function __construct(private readonly LaboratoryActorPolicy $policy) {}

    public function contentDigest(LaboratoryResultVersion $version): string
    {
        return $this->digest($version->state, $version->results ?? [], $version->correction_reason, $version->base_verified_version_id, $version->base_verified_digest, $version->prior_amendment_digest);
    }

    /** @param array<int,array<string,mixed>> $results */
    public function digest(string $state, array $results, ?string $reason, ?int $baseId, ?string $baseDigest, ?string $priorDigest): string
    {
        return LaboratoryCanonicalJson::digest([$state, $results, $reason, $baseId, $baseDigest, $priorDigest]);
    }

    public function specimenDigest(LaboratorySpecimenAttempt $attempt): string
    {
        $eventChainDigest = $this->verifySpecimenEventChain($attempt);

        return LaboratoryCanonicalJson::digest([$attempt->public_id, $attempt->laboratory_order_id, $attempt->attempt_number, $attempt->label_identifier, $attempt->collector_user_id, $attempt->collected_at->toJSON(), $attempt->collection_note, $attempt->state, $eventChainDigest]);
    }

    public function communicationDigest(LaboratoryCriticalCommunication $communication): string
    {
        return LaboratoryCanonicalJson::digest([$communication->public_id, $communication->laboratory_result_version_id, $communication->actor_user_id, $communication->recipient_user_id, $communication->communication_method, $communication->outcome, $communication->note, $communication->communicated_at->toJSON()]);
    }

    public function current(LaboratoryResultVersion $latest): string
    {
        $order = $this->currentModel(LaboratoryOrder::query()->whereKey($latest->laboratory_order_id))->firstOrFail();
        $specimen = $this->currentModel(LaboratorySpecimenAttempt::query()->whereKey($latest->laboratory_specimen_attempt_id))->firstOrFail();
        $orderSnapshotDigest = $this->verifyOrderSnapshot($order);
        if ($specimen->laboratory_order_id !== $order->id || $specimen->state !== LaboratorySpecimenAttempt::ACCEPTED) {
            throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Spesimen hasil laboratorium tidak valid.');
        }
        $base = $latest->state === LaboratoryResultVersion::VERIFIED
            ? $latest
            : $this->currentModel(LaboratoryResultVersion::query()->whereKey($latest->base_verified_version_id)->where('laboratory_order_id', $latest->laboratory_order_id))->firstOrFail();
        $baseDigest = $this->contentDigest($base);
        if ($base->state !== LaboratoryResultVersion::VERIFIED || $base->laboratory_specimen_attempt_id !== $specimen->id || ! hash_equals($baseDigest, (string) $base->content_digest) || ! hash_equals($baseDigest, (string) ($latest->base_verified_digest ?? $baseDigest))) {
            throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Rantai bukti hasil laboratorium tidak valid.');
        }
        $this->verifyResultComponents($order, $base);
        $baseEvidenceDigest = $this->resultEvidenceDigest($base, $baseDigest);
        $prior = null;
        $amendmentEvidenceDigests = [];
        $expectedAmendmentVersion = $base->version + 1;
        $amendments = $this->currentModel(LaboratoryResultVersion::query()->where('laboratory_order_id', $latest->laboratory_order_id)->where('state', LaboratoryResultVersion::AMENDED_VERIFIED)->where('version', '<=', $latest->version)->orderBy('version'))->get();
        foreach ($amendments as $amendment) {
            $computed = $this->contentDigest($amendment);
            if ($amendment->base_verified_version_id !== $base->id
                || $amendment->laboratory_specimen_attempt_id !== $specimen->id
                || $amendment->version !== $expectedAmendmentVersion
                || ! hash_equals($baseDigest, (string) $amendment->base_verified_digest)
                || $amendment->prior_amendment_digest !== $prior
                || ! hash_equals($computed, (string) $amendment->content_digest)) {
                throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Rantai amandemen laboratorium tidak valid.');
            }
            $this->verifyResultComponents($order, $amendment);
            $prior = $computed;
            $amendmentEvidenceDigests[] = $this->resultEvidenceDigest($amendment, $computed);
            $expectedAmendmentVersion++;
        }
        if ($latest->state === LaboratoryResultVersion::AMENDED_VERIFIED && ($amendments->isEmpty() || $amendments->last()->isNot($latest))) {
            throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Rantai amandemen laboratorium tidak lengkap.');
        }
        $resultChain = $amendments->prepend($base)->values();
        $communications = $this->currentModel(LaboratoryCriticalCommunication::query()
            ->whereIn('laboratory_result_version_id', $resultChain->pluck('id')))->get()->keyBy('laboratory_result_version_id');
        $communicationDigests = [];
        foreach ($resultChain as $version) {
            $communication = $communications->get($version->id);
            $critical = collect($version->results ?? [])->contains(fn (array $result): bool => ($result['interpretation'] ?? null) === 'CRITICAL');
            if ($critical && ! $communication) {
                throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Hasil kritis tidak memiliki bukti komunikasi.');
            }
            if (! $critical && $communication) {
                throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Bukti komunikasi kritis tidak sesuai hasil.');
            }
            if ($communication) {
                $digest = $this->communicationDigest($communication);
                $recipient = $this->currentModel(User::query()->whereKey($communication->recipient_user_id))->first();
                $recipientIsExactPhysician = $recipient instanceof User
                    && $this->policy->can($recipient, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::LABORATORY_ORDER_CREATE);
                $recipientValid = $recipientIsExactPhysician && ($communication->outcome === 'COMMUNICATED'
                    ? $communication->recipient_user_id === $order->ordered_by_user_id
                    : ($communication->outcome === 'ESCALATED' && $communication->note !== null));
                if (! hash_equals($digest, (string) $communication->content_digest)
                    || $communication->actor_user_id !== $version->author_user_id
                    || ! $recipientValid
                    || $communication->communicated_at->isBefore($this->communicationNotBefore($version, $specimen))
                    || $communication->communicated_at->isAfter($version->verified_at)) {
                    throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Attribution atau waktu komunikasi kritis tidak valid.');
                }
                $communicationDigests[] = [$version->public_id, $digest];
            }
        }

        return LaboratoryCanonicalJson::digest([
            $orderSnapshotDigest,
            $this->specimenDigest($specimen),
            $baseEvidenceDigest,
            $amendmentEvidenceDigests === []
                ? ''
                : LaboratoryCanonicalJson::digest($amendmentEvidenceDigests),
            $communicationDigests === [] ? '' : LaboratoryCanonicalJson::digest($communicationDigests),
        ]);
    }

    public function resultEvidenceDigest(LaboratoryResultVersion $version, ?string $verifiedContentDigest = null): string
    {
        $contentDigest = $verifiedContentDigest ?? $this->contentDigest($version);
        if (! hash_equals($contentDigest, (string) $version->content_digest)
            || ! in_array($version->state, [LaboratoryResultVersion::VERIFIED, LaboratoryResultVersion::AMENDED_VERIFIED], true)
            || $version->verified_at === null) {
            throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Attribution versi hasil laboratorium tidak valid.');
        }

        return LaboratoryCanonicalJson::digest([
            $version->public_id,
            $version->laboratory_order_id,
            $version->laboratory_specimen_attempt_id,
            $version->author_user_id,
            $version->base_verified_version_id,
            $version->version,
            $version->state,
            $contentDigest,
            $version->verified_at->toJSON(),
            $version->created_at->toJSON(),
        ]);
    }

    public function verifyMasterVersion(LaboratoryExaminationMasterVersion $version): string
    {
        $computed = $this->masterContentDigest($version->display_name, $version->specimen_type, $version->collection_instruction, $version->components, $version->state);
        if (! hash_equals($computed, (string) $version->content_digest)) {
            throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Digest master laboratorium tidak valid.');
        }

        return $computed;
    }

    /** @param array<int,array<string,mixed>> $components */
    public function masterContentDigest(string $displayName, string $specimenType, ?string $collectionInstruction, array $components, string $state): string
    {
        return LaboratoryCanonicalJson::digest([$displayName, $specimenType, $collectionInstruction, $components, $state]);
    }

    public function verifyOrderSnapshot(LaboratoryOrder $order): string
    {
        $version = $this->currentModel(LaboratoryExaminationMasterVersion::query()
            ->where('laboratory_examination_master_id', $order->master_id)
            ->where('version', $order->master_version)
            ->where('public_id', $order->master_version_public_id))->firstOrFail();
        $master = $this->currentModel(LaboratoryExaminationMaster::query()->whereKey($order->master_id))->firstOrFail();
        $digest = $this->verifyMasterVersion($version);
        if (! hash_equals($digest, (string) $order->master_content_digest)
            || $order->master_code !== $master->examination_code
            || $order->master_display_name !== $version->display_name
            || $order->specimen_type_snapshot !== $version->specimen_type
            || $order->collection_instruction_snapshot !== $version->collection_instruction
            || ! LaboratoryCanonicalJson::equivalent($order->components_snapshot, $version->components)) {
            throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Snapshot pesanan laboratorium tidak valid.');
        }

        return LaboratoryCanonicalJson::digest([
            $order->public_id,
            $order->encounter_id,
            $order->master_id,
            $order->ordered_by_user_id,
            $order->master_version,
            $version->public_id,
            $digest,
            $order->master_code,
            $order->master_display_name,
            $order->specimen_type_snapshot,
            $order->collection_instruction_snapshot,
            $order->components_snapshot,
            $order->care_setting,
            $order->encounter_status_snapshot,
            $order->encounter_number_snapshot,
            $order->care_location_label_snapshot,
            $order->priority,
            $order->clinical_question,
            $order->ordered_at->toJSON(),
            $order->created_at->toJSON(),
        ]);
    }

    public function verifySpecimenEventChain(LaboratorySpecimenAttempt $attempt): string
    {
        $events = $this->currentModel(LaboratorySpecimenEvent::query()->where('laboratory_specimen_attempt_id', $attempt->id)->orderBy('occurred_at')->orderBy('id'))->get();
        $expected = match ($attempt->state) {
            LaboratorySpecimenAttempt::COLLECTED => [],
            LaboratorySpecimenAttempt::RECEIVED => [LaboratorySpecimenEvent::RECEIVED],
            LaboratorySpecimenAttempt::ACCEPTED => [LaboratorySpecimenEvent::RECEIVED, LaboratorySpecimenEvent::ACCEPTED],
            LaboratorySpecimenAttempt::REJECTED => [LaboratorySpecimenEvent::RECEIVED, LaboratorySpecimenEvent::REJECTED],
            default => throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Status spesimen tidak valid.'),
        };
        $actual = $events->pluck('event_type')->values()->all();
        if ($actual !== $expected || $attempt->version !== count($expected) + 1) {
            throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Rantai peristiwa spesimen tidak lengkap.');
        }
        $priorTime = $attempt->collected_at;
        $payload = [];
        foreach ($events as $event) {
            if ($event->occurred_at->lt($priorTime)
                || ($event->event_type === LaboratorySpecimenEvent::REJECTED && $event->reason_code === null)
                || ($event->event_type !== LaboratorySpecimenEvent::REJECTED && $event->reason_code !== null)) {
                throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Urutan peristiwa spesimen tidak valid.');
            }
            $payload[] = [$event->public_id, $event->actor_user_id, $event->event_type, $event->reason_code, $event->note, $event->occurred_at->toJSON()];
            $priorTime = $event->occurred_at;
        }

        return LaboratoryCanonicalJson::digest($payload);
    }

    private function verifyResultComponents(LaboratoryOrder $order, LaboratoryResultVersion $version): void
    {
        $definitions = array_values($order->components_snapshot ?? []);
        $results = array_values($version->results ?? []);
        if (count($definitions) !== count($results)) {
            throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Komponen hasil tidak lengkap.');
        }
        foreach ($definitions as $index => $definition) {
            $result = $results[$index] ?? [];
            foreach (['code', 'display_name', 'value_kind', 'unit_text', 'reference_text', 'critical_allowed'] as $field) {
                if (($result[$field] ?? null) !== ($definition[$field] ?? null)) {
                    throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Snapshot komponen hasil tidak cocok.');
                }
            }
            $value = $result['value'] ?? null;
            $interpretation = $result['interpretation'] ?? null;
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 2000
                || ! in_array($interpretation, ['NORMAL', 'ABNORMAL', 'CRITICAL'], true)
                || ($interpretation === 'CRITICAL' && ! ($definition['critical_allowed'] ?? false))
                || (($definition['value_kind'] ?? null) === 'NUMERIC' && preg_match('/\A[+-]?(?:\d+(?:\.\d+)?|\.\d+)\z/', $value) !== 1)) {
                throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Nilai komponen hasil tidak valid.');
            }
        }
    }

    private function communicationNotBefore(LaboratoryResultVersion $version, LaboratorySpecimenAttempt $specimen): CarbonImmutable
    {
        $acceptedAt = $this->currentModel(LaboratorySpecimenEvent::query()
            ->where('laboratory_specimen_attempt_id', $specimen->id)
            ->where('event_type', LaboratorySpecimenEvent::ACCEPTED))->value('occurred_at');
        $sourceCreatedAt = $this->currentModel(LaboratoryResultVersion::query()
            ->where('laboratory_order_id', $version->laboratory_order_id)
            ->where('version', $version->version - 1))->value('created_at');
        $notBefore = CarbonImmutable::parse($sourceCreatedAt ?? $version->created_at);
        if ($acceptedAt !== null && CarbonImmutable::parse($acceptedAt)->isAfter($notBefore)) {
            $notBefore = CarbonImmutable::parse($acceptedAt);
        }

        return $notBefore;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function currentModel(Builder $query): Builder
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $query->sharedLock();
        }

        return $query;
    }
}
