<?php

namespace App\Support\Finance;

use App\Models\Encounter;
use App\Models\FinanceChargeEvent;
use App\Models\FinanceLaboratorySourceEvent;
use App\Models\LaboratoryCriticalCommunication;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryResultVersion;
use App\Models\LaboratorySpecimenAttempt;
use App\Models\User;
use App\Support\Laboratory\LaboratoryDenied;
use App\Support\Laboratory\LaboratoryEvidenceFingerprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class FinanceLaboratorySourceAdapter
{
    private const SOURCE_DOMAIN = 'LABORATORY';

    private const SOURCE_TABLE = 'finance_laboratory_source_events';

    public function __construct(
        private readonly FinanceLaboratoryTariffProjection $tariffs,
        private readonly LaboratoryEvidenceFingerprint $fingerprints,
    ) {}

    /** @param Collection<int, FinanceChargeEvent> $events */
    public function verifyRetained(Encounter $encounter, Collection $events): void
    {
        foreach ($events as $event) {
            if ($event->source_domain !== self::SOURCE_DOMAIN
                || $event->source_table !== self::SOURCE_TABLE
                || $event->pharmacy_financial_source_event_id !== null
                || $event->finance_radiology_source_event_id !== null
                || $event->getAttribute('finance_laboratory_source_event_id') === null) {
                throw new FinanceDenied('source_binding_invalid', 'Jenis sumber biaya laboratorium tidak valid.');
            }

            $source = FinanceLaboratorySourceEvent::query()
                ->whereKey($event->getAttribute('finance_laboratory_source_event_id'))->first();
            if (! $source instanceof FinanceLaboratorySourceEvent) {
                throw new FinanceDenied('source_integrity_failure', 'Bukti sumber biaya laboratorium tidak tersedia.');
            }

            [$order, $result, $specimen, $communication] = $this->retainedCompletion($source);
            $evidence = $this->validateCompletion($encounter, $order, $result, $specimen, $communication);
            $resolution = $this->tariffs->resolve($order, $result->verified_at);
            $expectedSource = $this->sourceSnapshot(
                $encounter, $order, $result, $specimen, $communication, $evidence, $resolution,
            );
            $this->assertSnapshot($source, $expectedSource, 'Sumber laboratorium tidak cocok dengan hasil terverifikasi.');
            if (! hash_equals($source->content_digest, FinanceCanonicalJson::digest($expectedSource))) {
                throw new FinanceDenied('source_integrity_failure', 'Digest sumber biaya laboratorium tidak valid.');
            }

            $expectedCharge = $this->chargeSnapshot($encounter, $source);
            $this->assertSnapshot($event, $expectedCharge, 'Baris biaya laboratorium tidak cocok dengan sumber bertipe.');
            if (! hash_equals($event->content_digest, FinanceCanonicalJson::digest($expectedCharge))) {
                throw new FinanceDenied('source_integrity_failure', 'Digest baris biaya laboratorium tidak valid.');
            }
        }
    }

    /** @return Collection<int, FinanceChargeEvent> */
    public function synchronize(Encounter $encounter, User $actor): Collection
    {
        $encounter->loadMissing('patient');
        if (! $encounter->patient?->is_synthetic) {
            throw new FinanceDenied('non_synthetic_record', 'Tagihan hanya dapat menggunakan pasien pengajaran yang diizinkan.');
        }

        $results = LaboratoryResultVersion::query()
            ->where('state', LaboratoryResultVersion::VERIFIED)
            ->whereNull('base_verified_version_id')
            ->whereHas('order', fn ($query) => $query->where('encounter_id', $encounter->id))
            ->orderBy('verified_at')->orderBy('id')->get();

        foreach ($results as $result) {
            $order = LaboratoryOrder::query()->with('master')->whereKey($result->laboratory_order_id)->first();
            $specimen = LaboratorySpecimenAttempt::query()->whereKey($result->laboratory_specimen_attempt_id)->first();
            $communication = LaboratoryCriticalCommunication::query()
                ->where('laboratory_result_version_id', $result->id)->first();
            if (! $order instanceof LaboratoryOrder || ! $specimen instanceof LaboratorySpecimenAttempt) {
                throw new FinanceDenied('source_integrity_failure', 'Rantai hasil laboratorium tidak lengkap.');
            }

            $existingSource = FinanceLaboratorySourceEvent::query()
                ->where('laboratory_result_version_id', $result->id)->first();
            if ($existingSource instanceof FinanceLaboratorySourceEvent) {
                $existingCharge = FinanceChargeEvent::query()
                    ->where('finance_laboratory_source_event_id', $existingSource->id)->first();
                if (! $existingCharge instanceof FinanceChargeEvent) {
                    throw new FinanceDenied('source_integrity_failure', 'Sumber laboratorium tidak memiliki baris biaya bertipe.');
                }
                $this->verifyRetained($encounter, collect([$existingCharge]));

                continue;
            }

            try {
                $evidence = $this->validateCompletion($encounter, $order, $result, $specimen, $communication);
                $resolution = $this->tariffs->resolve($order, $result->verified_at);
            } catch (FinanceDenied $denied) {
                if ($this->isReadinessGap($denied)) {
                    continue;
                }
                throw $denied;
            }

            $snapshot = $this->sourceSnapshot(
                $encounter, $order, $result, $specimen, $communication, $evidence, $resolution,
            );
            $now = now();
            $source = FinanceLaboratorySourceEvent::query()->create([
                ...$snapshot,
                'imported_by_user_id' => $actor->id,
                'content_digest' => FinanceCanonicalJson::digest($snapshot),
                'imported_at' => $now,
                'created_at' => $now,
            ]);
            $chargeSnapshot = $this->chargeSnapshot($encounter, $source);
            $charge = new FinanceChargeEvent;
            $charge->forceFill([
                ...$chargeSnapshot,
                'pharmacy_financial_source_event_id' => null,
                'finance_radiology_source_event_id' => null,
                'finance_laboratory_source_event_id' => $source->id,
                'imported_by_user_id' => $actor->id,
                'content_digest' => FinanceCanonicalJson::digest($chargeSnapshot),
                'imported_at' => $now,
                'created_at' => $now,
            ]);
            $charge->save();
        }

        return FinanceChargeEvent::query()->where('encounter_id', $encounter->id)
            ->where('source_domain', self::SOURCE_DOMAIN)
            ->orderBy('occurred_at')->orderBy('source_public_id')->orderBy('id')->get();
    }

    public function isReadinessGap(FinanceDenied $denied): bool
    {
        return in_array($denied->reason, [
            'unresolved_laboratory_source', 'tariff_not_effective', 'source_binding_invalid',
            'source_integrity_failure', 'source_reconciliation_failed',
        ], true);
    }

    public function validateOriginalVerified(
        Encounter $encounter,
        LaboratoryOrder $order,
        LaboratoryResultVersion $result,
        LaboratorySpecimenAttempt $specimen,
        ?LaboratoryCriticalCommunication $communication,
    ): void {
        $this->validateCompletion($encounter, $order, $result, $specimen, $communication);
    }

    /**
     * @return array{LaboratoryOrder,LaboratoryResultVersion,LaboratorySpecimenAttempt,LaboratoryCriticalCommunication|null}
     */
    private function retainedCompletion(FinanceLaboratorySourceEvent $source): array
    {
        $result = LaboratoryResultVersion::query()->whereKey($source->laboratory_result_version_id)->first();
        $order = LaboratoryOrder::query()->with('master')->whereKey($source->laboratory_order_id)->first();
        $specimen = LaboratorySpecimenAttempt::query()->whereKey($source->laboratory_specimen_attempt_id)->first();
        $communication = $source->laboratory_critical_communication_id === null
            ? null
            : LaboratoryCriticalCommunication::query()->whereKey($source->laboratory_critical_communication_id)->first();
        if (! $order instanceof LaboratoryOrder || ! $result instanceof LaboratoryResultVersion
            || ! $specimen instanceof LaboratorySpecimenAttempt
            || ($source->laboratory_critical_communication_id !== null && ! $communication instanceof LaboratoryCriticalCommunication)) {
            throw new FinanceDenied('source_integrity_failure', 'Bukti hasil laboratorium tidak lagi utuh.');
        }

        return [$order, $result, $specimen, $communication];
    }

    /** @return array<string, string> */
    private function validateCompletion(
        Encounter $encounter,
        LaboratoryOrder $order,
        LaboratoryResultVersion $result,
        LaboratorySpecimenAttempt $specimen,
        ?LaboratoryCriticalCommunication $communication,
    ): array {
        if ($order->encounter_id !== $encounter->id
            || $order->care_setting !== $encounter->care_setting
            || $order->status !== LaboratoryOrder::REPORTED_VERIFIED
            || $result->laboratory_order_id !== $order->id
            || $result->laboratory_specimen_attempt_id !== $specimen->id
            || $result->state !== LaboratoryResultVersion::VERIFIED
            || $result->base_verified_version_id !== null
            || $result->correction_reason !== null
            || $result->base_verified_digest !== null
            || $result->prior_amendment_digest !== null
            || $result->verified_at === null
            || $specimen->laboratory_order_id !== $order->id
            || $specimen->state !== LaboratorySpecimenAttempt::ACCEPTED) {
            throw new FinanceDenied('source_binding_invalid', 'Konteks hasil terverifikasi laboratorium tidak cocok.');
        }
        if (LaboratoryResultVersion::query()->where('laboratory_order_id', $order->id)
            ->where('state', LaboratoryResultVersion::VERIFIED)->whereNull('base_verified_version_id')->count() !== 1) {
            throw new FinanceDenied('source_integrity_failure', 'Hasil terverifikasi awal laboratorium tidak unik.');
        }
        if (LaboratorySpecimenAttempt::query()->where('laboratory_order_id', $order->id)
            ->where('state', LaboratorySpecimenAttempt::ACCEPTED)->count() !== 1) {
            throw new FinanceDenied('source_integrity_failure', 'Spesimen diterima laboratorium tidak unik.');
        }

        $critical = collect($result->results ?? [])->contains(
            fn (array $component): bool => ($component['interpretation'] ?? null) === 'CRITICAL',
        );
        if (($critical && ! $communication instanceof LaboratoryCriticalCommunication)
            || (! $critical && $communication instanceof LaboratoryCriticalCommunication)
            || ($communication instanceof LaboratoryCriticalCommunication
                && $communication->laboratory_result_version_id !== $result->id)) {
            throw new FinanceDenied('source_integrity_failure', 'Bukti komunikasi hasil kritis laboratorium tidak cocok.');
        }

        try {
            $orderDigest = $this->fingerprints->verifyOrderSnapshot($order);
            $specimenDigest = $this->fingerprints->specimenDigest($specimen);
            $resultContentDigest = $this->fingerprints->contentDigest($result);
            if (! hash_equals($result->content_digest, $resultContentDigest)) {
                throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Digest hasil terverifikasi tidak valid.');
            }
            $resultEvidenceDigest = $this->fingerprints->resultEvidenceDigest($result, $resultContentDigest);
            $completionDigest = $this->fingerprints->current($result);
            $communicationDigest = $communication instanceof LaboratoryCriticalCommunication
                ? $this->fingerprints->communicationDigest($communication)
                : '';
            if ($communication instanceof LaboratoryCriticalCommunication
                && ! hash_equals($communication->content_digest, $communicationDigest)) {
                throw new LaboratoryDenied('evidence_fingerprint_invalid', 'Digest komunikasi kritis tidak valid.');
            }
        } catch (LaboratoryDenied) {
            throw new FinanceDenied('source_integrity_failure', 'Rantai bukti hasil laboratorium tidak dapat direkonsiliasi.');
        }

        return [
            'order_snapshot_digest' => $orderDigest,
            'specimen_content_digest' => $specimenDigest,
            'result_content_digest' => $resultContentDigest,
            'result_evidence_digest' => $resultEvidenceDigest,
            'completion_evidence_digest' => $completionDigest,
            'critical_communication_content_digest' => $communicationDigest,
        ];
    }

    /** @param array<string, string> $evidence
     * @return array<string, int|string|\DateTimeInterface|null>
     */
    private function sourceSnapshot(
        Encounter $encounter,
        LaboratoryOrder $order,
        LaboratoryResultVersion $result,
        LaboratorySpecimenAttempt $specimen,
        ?LaboratoryCriticalCommunication $communication,
        array $evidence,
        FinanceLaboratoryTariffResolution $resolution,
    ): array {
        return [
            'laboratory_result_version_id' => $result->id,
            'laboratory_order_id' => $order->id,
            'laboratory_specimen_attempt_id' => $specimen->id,
            'laboratory_critical_communication_id' => $communication?->id,
            'binding_version_id' => $resolution->bindingVersion->id,
            'tariff_item_version_id' => $resolution->tariffVersion->id,
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'result_public_id' => $result->public_id,
            'result_version' => $result->version,
            'result_content_digest' => $evidence['result_content_digest'],
            'result_evidence_digest' => $evidence['result_evidence_digest'],
            'verified_at' => $result->verified_at,
            'order_public_id' => $order->public_id,
            'order_snapshot_digest' => $evidence['order_snapshot_digest'],
            'specimen_public_id' => $specimen->public_id,
            'specimen_attempt_number' => $specimen->attempt_number,
            'specimen_label_identifier' => $specimen->label_identifier,
            'specimen_content_digest' => $evidence['specimen_content_digest'],
            'critical_communication_public_id' => $communication?->public_id,
            'critical_communication_content_digest' => $communication === null
                ? null : $evidence['critical_communication_content_digest'],
            'completion_evidence_digest' => $evidence['completion_evidence_digest'],
            'encounter_public_id' => $encounter->public_id,
            'patient_public_id' => (string) $encounter->patient?->public_id,
            'care_setting' => $encounter->care_setting,
            'laboratory_master_version_public_id' => $order->master_version_public_id,
            'laboratory_master_version' => $order->master_version,
            'laboratory_master_code' => $order->master_code,
            'laboratory_master_content_digest' => $order->master_content_digest,
            'binding_public_id' => $resolution->binding->public_id,
            'binding_version_public_id' => $resolution->bindingVersion->public_id,
            'binding_version' => $resolution->bindingVersion->version,
            'binding_content_digest' => $resolution->bindingVersion->content_digest,
            'tariff_item_public_id' => $resolution->tariffItem->public_id,
            'tariff_item_version_public_id' => $resolution->tariffVersion->public_id,
            'tariff_item_code' => $resolution->tariffItem->tariff_code,
            'tariff_content_digest' => $resolution->tariffVersion->content_digest,
            'component_public_id' => $resolution->component->public_id,
            'component_code' => $resolution->component->component_code,
            'component_content_digest' => $resolution->componentVersion->content_digest,
            'service_date' => $resolution->serviceDate,
            'event_type' => FinanceChargeEvent::CHARGE,
            'quantity' => 1,
            'unit_amount' => $resolution->amountRupiah,
            'signed_amount' => $resolution->amountRupiah,
            'description' => 'Pemeriksaan laboratorium terverifikasi: '.$order->master_display_name,
        ];
    }

    /** @return array<string, int|string|\DateTimeInterface> */
    private function chargeSnapshot(Encounter $encounter, FinanceLaboratorySourceEvent $source): array
    {
        return [
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'source_domain' => self::SOURCE_DOMAIN,
            'source_table' => self::SOURCE_TABLE,
            'source_public_id' => $source->public_id,
            'source_content_digest' => $source->content_digest,
            'event_type' => FinanceChargeEvent::CHARGE,
            'care_setting' => $encounter->care_setting,
            'quantity' => 1,
            'unit_amount' => $source->unit_amount,
            'signed_amount' => $source->signed_amount,
            'description' => $source->description,
            'occurred_at' => $source->verified_at,
        ];
    }

    /** @param array<string, mixed> $expected */
    private function assertSnapshot(Model $record, array $expected, string $message): void
    {
        foreach ($expected as $field => $value) {
            $actual = $record->getAttribute($field);
            if ($field === 'service_date' && $actual instanceof \DateTimeInterface) {
                if ($actual->format('Y-m-d') !== (string) $value) {
                    throw new FinanceDenied('source_integrity_failure', $message);
                }

                continue;
            }
            if ($actual instanceof \DateTimeInterface && $value instanceof \DateTimeInterface) {
                if ($actual->format('Y-m-d H:i:s.uP') !== $value->format('Y-m-d H:i:s.uP')) {
                    throw new FinanceDenied('source_integrity_failure', $message);
                }

                continue;
            }
            if ((string) $actual !== (string) $value) {
                throw new FinanceDenied('source_integrity_failure', $message);
            }
        }
    }
}
