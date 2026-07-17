<?php

namespace App\Http\Requests\Clinical;

use App\Modules\Clinical\Enums\OutpatientSafetyDispositionOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOutpatientSafetyDispositionRequest extends FormRequest
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
            'outcome' => ['required', Rule::enum(OutpatientSafetyDispositionOutcome::class)],
            'rationale' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
