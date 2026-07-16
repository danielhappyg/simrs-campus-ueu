<?php

namespace App\Modules\Coding\Enums;

enum TerminologySystem: string
{
    case Icd10 = 'ICD_10';
    case Icd9Cm = 'ICD_9_CM';

    public function label(): string
    {
        return match ($this) {
            self::Icd10 => 'ICD-10',
            self::Icd9Cm => 'ICD-9-CM',
        };
    }

    public function logicalVersion(): string
    {
        return match ($this) {
            self::Icd10 => 'ICD10_2010',
            self::Icd9Cm => 'ICD9CM_2010',
        };
    }

    public function expectedSheetName(): string
    {
        return match ($this) {
            self::Icd10 => 'ICD10',
            self::Icd9Cm => 'ICD9 CM',
        };
    }

    public function sourceType(): string
    {
        return match ($this) {
            self::Icd10 => 'DIAGNOSIS',
            self::Icd9Cm => 'PROCEDURE',
        };
    }
}
