<?php

namespace App\Http\Requests\Teaching;

use App\Modules\Teaching\Enums\DebriefNoteType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDebriefNoteRequest extends FormRequest
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
            'note_type' => ['required', Rule::enum(DebriefNoteType::class)],
            'body' => ['required', 'string', 'min:3', 'max:4000'],
            'simulation_attestation' => ['accepted'],
        ];
    }
}
