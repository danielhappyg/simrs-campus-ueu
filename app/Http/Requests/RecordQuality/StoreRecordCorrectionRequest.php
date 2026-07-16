<?php

namespace App\Http\Requests\RecordQuality;

use Illuminate\Foundation\Http\FormRequest;

class StoreRecordCorrectionRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:4000'],
        ];
    }
}
