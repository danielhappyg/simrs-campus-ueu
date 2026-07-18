<?php

namespace App\Modules\Clinical\Enums;

enum OutpatientEarlyDepartureOutcome: string
{
    case PatientRequestedDeparture = 'PATIENT_REQUESTED_DEPARTURE';

    public function label(): string
    {
        return match ($this) {
            self::PatientRequestedDeparture => 'Pulang atas permintaan sendiri',
        };
    }

    public function interoperabilityCode(): string
    {
        return match ($this) {
            self::PatientRequestedDeparture => 'aadvice',
        };
    }
}
