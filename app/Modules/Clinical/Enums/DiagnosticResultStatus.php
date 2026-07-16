<?php

namespace App\Modules\Clinical\Enums;

enum DiagnosticResultStatus: string
{
    case Preliminary = 'PRELIMINARY';
    case Final = 'FINAL';
    case Corrected = 'CORRECTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Preliminary => 'Pendahuluan',
            self::Final => 'Final untuk simulasi',
            self::Corrected => 'Dikoreksi',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
