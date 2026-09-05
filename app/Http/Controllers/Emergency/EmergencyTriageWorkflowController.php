<?php

namespace App\Http\Controllers\Emergency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Emergency\Concerns\RespondsToEmergencyMutation;
use App\Models\EmergencyTriageAssessment;
use App\Models\User;
use App\Support\Emergency\EmergencyTriageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

final class EmergencyTriageWorkflowController extends Controller
{
    use RespondsToEmergencyMutation;

    public function __construct(private readonly EmergencyTriageService $triage) {}

    public function finalizeInitial(Request $request, string $encounter): RedirectResponse
    {
        $validated = $this->validated($request, reassessment: false);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->emergencyMutation(
            fn () => $this->triage->finalizeInitial(
                $encounter,
                $validated['vocabulary_public_id'],
                $validated['vocabulary_version'],
                $actor,
                Arr::except($validated, [
                    'vocabulary_public_id',
                    'vocabulary_version',
                    'expected_assessment_version',
                    'idempotency_key',
                ]),
                $validated['idempotency_key'],
            ),
            'Initial triage finalized.',
        );
    }

    public function reassess(Request $request, string $encounter): RedirectResponse
    {
        $validated = $this->validated($request, reassessment: true);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->emergencyMutation(
            fn () => $this->triage->reassess(
                $encounter,
                $actor,
                $validated['expected_assessment_version'],
                Arr::except($validated, [
                    'vocabulary_public_id',
                    'vocabulary_version',
                    'expected_assessment_version',
                    'idempotency_key',
                ]),
                $validated['idempotency_key'],
            ),
            'Reassessment added.',
        );
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $reassessment): array
    {
        $observationFields = [
            'respiratory_rate', 'pulse', 'systolic_pressure', 'diastolic_pressure',
            'oxygen_saturation', 'temperature', 'pain_score', 'weight',
        ];

        return $request->validate([
            'vocabulary_public_id' => ['required', 'ulid'],
            'vocabulary_version' => ['required', 'integer', 'min:1'],
            'expected_assessment_version' => ['required', 'integer', $reassessment ? 'min:1' : 'in:0'],
            'category_code' => ['required', Rule::in(EmergencyTriageAssessment::CATEGORIES)],
            'observed_at' => ['required', 'date'],
            'late_entry_reason' => ['nullable', 'string', 'max:1000'],
            'reassessment_reason' => [$reassessment ? 'required' : 'nullable', 'string', 'max:1000'],
            'presenting_concern' => ['required', 'string', 'min:3', 'max:2000'],
            'clinical_basis' => ['required', 'string', 'min:3', 'max:4000'],
            'arrival_condition' => ['required', 'string', 'min:3', 'max:2000'],
            'abcde' => ['required', 'array'],
            'abcde.airway' => ['required', 'array'],
            'abcde.breathing' => ['required', 'array'],
            'abcde.circulation' => ['required', 'array'],
            'abcde.disability' => ['required', 'array'],
            'abcde.exposure' => ['required', 'array'],
            'abcde.*.state' => ['required', Rule::in(EmergencyTriageAssessment::ABCDE_STATES)],
            'abcde.*.note' => ['nullable', 'string', 'max:1000'],
            'consciousness' => ['required', Rule::in(EmergencyTriageAssessment::CONSCIOUSNESS)],
            'vitals' => ['required', 'array'],
            'vitals.respiratory_rate' => ['nullable', 'integer', 'between:0,100'],
            'vitals.pulse' => ['nullable', 'integer', 'between:0,300'],
            'vitals.systolic_pressure' => ['nullable', 'integer', 'between:0,300'],
            'vitals.diastolic_pressure' => ['nullable', 'integer', 'between:0,300'],
            'vitals.oxygen_saturation' => ['nullable', 'integer', 'between:0,100'],
            'vitals.temperature' => ['nullable', 'numeric', 'between:20,45'],
            'vitals.pain_score' => ['nullable', 'integer', 'between:0,10'],
            'vitals.weight' => ['nullable', 'numeric', 'between:0.1,500'],
            'unobtainable_fields' => ['present', 'array'],
            'unobtainable_fields.*' => [Rule::in($observationFields)],
            'unobtainable_reason' => ['nullable', 'string', 'max:1000'],
            'trauma' => ['required', 'boolean'],
            'trauma_note' => ['nullable', 'string', 'max:1000'],
            'isolation_precaution' => ['required', 'boolean'],
            'isolation_note' => ['nullable', 'string', 'max:1000'],
            'handoff_note' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);
    }
}
