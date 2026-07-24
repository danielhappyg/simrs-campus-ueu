<?php

namespace App\Modules\Clinical\Enums;

enum DispensePreparationReviewAction: string
{
    case ApproveSimulation = 'APPROVE_SIMULATION';
    case RequestChanges = 'REQUEST_CHANGES';

    public function label(): string
    {
        return match ($this) {
            self::ApproveSimulation => 'Setujui pemeriksaan akhir',
            self::RequestChanges => 'Minta perbaikan penyiapan',
        };
    }
}
