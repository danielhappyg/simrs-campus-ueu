<?php

namespace App\Modules\Patient\Enums;

enum AppointmentStatus: string
{
    case Booked = 'BOOKED';
    case CheckedIn = 'CHECKED_IN';
    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Terjadwal',
            self::CheckedIn => 'Sudah check-in',
            self::Cancelled => 'Dibatalkan',
            self::NoShow => 'Tidak hadir',
        };
    }
}
