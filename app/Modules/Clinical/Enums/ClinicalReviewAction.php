<?php

namespace App\Modules\Clinical\Enums;

enum ClinicalReviewAction: string
{
    case Submit = 'SUBMIT';
    case RequestChanges = 'REQUEST_CHANGES';
    case ApproveSimulation = 'APPROVE_SIMULATION';

    public function label(): string
    {
        return match ($this) {
            self::Submit => 'Diajukan',
            self::RequestChanges => 'Perlu perbaikan',
            self::ApproveSimulation => 'Persetujuan simulasi',
        };
    }
}
