<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Pharmacy\PharmacyAuditUnavailable;
use App\Support\Pharmacy\PharmacyDenied;
use App\Support\Pharmacy\PharmacyWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PharmacyDispensingController extends Controller
{
    public function __construct(
        private readonly PharmacyWorkflowService $workflow,
    ) {}

    public function verify(Request $request, string $prescription): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate($this->verificationRules(true));
        $this->run(fn () => $this->workflow->verify($prescription, $actor, $data['expected_fingerprint'], $data['manual_allergy_review'], $data['checklist'], $data['item_decisions'], $data['idempotency_key']));

        return back()->with('success', 'Prescription verified.');
    }

    public function refuse(Request $request, string $prescription): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate($this->verificationRules(false));
        $this->run(fn () => $this->workflow->refuse($prescription, $actor, $data['expected_fingerprint'], $data['manual_allergy_review'], $data['checklist'], $data['reason_code'], $data['note'] ?? null, $data['idempotency_key']));

        return back()->with('success', 'Prescription refusal recorded.');
    }

    public function prepare(Request $request, string $prescription): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'expected_fingerprint' => ['required', 'string', 'size:64'],
            'item_quantities' => ['nullable', 'array', 'max:24'],
            'item_quantities.*' => ['integer', 'min:0', 'max:100000000'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $quantities = array_map('intval', $data['item_quantities'] ?? []);
        $this->run(fn () => $this->workflow->prepare($prescription, $actor, $data['expected_fingerprint'], $data['idempotency_key'], $quantities));

        return back()->with('success', 'Medicines prepared using FEFO order.');
    }

    public function handover(Request $request, string $preparation): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'expected_preparation_fingerprint' => ['required', 'string', 'size:64'],
            'partial_reason' => ['nullable', 'string', 'max:1000'],
            'confirm_handover' => ['accepted'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->run(fn () => $this->workflow->handover($preparation, $actor, $data['expected_preparation_fingerprint'], $data['partial_reason'] ?? null, $data['idempotency_key']));

        return back()->with('success', 'Medicine handover recorded and inventory updated.');
    }

    public function closeUnfilled(Request $request, string $prescription): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'expected_fingerprint' => ['required', 'string', 'size:64'],
            'reason_code' => ['required', 'string', 'min:3', 'max:64'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->run(fn () => $this->workflow->closeUnfilled($prescription, $actor, $data['expected_fingerprint'], $data['reason_code'], $data['idempotency_key']));

        return back()->with('success', 'Unfilled remainder closed with a reason.');
    }

    public function recordReturn(Request $request, string $prescription): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'handover_public_id' => ['required', 'string', 'size:26'],
            'expected_handover_fingerprint' => ['required', 'string', 'size:64'],
            'reason_code' => ['required', 'string', 'min:3', 'max:64'],
            'note' => ['nullable', 'string', 'max:1000'],
            'confirm_return' => ['accepted'],
            'items' => ['required', 'array', 'min:1', 'max:24'],
            'items.*.handover_item_public_id' => ['required', 'string', 'size:26'],
            'items.*.condition' => ['required', Rule::in(['RETURN_TO_STOCK', 'QUARANTINE', 'DESTROYED_OR_NOT_RETURNABLE'])],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:100000000'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $items = array_values(array_filter($data['items'], fn (array $item): bool => (int) $item['quantity'] > 0));
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Select at least one return quantity.']);
        }
        $this->run(fn () => $this->workflow->recordReturn($data['handover_public_id'], $prescription, $actor, $data['expected_handover_fingerprint'], $data['reason_code'], $data['note'] ?? null, $items, $data['idempotency_key']));

        return back()->with('success', 'Medicine return recorded without changing the original handover record.');
    }

    /** @return array<string,array<int,mixed>> */
    private function verificationRules(bool $verified): array
    {
        $rules = [
            'expected_fingerprint' => ['required', 'string', 'size:64'],
            'manual_allergy_review' => ['required', Rule::in(['REVIEWED_NO_CONFLICT', 'REVIEWED_WITH_NOTE', 'UNKNOWN_BLOCKED'])],
            'checklist' => ['required', 'array:identity_confirmed,context_confirmed,medicine_readable,instruction_readable'],
            'checklist.identity_confirmed' => ['required', 'boolean'],
            'checklist.context_confirmed' => ['required', 'boolean'],
            'checklist.medicine_readable' => ['required', 'boolean'],
            'checklist.instruction_readable' => ['required', 'boolean'],
            'idempotency_key' => $this->idempotencyRules(),
        ];
        if ($verified) {
            $rules += [
                'item_decisions' => ['required', 'array', 'min:1', 'max:24'],
                'item_decisions.*.item_public_id' => ['required', 'string', 'size:26'],
                'item_decisions.*.verified_quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
                'item_decisions.*.reason_code' => ['nullable', 'string', 'max:64'],
            ];
        } else {
            $rules += [
                'reason_code' => ['required', 'string', 'min:3', 'max:64'],
                'note' => ['nullable', 'string', 'max:1000'],
            ];
        }

        return $rules;
    }

    /** @return list<string> */
    private function idempotencyRules(): array
    {
        return ['required', 'string', 'min:8', 'max:128'];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }

    private function run(callable $operation): void
    {
        try {
            $operation();
        } catch (PharmacyDenied $denial) {
            $field = $denial->reason === 'prescription_handover_mismatch'
                ? 'handover_public_id'
                : 'pharmacy';

            throw ValidationException::withMessages([$field => __($denial->getMessage())]);
        } catch (PharmacyAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Pharmacy audit recording is unavailable.');
        }
    }
}
