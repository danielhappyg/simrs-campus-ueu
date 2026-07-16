<?php

namespace App\Modules\Clinical\Enums;

enum EncounterClosureStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case ChangesRequested = 'CHANGES_REQUESTED';
    case Approved = 'APPROVED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Submitted => 'Diajukan',
            self::ChangesRequested => 'Perlu perbaikan',
            self::Approved => 'Disetujui untuk simulasi',
        };
    }
}
