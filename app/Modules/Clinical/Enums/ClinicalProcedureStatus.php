<?php

namespace App\Modules\Clinical\Enums;

enum ClinicalProcedureStatus: string
{
    case Completed = 'COMPLETED';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Telah dilakukan',
        };
    }
}
