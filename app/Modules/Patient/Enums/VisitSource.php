<?php

namespace App\Modules\Patient\Enums;

enum VisitSource: string
{
    case Scheduled = 'SCHEDULED';
    case WalkIn = 'WALK_IN';
    case SimulatedReferral = 'SIMULATED_REFERRAL';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Janji terjadwal',
            self::WalkIn => 'Datang langsung (simulasi)',
            self::SimulatedReferral => 'Rujukan simulasi',
        };
    }
}
