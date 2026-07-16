<?php

namespace App\Modules\Coding\Enums;

enum CodingReviewAction: string
{
    case ApproveSimulation = 'APPROVE_SIMULATION';
    case RequestChanges = 'REQUEST_CHANGES';

    public function label(): string
    {
        return match ($this) {
            self::ApproveSimulation => 'Setujui untuk simulasi',
            self::RequestChanges => 'Minta perbaikan',
        };
    }
}
