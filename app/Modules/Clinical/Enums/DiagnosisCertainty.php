<?php

namespace App\Modules\Clinical\Enums;

enum DiagnosisCertainty: string
{
    case Working = 'WORKING';
    case Differential = 'DIFFERENTIAL';
    case FinalForScenario = 'FINAL_FOR_SCENARIO';

    public function label(): string
    {
        return match ($this) {
            self::Working => 'Diagnosis kerja',
            self::Differential => 'Diagnosis banding',
            self::FinalForScenario => 'Diagnosis akhir untuk skenario',
        };
    }
}
