<?php

namespace App\Support\Pharmacy;

use App\Models\PharmacyHandover;
use App\Models\PharmacyPreparation;
use App\Models\PharmacyPrescription;
use App\Models\PharmacyReturn;
use App\Models\PharmacyStockLot;

final class PharmacyEvidenceFingerprint
{
    public function record(mixed ...$parts): string
    {
        return PharmacyCanonicalJson::digest($parts);
    }

    public function prescription(PharmacyPrescription $prescription): string
    {
        return $this->prescriptionAt(
            $prescription,
            $prescription->status,
            $prescription->version,
            $prescription->current_content_digest,
        );
    }

    public function prescriptionAt(PharmacyPrescription $prescription, string $state, int $version, string $contentDigest): string
    {
        return $this->record($prescription->public_id, $prescription->encounter_id, $prescription->patient_id, $prescription->depot_id, $state, $version, $contentDigest, $prescription->location_fingerprint);
    }

    public function lot(PharmacyStockLot $lot): string
    {
        return $this->record($lot->public_id, $lot->medicine_id, $lot->depot_id, $lot->lot_code, $lot->expiry_date, $lot->available_quantity, $lot->quarantined_quantity, $lot->state, $lot->version, $lot->content_digest);
    }

    public function preparation(PharmacyPreparation $preparation): string
    {
        return $this->record($preparation->public_id, $preparation->prescription_id, $preparation->sequence, $preparation->state, $preparation->prescription_fingerprint, $preparation->stock_fingerprint, $preparation->content_digest);
    }

    public function handover(PharmacyHandover $handover): string
    {
        return $this->record($handover->public_id, $handover->prescription_id, $handover->preparation_id, $handover->sequence, $handover->state, $handover->preparation_fingerprint, $handover->content_digest);
    }

    public function return(PharmacyReturn $return): string
    {
        return $this->record($return->public_id, $return->handover_id, $return->reason_code, $return->handover_fingerprint, $return->content_digest);
    }
}
