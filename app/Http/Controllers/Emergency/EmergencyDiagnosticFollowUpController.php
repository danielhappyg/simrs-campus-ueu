<?php

namespace App\Http\Controllers\Emergency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Emergency\Concerns\RespondsToEmergencyMutation;
use App\Models\User;
use App\Support\Emergency\EmergencyDiagnosticFollowUpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EmergencyDiagnosticFollowUpController extends Controller
{
    use RespondsToEmergencyMutation;

    public function __construct(private readonly EmergencyDiagnosticFollowUpService $followUp) {}

    public function propose(Request $request, string $encounter, string $orderType, string $order): RedirectResponse
    {
        $validated = $request->validate([
            'order_type' => ['required', Rule::in(['LABORATORY', 'RADIOLOGY'])],
            'order_public_id' => ['required', 'ulid'],
            'expected_result_fingerprint' => ['required', 'string', 'size:64'],
            'assignee_physician_public_id' => ['required', 'ulid'],
            'assignment_reason' => ['required', 'string', 'min:3', 'max:1000'],
            'effective_at' => ['required', 'date'],
            'handoff_note' => ['required', 'string', 'min:3', 'max:2000'],
            'expected_assignment_version' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);
        $this->assertOrderBinding($validated, $orderType, $order);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->emergencyMutation(
            fn () => $this->followUp->propose(
                $encounter,
                $orderType,
                $order,
                $validated['expected_result_fingerprint'],
                $validated['assignee_physician_public_id'],
                $actor,
                $validated['assignment_reason'],
                $validated['effective_at'],
                $validated['handoff_note'],
                $validated['idempotency_key'],
            ),
            'Diagnostic follow-up assignment proposed.',
        );
    }

    public function accept(Request $request, string $proposal): RedirectResponse
    {
        $validated = $request->validate([
            'order_type' => ['required', Rule::in(['LABORATORY', 'RADIOLOGY'])],
            'order_public_id' => ['required', 'ulid'],
            'proposal_public_id' => ['required', 'ulid'],
            'expected_proposal_fingerprint' => ['required', 'string', 'size:64'],
            'expected_result_fingerprint' => ['required', 'string', 'size:64'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);
        if ($validated['proposal_public_id'] !== $proposal) {
            throw ValidationException::withMessages(['proposal_public_id' => 'The assignment proposal does not match the request.']);
        }
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->emergencyMutation(
            fn () => $this->followUp->accept(
                $proposal,
                $actor,
                $validated['expected_proposal_fingerprint'],
                $validated['expected_result_fingerprint'],
                $validated['idempotency_key'],
            ),
            'Follow-up assignment accepted.',
        );
    }

    /** @param array<string, mixed> $validated */
    private function assertOrderBinding(array $validated, string $orderType, string $order): void
    {
        if ($validated['order_type'] !== $orderType || $validated['order_public_id'] !== $order) {
            throw ValidationException::withMessages(['order_public_id' => 'The diagnostic order does not match the request.']);
        }
    }
}
