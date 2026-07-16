<?php

namespace App\Modules\Coding\Enums;

enum CodingSourceType: string
{
    case Diagnosis = 'DIAGNOSIS';
    case Procedure = 'PROCEDURE';

    public function label(): string
    {
        return match ($this) {
            self::Diagnosis => 'Diagnosis klinisi',
            self::Procedure => 'Prosedur yang dilakukan',
        };
    }

    public function terminologySystem(): TerminologySystem
    {
        return match ($this) {
            self::Diagnosis => TerminologySystem::Icd10,
            self::Procedure => TerminologySystem::Icd9Cm,
        };
    }
}
