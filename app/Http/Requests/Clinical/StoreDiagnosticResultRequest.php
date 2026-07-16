<?php

namespace App\Http\Requests\Clinical;

use Illuminate\Foundation\Http\FormRequest;

class StoreDiagnosticResultRequest extends FormRequest
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
            'report_code' => ['required', 'string', 'max:255'],
            'report_display' => ['required', 'string', 'max:1000'],
            'effective_at' => ['required', 'date'],
            'narrative_conclusion' => ['required', 'string', 'max:6000'],
            'components' => ['array', 'max:50'],
            'components.*.code' => ['required', 'string', 'max:255'],
            'components.*.display' => ['required', 'string', 'max:1000'],
            'components.*.value' => ['required', 'string', 'max:1000'],
            'components.*.unit' => ['nullable', 'string', 'max:255'],
        ];
    }
}
