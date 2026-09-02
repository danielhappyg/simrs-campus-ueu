<?php

namespace App\Support\Pharmacy;

use App\Models\Encounter;
use App\Models\PharmacyDepot;
use App\Models\PharmacyInventoryMutex;
use App\Models\PharmacyMedicine;
use App\Models\PharmacyPreparation;
use App\Models\PharmacyPrescription;
use App\Models\PharmacyPrescriptionItem;
use Illuminate\Support\Facades\DB;

final class PharmacyEncounterLifecycleGate
{
    public function lockInventoryForEncounter(int $encounterId): void
    {
        $pairs = DB::table((new PharmacyPrescription)->getTable().' as p')
            ->join((new PharmacyPrescriptionItem)->getTable().' as i', 'i.prescription_id', '=', 'p.id')
            ->join((new PharmacyDepot)->getTable().' as d', 'd.id', '=', 'p.depot_id')
            ->join((new PharmacyMedicine)->getTable().' as m', 'm.id', '=', 'i.medicine_id')
            ->where('p.encounter_id', $encounterId)
            ->select(['p.depot_id', 'i.medicine_id', 'd.depot_code', 'm.medicine_code'])
            ->distinct()
            ->orderBy('d.depot_code')->orderBy('m.medicine_code')->get();

        PharmacyMutationScope::run(function () use ($pairs): void {
            foreach ($pairs as $pair) {
                PharmacyInventoryMutex::query()->firstOrCreate(
                    ['depot_id' => $pair->depot_id, 'medicine_id' => $pair->medicine_id],
                    ['lock_version' => 0],
                );
            }
        });
        PharmacyMutationScope::run(function () use ($pairs): void {
            foreach ($pairs as $pair) {
                PharmacyInventoryMutex::query()
                    ->where('depot_id', $pair->depot_id)
                    ->where('medicine_id', $pair->medicine_id)
                    ->lockForUpdate()->firstOrFail();
            }
        });
    }

    /** @return array{has_any_evidence:bool,active_prescription_public_ids:list<string>,active_preparation_public_ids:list<string>} */
    public function inspect(Encounter $encounter): array
    {
        // Mutation gates must remain authoritative even when a caller supplied a
        // filtered or only partially eager-loaded relationship collection.
        $prescriptions = PharmacyPrescription::query()->where('encounter_id', $encounter->id);
        $activePrescriptionIds = (clone $prescriptions)->whereIn('status', PharmacyPrescription::ACTIVE_STATES)->orderBy('public_id')->pluck('public_id')->all();
        $activePreparationIds = PharmacyPreparation::query()->whereHas('prescription', fn ($q) => $q->where('encounter_id', $encounter->id))->where('state', PharmacyPreparation::ACTIVE)->whereDoesntHave('handover')->orderBy('public_id')->pluck('public_id')->all();
        $hasAny = (clone $prescriptions)->exists();

        return [
            'has_any_evidence' => $hasAny,
            'active_prescription_public_ids' => array_values($activePrescriptionIds),
            'active_preparation_public_ids' => array_values($activePreparationIds),
        ];
    }

    /**
     * Read-model-only fast path. Mutation services must use inspect().
     * The caller must eager-load the unfiltered pharmacyPrescriptions relation
     * with preparations.handover; otherwise this method falls back to the
     * authoritative query path.
     *
     * @return array{has_any_evidence:bool,active_prescription_public_ids:list<string>,active_preparation_public_ids:list<string>}
     */
    public function inspectReadModel(Encounter $encounter): array
    {
        if (! $encounter->relationLoaded('pharmacyPrescriptions')
            || $encounter->pharmacyPrescriptions->contains(fn (PharmacyPrescription $prescription): bool => ! $prescription->relationLoaded('preparations')
                || $prescription->preparations->contains(fn (PharmacyPreparation $preparation): bool => ! $preparation->relationLoaded('handover')))) {
            return $this->inspect($encounter);
        }
        $prescriptions = $encounter->pharmacyPrescriptions;

        return [
            'has_any_evidence' => $prescriptions->isNotEmpty(),
            'active_prescription_public_ids' => array_values($prescriptions->whereIn('status', PharmacyPrescription::ACTIVE_STATES)->pluck('public_id')->map(static fn (mixed $id): string => (string) $id)->sort()->all()),
            'active_preparation_public_ids' => array_values($prescriptions->flatMap(fn (PharmacyPrescription $prescription) => $prescription->preparations
                ->filter(fn (PharmacyPreparation $preparation): bool => $preparation->state === PharmacyPreparation::ACTIVE && $preparation->handover === null))
                ->pluck('public_id')->map(static fn (mixed $id): string => (string) $id)->sort()->all()),
        ];
    }
}
