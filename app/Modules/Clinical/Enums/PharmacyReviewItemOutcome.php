<?php

namespace App\Modules\Clinical\Enums;

enum PharmacyReviewItemOutcome: string
{
    case Clear = 'CLEAR';
    case Finding = 'FINDING';
    case NotApplicable = 'NOT_APPLICABLE';

    public function label(): string
    {
        return match ($this) {
            self::Clear => 'Lengkap / tidak ada temuan',
            self::Finding => 'Temuan',
            self::NotApplicable => 'Tidak berlaku',
        };
    }
}
