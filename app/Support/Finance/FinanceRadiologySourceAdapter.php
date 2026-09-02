<?php

namespace App\Support\Finance;

use App\Models\Encounter;
use App\Models\FinanceChargeEvent;
use App\Models\FinanceRadiologySourceEvent;
use App\Models\RadiologyOrder;
use App\Models\RadiologyPerformance;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class FinanceRadiologySourceAdapter
{
    public function __construct(
        private readonly FinanceRadiologyTariffProjection $tariffs,
    ) {}

    /** @param Collection<int, FinanceChargeEvent> $events */
    public function verifyRetained(Encounter $encounter, Collection $events): void
    {
        foreach ($events as $event) {
            if ($event->source_domain !== FinanceChargeEvent::SOURCE_RADIOLOGY
                || $event->source_table !== FinanceChargeEvent::SOURCE_TABLE_RADIOLOGY
                || $event->pharmacy_financial_source_event_id !== null
                || $event->finance_radiology_source_event_id === null) {
                throw new FinanceDenied('source_binding_invalid', 'Jenis sumber biaya radiologi tidak valid.');
            }

            $source = FinanceRadiologySourceEvent::query()
                ->whereKey($event->finance_radiology_source_event_id)->first();
            if (! $source instanceof FinanceRadiologySourceEvent) {
                throw new FinanceDenied('source_integrity_failure', 'Bukti sumber biaya radiologi tidak lagi tersedia.');
            }

            $performance = RadiologyPerformance::query()->whereKey($source->radiology_performance_id)->first();
            $order = RadiologyOrder::query()->with('master')->whereKey($source->radiology_order_id)->first();
            if (! $performance instanceof RadiologyPerformance || ! $order instanceof RadiologyOrder
                || $performance->radiology_order_id !== $order->id) {
                throw new FinanceDenied('source_integrity_failure', 'Bukti pemeriksaan radiologi tidak lagi utuh.');
            }

            $resolution = $this->tariffs->resolve($order, $performance->performed_at);
            $expectedSource = $this->sourceSnapshot($encounter, $order, $performance, $resolution);
            $this->assertSnapshot($source, $expectedSource, 'Sumber radiologi yang disimpan tidak cocok dengan bukti pemeriksaan.');
            if (! hash_equals($source->content_digest, FinanceCanonicalJson::digest($expectedSource))) {
                throw new FinanceDenied('source_integrity_failure', 'Digest sumber biaya radiologi tidak valid.');
            }

            $expectedCharge = $this->chargeSnapshot($encounter, $source);
            $this->assertSnapshot($event, $expectedCharge, 'Baris biaya radiologi tidak cocok dengan sumber bertipe.');
            if (! hash_equals($event->content_digest, FinanceCanonicalJson::digest($expectedCharge))) {
                throw new FinanceDenied('source_integrity_failure', 'Digest baris biaya radiologi tidak valid.');
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

        $orders = RadiologyOrder::query()->with(['master', 'performance'])
            ->where('encounter_id', $encounter->id)
            ->whereHas('performance')
            ->orderBy('id')->get();

        foreach ($orders as $order) {
            $performance = $order->performance;
            if (! $performance instanceof RadiologyPerformance) {
                continue;
            }

            $existingSource = FinanceRadiologySourceEvent::query()
                ->where('radiology_performance_id', $performance->id)->first();
            if ($existingSource instanceof FinanceRadiologySourceEvent) {
                $existingCharge = FinanceChargeEvent::query()
                    ->where('finance_radiology_source_event_id', $existingSource->id)->first();
                if (! $existingCharge instanceof FinanceChargeEvent) {
                    throw new FinanceDenied('source_integrity_failure', 'Sumber radiologi tidak memiliki baris biaya bertipe.');
                }
                $this->verifyRetained($encounter, collect([$existingCharge]));

                continue;
            }

            try {
                $this->validatePerformance($encounter, $order, $performance);
                $resolution = $this->tariffs->resolve($order, $performance->performed_at);
            } catch (FinanceDenied $denied) {
                if ($this->isReadinessGap($denied)) {
                    continue;
                }

                throw $denied;
            }

            $sourceSnapshot = $this->sourceSnapshot($encounter, $order, $performance, $resolution);
            $now = now();
            $source = FinanceRadiologySourceEvent::query()->create([
                ...$sourceSnapshot,
                'imported_by_user_id' => $actor->id,
                'content_digest' => FinanceCanonicalJson::digest($sourceSnapshot),
                'imported_at' => $now,
                'created_at' => $now,
            ]);
            $chargeSnapshot = $this->chargeSnapshot($encounter, $source);
            FinanceChargeEvent::query()->create([
                ...$chargeSnapshot,
                'finance_radiology_source_event_id' => $source->id,
                'pharmacy_financial_source_event_id' => null,
                'imported_by_user_id' => $actor->id,
                'content_digest' => FinanceCanonicalJson::digest($chargeSnapshot),
                'imported_at' => $now,
                'created_at' => $now,
            ]);
        }

        return FinanceChargeEvent::query()->where('encounter_id', $encounter->id)
            ->where('source_domain', FinanceChargeEvent::SOURCE_RADIOLOGY)
            ->orderBy('occurred_at')->orderBy('source_public_id')->orderBy('id')->get();
    }

    public function validatePerformance(Encounter $encounter, RadiologyOrder $order, RadiologyPerformance $performance): void
    {
        if ($order->encounter_id !== $encounter->id
            || $order->care_setting !== $encounter->care_setting
            || $performance->radiology_order_id !== $order->id
            || ! in_array($order->status, [RadiologyOrder::PERFORMED, RadiologyOrder::REPORTED_VERIFIED], true)) {
            throw new FinanceDenied('source_binding_invalid', 'Konteks pemeriksaan radiologi tidak cocok dengan encounter.');
        }
        if (RadiologyPerformance::query()->where('radiology_order_id', $order->id)->count() !== 1) {
            throw new FinanceDenied('source_integrity_failure', 'Bukti performa radiologi tidak satu-ke-satu.');
        }
    }

    public function isReadinessGap(FinanceDenied $denied): bool
    {
        return in_array($denied->reason, [
            'unresolved_radiology_source', 'tariff_not_effective', 'source_binding_invalid',
            'source_integrity_failure', 'source_reconciliation_failed',
        ], true);
    }

    /** @return array<string, int|string|\DateTimeInterface> */
    private function sourceSnapshot(
        Encounter $encounter,
        RadiologyOrder $order,
        RadiologyPerformance $performance,
        FinanceRadiologyTariffResolution $resolution,
    ): array {
        return [
            'radiology_performance_id' => $performance->id,
            'radiology_order_id' => $order->id,
            'binding_version_id' => $resolution->bindingVersion->id,
            'tariff_item_version_id' => $resolution->tariffVersion->id,
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'performance_public_id' => $performance->public_id,
            'performed_at' => $performance->performed_at,
            'order_public_id' => $order->public_id,
            'encounter_public_id' => $encounter->public_id,
            'patient_public_id' => (string) $encounter->patient?->public_id,
            'care_setting' => $encounter->care_setting,
            'radiology_master_version_public_id' => $order->master_version_public_id,
            'radiology_master_version' => $order->master_version,
            'radiology_master_code' => $order->master_code,
            'radiology_master_content_digest' => $order->master_content_digest,
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
            'description' => 'Pemeriksaan radiologi selesai: '.$order->master_display_name,
        ];
    }

    /** @return array<string, int|string|\DateTimeInterface> */
    private function chargeSnapshot(Encounter $encounter, FinanceRadiologySourceEvent $source): array
    {
        return [
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'source_domain' => FinanceChargeEvent::SOURCE_RADIOLOGY,
            'source_table' => FinanceChargeEvent::SOURCE_TABLE_RADIOLOGY,
            'source_public_id' => $source->public_id,
            'source_content_digest' => $source->content_digest,
            'event_type' => FinanceChargeEvent::CHARGE,
            'care_setting' => $encounter->care_setting,
            'quantity' => 1,
            'unit_amount' => $source->unit_amount,
            'signed_amount' => $source->signed_amount,
            'description' => $source->description,
            'occurred_at' => $source->performed_at,
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
