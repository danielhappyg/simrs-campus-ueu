<?php

namespace App\Http\Requests\Clinical;

use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNursingIntakeVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $submitting = fn (): bool => $this->input('intent') === ClinicalSaveIntent::Submit->value;
        $knownAllergy = fn (): bool => $this->input('allergy_state') === AllergyAssessmentState::KnownAllergy->value;
        $noKnownAllergy = fn (): bool => $this->input('allergy_state') === AllergyAssessmentState::NoKnownAllergyReported->value;
        $hasMedication = fn (): bool => $this->input('current_medication_state') === CurrentMedicationState::HasMedication->value;
        $noneReported = fn (): bool => $this->input('current_medication_state') === CurrentMedicationState::NoneReported->value;

        return [
            'request_key' => ['required', 'ulid'],
            'intent' => ['required', Rule::enum(ClinicalSaveIntent::class)],
            'clinical_occurrence_at' => ['required', 'date'],
            'history_source' => ['nullable', 'string', 'max:500'],
            'chief_complaint' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:2000'],
            'onset_duration' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:500'],
            'consciousness' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:500'],
            'allergy_state' => [Rule::requiredIf($submitting), 'nullable', Rule::enum(AllergyAssessmentState::class)],
            'allergy_details' => [Rule::requiredIf(fn (): bool => $submitting() && $knownAllergy()), Rule::prohibitedIf($noKnownAllergy), 'nullable', 'string', 'max:1000'],
            'current_medication_state' => [Rule::requiredIf($submitting), 'nullable', Rule::enum(CurrentMedicationState::class)],
            'current_medication_details' => [Rule::requiredIf(fn (): bool => $submitting() && $hasMedication()), Rule::prohibitedIf($noneReported), 'nullable', 'string', 'max:2000'],
            'vitals' => [Rule::requiredIf($submitting), 'array'],
            'vitals.temperature' => [Rule::requiredIf($submitting), 'nullable', 'numeric', 'gt:0'],
            'vitals.heart_rate' => [Rule::requiredIf($submitting), 'nullable', 'numeric', 'gt:0'],
            'vitals.respiratory_rate' => [Rule::requiredIf($submitting), 'nullable', 'numeric', 'gt:0'],
            'vitals.systolic_blood_pressure' => [Rule::requiredIf($submitting), 'nullable', 'numeric', 'gt:0'],
            'vitals.diastolic_blood_pressure' => [Rule::requiredIf($submitting), 'nullable', 'numeric', 'gt:0'],
            'vitals.oxygen_saturation' => [Rule::requiredIf($submitting), 'nullable', 'numeric', 'between:0,100'],
            'safety_responses' => ['array', 'max:20'],
            'safety_responses.*.question_code' => ['required', 'string', 'max:100', 'distinct'],
            'safety_responses.*.response' => ['required', Rule::in(['YES', 'NO', 'UNKNOWN'])],
            'safety_responses.*.note' => ['nullable', 'string', 'max:1000'],
            'safety_decision' => [Rule::requiredIf($submitting), 'nullable', Rule::enum(IntakeSafetyDecision::class)],
            'note' => ['nullable', 'string', 'max:4000'],
            'handoff_summary' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:2000'],
            'change_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
