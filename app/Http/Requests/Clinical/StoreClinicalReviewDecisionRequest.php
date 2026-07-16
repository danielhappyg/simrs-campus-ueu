<?php

namespace App\Http\Requests\Clinical;

use App\Modules\Clinical\Enums\ClinicalReviewAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClinicalReviewDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $requestingChanges = fn (): bool => $this->input('action') === ClinicalReviewAction::RequestChanges->value;

        return [
            'request_key' => ['required', 'ulid'],
            'action' => ['required', Rule::in([
                ClinicalReviewAction::RequestChanges->value,
                ClinicalReviewAction::ApproveSimulation->value,
            ])],
            'comment' => [Rule::requiredIf($requestingChanges), 'nullable', 'string', 'max:4000'],
            'findings' => ['array', 'max:20'],
            'findings.*.code' => ['required', 'string', 'max:100'],
            'findings.*.severity' => ['required', Rule::in(['BLOCKING', 'NON_BLOCKING', 'INFORMATIONAL'])],
            'findings.*.message' => ['required', 'string', 'max:1000'],
        ];
    }
}
