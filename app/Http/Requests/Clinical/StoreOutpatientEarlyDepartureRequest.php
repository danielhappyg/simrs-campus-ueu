<?php

namespace App\Http\Requests\Clinical;

use Illuminate\Foundation\Http\FormRequest;

class StoreOutpatientEarlyDepartureRequest extends FormRequest
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
            'confirmed' => ['required', 'accepted'],
            'stated_reason' => ['required', 'string', 'min:10', 'max:1000'],
            'communication_summary' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
