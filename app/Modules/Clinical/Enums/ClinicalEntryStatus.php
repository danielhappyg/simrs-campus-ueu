<?php

namespace App\Modules\Clinical\Enums;

enum ClinicalEntryStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case ChangesRequested = 'CHANGES_REQUESTED';
    case Approved = 'APPROVED';
    case Amended = 'AMENDED';
    case EnteredInError = 'ENTERED_IN_ERROR';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Submitted => 'Diajukan untuk ditinjau',
            self::ChangesRequested => 'Perlu perbaikan',
            self::Approved => 'Disetujui untuk simulasi',
            self::Amended => 'Dikoreksi/Amendemen',
            self::EnteredInError => 'Dimasukkan karena kekeliruan',
        };
    }
}
