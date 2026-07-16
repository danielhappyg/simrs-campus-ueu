<?php

namespace App\Modules\Coding\Enums;

enum CodingAssignmentStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case ChangesRequested = 'CHANGES_REQUESTED';
    case Approved = 'APPROVED';
    case ReviewRequired = 'REVIEW_REQUIRED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Submitted => 'Diajukan',
            self::ChangesRequested => 'Perlu perbaikan',
            self::Approved => 'Disetujui',
            self::ReviewRequired => 'Perlu ditinjau ulang',
        };
    }
}
