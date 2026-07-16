<?php

namespace App\Http\Requests\Patient;

use App\Modules\Patient\Enums\AdministrativeSex;
use App\Modules\Patient\Enums\DuplicateDecision;
use App\Modules\Patient\Enums\VisitSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterSyntheticPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'request_key' => ['required', 'ulid'],
            'existing_patient_public_id' => ['nullable', 'ulid', 'exists:synthetic_patients,public_id'],
            'full_name' => ['nullable', 'required_without:existing_patient_public_id', 'string', 'max:160', 'regex:/^Pasien Sintetis\s+.+/i'],
            'birth_date' => ['nullable', 'required_without:existing_patient_public_id', 'date', 'before_or_equal:today'],
            'administrative_sex' => ['nullable', 'required_without:existing_patient_public_id', Rule::enum(AdministrativeSex::class)],
            'location_public_id' => ['required', 'ulid', 'exists:service_locations,public_id'],
            'scheduled_at' => ['required', 'date'],
            'visit_reason' => ['required', 'string', 'max:255'],
            'visit_source' => ['required', Rule::enum(VisitSource::class)],
            'identity_verification_method' => [
                'required',
                Rule::in($this->filled('existing_patient_public_id')
                    ? ['TWO_SYNTHETIC_IDENTIFIERS']
                    : ['SCENARIO_BRIEF']),
            ],
            'consent_acknowledged' => ['accepted'],
            'duplicate_decision' => ['nullable', Rule::enum(DuplicateDecision::class)],
            'duplicate_reason' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn (): bool => $this->string('duplicate_decision')->toString() === DuplicateDecision::CreateNew->value),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.regex' => 'Nama latihan wajib diawali “Pasien Sintetis” agar tidak menyerupai data pelayanan nyata.',
            'consent_acknowledged.accepted' => 'Acknowledge penggunaan data sintetis wajib dikonfirmasi.',
            'identity_verification_method.in' => 'Metode verifikasi harus sesuai dengan identitas baru atau rekam sintetis yang dipilih.',
        ];
    }
}
