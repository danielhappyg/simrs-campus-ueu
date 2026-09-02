<?php

namespace App\Support\Finance;

use App\Models\Encounter;
use App\Models\FinanceAccommodationSourceEvent;
use App\Models\FinanceChargeEvent;
use App\Models\InpatientDischarge;
use App\Models\InpatientLocationEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class FinanceAccommodationSourceAdapter
{
    private const SOURCE_DOMAIN = 'ACCOMMODATION';

    private const SOURCE_TABLE = 'finance_accommodation_source_events';

    public function __construct(
        private readonly FinanceAccommodationOccupancyDayAllocator $allocator,
        private readonly FinanceAccommodationTariffProjection $tariffs,
    ) {}

    /** @return list<array<string, mixed>> */
    public function readiness(Encounter $encounter): array
    {
        if ($encounter->care_setting !== Encounter::CARE_SETTING_INPATIENT) {
            return [];
        }
        $plan = $this->allocator->allocate($encounter);
        if (in_array($plan->blockingState, [FinanceAccommodationOccupancyDayAllocator::INCOMPLETE_HISTORY, FinanceAccommodationOccupancyDayAllocator::CORRUPT_EVIDENCE], true)) {
            return [$this->issue($plan->blockingState, null, null, null)];
        }
        $items = [];
        foreach ($plan->days as $day) {
            $source = FinanceAccommodationSourceEvent::query()->where('encounter_id', $encounter->id)
                ->whereDate('service_date', $day->serviceDate)->first();
            if ($source instanceof FinanceAccommodationSourceEvent) {
                $charge = FinanceChargeEvent::query()->where('finance_accommodation_source_event_id', $source->id)->first();
                try {
                    if (! $charge instanceof FinanceChargeEvent) {
                        throw new FinanceDenied('source_integrity_failure', 'Sumber akomodasi tidak memiliki biaya bertipe.');
                    }
                    $this->verifyRetained($encounter, collect([$charge]));
                    $items[] = $this->issue(
                        'TERSINKRONISASI',
                        $day->serviceDate,
                        null,
                        $day->anchorAt->toIso8601String(),
                    );
                } catch (FinanceDenied) {
                    $items[] = $this->issue(
                        'BUKTI_TIDAK_KONSISTEN',
                        $day->serviceDate,
                        null,
                        $day->anchorAt->toIso8601String(),
                    );
                }

                continue;
            }
            try {
                $resolution = $this->tariffs->resolveExact(
                    (string) $day->interval->opening->to_inpatient_bed_version_public_id,
                    (string) $day->interval->opening->to_inpatient_bed_after_digest,
                    $day->serviceDate,
                );
                $items[] = $this->issue(
                    'SIAP_DISINKRONKAN',
                    $day->serviceDate,
                    $resolution->amountRupiah,
                    $day->anchorAt->toIso8601String(),
                );
            } catch (FinanceDenied $denied) {
                $items[] = $this->issue(
                    $this->readinessState($denied),
                    $day->serviceDate,
                    null,
                    $day->anchorAt->toIso8601String(),
                );
            }
        }
        if ($plan->blockingState === FinanceAccommodationOccupancyDayAllocator::OPEN_INTERVAL) {
            $items[] = $this->issue($plan->blockingState, null, null, null);
        }

        return $items;
    }

    /** @return Collection<int, FinanceChargeEvent> */
    public function synchronize(Encounter $encounter, User $actor): Collection
    {
        if ($encounter->care_setting !== Encounter::CARE_SETTING_INPATIENT) {
            return collect();
        }

        return FinanceMutationScope::run(function () use ($encounter, $actor): Collection {
            $encounter->loadMissing('patient');
            if (! $encounter->patient?->is_synthetic) {
                throw new FinanceDenied('non_synthetic_record', 'Tagihan akomodasi hanya untuk pasien pengajaran.');
            }
            $plan = $this->allocator->allocate($encounter);
            if (in_array($plan->blockingState, [FinanceAccommodationOccupancyDayAllocator::INCOMPLETE_HISTORY, FinanceAccommodationOccupancyDayAllocator::CORRUPT_EVIDENCE], true)) {
                throw new FinanceDenied('unresolved_accommodation_source', 'Riwayat lokasi rawat inap belum dapat dinilai sebagai sumber akomodasi.');
            }
            foreach ($plan->days as $day) {
                $existing = FinanceAccommodationSourceEvent::query()->where('encounter_id', $encounter->id)
                    ->whereDate('service_date', $day->serviceDate)->first();
                if ($existing instanceof FinanceAccommodationSourceEvent) {
                    $charge = FinanceChargeEvent::query()->where('finance_accommodation_source_event_id', $existing->id)->first();
                    if (! $charge instanceof FinanceChargeEvent) {
                        throw new FinanceDenied('source_integrity_failure', 'Sumber akomodasi tidak memiliki biaya bertipe.');
                    }
                    $this->verifyRetained($encounter, collect([$charge]));

                    continue;
                }
                try {
                    $resolution = $this->tariffs->resolveExact(
                        (string) $day->interval->opening->to_inpatient_bed_version_public_id,
                        (string) $day->interval->opening->to_inpatient_bed_after_digest,
                        $day->serviceDate,
                    );
                } catch (FinanceDenied $denied) {
                    if ($this->isReadinessGap($denied)) {
                        continue;
                    }
                    throw $denied;
                }
                $snapshot = $this->sourceSnapshot($encounter, $day, $resolution);
                $now = now();
                $source = FinanceAccommodationSourceEvent::query()->create([
                    ...$snapshot, 'imported_by_user_id' => $actor->id,
                    'content_digest' => FinanceCanonicalJson::digest($snapshot), 'imported_at' => $now, 'created_at' => $now,
                ]);
                $chargeSnapshot = $this->chargeSnapshot($encounter, $source);
                $charge = new FinanceChargeEvent;
                $charge->forceFill([
                    ...$chargeSnapshot,
                    'pharmacy_financial_source_event_id' => null,
                    'finance_radiology_source_event_id' => null,
                    'finance_laboratory_source_event_id' => null,
                    'finance_accommodation_source_event_id' => $source->id,
                    'imported_by_user_id' => $actor->id,
                    'content_digest' => FinanceCanonicalJson::digest($chargeSnapshot),
                    'imported_at' => $now, 'created_at' => $now,
                ]);
                $charge->save();
            }

            return FinanceChargeEvent::query()->where('encounter_id', $encounter->id)
                ->where('source_domain', self::SOURCE_DOMAIN)->orderBy('occurred_at')->orderBy('id')->get();
        });
    }

    /** @param Collection<int, FinanceChargeEvent> $events */
    public function verifyRetained(Encounter $encounter, Collection $events): void
    {
        if ($encounter->care_setting !== Encounter::CARE_SETTING_INPATIENT) {
            if ($events->isNotEmpty()) {
                throw new FinanceDenied('source_binding_invalid', 'Sumber akomodasi tidak boleh terikat pada encounter non-rawat-inap.');
            }

            return;
        }
        $plan = $this->allocator->allocate($encounter);
        if (in_array($plan->blockingState, [FinanceAccommodationOccupancyDayAllocator::INCOMPLETE_HISTORY, FinanceAccommodationOccupancyDayAllocator::CORRUPT_EVIDENCE], true)) {
            throw new FinanceDenied('source_integrity_failure', 'Riwayat lokasi sumber akomodasi tidak lagi utuh.');
        }
        $days = collect($plan->days)->keyBy('serviceDate');
        foreach ($events as $event) {
            if ($event->source_domain !== self::SOURCE_DOMAIN || $event->source_table !== self::SOURCE_TABLE
                || $event->pharmacy_financial_source_event_id !== null || $event->finance_radiology_source_event_id !== null
                || $event->finance_laboratory_source_event_id !== null || $event->finance_accommodation_source_event_id === null) {
                throw new FinanceDenied('source_binding_invalid', 'Jenis sumber biaya akomodasi tidak valid.');
            }
            $source = FinanceAccommodationSourceEvent::query()->whereKey($event->finance_accommodation_source_event_id)->first();
            if (! $source instanceof FinanceAccommodationSourceEvent) {
                throw new FinanceDenied('source_integrity_failure', 'Bukti sumber akomodasi tidak tersedia.');
            }
            $day = $days->get($source->service_date->format('Y-m-d'));
            if (! $day instanceof FinanceAccommodationOccupancyDay) {
                throw new FinanceDenied('source_integrity_failure', 'Hari okupansi sumber akomodasi tidak dapat direkonsiliasi.');
            }
            $resolution = $this->tariffs->resolveExact($source->inpatient_bed_version_public_id, $source->inpatient_bed_content_digest, $day->serviceDate);
            $expectedSource = $this->sourceSnapshot($encounter, $day, $resolution);
            $this->assertSnapshot($source, $expectedSource);
            if (! hash_equals($source->content_digest, FinanceCanonicalJson::digest($expectedSource))) {
                throw new FinanceDenied('source_integrity_failure', 'Digest sumber akomodasi tidak valid.');
            }
            $expectedCharge = $this->chargeSnapshot($encounter, $source);
            $this->assertSnapshot($event, $expectedCharge);
            if (! hash_equals($event->content_digest, FinanceCanonicalJson::digest($expectedCharge))) {
                throw new FinanceDenied('source_integrity_failure', 'Digest biaya akomodasi tidak valid.');
            }
        }
    }

    public function isReadinessGap(FinanceDenied $denied): bool
    {
        return in_array($denied->reason, ['unresolved_accommodation_source', 'tariff_not_effective', 'source_binding_invalid'], true);
    }

    /** @return array<string, mixed> */
    private function sourceSnapshot(Encounter $encounter, FinanceAccommodationOccupancyDay $day, FinanceAccommodationTariffResolution $r): array
    {
        $opening = $day->interval->opening;
        $closing = $day->interval->closing;
        $transfer = $closing instanceof InpatientLocationEvent ? $closing : null;
        $discharge = $closing instanceof InpatientDischarge ? $closing : null;
        if ($transfer === null && $discharge === null) {
            throw new FinanceDenied('source_integrity_failure', 'Interval akomodasi belum memiliki penutup immutable.');
        }

        return [
            'inpatient_location_event_id' => $opening->id,
            'inpatient_bed_version_id' => $opening->to_inpatient_bed_version_id,
            'closing_location_event_id' => $transfer?->id,
            'inpatient_discharge_id' => $discharge?->id,
            'binding_version_id' => $r->bindingVersion->id,
            'tariff_item_version_id' => $r->tariffVersion->id,
            'encounter_id' => $encounter->id, 'patient_id' => $encounter->patient_id,
            'opening_location_event_public_id' => $opening->public_id,
            'opening_location_event_digest' => $opening->payload_digest,
            'closing_type' => $transfer ? FinanceAccommodationSourceEvent::BED_TRANSFER : FinanceAccommodationSourceEvent::ROUTINE_DISCHARGE,
            'closing_public_id' => (string) ($transfer !== null ? $transfer->public_id : $discharge->public_id),
            'closing_content_digest' => (string) ($transfer !== null ? $transfer->payload_digest : $discharge->payload_digest),
            'interval_start_at' => $day->interval->startsAt,
            'interval_end_at' => $day->interval->endsAt,
            'occupancy_anchor_at' => $day->anchorAt,
            'encounter_public_id' => $encounter->public_id,
            'patient_public_id' => (string) $encounter->patient?->public_id,
            'care_setting' => 'INPATIENT',
            'ward_public_id' => $opening->to_ward_public_id, 'ward_code' => $opening->to_ward_code,
            'bed_public_id' => $opening->to_bed_public_id, 'bed_code' => $opening->to_bed_code,
            'bed_display_name' => $opening->to_bed_display_name, 'room_label' => $opening->to_room_label,
            'service_class' => $opening->to_service_class,
            'inpatient_bed_version_public_id' => $opening->to_inpatient_bed_version_public_id,
            'inpatient_bed_version' => $opening->to_inpatient_bed_version,
            'inpatient_bed_content_digest' => $opening->to_inpatient_bed_after_digest,
            'binding_public_id' => $r->binding->public_id,
            'binding_version_public_id' => $r->bindingVersion->public_id,
            'binding_version' => $r->bindingVersion->version,
            'binding_content_digest' => $r->bindingVersion->content_digest,
            'tariff_item_public_id' => $r->tariffItem->public_id,
            'tariff_item_version_public_id' => $r->tariffVersion->public_id,
            'tariff_item_code' => $r->tariffItem->tariff_code,
            'tariff_content_digest' => $r->tariffVersion->content_digest,
            'component_public_id' => $r->component->public_id,
            'component_code' => $r->component->component_code,
            'component_content_digest' => $r->componentVersion->content_digest,
            'service_date' => $day->serviceDate, 'pricing_unit' => 'OCCUPANCY_DAY',
            'event_type' => FinanceChargeEvent::CHARGE, 'quantity' => 1,
            'unit_amount' => $r->amountRupiah, 'signed_amount' => $r->amountRupiah,
            'description' => 'Akomodasi rawat inap '.$opening->to_bed_code.' '.$day->serviceDate,
        ];
    }

    /** @return array<string, mixed> */
    private function chargeSnapshot(Encounter $encounter, FinanceAccommodationSourceEvent $source): array
    {
        return [
            'encounter_id' => $encounter->id, 'patient_id' => $encounter->patient_id,
            'source_domain' => self::SOURCE_DOMAIN, 'source_table' => self::SOURCE_TABLE,
            'source_public_id' => $source->public_id, 'source_content_digest' => $source->content_digest,
            'event_type' => FinanceChargeEvent::CHARGE, 'care_setting' => 'INPATIENT',
            'quantity' => 1, 'unit_amount' => $source->unit_amount, 'signed_amount' => $source->signed_amount,
            'description' => $source->description, 'occurred_at' => $source->occupancy_anchor_at,
        ];
    }

    /** @param array<string, mixed> $expected */
    private function assertSnapshot(Model $model, array $expected): void
    {
        foreach ($expected as $key => $value) {
            $actual = $model->getAttribute($key);
            if ($actual instanceof \DateTimeInterface && $value instanceof \DateTimeInterface) {
                if ($actual->format('Y-m-d H:i:s.uP') !== $value->format('Y-m-d H:i:s.uP')) {
                    throw new FinanceDenied('source_integrity_failure', 'Snapshot sumber akomodasi berubah.');
                }
            } elseif ($key === 'service_date' && $actual instanceof \DateTimeInterface) {
                if ($actual->format('Y-m-d') !== (string) $value) {
                    throw new FinanceDenied('source_integrity_failure', 'Snapshot sumber akomodasi berubah.');
                }
            } elseif ((string) $actual !== (string) $value) {
                throw new FinanceDenied('source_integrity_failure', 'Snapshot sumber akomodasi berubah.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function issue(?string $state, ?string $date, ?int $previewSignedAmount, ?string $serviceAt): array
    {
        return [
            'source_domain' => self::SOURCE_DOMAIN,
            'state' => $state,
            'service_date' => $date,
            'service_at' => $serviceAt,
            'preview_signed_amount' => $previewSignedAmount,
        ];
    }

    private function readinessState(FinanceDenied $denied): string
    {
        return match ($denied->reason) {
            'unresolved_accommodation_source' => 'TARIF_BELUM_DIPETAKAN',
            'tariff_not_effective' => 'TARIF_TIDAK_EFEKTIF',
            'source_binding_invalid' => 'KONTEKS_TIDAK_COCOK',
            default => 'BUKTI_TIDAK_KONSISTEN',
        };
    }
}
