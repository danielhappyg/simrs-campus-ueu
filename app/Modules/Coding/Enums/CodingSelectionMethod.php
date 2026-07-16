<?php

namespace App\Modules\Coding\Enums;

enum CodingSelectionMethod: string
{
    case Suggested = 'SUGGESTED';
    case Manual = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::Suggested => 'Dari kandidat sistem',
            self::Manual => 'Dipilih manual',
        };
    }
}
