<?php

namespace App\Modules\Clinical\Enums;

enum DiagnosisRole: string
{
    case Primary = 'PRIMARY';
    case Secondary = 'SECONDARY';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Utama',
            self::Secondary => 'Sekunder',
        };
    }
}
