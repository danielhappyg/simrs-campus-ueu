<?php

namespace App\Support\Pharmacy;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientLocationEvent;
use App\Models\InpatientPatientClaimMutex;
use App\Models\PharmacyDepot;
use App\Models\PharmacyInventoryMutex;
use App\Models\PharmacyMedicine;
use App\Models\PharmacyPrescription;

final class PharmacyLockCoordinator
{
    /**
     * @param  list<int>  $medicineIds
     * @return array{Encounter, PharmacyPrescription|null, PharmacyDepot}
     */
    public function lockContext(Encounter $candidateEncounter, PharmacyDepot $depot, array $medicineIds, ?PharmacyPrescription $candidatePrescription = null): array
    {
        if ($candidateEncounter->care_setting === Encounter::CARE_SETTING_INPATIENT) {
            InpatientPatientClaimMutex::query()->firstOrCreate(['patient_id' => $candidateEncounter->patient_id]);
            InpatientPatientClaimMutex::query()->where('patient_id', $candidateEncounter->patient_id)->lockForUpdate()->firstOrFail();
        }

        $medicines = PharmacyMedicine::query()->whereIn('id', array_values(array_unique($medicineIds)))
            ->orderBy('medicine_code')->get(['id', 'medicine_code']);
        foreach ($medicines as $medicine) {
            PharmacyInventoryMutex::query()->firstOrCreate(['depot_id' => $depot->id, 'medicine_id' => $medicine->id], ['lock_version' => 0]);
        }
        foreach ($medicines as $medicine) {
            PharmacyInventoryMutex::query()->where('depot_id', $depot->id)->where('medicine_id', $medicine->id)->lockForUpdate()->firstOrFail();
        }

        $lockedDepot = PharmacyDepot::query()->whereKey($depot->id)->lockForUpdate()->firstOrFail();
        if ($medicines->isNotEmpty()) {
            PharmacyMedicine::query()->whereIn('id', $medicines->pluck('id')->all())
                ->orderBy('medicine_code')->lockForUpdate()->get();
        }

        $encounter = Encounter::query()->whereKey($candidateEncounter->id)->lockForUpdate()->firstOrFail();
        if ($encounter->care_setting === Encounter::CARE_SETTING_INPATIENT) {
            InpatientLocationEvent::query()->where('encounter_id', $encounter->id)->orderByDesc('sequence')->lockForUpdate()->first();
            if ($encounter->inpatient_bed_id !== null) {
                InpatientBed::query()->whereKey($encounter->inpatient_bed_id)->lockForUpdate()->firstOrFail();
            }
        }
        $prescription = $candidatePrescription === null
            ? null
            : PharmacyPrescription::query()->whereKey($candidatePrescription->id)->lockForUpdate()->firstOrFail();

        return [$encounter, $prescription, $lockedDepot];
    }
}
