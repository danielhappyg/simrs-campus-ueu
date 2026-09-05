<?php

namespace App\Http\Controllers\Emergency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Emergency\Concerns\RespondsToEmergencyMutation;
use App\Models\User;
use App\Support\Emergency\EmergencyInpatientHandoffCompensationService;
use App\Support\Emergency\EmergencyInpatientHandoffService;
use App\Support\Http\RequestCorrelation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class EmergencyInpatientHandoffController extends Controller
{
    use RespondsToEmergencyMutation;

    public function __construct(
        private readonly EmergencyInpatientHandoffService $handoff,
        private readonly EmergencyInpatientHandoffCompensationService $compensation,
    ) {}

    public function execute(Request $request, string $encounter): RedirectResponse
    {
        $validated = $request->validate([
            'disposition_public_id' => ['nullable', 'ulid'],
            'expected_disposition_version' => ['required', 'integer', 'min:1'],
            'bed_public_id' => ['required', 'ulid'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->emergencyMutation(
            fn () => $this->handoff->execute(
                $encounter,
                $actor,
                $validated['expected_disposition_version'],
                $validated['bed_public_id'],
                $validated['idempotency_key'],
                RequestCorrelation::existing($request),
            ),
            'Inpatient handoff completed.',
        );
    }

    public function compensate(Request $request, string $encounter): RedirectResponse
    {
        $validated = $request->validate([
            'correction_intent_public_id' => ['required', 'ulid'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->emergencyMutation(
            fn () => $this->compensation->execute(
                $encounter,
                $actor,
                $validated['correction_intent_public_id'],
                $validated['idempotency_key'],
                RequestCorrelation::existing($request),
            ),
            'Inpatient handoff reversed with an audit record.',
        );
    }
}
