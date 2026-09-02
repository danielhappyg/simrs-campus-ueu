<?php

namespace App\Support\Finance;

use App\Models\Encounter;
use App\Models\FinanceChargeEvent;
use App\Models\PharmacyFinancialSourceEvent;
use App\Models\PharmacyHandoverItem;
use App\Models\PharmacyPrescription;
use App\Models\PharmacyPrescriptionItem;
use App\Models\PharmacyReturnItem;
use App\Models\User;
use App\Support\Pharmacy\PharmacyCanonicalJson;
use Illuminate\Support\Collection;

final class FinancePharmacySourceAdapter
{
    /** @param Collection<int, FinanceChargeEvent> $events */
    public function verifyRetained(Encounter $encounter, Collection $events): void
    {
        foreach ($events as $event) {
            $source = PharmacyFinancialSourceEvent::query()->whereKey($event->pharmacy_financial_source_event_id)->first();
            if (! $source) {
                throw new FinanceDenied('source_integrity_failure', 'Bukti sumber biaya farmasi tidak lagi tersedia.');
            }
            $expected = $this->validatedSource($source, $encounter);
            foreach ($expected as $field => $value) {
                if ((string) $event->getAttribute($field) !== (string) $value) {
                    throw new FinanceDenied('source_integrity_failure', 'Sumber biaya yang telah dimasukkan tidak lagi cocok dengan bukti farmasi.');
                }
            }
        }
    }

    /** @return Collection<int, FinanceChargeEvent> */
    public function synchronize(Encounter $encounter, User $actor): Collection
    {
        if (! $encounter->patient?->is_synthetic) {
            throw new FinanceDenied('non_synthetic_record', 'Tagihan hanya dapat menggunakan pasien pengajaran yang diizinkan.');
        }
        $prescriptionIds = PharmacyPrescription::query()->where('encounter_id', $encounter->id)->pluck('id');
        $sources = PharmacyFinancialSourceEvent::query()
            ->whereIn('prescription_id', $prescriptionIds)
            ->orderBy('occurred_at')->orderBy('public_id')->orderBy('id')->get();

        foreach ($sources as $source) {
            $expected = $this->validatedSource($source, $encounter);
            $existing = FinanceChargeEvent::query()->where('pharmacy_financial_source_event_id', $source->id)->first();
            if ($existing) {
                foreach ($expected as $field => $value) {
                    if ((string) $existing->getAttribute($field) !== (string) $value) {
                        throw new FinanceDenied('source_integrity_failure', 'Sumber biaya yang telah dimasukkan tidak lagi cocok dengan bukti farmasi.');
                    }
                }

                continue;
            }
            FinanceChargeEvent::query()->create([
                ...$expected,
                'pharmacy_financial_source_event_id' => $source->id,
                'imported_by_user_id' => $actor->id,
                'imported_at' => now(),
                'created_at' => now(),
            ]);
        }

        return FinanceChargeEvent::query()->where('encounter_id', $encounter->id)
            ->where('source_domain', FinanceChargeEvent::SOURCE_PHARMACY)
            ->orderBy('occurred_at')->orderBy('source_domain')->orderBy('source_public_id')->orderBy('id')->get();
    }

    /** @return array<string, int|string> */
    private function validatedSource(PharmacyFinancialSourceEvent $source, Encounter $encounter): array
    {
        $prescription = PharmacyPrescription::query()->whereKey($source->prescription_id)->first();
        $item = PharmacyPrescriptionItem::query()->whereKey($source->prescription_item_id)->first();
        if (! $prescription || ! $item || $item->prescription_id !== $prescription->id
            || $prescription->encounter_id !== $encounter->id || $prescription->patient_id !== $encounter->patient_id
            || $prescription->care_setting !== $encounter->care_setting) {
            throw new FinanceDenied('source_binding_invalid', 'Ikatan pasien atau encounter sumber biaya tidak valid.');
        }
        $retainedDigest = PharmacyCanonicalJson::digest([
            $prescription->public_id, $item->public_id, $source->actor_user_id, $source->event_type,
            $source->quantity, $source->amount, $source->source_type, $source->source_public_id,
        ]);
        if (! hash_equals($source->content_digest, $retainedDigest) || $source->quantity < 1) {
            throw new FinanceDenied('source_integrity_failure', 'Bukti sumber biaya farmasi tidak utuh.');
        }

        $unitAmount = 0;
        $description = '';
        if ($source->event_type === PharmacyFinancialSourceEvent::CHARGE && $source->source_type === 'HANDOVER_ITEM') {
            $handoverItem = PharmacyHandoverItem::query()->where('public_id', $source->source_public_id)->first();
            if (! $handoverItem || $handoverItem->prescription_item_id !== $item->id
                || $handoverItem->quantity !== $source->quantity
                || $source->amount !== $handoverItem->quantity * $handoverItem->sale_value_snapshot
                || $source->amount < 0) {
                throw new FinanceDenied('source_reconciliation_failed', 'Biaya penyerahan obat tidak cocok dengan item penyerahan.');
            }
            $unitAmount = $handoverItem->sale_value_snapshot;
            $description = 'Obat diserahkan: '.$item->medicine_name;
        } elseif ($source->event_type === PharmacyFinancialSourceEvent::REVERSAL && $source->source_type === 'RETURN_ITEM') {
            $returnItem = PharmacyReturnItem::query()->where('public_id', $source->source_public_id)->first();
            $handoverItem = $returnItem?->handoverItem()->first();
            $originalCharge = $handoverItem ? PharmacyFinancialSourceEvent::query()
                ->where('event_type', PharmacyFinancialSourceEvent::CHARGE)
                ->where('source_type', 'HANDOVER_ITEM')->where('source_public_id', $handoverItem->public_id)
                ->where('prescription_item_id', $item->id)->first() : null;
            if (! $returnItem || ! $handoverItem || ! $originalCharge
                || $handoverItem->prescription_item_id !== $item->id || $returnItem->quantity !== $source->quantity
                || $source->amount !== -($returnItem->quantity * $handoverItem->sale_value_snapshot)
                || $source->amount > 0) {
                throw new FinanceDenied('source_reconciliation_failed', 'Pembalikan biaya retur tidak cocok dengan item penyerahan asal.');
            }
            $unitAmount = $handoverItem->sale_value_snapshot;
            $description = 'Retur obat: '.$item->medicine_name;
        } else {
            throw new FinanceDenied('unsupported_source', 'Jenis sumber biaya belum diizinkan.');
        }

        $normalized = [
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'source_domain' => FinanceChargeEvent::SOURCE_PHARMACY,
            'source_table' => FinanceChargeEvent::SOURCE_TABLE_PHARMACY,
            'source_public_id' => $source->public_id,
            'source_content_digest' => $source->content_digest,
            'event_type' => $source->event_type,
            'care_setting' => $encounter->care_setting,
            'quantity' => $source->quantity,
            'unit_amount' => $unitAmount,
            'signed_amount' => $source->amount,
            'description' => $description,
            'occurred_at' => $source->occurred_at,
        ];

        return [
            ...$normalized,
            'content_digest' => FinanceCanonicalJson::digest($normalized),
        ];
    }
}
