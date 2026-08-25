<?php

namespace App\Support\Clinical;

use App\Models\OutpatientClinicalDocument;
use InvalidArgumentException;

final class OutpatientDocumentationDefinition
{
    /** @return list<string> */
    public static function allowedFields(string $documentType): array
    {
        return match ($documentType) {
            OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT => [
                'nursing_assessment',
                'additional_notes',
            ],
            OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT => [
                'anamnesis',
                'objective_examination',
                'clinical_assessment',
                'care_plan',
                'additional_notes',
            ],
            default => [],
        };
    }

    /** @return list<string> */
    public static function requiredOnFinal(string $documentType): array
    {
        return match ($documentType) {
            OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT => ['nursing_assessment'],
            OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT => [
                'anamnesis',
                'objective_examination',
                'clinical_assessment',
                'care_plan',
            ],
            default => [],
        };
    }

    public static function capability(string $documentType): string
    {
        return match ($documentType) {
            OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT => 'clinical.nursing.write',
            OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT => 'clinical.medical.write',
            default => '',
        };
    }

    public static function auditPrefix(string $documentType): string
    {
        return match ($documentType) {
            OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT => 'clinical.nursing',
            OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT => 'clinical.medical',
            default => throw new InvalidArgumentException('Unsupported outpatient document type for audit.'),
        };
    }
}
