<?php

namespace App\Modules\Clinical\Enums;

enum IntakeSafetyDecision: string
{
    case RoutineFlow = 'ROUTINE_FLOW';
    case ReviewRequired = 'REVIEW_REQUIRED';
    case EscalateToSupervisor = 'ESCALATE_TO_SUPERVISOR';

    public function label(): string
    {
        return match ($this) {
            self::RoutineFlow => 'Lanjut alur rutin',
            self::ReviewRequired => 'Perlu ditinjau supervisor',
            self::EscalateToSupervisor => 'Hentikan dan eskalasi ke supervisor',
        };
    }
}
