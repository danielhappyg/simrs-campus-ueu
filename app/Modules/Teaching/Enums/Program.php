<?php

namespace App\Modules\Teaching\Enums;

enum Program: string
{
    case Medicine = 'MEDICINE';
    case Nursing = 'NURSING';
    case Rmik = 'RMIK';
    case Pharmacy = 'PHARMACY';
    case Facilitation = 'FACILITATION';
    case System = 'SYSTEM';

    public function label(): string
    {
        return match ($this) {
            self::Medicine => 'Kedokteran',
            self::Nursing => 'Keperawatan',
            self::Rmik => 'RMIK',
            self::Pharmacy => 'Farmasi',
            self::Facilitation => 'Fasilitasi Simulasi',
            self::System => 'Sistem',
        };
    }
}
