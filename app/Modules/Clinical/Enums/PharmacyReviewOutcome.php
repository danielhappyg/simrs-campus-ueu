<?php

namespace App\Modules\Clinical\Enums;

enum PharmacyReviewOutcome: string
{
    case Accept = 'ACCEPT';
    case ClarificationRequired = 'CLARIFICATION_REQUIRED';
    case RecommendCancel = 'RECOMMEND_CANCEL';

    public function label(): string
    {
        return match ($this) {
            self::Accept => 'Terima untuk simulasi',
            self::ClarificationRequired => 'Perlu klarifikasi',
            self::RecommendCancel => 'Rekomendasikan pembatalan',
        };
    }
}
