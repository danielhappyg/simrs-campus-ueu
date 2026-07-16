<?php

namespace App\Http\Requests\Clinical;

use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\ProcedureDocumentationState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEncounterClosureVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $submitting = fn (): bool => $this->input('intent') === ClinicalSaveIntent::Submit->value;
        $proceduresRecorded = fn (): bool => $this->input('procedure_documentation_state') === ProcedureDocumentationState::ProceduresRecorded->value;
        $noProcedurePerformed = fn (): bool => $this->input('procedure_documentation_state') === ProcedureDocumentationState::NonePerformed->value;

        return [
            'request_key' => ['required', 'ulid'],
            'intent' => ['required', Rule::enum(ClinicalSaveIntent::class)],
            'clinical_occurrence_at' => ['required', 'date'],
            'leaving_condition' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:2000'],
            'disposition' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:2000'],
            'follow_up_plan' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:4000'],
            'referral_plan' => ['nullable', 'string', 'max:4000'],
            'education_instructions' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:6000'],
            'outpatient_summary' => [Rule::requiredIf($submitting), 'nullable', 'string', 'max:8000'],
            'procedure_documentation_state' => [Rule::requiredIf($submitting), 'nullable', Rule::enum(ProcedureDocumentationState::class)],
            'procedures' => [
                Rule::requiredIf(fn (): bool => $submitting() && $proceduresRecorded()),
                Rule::prohibitedIf($noProcedurePerformed),
                'array',
                'max:20',
            ],
            'procedures.*.authored_text' => [Rule::requiredIf($proceduresRecorded), 'nullable', 'string', 'max:1000'],
            'procedures.*.performed_start_at' => [Rule::requiredIf($proceduresRecorded), 'nullable', 'date'],
            'procedures.*.performed_end_at' => ['nullable', 'date'],
            'procedures.*.performer_text' => [Rule::requiredIf($proceduresRecorded), 'nullable', 'string', 'max:255'],
            'procedures.*.body_site_text' => ['nullable', 'string', 'max:255'],
            'procedures.*.outcome_text' => ['nullable', 'string', 'max:255'],
            'procedures.*.note' => ['nullable', 'string', 'max:2000'],
            'procedures.*.reason_condition_public_id' => ['nullable', 'ulid'],
            'procedures.*.based_on_service_request_public_id' => ['nullable', 'ulid'],
            'change_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
