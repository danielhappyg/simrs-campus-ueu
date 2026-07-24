<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Models\AllergyAssessment;
use App\Modules\Encounter\Models\Encounter;

class ApprovedAllergyAssessmentResolver
{
    public function forEncounter(Encounter $encounter): ?AllergyAssessment
    {
        return AllergyAssessment::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereHas(
                'sourceEntryVersion',
                fn ($query) => $query->where('status', ClinicalEntryStatus::Approved->value),
            )
            ->with('sourceEntryVersion')
            ->orderByDesc('assessed_at')
            ->first();
    }

    public function label(?AllergyAssessment $assessment, string $fallback = 'Belum dinilai'): string
    {
        if (! $assessment) {
            return $fallback;
        }

        return $assessment->assessment_state->label()
            .($assessment->details ? ": {$assessment->details}" : '');
    }
}
