<?php

namespace App\Modules\Teaching\Enums;

enum RubricReferenceStatus: string
{
    case PendingProgramReview = 'PENDING_PROGRAM_REVIEW';
    case Approved = 'APPROVED';
    case Retired = 'RETIRED';

    public function label(): string
    {
        return match ($this) {
            self::PendingProgramReview => 'Menunggu telaah program studi',
            self::Approved => 'Disetujui untuk skenario',
            self::Retired => 'Tidak lagi digunakan',
        };
    }
}
