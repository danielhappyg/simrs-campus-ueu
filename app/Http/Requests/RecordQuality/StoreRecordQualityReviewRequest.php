<?php

namespace App\Http\Requests\RecordQuality;

use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\RecordQuality\Enums\RecordQualityFindingSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecordQualityReviewRequest extends FormRequest
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
            'intent' => ['required', Rule::enum(ClinicalSaveIntent::class)],
            'findings' => ['array', 'max:20'],
            'findings.*.code' => ['required', 'string', 'max:100'],
            'findings.*.severity' => ['required', Rule::enum(RecordQualityFindingSeverity::class)],
            'findings.*.message' => ['required', 'string', 'max:2000'],
            'findings.*.requested_action' => ['required', 'string', 'max:2000'],
            'resolved_correction_public_ids' => ['array', 'max:20'],
            'resolved_correction_public_ids.*' => ['required', 'ulid', 'distinct'],
            'change_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
