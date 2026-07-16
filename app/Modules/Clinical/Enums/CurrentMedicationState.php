<?php

namespace App\Modules\Clinical\Enums;

enum CurrentMedicationState: string
{
    case HasMedication = 'HAS_MEDICATION';
    case NoneReported = 'NONE_REPORTED';
    case Unknown = 'UNKNOWN';

    public function label(): string
    {
        return match ($this) {
            self::HasMedication => 'Ada obat yang sedang digunakan',
            self::NoneReported => 'Tidak ada obat yang dilaporkan',
            self::Unknown => 'Belum diketahui',
        };
    }
}
