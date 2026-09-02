<?php

namespace App\Support\Inpatient;

use App\Models\InpatientClinicalDocument;

final class InpatientDocumentationDefinition
{
    /** @return list<string> */
    public static function allowedFields(string $documentType): array
    {
        return match ($documentType) {
            InpatientClinicalDocument::TYPE_NURSING_DAILY => [
                'nursing_observation', 'nursing_intervention', 'nursing_evaluation', 'additional_notes',
            ],
            InpatientClinicalDocument::TYPE_MEDICAL_DAILY => [
                'subjective', 'objective', 'assessment', 'plan', 'additional_notes',
            ],
            default => [],
        };
    }

    /** @return list<string> */
    public static function requiredOnFinal(string $documentType): array
    {
        return match ($documentType) {
            InpatientClinicalDocument::TYPE_NURSING_DAILY => [
                'nursing_observation', 'nursing_intervention', 'nursing_evaluation',
            ],
            InpatientClinicalDocument::TYPE_MEDICAL_DAILY => [
                'subjective', 'objective', 'assessment', 'plan',
            ],
            default => [],
        };
    }

    public static function auditAction(string $documentType, bool $finalize): string
    {
        $actor = $documentType === InpatientClinicalDocument::TYPE_NURSING_DAILY ? 'nursing' : 'medical';

        return 'clinical.inpatient.'.$actor.($finalize ? '.finalize' : '.draft.save');
    }
}
