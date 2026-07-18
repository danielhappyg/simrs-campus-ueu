<?php

namespace App\Modules\Patient\Enums;

enum AppointmentStatus: string
{
    case Booked = 'BOOKED';
    case CheckedIn = 'CHECKED_IN';
    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';
    case DepartedOnRequest = 'DEPARTED_ON_REQUEST';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Terjadwal',
            self::CheckedIn => 'Sudah check-in',
            self::Cancelled => 'Dibatalkan',
            self::NoShow => 'Tidak hadir',
            self::DepartedOnRequest => 'Pulang atas permintaan sendiri',
        };
    }
}
