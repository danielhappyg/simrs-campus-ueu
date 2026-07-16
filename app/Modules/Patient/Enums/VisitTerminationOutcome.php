<?php

namespace App\Modules\Patient\Enums;

use App\Modules\Encounter\Enums\EncounterStatus;

enum VisitTerminationOutcome: string
{
    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';

    public function appointmentStatus(): AppointmentStatus
    {
        return match ($this) {
            self::Cancelled => AppointmentStatus::Cancelled,
            self::NoShow => AppointmentStatus::NoShow,
        };
    }

    public function encounterStatus(): EncounterStatus
    {
        return match ($this) {
            self::Cancelled => EncounterStatus::Cancelled,
            self::NoShow => EncounterStatus::NoShow,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Cancelled => 'Batalkan kunjungan',
            self::NoShow => 'Tandai tidak hadir',
        };
    }
}
