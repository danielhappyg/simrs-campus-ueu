<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Pharmacy\PharmacyAuditUnavailable;
use App\Support\Pharmacy\PharmacyDenied;
use App\Support\Pharmacy\PharmacyWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PharmacyPrescriptionController extends Controller
{
    public function __construct(
        private readonly PharmacyWorkflowService $workflow,
    ) {}

    public function store(Request $request, string $encounter): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->draft($request, true);
        $this->run(fn () => $this->workflow->createDraft($encounter, $actor, $data['depot_public_id'], $data['items'], $data['clinical_note'] ?? null, $data['idempotency_key']));

        return back()->with('success', 'Prescription draft saved.');
    }

    public function updateDraft(Request $request, string $prescription): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->draft($request, false);
        $this->run(fn () => $this->workflow->reviseDraft($prescription, $actor, $data['expected_version'], $data['items'], $data['clinical_note'] ?? null, $data['idempotency_key']));

        return back()->with('success', 'Prescription draft updated.');
    }

    public function order(Request $request, string $prescription): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate($this->evidenceRules(['expected_version' => ['required', 'integer', 'min:1']]));
        $this->run(fn () => $this->workflow->order($prescription, $actor, $data['expected_version'], $data['expected_fingerprint'], $data['idempotency_key']));

        return back()->with('success', 'Resep dikirim ke Apotek.');
    }

    public function replace(Request $request, string $prescription): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->draft($request, true, true);
        $this->run(fn () => $this->workflow->replace($prescription, $actor, $data['expected_fingerprint'], $data['depot_public_id'], $data['items'], $data['clinical_note'] ?? null, $data['reason_code'], $data['idempotency_key']));

        return back()->with('success', 'Replacement draft created; the previous prescription remains in the history.');
    }

    public function cancel(Request $request, string $prescription): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate($this->evidenceRules([
            'reason_code' => ['required', 'string', 'min:3', 'max:64'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]));
        $this->run(fn () => $this->workflow->cancel($prescription, $actor, $data['expected_fingerprint'], $data['reason_code'], $data['note'] ?? null, $data['idempotency_key']));

        return back()->with('success', 'Prescription cancelled.');
    }

    /** @return array<string,mixed> */
    private function draft(Request $request, bool $withDepot, bool $replacement = false): array
    {
        $rules = [
            'expected_version' => ['nullable', 'integer', 'min:0'],
            'clinical_note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:24'],
            'items.*.medicine_public_id' => ['required', 'string', 'size:26'],
            'items.*.dose_text' => ['required', 'string', 'max:160'],
            'items.*.route' => ['required', 'string', 'max:64'],
            'items.*.frequency_text' => ['required', 'string', 'max:160'],
            'items.*.duration_text' => ['required', 'string', 'max:160'],
            'items.*.requested_quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
            'items.*.instruction' => ['required', 'string', 'max:1000'],
            'idempotency_key' => $this->idempotencyRules(),
        ];
        if ($withDepot) {
            $rules['depot_public_id'] = ['required', 'string', 'size:26'];
        }
        if ($replacement) {
            $rules['expected_fingerprint'] = ['required', 'string', 'size:64'];
            $rules['reason_code'] = ['required', 'string', 'min:3', 'max:64'];
        }

        return $request->validate($rules);
    }

    /**
     * @param  array<string, list<string>>  $extra
     * @return array<string, list<string>>
     */
    private function evidenceRules(array $extra): array
    {
        return [...$extra, 'expected_fingerprint' => ['required', 'string', 'size:64'], 'idempotency_key' => $this->idempotencyRules()];
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
            throw ValidationException::withMessages(['pharmacy' => __($denial->getMessage())]);
        } catch (PharmacyAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Pharmacy audit recording is unavailable.');
        }
    }
}
