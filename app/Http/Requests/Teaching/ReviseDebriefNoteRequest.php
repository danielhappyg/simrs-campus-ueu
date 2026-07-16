<?php

namespace App\Http\Requests\Teaching;

use Illuminate\Foundation\Http\FormRequest;

class ReviseDebriefNoteRequest extends FormRequest
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
            'body' => ['required', 'string', 'min:3', 'max:4000'],
            'change_reason' => ['required', 'string', 'min:3', 'max:1000'],
            'simulation_attestation' => ['accepted'],
        ];
    }
}
