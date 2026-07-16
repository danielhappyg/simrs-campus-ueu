<?php

namespace App\Http\Requests\Clinical;

use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\DiagnosisCertainty;
use App\Modules\Clinical\Enums\DiagnosisRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMedicalAssessmentVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $submitting = fn (): bool => $this->input('intent') === ClinicalSaveIntent::Submit->value;

        return [
            'request_key' => ['required', 'ulid'],
            'intent' => ['required', Rule::enum(ClinicalSaveIntent::class)],
            'clinical_occurrence_at' => ['required', 'date'],
            'history_source' => ['nullable', 'string', 'max:500'],
            'present_illness' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:6000'],
            'past_medical_history' => ['nullable', 'string', 'max:4000'],
            'family_history' => ['nullable', 'string', 'max:4000'],
            'social_history' => ['nullable', 'string', 'max:4000'],
            'general_examination' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:6000'],
            'focused_examination' => ['nullable', 'string', 'max:6000'],
            'assessment_summary' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:6000'],
            'diagnoses' => [Rule::requiredIf($submitting), 'array', 'max:20'],
            'diagnoses.*.authored_text' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:2000'],
            'diagnoses.*.certainty' => [Rule::requiredIf($submitting), 'nullable', Rule::enum(DiagnosisCertainty::class)],
            'diagnoses.*.role' => [Rule::requiredIf($submitting), 'nullable', Rule::enum(DiagnosisRole::class)],
            'diagnoses.*.onset_at' => ['nullable', 'date'],
            'service_requests' => ['array', 'max:10'],
            'service_requests.*.request_type' => ['required', Rule::in(['LABORATORY', 'IMAGING', 'OTHER'])],
            'service_requests.*.authored_service' => ['required', 'string', 'max:1000'],
            'service_requests.*.clinical_question' => ['required', 'string', 'max:2000'],
            'service_requests.*.priority' => ['required', Rule::in(['ROUTINE', 'URGENT_SIMULATION'])],
            'service_requests.*.source_diagnosis_index' => ['nullable', 'integer', 'min:0'],
            'medication_requests' => ['array', 'max:20'],
            'medication_requests.*.authored_medication' => ['required', 'string', 'max:1000'],
            'medication_requests.*.form' => ['nullable', 'string', 'max:255'],
            'medication_requests.*.strength' => ['nullable', 'string', 'max:255'],
            'medication_requests.*.dose_value' => ['required', 'numeric', 'gt:0'],
            'medication_requests.*.dose_unit' => ['required', 'string', 'max:100'],
            'medication_requests.*.route' => ['required', 'string', 'max:255'],
            'medication_requests.*.frequency' => ['required', 'string', 'max:500'],
            'medication_requests.*.duration' => ['required', 'string', 'max:500'],
            'medication_requests.*.quantity_value' => ['required', 'numeric', 'gt:0'],
            'medication_requests.*.quantity_unit' => ['required', 'string', 'max:100'],
            'medication_requests.*.directions' => ['required', 'string', 'max:2000'],
            'medication_requests.*.indication_text' => ['nullable', 'string', 'max:2000'],
            'medication_requests.*.source_diagnosis_index' => ['nullable', 'integer', 'min:0'],
            'care_plan' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:6000'],
            'education' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:4000'],
            'follow_up_plan' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:4000'],
            'intended_disposition' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:2000'],
            'change_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('intent') !== ClinicalSaveIntent::Submit->value) {
                    return;
                }

                $diagnoses = $this->input('diagnoses', []);

                if (! is_array($diagnoses) || $diagnoses === []) {
                    $validator->errors()->add('diagnoses', 'Sedikitnya satu pernyataan diagnosis oleh klinisi diperlukan.');

                    return;
                }

                $primaryCount = collect($diagnoses)
                    ->filter(fn (mixed $diagnosis): bool => is_array($diagnosis)
                        && ($diagnosis['role'] ?? null) === DiagnosisRole::Primary->value)
                    ->count();

                if ($primaryCount !== 1) {
                    $validator->errors()->add('diagnoses', 'Tepat satu diagnosis utama harus dipilih sebelum pengajuan.');
                }

                foreach (['service_requests', 'medication_requests'] as $collectionKey) {
                    $requests = $this->input($collectionKey, []);

                    if (! is_array($requests)) {
                        continue;
                    }

                    foreach ($requests as $index => $request) {
                        if (! is_array($request) || ! isset($request['source_diagnosis_index'])) {
                            continue;
                        }

                        $sourceIndex = $request['source_diagnosis_index'];

                        if (! is_int($sourceIndex)
                            || ! isset($diagnoses[$sourceIndex])
                            || ! is_array($diagnoses[$sourceIndex])
                            || blank($diagnoses[$sourceIndex]['authored_text'] ?? null)) {
                            $validator->errors()->add(
                                "{$collectionKey}.{$index}.source_diagnosis_index",
                                'Diagnosis sumber yang dipilih tidak tersedia pada versi ini.',
                            );
                        }
                    }
                }
            },
        ];
    }
}
