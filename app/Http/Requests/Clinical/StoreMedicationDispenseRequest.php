<?php

namespace App\Http\Requests\Clinical;

use App\Modules\Clinical\Enums\MedicationDispenseOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedicationDispenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'request_key' => ['required', 'ulid'],
            'outcome' => ['required', Rule::enum(MedicationDispenseOutcome::class)],
            'quantity' => ['required', 'numeric', 'min:0'],
            'medication_stock_id' => ['nullable', 'integer', 'exists:medication_stocks,id'],
            'outcome_reason' => ['nullable', 'string', 'max:3000'],
            'preparation_notes' => ['nullable', 'string', 'max:3000'],
            'final_check_confirmed' => ['required', 'accepted'],
            'final_check_notes' => ['nullable', 'string', 'max:3000'],
            'handoff_recipient' => ['nullable', 'string', 'max:500'],
            'counseling_topics' => ['array', 'max:20'],
            'counseling_topics.*' => ['string', 'max:500'],
            'counseling_acknowledged' => ['boolean'],
        ];
    }
}
