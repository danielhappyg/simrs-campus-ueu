<?php

namespace App\Support\Pharmacy;

use App\Models\Encounter;
use App\Models\InpatientLocationEvent;
use App\Models\PharmacyDepot;
use App\Models\PharmacyFinancialSourceEvent;
use App\Models\PharmacyHandover;
use App\Models\PharmacyHandoverItem;
use App\Models\PharmacyMedicine;
use App\Models\PharmacyMedicineVersion;
use App\Models\PharmacyOperationReceipt;
use App\Models\PharmacyPreparation;
use App\Models\PharmacyPreparationAllocation;
use App\Models\PharmacyPrescription;
use App\Models\PharmacyPrescriptionItem;
use App\Models\PharmacyPrescriptionVersion;
use App\Models\PharmacyReturn;
use App\Models\PharmacyReturnItem;
use App\Models\PharmacyStockLot;
use App\Models\PharmacyStockMovement;
use App\Models\PharmacyVerification;
use App\Models\User;
use Illuminate\Support\Collection;

final class PharmacyWorkflowService
{
    private const CHECKLIST = ['identity_confirmed', 'context_confirmed', 'medicine_readable', 'instruction_readable'];

    public function __construct(private readonly PharmacyActorPolicy $policy, private readonly PharmacyOperationCoordinator $operations, private readonly PharmacyEvidenceFingerprint $fingerprints, private readonly PharmacyLockCoordinator $locks) {}

    /** @param list<mixed> $items */
    public function createDraft(string $encounterPublicId, User $actor, string $depotPublicId, array $items, ?string $clinicalNote, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_PRESCRIPTION_DRAFT_CREATE', $encounterPublicId, fn () => $this->policy->prescribe($actor));
        [$content,$snapshot] = $this->operations->validate($actor, 'PHARMACY_PRESCRIPTION_DRAFT_CREATE', $encounterPublicId, function () use ($depotPublicId, $items, $clinicalNote): array {
            $content = $this->normalizeDraft($depotPublicId, $items, $clinicalNote);

            return [$content, $this->materializeDraft($content)];
        });

        return $this->operations->execute($actor, 'PHARMACY_PRESCRIPTION_DRAFT_CREATE', $encounterPublicId, $key, compact('encounterPublicId', 'content'), PharmacyOperationReceipt::RESULT_PRESCRIPTION, function () use ($encounterPublicId, $actor, $content, $snapshot): PharmacyPrescription {
            $candidateEncounter = Encounter::query()->where('public_id', $encounterPublicId)->firstOrFail();
            $depot = PharmacyDepot::query()->where('public_id', $content['depot_public_id'])->where('state', PharmacyDepot::ACTIVE)->firstOrFail();
            [$encounter,,$depot] = $this->locks->lockContext($candidateEncounter, $depot, array_column($snapshot['items'], 'medicine_id'));
            $this->eligibleEncounter($encounter);
            $this->eligibleDepot($depot, $encounter);
            $this->assertSnapshotMasters($snapshot['items']);
            [$location,$locationFingerprint] = $this->location($encounter);
            $digest = PharmacyCanonicalJson::digest([$encounter->public_id, $depot->public_id, $snapshot, $locationFingerprint, PharmacyPrescription::DRAFT, 1]);
            $prescription = PharmacyPrescription::query()->create(['encounter_id' => $encounter->id, 'patient_id' => $encounter->patient_id, 'ordering_physician_user_id' => $actor->id, 'depot_id' => $depot->id, 'care_setting' => $encounter->care_setting, 'encounter_number_snapshot' => $encounter->public_id, 'location_snapshot' => $location, 'location_fingerprint' => $locationFingerprint, 'depot_version' => $depot->version, 'depot_code_snapshot' => $depot->depot_code, 'clinical_note' => $content['clinical_note'], 'status' => PharmacyPrescription::DRAFT, 'version' => 1, 'current_content_digest' => $digest]);
            $this->version($prescription, $actor, $snapshot);

            return $prescription;
        });
    }

    /** @param list<mixed> $items */
    public function reviseDraft(string $prescriptionPublicId, User $actor, int $expectedVersion, array $items, ?string $clinicalNote, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_PRESCRIPTION_DRAFT_REVISE', $prescriptionPublicId, fn () => $this->policy->prescribe($actor));
        [$content,$snapshot] = $this->operations->validate($actor, 'PHARMACY_PRESCRIPTION_DRAFT_REVISE', $prescriptionPublicId, function () use ($items, $clinicalNote): array {
            $content = $this->normalizeDraft(null, $items, $clinicalNote);

            return [$content, $this->materializeDraft($content)];
        });

        return $this->operations->execute($actor, 'PHARMACY_PRESCRIPTION_DRAFT_REVISE', $prescriptionPublicId, $key, compact('prescriptionPublicId', 'expectedVersion', 'content'), PharmacyOperationReceipt::RESULT_PRESCRIPTION, function () use ($prescriptionPublicId, $actor, $expectedVersion, $content, $snapshot): PharmacyPrescription {
            $prescription = $this->lockPrescription($prescriptionPublicId, array_column($snapshot['items'], 'medicine_id'));
            $this->ownDraft($prescription, $actor, $expectedVersion);
            $encounter = $prescription->encounter()->firstOrFail();
            $this->eligibleEncounter($encounter);
            $this->assertCurrentContext($prescription, $encounter);
            $this->assertSnapshotMasters($snapshot['items']);
            $prescription->version++;
            $prescription->clinical_note = $content['clinical_note'];
            $prescription->current_content_digest = PharmacyCanonicalJson::digest([$prescription->public_id, $snapshot, PharmacyPrescription::DRAFT, $prescription->version]);
            $prescription->save();
            $this->version($prescription, $actor, $snapshot);

            return $prescription;
        });
    }

    public function order(string $prescriptionPublicId, User $actor, int $expectedVersion, string $expectedFingerprint, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_PRESCRIPTION_ORDER', $prescriptionPublicId, fn () => $this->policy->prescribe($actor));

        return $this->operations->execute($actor, 'PHARMACY_PRESCRIPTION_ORDER', $prescriptionPublicId, $key, compact('prescriptionPublicId', 'expectedVersion', 'expectedFingerprint'), PharmacyOperationReceipt::RESULT_PRESCRIPTION, function () use ($prescriptionPublicId, $actor, $expectedVersion, $expectedFingerprint): PharmacyPrescription {
            $prescription = $this->lockPrescription($prescriptionPublicId);
            $this->ownDraft($prescription, $actor, $expectedVersion);
            $this->assertPrescriptionFingerprint($prescription, $expectedFingerprint);
            $encounter = $prescription->encounter()->firstOrFail();
            $this->eligibleEncounter($encounter);
            $this->assertCurrentContext($prescription, $encounter);
            $latest = PharmacyPrescriptionVersion::query()->where('prescription_id', $prescription->id)->where('version', $prescription->version)->firstOrFail();
            $items = $latest->content_snapshot['items'] ?? [];
            if ($items === []) {
                throw new PharmacyDenied('validation_failed', 'Resep tidak memiliki item.');
            }
            $this->assertSnapshotMasters($items);
            foreach ($items as $index => $item) {
                PharmacyPrescriptionItem::query()->create(['prescription_id' => $prescription->id, 'medicine_id' => $item['medicine_id'], 'line_number' => $index + 1, 'medicine_version' => $item['medicine_version'], 'medicine_version_public_id' => $item['medicine_version_public_id'], 'medicine_content_digest' => $item['medicine_content_digest'], 'medicine_code' => $item['medicine_code'], 'medicine_name' => $item['medicine_name'], 'strength_text' => $item['strength_text'], 'dosage_form' => $item['dosage_form'], 'base_unit' => $item['base_unit'], 'dose_text' => $item['dose_text'], 'route' => $item['route'], 'frequency_text' => $item['frequency_text'], 'duration_text' => $item['duration_text'], 'requested_quantity' => $item['requested_quantity'], 'sale_value_snapshot' => $item['sale_value_snapshot'], 'instruction' => $item['instruction'], 'content_digest' => PharmacyCanonicalJson::digest($item), 'created_at' => now()]);
            }
            $prescription->status = PharmacyPrescription::ORDERED;
            $prescription->version++;
            $prescription->ordered_at = now();
            $prescription->current_content_digest = PharmacyCanonicalJson::digest([$prescription->public_id, $items, PharmacyPrescription::ORDERED, $prescription->version]);
            $prescription->save();
            $this->version($prescription, $actor, ['items' => $items, 'clinical_note' => $prescription->clinical_note]);

            return $prescription;
        });
    }

    public function cancel(string $prescriptionPublicId, User $actor, string $expectedFingerprint, string $reasonCode, ?string $note, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_PRESCRIPTION_CANCEL', $prescriptionPublicId, fn () => $this->policy->prescribe($actor));
        [$reasonCode,$note] = $this->operations->validate($actor, 'PHARMACY_PRESCRIPTION_CANCEL', $prescriptionPublicId, fn (): array => [$this->reason($reasonCode), $this->optional($note, 1000)]);

        return $this->terminalPhysician($prescriptionPublicId, $actor, $expectedFingerprint, PharmacyPrescription::CANCELLED, $reasonCode, $note, $key, 'PHARMACY_PRESCRIPTION_CANCEL');
    }

    /** @param list<mixed> $items */
    public function replace(string $prescriptionPublicId, User $actor, string $expectedFingerprint, string $depotPublicId, array $items, ?string $clinicalNote, string $reasonCode, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_PRESCRIPTION_REPLACE', $prescriptionPublicId, fn () => $this->policy->prescribe($actor));
        [$reasonCode,$content,$snapshot] = $this->operations->validate($actor, 'PHARMACY_PRESCRIPTION_REPLACE', $prescriptionPublicId, function () use ($reasonCode, $depotPublicId, $items, $clinicalNote): array {
            $reason = $this->reason($reasonCode);
            $content = $this->normalizeDraft($depotPublicId, $items, $clinicalNote);

            return [$reason, $content, $this->materializeDraft($content)];
        });

        return $this->operations->execute($actor, 'PHARMACY_PRESCRIPTION_REPLACE', $prescriptionPublicId, $key, compact('prescriptionPublicId', 'expectedFingerprint', 'content', 'reasonCode'), PharmacyOperationReceipt::RESULT_PRESCRIPTION, function () use ($prescriptionPublicId, $actor, $expectedFingerprint, $content, $snapshot, $reasonCode): PharmacyPrescription {
            $source = $this->lockPrescription($prescriptionPublicId, array_column($snapshot['items'], 'medicine_id'));
            if ($source->ordering_physician_user_id !== $actor->id || ! in_array($source->status, [PharmacyPrescription::DRAFT, PharmacyPrescription::ORDERED, PharmacyPrescription::VERIFIED], true) || $source->preparations()->exists() || $source->handovers()->exists()) {
                throw new PharmacyDenied('replacement_not_permitted', 'Resep tidak dapat diganti.');
            }$this->assertPrescriptionFingerprint($source, $expectedFingerprint);
            $encounter = $source->encounter()->firstOrFail();
            $this->eligibleEncounter($encounter);
            $depot = PharmacyDepot::query()->where('public_id', $content['depot_public_id'])->where('state', PharmacyDepot::ACTIVE)->firstOrFail();
            if ($depot->id !== $source->depot_id) {
                throw new PharmacyDenied('replacement_depot_change_not_permitted', 'Penggantian resep harus tetap menggunakan depo yang sama.');
            }$this->eligibleDepot($depot, $encounter);
            $this->assertSnapshotMasters($snapshot['items']);
            $source->status = PharmacyPrescription::CANCELLED;
            $source->version++;
            $source->current_content_digest = PharmacyCanonicalJson::digest([$source->public_id, 'REPLACED', $reasonCode, $source->version]);
            $source->save();
            $this->version($source, $actor, ['reason_code' => $reasonCode, 'replacement_pending' => true]);
            [$location,$locationFingerprint] = $this->location($encounter);
            $digest = PharmacyCanonicalJson::digest([$encounter->public_id, $depot->public_id, $snapshot, $locationFingerprint, PharmacyPrescription::DRAFT, 1, $source->public_id]);
            $replacement = PharmacyPrescription::query()->create(['encounter_id' => $encounter->id, 'patient_id' => $encounter->patient_id, 'ordering_physician_user_id' => $actor->id, 'depot_id' => $depot->id, 'replaces_prescription_id' => $source->id, 'care_setting' => $encounter->care_setting, 'encounter_number_snapshot' => $encounter->public_id, 'location_snapshot' => $location, 'location_fingerprint' => $locationFingerprint, 'depot_version' => $depot->version, 'depot_code_snapshot' => $depot->depot_code, 'clinical_note' => $content['clinical_note'], 'status' => PharmacyPrescription::DRAFT, 'version' => 1, 'current_content_digest' => $digest]);
            $this->version($replacement, $actor, $snapshot);

            return $replacement;
        });
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @param  list<array<string, mixed>>  $itemDecisions
     */
    public function verify(string $prescriptionPublicId, User $actor, string $expectedFingerprint, string $manualAllergyReview, array $checklist, array $itemDecisions, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_VERIFY', $prescriptionPublicId, fn () => $this->policy->verify($actor));

        return $this->verification($prescriptionPublicId, $actor, $expectedFingerprint, $manualAllergyReview, $checklist, $itemDecisions, PharmacyVerification::VERIFIED, null, null, $key);
    }

    /** @param array<string, mixed> $checklist */
    public function refuse(string $prescriptionPublicId, User $actor, string $expectedFingerprint, string $manualAllergyReview, array $checklist, string $reasonCode, ?string $note, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_REFUSE', $prescriptionPublicId, fn () => $this->policy->verify($actor));
        [$reasonCode,$note] = $this->operations->validate($actor, 'PHARMACY_REFUSE', $prescriptionPublicId, fn (): array => [$this->reason($reasonCode), $this->optional($note, 1000)]);

        return $this->verification($prescriptionPublicId, $actor, $expectedFingerprint, $manualAllergyReview, $checklist, [], PharmacyVerification::REFUSED, $reasonCode, $note, $key);
    }

    /** @param array<string, mixed>|null $itemQuantities */
    public function prepare(string $prescriptionPublicId, User $actor, string $expectedFingerprint, string $key, ?array $itemQuantities = null, ?string $replacementReason = null): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_PREPARE', $prescriptionPublicId, fn () => $this->policy->prepare($actor));

        return $this->operations->execute($actor, 'PHARMACY_PREPARE', $prescriptionPublicId, $key, compact('prescriptionPublicId', 'expectedFingerprint', 'itemQuantities', 'replacementReason'), PharmacyOperationReceipt::RESULT_PREPARATION, function () use ($prescriptionPublicId, $actor, $expectedFingerprint, $itemQuantities, $replacementReason): PharmacyPreparation {
            $prescription = $this->lockPrescription($prescriptionPublicId, [], ['items', 'verification', 'handovers.items']);
            if (! in_array($prescription->status, [PharmacyPrescription::VERIFIED, PharmacyPrescription::PARTIALLY_HANDED_OVER, PharmacyPrescription::PREPARED], true)) {
                throw new PharmacyDenied('prescription_not_verified', 'Resep belum terverifikasi atau tidak dapat disiapkan.');
            }$this->assertPrescriptionFingerprint($prescription, $expectedFingerprint);
            $encounter = $prescription->encounter()->firstOrFail();
            $this->eligibleEncounter($encounter);
            $this->assertCurrentContext($prescription, $encounter);
            $this->assertOrderedItemMasters($prescription);
            $replaces = null;
            $active = PharmacyPreparation::query()->where('prescription_id', $prescription->id)->where('state', PharmacyPreparation::ACTIVE)->whereDoesntHave('handover')->with(['allocations.lot', 'allocations.item'])->lockForUpdate()->first();
            if ($active) {
                try {
                    $this->assertAllocationsCurrentFefo($active);
                    throw new PharmacyDenied('active_preparation_exists', 'Masih ada penyiapan aktif.');
                } catch (PharmacyDenied $denial) {
                    if ($denial->reason !== 'stale_preparation') {
                        throw $denial;
                    }
                }$reason = $this->optional($replacementReason, 1000);
                if ($reason === null) {
                    throw new PharmacyDenied('replacement_reason_required', 'Alasan penggantian penyiapan yang usang wajib diisi.');
                }$active->state = PharmacyPreparation::SUPERSEDED;
                $active->replacement_reason = $reason;
                $active->save();
                $replaces = $active->id;
            }
            if ($itemQuantities !== null) {
                $known = $prescription->items->pluck('public_id')->all();
                foreach ($itemQuantities as $itemPublicId => $quantity) {
                    if (! in_array((string) $itemPublicId, $known, true) || filter_var($quantity, FILTER_VALIDATE_INT) === false || (int) $quantity < 0) {
                        throw new PharmacyDenied('verification_quantity_invalid', 'Pilihan jumlah penyiapan tidak valid.');
                    }
                }if (! collect($itemQuantities)->contains(fn ($quantity) => (int) $quantity > 0)) {
                    throw new PharmacyDenied('nothing_to_prepare', 'Pilih sedikitnya satu item untuk disiapkan.');
                }
            }
            $allocations = [];
            $stockRoots = [];
            $sequence = 1;
            foreach ($prescription->items as $item) {
                $target = $this->verifiedQuantity($prescription->verification, $item);
                $already = $prescription->handovers->flatMap->items->where('prescription_item_id', $item->id)->sum('quantity');
                $remaining = $target - $already;
                if ($remaining <= 0) {
                    continue;
                }$requested = $itemQuantities === null ? $remaining : (int) ($itemQuantities[$item->public_id] ?? 0);
                if ($requested === 0) {
                    continue;
                }if ($requested < 1 || $requested > $remaining) {
                    throw new PharmacyDenied('verification_quantity_invalid', 'Jumlah penyiapan melebihi sisa terverifikasi.');
                }$needed = $requested;
                $lots = $this->eligibleLots($prescription->depot_id, $item->medicine_id, true);
                foreach ($lots as $lot) {
                    if ($needed <= 0) {
                        break;
                    }$take = min($needed, $lot->available_quantity);
                    if ($take <= 0) {
                        continue;
                    }$fingerprint = $this->fingerprints->lot($lot);
                    $allocations[] = ['item' => $item, 'lot' => $lot, 'quantity' => $take, 'sequence' => $sequence++, 'lot_fingerprint' => $fingerprint];
                    $stockRoots[] = $fingerprint;
                    $needed -= $take;
                }if ($needed > 0) {
                    throw new PharmacyDenied('insufficient_stock', 'Stok FEFO tidak mencukupi.');
                }
            }
            if ($allocations === []) {
                throw new PharmacyDenied('nothing_to_prepare', 'Tidak ada sisa obat untuk disiapkan.');
            }
            $seq = PharmacyPreparation::query()->where('prescription_id', $prescription->id)->count() + 1;
            $content = PharmacyCanonicalJson::digest(array_map(fn ($a) => [$a['item']->public_id, $a['lot']->public_id, $a['quantity'], $a['sequence'], $a['lot_fingerprint']], $allocations));
            $preparation = PharmacyPreparation::query()->create(['prescription_id' => $prescription->id, 'technician_user_id' => $actor->id, 'replaces_preparation_id' => $replaces, 'sequence' => $seq, 'state' => PharmacyPreparation::ACTIVE, 'prescription_fingerprint' => $expectedFingerprint, 'stock_fingerprint' => PharmacyCanonicalJson::digest($stockRoots), 'replacement_reason' => $replaces ? $this->optional($replacementReason, 1000) : null, 'content_digest' => $content, 'prepared_at' => now(), 'created_at' => now()]);
            foreach ($allocations as $a) {
                PharmacyPreparationAllocation::query()->create(['preparation_id' => $preparation->id, 'prescription_item_id' => $a['item']->id, 'stock_lot_id' => $a['lot']->id, 'quantity' => $a['quantity'], 'fefo_sequence' => $a['sequence'], 'lot_fingerprint' => $a['lot_fingerprint'], 'content_digest' => PharmacyCanonicalJson::digest([$preparation->public_id, $a['item']->public_id, $a['lot']->public_id, $a['quantity'], $a['sequence'], $a['lot_fingerprint']]), 'created_at' => now()]);
            }
            $prescription->status = PharmacyPrescription::PREPARED;
            $prescription->version++;
            $prescription->current_content_digest = PharmacyCanonicalJson::digest([$prescription->public_id, $content, PharmacyPrescription::PREPARED, $prescription->version]);
            $prescription->save();
            $this->version($prescription, $actor, ['preparation_public_id' => $preparation->public_id]);

            return $preparation;
        });
    }

    public function handover(string $preparationPublicId, User $actor, string $expectedPreparationFingerprint, ?string $partialReason, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_HANDOVER', $preparationPublicId, fn () => $this->policy->handover($actor));
        $partialReason = $this->operations->validate($actor, 'PHARMACY_HANDOVER', $preparationPublicId, fn () => $this->optional($partialReason, 1000));

        return $this->operations->execute($actor, 'PHARMACY_HANDOVER', $preparationPublicId, $key, compact('preparationPublicId', 'expectedPreparationFingerprint', 'partialReason'), PharmacyOperationReceipt::RESULT_HANDOVER, function () use ($preparationPublicId, $actor, $expectedPreparationFingerprint, $partialReason): PharmacyHandover {
            $candidate = PharmacyPreparation::query()->where('public_id', $preparationPublicId)->with('prescription')->firstOrFail();
            $prescription = $this->lockPrescription($candidate->prescription->public_id, [], ['items', 'verification', 'handovers.items']);
            $preparation = PharmacyPreparation::query()->whereKey($candidate->id)->lockForUpdate()->with(['allocations.lot', 'allocations.item'])->firstOrFail();
            if ($preparation->state !== PharmacyPreparation::ACTIVE) {
                throw new PharmacyDenied('stale_preparation', 'Penyiapan telah digantikan.');
            }
            if ($preparation->handover()->exists()) {
                throw new PharmacyDenied('preparation_already_handed_over', 'Penyiapan sudah diserahkan.');
            }if (! in_array($prescription->status, [PharmacyPrescription::PREPARED], true)) {
                throw new PharmacyDenied('prescription_not_prepared', 'Resep tidak dalam status siap diserahkan.');
            }if (! hash_equals($this->fingerprints->preparation($preparation), $expectedPreparationFingerprint)) {
                throw new PharmacyDenied('evidence_fingerprint_invalid', 'Sidik penyiapan tidak valid.');
            }$this->assertHeadVersion($prescription);
            $basis = PharmacyPrescriptionVersion::query()->where('prescription_id', $prescription->id)->where('version', $prescription->version - 1)->first();
            if (! $basis || ! hash_equals($preparation->prescription_fingerprint, $this->fingerprints->prescriptionAt($prescription, $basis->state, $basis->version, $basis->content_digest))) {
                throw new PharmacyDenied('stale_preparation', 'Resep yang menjadi dasar penyiapan tidak lagi dapat dibuktikan.');
            }$encounter = $prescription->encounter()->firstOrFail();
            $this->eligibleEncounter($encounter);
            $this->assertCurrentContext($prescription, $encounter);
            $this->assertOrderedItemMasters($prescription);
            $this->assertAllocationsCurrentFefo($preparation);
            $sequence = $prescription->handovers()->count() + 1;
            $totalPrepared = $preparation->allocations->sum('quantity');
            $remainingBefore = $prescription->items->sum(fn ($item) => $this->verifiedQuantity($prescription->verification, $item)) - $prescription->handovers->flatMap->items->sum('quantity');
            $partial = $totalPrepared < $remainingBefore;
            if ($partial && $partialReason === null) {
                throw new PharmacyDenied('partial_reason_required', 'Alasan penyerahan sebagian wajib diisi.');
            }
            $handover = PharmacyHandover::query()->create(['prescription_id' => $prescription->id, 'preparation_id' => $preparation->id, 'pharmacist_user_id' => $actor->id, 'sequence' => $sequence, 'state' => $partial ? PharmacyHandover::PARTIAL : PharmacyHandover::FULL, 'partial_reason' => $partialReason, 'preparation_fingerprint' => $expectedPreparationFingerprint, 'content_digest' => PharmacyCanonicalJson::digest([$preparation->public_id, $expectedPreparationFingerprint, $totalPrepared, $partial, $partialReason]), 'handed_over_at' => now(), 'created_at' => now()]);
            foreach ($preparation->allocations as $allocation) {
                $lot = PharmacyStockLot::query()->whereKey($allocation->stock_lot_id)->lockForUpdate()->firstOrFail();
                if ($lot->available_quantity < $allocation->quantity) {
                    throw new PharmacyDenied('insufficient_stock', 'Stok berubah sebelum penyerahan.');
                }$lot->available_quantity -= $allocation->quantity;
                $lot->version++;
                $lot->content_digest = $this->lotDigest($lot);
                $lot->save();
                $handoverItem = PharmacyHandoverItem::query()->create(['handover_id' => $handover->id, 'prescription_item_id' => $allocation->prescription_item_id, 'stock_lot_id' => $lot->id, 'quantity' => $allocation->quantity, 'sale_value_snapshot' => $allocation->item->sale_value_snapshot, 'content_digest' => PharmacyCanonicalJson::digest([$handover->public_id, $allocation->item->public_id, $lot->public_id, $allocation->quantity, $allocation->item->sale_value_snapshot]), 'created_at' => now()]);
                $this->movement($lot, $actor, $handoverItem, PharmacyStockMovement::HANDOVER, -$allocation->quantity, 0, 'PATIENT_HANDOVER', 'HANDOVER_ITEM', $handoverItem->public_id);
                $this->financial($prescription, $allocation->item, $actor, PharmacyFinancialSourceEvent::CHARGE, $allocation->quantity, $allocation->quantity * $allocation->item->sale_value_snapshot, 'HANDOVER_ITEM', $handoverItem->public_id);
            }
            $prescription->status = $partial ? PharmacyPrescription::PARTIALLY_HANDED_OVER : PharmacyPrescription::HANDED_OVER;
            $prescription->version++;
            $prescription->current_content_digest = PharmacyCanonicalJson::digest([$prescription->public_id, $handover->content_digest, $prescription->status, $prescription->version]);
            $prescription->save();
            $this->version($prescription, $actor, ['handover_public_id' => $handover->public_id]);

            return $handover;
        });
    }

    public function closeUnfilled(string $prescriptionPublicId, User $actor, string $expectedFingerprint, string $reasonCode, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_UNFILLED_CLOSE', $prescriptionPublicId, fn () => $this->policy->handover($actor));
        $reasonCode = $this->operations->validate($actor, 'PHARMACY_UNFILLED_CLOSE', $prescriptionPublicId, fn () => $this->reason($reasonCode));

        return $this->operations->execute($actor, 'PHARMACY_UNFILLED_CLOSE', $prescriptionPublicId, $key, compact('prescriptionPublicId', 'expectedFingerprint', 'reasonCode'), PharmacyOperationReceipt::RESULT_PRESCRIPTION, function () use ($prescriptionPublicId, $actor, $expectedFingerprint, $reasonCode): PharmacyPrescription {
            $p = $this->lockPrescription($prescriptionPublicId);
            if ($p->status !== PharmacyPrescription::PARTIALLY_HANDED_OVER) {
                throw new PharmacyDenied('unfilled_close_not_permitted', 'Tidak ada sisa terbuka yang dapat ditutup.');
            }$this->assertPrescriptionFingerprint($p, $expectedFingerprint);
            $p->status = PharmacyPrescription::UNFILLED_CLOSED;
            $p->version++;
            $p->current_content_digest = PharmacyCanonicalJson::digest([$p->public_id, $reasonCode, $p->status, $p->version]);
            $p->save();
            $this->version($p, $actor, ['reason_code' => $reasonCode]);

            return $p;
        });
    }

    /** @param array<int,array{handover_item_public_id:string,condition:string,quantity:int}> $items */
    public function recordReturn(string $handoverPublicId, string $expectedPrescriptionPublicId, User $actor, string $expectedHandoverFingerprint, string $reasonCode, ?string $note, array $items, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_RETURN', $handoverPublicId, fn () => $this->policy->return($actor));
        [$reasonCode,$note] = $this->operations->validate($actor, 'PHARMACY_RETURN', $handoverPublicId, fn (): array => [$this->reason($reasonCode), $this->optional($note, 1000)]);

        return $this->operations->execute($actor, 'PHARMACY_RETURN', $handoverPublicId, $key, compact('handoverPublicId', 'expectedPrescriptionPublicId', 'expectedHandoverFingerprint', 'reasonCode', 'note', 'items'), PharmacyOperationReceipt::RESULT_RETURN, function () use ($handoverPublicId, $expectedPrescriptionPublicId, $actor, $expectedHandoverFingerprint, $reasonCode, $note, $items): PharmacyReturn {
            $candidate = PharmacyHandover::query()->where('public_id', $handoverPublicId)->with('prescription')->firstOrFail();
            $this->lockPrescription($candidate->prescription->public_id);
            $handover = PharmacyHandover::query()->whereKey($candidate->id)->with(['items.returnItems', 'items.prescriptionItem', 'prescription.encounter'])->firstOrFail();
            if (! hash_equals($handover->prescription->public_id, $expectedPrescriptionPublicId)) {
                throw new PharmacyDenied('prescription_handover_mismatch', 'Penyerahan tidak berasal dari resep pada formulir.');
            }if (! hash_equals($this->fingerprints->handover($handover), $expectedHandoverFingerprint)) {
                throw new PharmacyDenied('evidence_fingerprint_invalid', 'Sidik penyerahan tidak valid.');
            }if (! in_array($handover->prescription->encounter->status, [Encounter::STATUS_IN_EXAMINATION, Encounter::STATUS_READY_FOR_RM], true)) {
                throw new PharmacyDenied($handover->prescription->encounter->status === Encounter::STATUS_CANCELLED ? 'encounter_cancelled' : 'encounter_closed', 'Kunjungan tidak menerima retur.');
            }
            $normalized = $this->returnItems($handover, $items);
            $return = PharmacyReturn::query()->create(['handover_id' => $handover->id, 'pharmacist_user_id' => $actor->id, 'reason_code' => $reasonCode, 'note' => $note, 'handover_fingerprint' => $expectedHandoverFingerprint, 'content_digest' => PharmacyCanonicalJson::digest([$handover->public_id, $reasonCode, $note, $normalized]), 'returned_at' => now(), 'created_at' => now()]);
            foreach ($normalized as $row) {
                $handoverItem = $row['item'];
                $lot = PharmacyStockLot::query()->whereKey($handoverItem->stock_lot_id)->lockForUpdate()->firstOrFail();
                $ad = $row['condition'] === PharmacyReturnItem::RETURN_TO_STOCK ? $row['quantity'] : 0;
                $qd = $row['condition'] === PharmacyReturnItem::QUARANTINE ? $row['quantity'] : 0;
                $lot->available_quantity += $ad;
                $lot->quarantined_quantity += $qd;
                $lot->version++;
                $lot->content_digest = $this->lotDigest($lot);
                $lot->save();
                $returnItem = PharmacyReturnItem::query()->create(['return_id' => $return->id, 'handover_item_id' => $handoverItem->id, 'condition' => $row['condition'], 'quantity' => $row['quantity'], 'content_digest' => PharmacyCanonicalJson::digest([$return->public_id, $handoverItem->public_id, $row['condition'], $row['quantity']]), 'created_at' => now()]);
                $this->movement($lot, $actor, $handoverItem, PharmacyStockMovement::RETURN, $ad, $qd, $row['condition'], 'RETURN_ITEM', $returnItem->public_id);
                $this->financial($handover->prescription, $handoverItem->prescriptionItem, $actor, PharmacyFinancialSourceEvent::REVERSAL, $row['quantity'], -($row['quantity'] * $handoverItem->sale_value_snapshot), 'RETURN_ITEM', $returnItem->public_id);
            }

            return $return;
        });
    }

    private function terminalPhysician(string $publicId, User $actor, string $fingerprint, string $state, string $reason, ?string $note, string $key, string $operation): PharmacyMutationResult
    {
        return $this->operations->execute($actor, $operation, $publicId, $key, compact('publicId', 'fingerprint', 'state', 'reason', 'note'), PharmacyOperationReceipt::RESULT_PRESCRIPTION, function () use ($publicId, $actor, $fingerprint, $state, $reason, $note): PharmacyPrescription {
            $p = $this->lockPrescription($publicId);
            if ($p->ordering_physician_user_id !== $actor->id || ! in_array($p->status, [PharmacyPrescription::DRAFT, PharmacyPrescription::ORDERED], true) || $p->verification()->exists()) {
                throw new PharmacyDenied('cancellation_not_permitted', 'Resep tidak dapat dibatalkan.');
            }$this->assertPrescriptionFingerprint($p, $fingerprint);
            $p->status = $state;
            $p->version++;
            $p->current_content_digest = PharmacyCanonicalJson::digest([$p->public_id, $state, $reason, $note, $p->version]);
            $p->save();
            $this->version($p, $actor, ['reason_code' => $reason, 'note' => $note]);

            return $p;
        });
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @param  list<array<string, mixed>>  $decisions
     */
    private function verification(string $publicId, User $actor, string $fingerprint, string $allergy, array $checklist, array $decisions, string $decision, ?string $reason, ?string $note, string $key): PharmacyMutationResult
    {
        [$allergy,$checklist] = $this->operations->validate($actor, $decision === PharmacyVerification::VERIFIED ? 'PHARMACY_VERIFY' : 'PHARMACY_REFUSE', $publicId, fn (): array => [mb_strtoupper(trim($allergy)), $this->checklist($checklist)]);

        return $this->operations->execute($actor, $decision === PharmacyVerification::VERIFIED ? 'PHARMACY_VERIFY' : 'PHARMACY_REFUSE', $publicId, $key, compact('publicId', 'fingerprint', 'allergy', 'checklist', 'decisions', 'decision', 'reason', 'note'), PharmacyOperationReceipt::RESULT_VERIFICATION, function () use ($publicId, $actor, $fingerprint, $allergy, $checklist, $decisions, $decision, $reason, $note): PharmacyVerification {
            $p = $this->lockPrescription($publicId, [], ['items']);
            if ($p->status !== PharmacyPrescription::ORDERED || $p->verification()->exists()) {
                throw new PharmacyDenied('prescription_not_ordered', 'Resep tidak dapat diverifikasi.');
            }$this->assertPrescriptionFingerprint($p, $fingerprint);
            $encounter = $p->encounter()->firstOrFail();
            $this->eligibleEncounter($encounter);
            $this->assertCurrentContext($p, $encounter);
            $this->assertOrderedItemMasters($p);
            if (! in_array($allergy, ['REVIEWED_NO_CONFLICT', 'REVIEWED_WITH_NOTE', 'UNKNOWN_BLOCKED'], true)) {
                throw new PharmacyDenied('manual_allergy_review_required', 'Status tinjauan alergi wajib.');
            }if ($decision === PharmacyVerification::VERIFIED && ($allergy === 'UNKNOWN_BLOCKED' || in_array(false, $checklist, true))) {
                throw new PharmacyDenied('verification_check_failed', 'Tinjauan manual belum memenuhi syarat.');
            }$itemDecisions = $decision === PharmacyVerification::VERIFIED ? $this->itemDecisions($p, $decisions) : [];
            $content = PharmacyCanonicalJson::digest([$p->public_id, $decision, $allergy, $checklist, $itemDecisions, $reason, $note, $fingerprint]);
            $verification = PharmacyVerification::query()->create(['prescription_id' => $p->id, 'pharmacist_user_id' => $actor->id, 'decision' => $decision, 'manual_allergy_review' => $allergy, 'checklist' => $checklist, 'item_decisions' => $itemDecisions, 'reason_code' => $reason, 'note' => $note, 'prescription_fingerprint' => $fingerprint, 'content_digest' => $content, 'verified_at' => now(), 'created_at' => now()]);
            $p->status = $decision === PharmacyVerification::VERIFIED ? PharmacyPrescription::VERIFIED : PharmacyPrescription::REFUSED;
            $p->version++;
            $p->current_content_digest = PharmacyCanonicalJson::digest([$p->public_id, $content, $p->status, $p->version]);
            $p->save();
            $this->version($p, $actor, ['verification_public_id' => $verification->public_id]);

            return $verification;
        });
    }

    /**
     * @param  list<mixed>  $items
     * @return array{depot_public_id:?string,clinical_note:?string,items:list<mixed>}
     */
    private function normalizeDraft(?string $depot, array $items, ?string $note): array
    {
        if (count($items) < 1 || count($items) > 20) {
            throw new PharmacyDenied('validation_failed', 'Jumlah item resep tidak valid.');
        }

        return ['depot_public_id' => $depot, 'clinical_note' => $this->optional($note, 2000), 'items' => $items];
    }

    /**
     * @param  array{depot_public_id:?string,clinical_note:?string,items:list<mixed>}  $content
     * @return array{items:list<array<string, mixed>>,clinical_note:?string}
     */
    private function materializeDraft(array $content): array
    {
        $out = [];
        $seen = [];
        foreach ($content['items'] as $row) {
            if (! is_array($row)) {
                throw new PharmacyDenied('validation_failed', 'Item resep tidak valid.');
            }$medicine = PharmacyMedicine::query()->where('public_id', (string) ($row['medicine_public_id'] ?? ''))->where('state', PharmacyMedicine::ACTIVE)->firstOrFail();
            if (isset($seen[$medicine->id])) {
                throw new PharmacyDenied('validation_failed', 'Obat ganda harus digabungkan.');
            }$seen[$medicine->id] = true;
            $version = PharmacyMedicineVersion::query()->where('medicine_id', $medicine->id)->where('version', $medicine->version)->firstOrFail();
            $route = mb_strtoupper(trim((string) ($row['route'] ?? '')));
            $quantity = (int) ($row['requested_quantity'] ?? 0);
            $fields = [];
            foreach (['dose_text', 'frequency_text', 'duration_text', 'instruction'] as $field) {
                $fields[$field] = trim((string) ($row[$field] ?? ''));
                if ($fields[$field] === '' || mb_strlen($fields[$field]) > 500) {
                    throw new PharmacyDenied('validation_failed', 'Instruksi resep tidak valid.');
                }
            }if ($quantity < 1 || $quantity > 100000 || ! in_array($route, $medicine->route_choices, true)) {
                throw new PharmacyDenied('validation_failed', 'Jumlah atau rute resep tidak valid.');
            }$out[] = ['medicine_id' => $medicine->id, 'medicine_public_id' => $medicine->public_id, 'medicine_version' => $medicine->version, 'medicine_version_public_id' => $version->public_id, 'medicine_content_digest' => $version->content_digest, 'medicine_code' => $medicine->medicine_code, 'medicine_name' => $medicine->generic_name, 'strength_text' => $medicine->strength_text, 'dosage_form' => $medicine->dosage_form, 'base_unit' => $medicine->base_unit, 'sale_value_snapshot' => $medicine->teaching_sale_value, 'route' => $route, 'requested_quantity' => $quantity, ...$fields];
        }

        return ['items' => $out, 'clinical_note' => $content['clinical_note']];
    }

    private function ownDraft(PharmacyPrescription $p, User $actor, int $version): void
    {
        if ($p->ordering_physician_user_id !== $actor->id || $p->status !== PharmacyPrescription::DRAFT) {
            throw new PharmacyDenied('draft_not_editable', 'Draft resep tidak dapat diubah.');
        }if ($p->version !== $version) {
            throw new PharmacyDenied('stale_version', 'Versi resep sudah berubah.');
        }
    }

    private function eligibleEncounter(Encounter $e): void
    {
        if (! in_array($e->care_setting, Encounter::CARE_SETTINGS, true) || $e->status !== Encounter::STATUS_IN_EXAMINATION || $e->isCancelled()) {
            throw new PharmacyDenied(in_array($e->status, Encounter::TERMINAL_STATUSES, true) ? 'encounter_closed' : 'encounter_not_eligible', 'Kunjungan tidak memenuhi syarat farmasi.');
        }
    }

    private function eligibleDepot(PharmacyDepot $d, Encounter $e): void
    {
        if ($d->state !== PharmacyDepot::ACTIVE || ! in_array($e->care_setting, $d->eligible_care_settings, true)) {
            throw new PharmacyDenied('depot_not_eligible', 'Depo tidak sesuai layanan.');
        }
    }

    private function assertCurrentContext(PharmacyPrescription $prescription, Encounter $encounter): void
    {
        $depot = $prescription->depot()->firstOrFail();
        if ($depot->state !== PharmacyDepot::ACTIVE
            || $depot->version !== $prescription->depot_version
            || ! in_array($encounter->care_setting, $depot->eligible_care_settings, true)) {
            throw new PharmacyDenied('pharmacy_master_changed', 'Depo resep berubah; buat resep pengganti yang terikat pada master terkini.');
        }
        [, $fingerprint] = $this->location($encounter);
        if (! is_string($prescription->location_fingerprint) || ! hash_equals($prescription->location_fingerprint, $fingerprint)) {
            throw new PharmacyDenied('pharmacy_location_changed', 'Lokasi pasien berubah; resep harus diganti sebelum diproses.');
        }
    }

    /** @param list<array<string,mixed>> $items */
    private function assertSnapshotMasters(array $items): void
    {
        $heads = PharmacyMedicine::query()->whereIn('id', array_column($items, 'medicine_id'))->get()->keyBy('id');
        foreach ($items as $item) {
            $head = $heads->get($item['medicine_id']);
            if (! $head instanceof PharmacyMedicine
                || $head->state !== PharmacyMedicine::ACTIVE
                || $head->version !== (int) $item['medicine_version']
                || ! hash_equals((string) $head->current_content_digest, (string) $item['medicine_content_digest'])) {
                throw new PharmacyDenied('pharmacy_master_changed', 'Master obat berubah; muat ulang resep sebelum melanjutkan.');
            }
        }
    }

    private function assertOrderedItemMasters(PharmacyPrescription $prescription): void
    {
        $items = $prescription->relationLoaded('items') ? $prescription->items : $prescription->items()->get();
        $heads = PharmacyMedicine::query()->whereIn('id', $items->pluck('medicine_id')->all())->get()->keyBy('id');
        foreach ($items as $item) {
            $head = $heads->get($item->medicine_id);
            if (! $head instanceof PharmacyMedicine
                || $head->state !== PharmacyMedicine::ACTIVE
                || $head->version !== $item->medicine_version
                || ! hash_equals((string) $head->current_content_digest, (string) $item->medicine_content_digest)) {
                throw new PharmacyDenied('pharmacy_master_changed', 'Master obat berubah; resep pengganti diperlukan.');
            }
        }
    }

    /** @return array{string, string} */
    private function location(Encounter $e): array
    {
        $label = $e->care_setting === Encounter::CARE_SETTING_INPATIENT ? trim(implode(' · ', array_filter([$e->ward_name, $e->bed_code]))) : ($e->clinic_name ?: $e->care_setting);
        $event = $e->care_setting === Encounter::CARE_SETTING_INPATIENT ? InpatientLocationEvent::query()->where('encounter_id', $e->id)->orderByDesc('sequence')->first() : null;

        return [$label ?: $e->care_setting, $event ? PharmacyCanonicalJson::digest([$event->getAttribute('public_id'), $event->getAttribute('sequence'), $event->getAttribute('payload_digest')]) : PharmacyCanonicalJson::digest([$e->public_id, $e->care_setting, $label])];
    }

    /** @param array<string, mixed> $snapshot */
    private function version(PharmacyPrescription $p, User $actor, array $snapshot): void
    {
        PharmacyPrescriptionVersion::query()->create(['prescription_id' => $p->id, 'actor_user_id' => $actor->id, 'version' => $p->version, 'state' => $p->status, 'content_snapshot' => $snapshot, 'content_digest' => $p->current_content_digest, 'created_at' => now()]);
    }

    private function assertPrescriptionFingerprint(PharmacyPrescription $p, string $expected): void
    {
        if (! hash_equals($this->fingerprints->prescription($p), $expected)) {
            throw new PharmacyDenied('evidence_fingerprint_invalid', 'Sidik resep tidak valid.');
        }
    }

    private function assertHeadVersion(PharmacyPrescription $p): void
    {
        $head = PharmacyPrescriptionVersion::query()->where('prescription_id', $p->id)->where('version', $p->version)->first();
        if (! $head || ! hash_equals((string) $p->current_content_digest, (string) $head->content_digest)) {
            throw new PharmacyDenied('evidence_fingerprint_invalid', 'Rantai versi resep tidak valid.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{identity_confirmed:bool,context_confirmed:bool,medicine_readable:bool,instruction_readable:bool}
     */
    private function checklist(array $input): array
    {
        $out = [];
        foreach (self::CHECKLIST as $key) {
            $out[$key] = filter_var($input[$key] ?? false, FILTER_VALIDATE_BOOL);
        }if (array_diff(array_keys($input), self::CHECKLIST)) {
            throw new PharmacyDenied('validation_failed', 'Checklist verifikasi tidak valid.');
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{item_public_id:string,verified_quantity:int,reason_code:?string}>
     */
    private function itemDecisions(PharmacyPrescription $p, array $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $id = (string) ($row['item_public_id'] ?? '');
            if (isset($mapped[$id])) {
                throw new PharmacyDenied('validation_failed', 'Keputusan item ganda.');
            }$item = $p->items->firstWhere('public_id', $id);
            if (! $item) {
                throw new PharmacyDenied('validation_failed', 'Item verifikasi tidak dikenal.');
            }$qty = (int) ($row['verified_quantity'] ?? 0);
            $reason = $this->optional($row['reason_code'] ?? null, 64);
            if ($qty < 1 || $qty > $item->requested_quantity || ($qty < $item->requested_quantity && $reason === null)) {
                throw new PharmacyDenied('verification_quantity_invalid', 'Jumlah verifikasi tidak valid.');
            }$mapped[$id] = ['item_public_id' => $id, 'verified_quantity' => $qty, 'reason_code' => $reason];
        }if (count($mapped) !== $p->items->count()) {
            throw new PharmacyDenied('verification_items_incomplete', 'Semua item harus diverifikasi.');
        }ksort($mapped);

        return array_values($mapped);
    }

    private function verifiedQuantity(PharmacyVerification $v, PharmacyPrescriptionItem $item): int
    {
        $row = collect($v->item_decisions)->firstWhere('item_public_id', $item->public_id);

        return (int) ($row['verified_quantity'] ?? 0);
    }

    /**
     * @param  list<int>  $additionalMedicineIds
     * @param  list<string>  $relations
     */
    private function lockPrescription(string $publicId, array $additionalMedicineIds = [], array $relations = []): PharmacyPrescription
    {
        $candidate = PharmacyPrescription::query()->where('public_id', $publicId)->with(['encounter', 'depot', 'items'])->firstOrFail();
        $medicineIds = $candidate->items->pluck('medicine_id')->all();
        if ($medicineIds === []) {
            $latest = PharmacyPrescriptionVersion::query()->where('prescription_id', $candidate->id)->orderByDesc('version')->first();
            $medicineIds = array_column((array) ($latest?->content_snapshot['items'] ?? []), 'medicine_id');
        }$medicineIds = array_values(array_unique([...$medicineIds, ...$additionalMedicineIds]));
        [, $locked] = $this->locks->lockContext($candidate->encounter, $candidate->depot, $medicineIds, $candidate);

        return $locked->load(array_values(array_unique(['encounter', 'depot', ...$relations])));
    }

    /** @return Collection<int, PharmacyStockLot> */
    private function eligibleLots(int $depotId, int $medicineId, bool $lock = false): Collection
    {
        $q = PharmacyStockLot::query()->where('depot_id', $depotId)->where('medicine_id', $medicineId)->where('state', PharmacyStockLot::ACTIVE)->where('available_quantity', '>', 0)->where(fn ($x) => $x->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()))->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_date')->orderBy('received_at')->orderBy('lot_code')->orderBy('id');
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->get();
    }

    private function assertAllocationsCurrentFefo(PharmacyPreparation $prep): void
    {
        foreach ($prep->allocations->groupBy('prescription_item_id') as $allocs) {
            $first = $allocs->first();
            $eligible = $this->eligibleLots($first->lot->depot_id, $first->item->medicine_id, true);
            $expected = [];
            $needed = $allocs->sum('quantity');
            foreach ($eligible as $lot) {
                if ($needed <= 0) {
                    break;
                }$take = min($needed, $lot->available_quantity);
                if ($take > 0) {
                    $expected[] = [$lot->id, $take, $this->fingerprints->lot($lot)];
                    $needed -= $take;
                }
            }$actual = $allocs->sortBy('fefo_sequence')->map(fn ($a) => [$a->stock_lot_id, $a->quantity, $a->lot_fingerprint])->values()->all();
            if ($needed > 0 || $expected !== $actual) {
                throw new PharmacyDenied('stale_preparation', 'Alokasi FEFO telah berubah.');
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{item:PharmacyHandoverItem,condition:string,quantity:int}>
     */
    private function returnItems(PharmacyHandover $h, array $rows): array
    {
        if ($rows === []) {
            throw new PharmacyDenied('validation_failed', 'Item retur wajib diisi.');
        }$out = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = (string) ($row['handover_item_public_id'] ?? '');
            if (isset($seen[$id])) {
                throw new PharmacyDenied('validation_failed', 'Item retur ganda.');
            }$seen[$id] = true;
            $item = $h->items->firstWhere('public_id', $id);
            if (! $item) {
                throw new PharmacyDenied('validation_failed', 'Item penyerahan tidak dikenal.');
            }$condition = mb_strtoupper(trim((string) ($row['condition'] ?? '')));
            $qty = (int) ($row['quantity'] ?? 0);
            $returned = $item->returnItems->sum('quantity');
            if (! in_array($condition, [PharmacyReturnItem::RETURN_TO_STOCK, PharmacyReturnItem::QUARANTINE, PharmacyReturnItem::DESTROYED_OR_NOT_RETURNABLE], true) || $qty < 1 || $returned + $qty > $item->quantity) {
                throw new PharmacyDenied('return_quantity_invalid', 'Jumlah atau kondisi retur tidak valid.');
            }$out[] = ['item' => $item, 'condition' => $condition, 'quantity' => $qty];
        }

        return $out;
    }

    private function movement(PharmacyStockLot $lot, User $actor, PharmacyHandoverItem $hi, string $type, int $ad, int $qd, string $reason, string $sourceType, string $sourceId): void
    {
        PharmacyStockMovement::query()->create(['stock_lot_id' => $lot->id, 'actor_user_id' => $actor->id, 'handover_item_id' => $hi->id, 'movement_type' => $type, 'available_delta' => $ad, 'quarantined_delta' => $qd, 'available_balance_after' => $lot->available_quantity, 'quarantined_balance_after' => $lot->quarantined_quantity, 'reason_code' => $reason, 'source_type' => $sourceType, 'source_public_id' => $sourceId, 'content_digest' => PharmacyCanonicalJson::digest([$lot->public_id, $actor->id, $hi->public_id, $type, $ad, $qd, $lot->available_quantity, $lot->quarantined_quantity, $reason, $sourceType, $sourceId]), 'occurred_at' => now(), 'created_at' => now()]);
    }

    private function financial(PharmacyPrescription $p, PharmacyPrescriptionItem $i, User $actor, string $type, int $qty, int $amount, string $sourceType, string $sourceId): void
    {
        PharmacyFinancialSourceEvent::query()->create(['prescription_id' => $p->id, 'prescription_item_id' => $i->id, 'actor_user_id' => $actor->id, 'event_type' => $type, 'quantity' => $qty, 'amount' => $amount, 'source_type' => $sourceType, 'source_public_id' => $sourceId, 'content_digest' => PharmacyCanonicalJson::digest([$p->public_id, $i->public_id, $actor->id, $type, $qty, $amount, $sourceType, $sourceId]), 'occurred_at' => now(), 'created_at' => now()]);
    }

    private function lotDigest(PharmacyStockLot $lot): string
    {
        return PharmacyCanonicalJson::digest([$lot->medicine_id, $lot->depot_id, $lot->medicine_version, $lot->depot_version, $lot->lot_code, $lot->received_at, $lot->expiry_date, $lot->available_quantity, $lot->quarantined_quantity, $lot->state, $lot->version]);
    }

    private function reason(string $reason): string
    {
        $reason = preg_replace('/\s+/u', '_', mb_strtoupper(trim($reason))) ?? '';
        if (! preg_match('/\A[A-Z0-9][A-Z0-9._-]{2,63}\z/', $reason)) {
            throw new PharmacyDenied('validation_failed', 'Alasan tidak valid.');
        }

        return $reason;
    }

    private function optional(mixed $value, int $max): ?string
    {
        $value = $value === null ? null : trim((string) $value);
        if ($value !== null && mb_strlen($value) > $max) {
            throw new PharmacyDenied('validation_failed', 'Teks terlalu panjang.');
        }

        return $value === '' ? null : $value;
    }
}
