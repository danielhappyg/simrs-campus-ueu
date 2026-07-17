<?php

namespace App\Modules\Clinical\Enums;

enum OutpatientSafetyDispositionOutcome: string
{
    case ResumeRoutineFlow = 'RESUME_ROUTINE_FLOW';
    case SimulatedTransfer = 'SIMULATED_TRANSFER';

    public function label(): string
    {
        return match ($this) {
            self::ResumeRoutineFlow => 'Lanjutkan alur rutin simulasi',
            self::SimulatedTransfer => 'Alihkan dalam simulasi',
        };
    }
}
