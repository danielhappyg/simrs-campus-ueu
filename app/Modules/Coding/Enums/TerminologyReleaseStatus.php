<?php

namespace App\Modules\Coding\Enums;

enum TerminologyReleaseStatus: string
{
    case Imported = 'IMPORTED';
    case Active = 'ACTIVE';
    case Superseded = 'SUPERSEDED';

    public function label(): string
    {
        return match ($this) {
            self::Imported => 'Diimpor',
            self::Active => 'Aktif',
            self::Superseded => 'Digantikan',
        };
    }
}
