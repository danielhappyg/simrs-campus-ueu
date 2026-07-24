<?php

namespace App\Http\Requests\Clinical;

use App\Modules\Clinical\Enums\MedicationDispenseOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'action' => ['required', Rule::in(['PREPARE', 'FINAL_CHECK'])],
            'preparation_public_id' => ['nullable', 'ulid', 'exists:medication_dispense_preparations,public_id'],
            'review_action' => ['nullable', Rule::in(['APPROVE_SIMULATION', 'REQUEST_CHANGES'])],
            'outcome' => ['nullable', Rule::enum(MedicationDispenseOutcome::class)],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'medication_stock_id' => ['nullable', 'integer', 'exists:medication_stocks,id'],
            'outcome_reason' => ['nullable', 'string', 'max:3000'],
            'preparation_notes' => ['nullable', 'string', 'max:3000'],
            'change_reason' => ['nullable', 'string', 'max:3000'],
            'final_check_confirmed' => ['nullable', 'boolean'],
            'final_check_notes' => ['nullable', 'string', 'max:3000'],
            'comment' => ['nullable', 'string', 'max:3000'],
            'handoff_recipient' => ['nullable', 'string', 'max:500'],
            'counseling_topics' => ['array', 'max:20'],
            'counseling_topics.*' => ['string', 'max:500'],
            'counseling_acknowledged' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->string('action')->toString() === 'PREPARE') {
                    foreach (['outcome', 'quantity'] as $field) {
                        if ($this->input($field) === null || $this->input($field) === '') {
                            $validator->errors()->add($field, 'Field penyiapan ini wajib diisi.');
                        }
                    }

                    return;
                }

                foreach (['preparation_public_id', 'review_action'] as $field) {
                    if (blank($this->input($field))) {
                        $validator->errors()->add($field, 'Field pemeriksaan akhir ini wajib diisi.');
                    }
                }

                if ($this->input('review_action') === 'REQUEST_CHANGES' && blank($this->input('comment'))) {
                    $validator->errors()->add('comment', 'Permintaan perbaikan memerlukan komentar.');
                }

                if ($this->input('review_action') === 'APPROVE_SIMULATION'
                    && ! $this->boolean('final_check_confirmed')) {
                    $validator->errors()->add('final_check_confirmed', 'Konfirmasi pemeriksaan akhir wajib dipilih.');
                }
            },
        ];
    }
}
