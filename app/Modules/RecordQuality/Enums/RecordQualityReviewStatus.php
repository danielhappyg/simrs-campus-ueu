<?php

namespace App\Modules\RecordQuality\Enums;

enum RecordQualityReviewStatus: string
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
