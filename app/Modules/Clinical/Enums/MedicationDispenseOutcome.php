<?php

namespace App\Modules\Clinical\Enums;

enum MedicationDispenseOutcome: string
{
    case Complete = 'COMPLETE';
    case Partial = 'PARTIAL';
    case NotDispensed = 'NOT_DISPENSED';

    public function label(): string
    {
        return match ($this) {
            self::Complete => 'Diserahkan lengkap',
            self::Partial => 'Diserahkan sebagian',
            self::NotDispensed => 'Tidak diserahkan',
        };
    }
}
