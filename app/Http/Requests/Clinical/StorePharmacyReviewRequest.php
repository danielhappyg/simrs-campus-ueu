<?php

namespace App\Http\Requests\Clinical;

use App\Modules\Clinical\Enums\PharmacyReviewItemOutcome;
use App\Modules\Clinical\Enums\PharmacyReviewOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePharmacyReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $needsIntervention = fn (): bool => $this->input('overall_outcome') !== PharmacyReviewOutcome::Accept->value;

        return [
            'request_key' => ['required', 'ulid'],
            'overall_outcome' => ['required', Rule::enum(PharmacyReviewOutcome::class)],
            'domain_results' => ['required', 'array'],
            'domain_results.administrative' => ['required', 'array'],
            'domain_results.pharmaceutical' => ['required', 'array'],
            'domain_results.clinical' => ['required', 'array'],
            'domain_results.*.*.criterion_code' => ['required', 'string', 'max:255'],
            'domain_results.*.*.outcome' => ['required', Rule::enum(PharmacyReviewItemOutcome::class)],
            'domain_results.*.*.comment' => ['nullable', 'string', 'max:3000'],
            'intervention' => [Rule::requiredIf($needsIntervention), 'nullable', 'array'],
            'intervention.request_key' => [Rule::requiredIf($needsIntervention), 'nullable', 'ulid'],
            'intervention.issue_category' => [Rule::requiredIf($needsIntervention), 'nullable', 'string', 'max:255'],
            'intervention.urgency' => [Rule::requiredIf($needsIntervention), 'nullable', Rule::in(['ROUTINE', 'PRIORITY_SIMULATION'])],
            'intervention.question' => [Rule::requiredIf($needsIntervention), 'nullable', 'string', 'max:4000'],
            'intervention.recommendation' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
