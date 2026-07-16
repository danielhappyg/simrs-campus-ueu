<?php

namespace App\Modules\Patient\Enums;

enum AdministrativeSex: string
{
    case Female = 'FEMALE';
    case Male = 'MALE';
    case Unknown = 'UNKNOWN';

    public function label(): string
    {
        return match ($this) {
            self::Female => 'Perempuan',
            self::Male => 'Laki-laki',
            self::Unknown => 'Belum ditentukan',
        };
    }
}
