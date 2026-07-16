<?php

namespace App\Http\Requests\Clinical;

use App\Modules\Clinical\Enums\EncounterClosureReviewAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEncounterClosureReviewDecisionRequest extends FormRequest
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
            'action' => ['required', Rule::enum(EncounterClosureReviewAction::class)],
            'comment' => ['nullable', 'string', 'max:4000'],
            'findings' => ['array', 'max:20'],
            'findings.*.code' => ['required', 'string', 'max:100'],
            'findings.*.severity' => ['required', Rule::in(['BLOCKING', 'NON_BLOCKING', 'INFORMATIONAL'])],
            'findings.*.message' => ['required', 'string', 'max:2000'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('action') === EncounterClosureReviewAction::RequestChanges->value
                    && $this->input('findings', []) === []) {
                    $validator->errors()->add('findings', 'Sedikitnya satu temuan diperlukan saat meminta perbaikan.');
                }
            },
        ];
    }
}
