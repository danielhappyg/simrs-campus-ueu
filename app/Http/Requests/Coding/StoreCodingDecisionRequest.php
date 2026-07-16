<?php

namespace App\Http\Requests\Coding;

use App\Modules\Coding\Enums\CodingDecisionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCodingDecisionRequest extends FormRequest
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
            'decision' => ['required', Rule::enum(CodingDecisionType::class)],
            'candidate_public_id' => ['nullable', 'ulid'],
            'manual_concept_public_id' => ['nullable', 'ulid'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'rationale' => ['nullable', 'string', 'max:2000'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $decision = CodingDecisionType::tryFrom((string) $this->input('decision'));

                if ($decision === CodingDecisionType::AcceptedToDraft && ! $this->filled('candidate_public_id')) {
                    $validator->errors()->add('candidate_public_id', 'Pilih kandidat dari run yang sedang ditinjau.');
                }

                if ($decision === CodingDecisionType::ManualAlternative && ! $this->filled('manual_concept_public_id')) {
                    $validator->errors()->add('manual_concept_public_id', 'Pilih kode alternatif dari release yang sama.');
                }

                if (in_array($decision, [CodingDecisionType::Rejected, CodingDecisionType::CorrectionRequested], true)
                    && ! $this->filled('reason')) {
                    $validator->errors()->add('reason', 'Alasan wajib dicatat untuk keputusan ini.');
                }
            },
        ];
    }
}
