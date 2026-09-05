<?php

namespace App\Http\Controllers\Registration;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\EncounterCancellation;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Http\RequestCorrelation;
use App\Support\Registration\EncounterCancellationAuditUnavailable;
use App\Support\Registration\EncounterCancellationDenied;
use App\Support\Registration\EncounterCancellationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class EncounterCancellationController extends Controller
{
    public function __invoke(
        Request $request,
        string $encounter,
        EncounterCancellationService $service,
    ): RedirectResponse {
        Gate::authorize(Capability::ENCOUNTER_CANCEL);

        $resolvedEncounter = Encounter::query()
            ->syntheticOnly()
            ->where('public_id', $encounter)
            ->firstOrFail();

        $request->merge([
            'idempotency_key' => $request->input('idempotency_key')
                ?? $request->header('Idempotency-Key'),
        ]);
        $validated = $request->validate([
            'reason_code' => ['required', 'string', Rule::in(EncounterCancellation::REASON_CODES)],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/'],
        ]);

        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        try {
            $result = $service->cancel(
                encounter: $resolvedEncounter,
                actor: $actor,
                reasonCode: $validated['reason_code'],
                note: $validated['note'] ?? null,
                idempotencyKey: $validated['idempotency_key'],
                requestCorrelationId: RequestCorrelation::existing($request),
            );
        } catch (EncounterCancellationDenied $denial) {
            if ($request->header('X-Inertia') === 'true') {
                return back()->withErrors(['cancellation' => __($denial->getMessage())]);
            }

            abort($denial->status, __($denial->getMessage()));
        } catch (EncounterCancellationAuditUnavailable $failure) {
            if ($request->header('X-Inertia') === 'true') {
                return back()->withErrors(['cancellation' => __($failure->getMessage())]);
            }

            abort(503, __($failure->getMessage()));
        }

        return back()
            ->with('success', $result->replayed
                ? 'The visit cancellation is already recorded.'
                : 'Visit cancelled successfully.')
            ->with('last_cancellation_public_id', $result->cancellation->public_id);
    }
}
