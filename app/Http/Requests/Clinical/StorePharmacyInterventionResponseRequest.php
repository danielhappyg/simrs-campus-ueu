<?php

namespace App\Http\Requests\Clinical;

use App\Modules\Clinical\Enums\PharmacyResponseAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePharmacyInterventionResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $replacing = fn (): bool => $this->input('response_action') === PharmacyResponseAction::Replace->value;

        return [
            'request_key' => ['required', 'ulid'],
            'response_action' => ['required', Rule::enum(PharmacyResponseAction::class)],
            'message_text' => ['required', 'string', 'max:4000'],
            'replacement' => [Rule::requiredIf($replacing), 'nullable', 'array'],
            'replacement.authored_medication' => [Rule::requiredIf($replacing), 'nullable', 'string', 'max:1000'],
            'replacement.form' => ['nullable', 'string', 'max:255'],
            'replacement.strength' => ['nullable', 'string', 'max:255'],
            'replacement.dose_value' => [Rule::requiredIf($replacing), 'nullable', 'numeric', 'gt:0'],
            'replacement.dose_unit' => [Rule::requiredIf($replacing), 'nullable', 'string', 'max:100'],
            'replacement.route' => [Rule::requiredIf($replacing), 'nullable', 'string', 'max:255'],
            'replacement.frequency' => [Rule::requiredIf($replacing), 'nullable', 'string', 'max:500'],
            'replacement.duration' => [Rule::requiredIf($replacing), 'nullable', 'string', 'max:500'],
            'replacement.quantity_value' => [Rule::requiredIf($replacing), 'nullable', 'numeric', 'gt:0'],
            'replacement.quantity_unit' => [Rule::requiredIf($replacing), 'nullable', 'string', 'max:100'],
            'replacement.directions' => [Rule::requiredIf($replacing), 'nullable', 'string', 'max:2000'],
            'replacement.indication_text' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
