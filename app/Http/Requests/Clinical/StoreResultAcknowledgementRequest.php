<?php

namespace App\Http\Requests\Clinical;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResultAcknowledgementRequest extends FormRequest
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
            'outcome' => ['required', Rule::in(['ACKNOWLEDGED', 'ACKNOWLEDGED_PLAN_REVIEW_REQUIRED'])],
            'comment' => [
                Rule::requiredIf(fn (): bool => $this->input('outcome') === 'ACKNOWLEDGED_PLAN_REVIEW_REQUIRED'),
                'nullable',
                'string',
                'max:4000',
            ],
        ];
    }
}
