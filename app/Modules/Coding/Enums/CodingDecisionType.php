<?php

namespace App\Modules\Coding\Enums;

enum CodingDecisionType: string
{
    case AcceptedToDraft = 'ACCEPTED_TO_DRAFT';
    case Rejected = 'REJECTED';
    case ManualAlternative = 'MANUAL_ALTERNATIVE';
    case CorrectionRequested = 'CORRECTION_REQUESTED';

    public function label(): string
    {
        return match ($this) {
            self::AcceptedToDraft => 'Diterima ke draf',
            self::Rejected => 'Ditolak',
            self::ManualAlternative => 'Alternatif manual',
            self::CorrectionRequested => 'Koreksi dokumentasi diminta',
        };
    }
}
