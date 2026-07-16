<?php

namespace App\Modules\Clinical\Enums;

enum AllergyAssessmentState: string
{
    case KnownAllergy = 'KNOWN_ALLERGY';
    case NoKnownAllergyReported = 'NO_KNOWN_ALLERGY_REPORTED';
    case NotAssessed = 'NOT_ASSESSED';

    public function label(): string
    {
        return match ($this) {
            self::KnownAllergy => 'Alergi diketahui',
            self::NoKnownAllergyReported => 'Tidak ada alergi yang dilaporkan',
            self::NotAssessed => 'Belum dinilai',
        };
    }
}
