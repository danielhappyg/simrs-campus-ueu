<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\OutpatientPostClosureAmendmentRequest;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Clinical\OutpatientAmendmentActorPolicy;
use App\Support\Clinical\OutpatientAmendmentAuditUnavailable;
use App\Support\Clinical\OutpatientAmendmentDenied;
use App\Support\Clinical\OutpatientRmAmendmentService;
use App\Support\Http\RequestCorrelation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class OutpatientRmAmendmentController extends Controller
{
    public function __construct(
        private readonly OutpatientRmAmendmentService $service,
        private readonly OutpatientAmendmentActorPolicy $actorPolicy,
    ) {}

    public function saveReview(Request $request, string $amendmentRequest): RedirectResponse
    {
        $actor = $this->authorizeRmik($request, Capability::RMIK_REVIEW);
        $resolvedRequest = OutpatientPostClosureAmendmentRequest::query()
            ->where('public_id', $amendmentRequest)
            ->firstOrFail();
        $validated = $this->validateOperation($request, versionMayBeZero: true);

        try {
            $result = $this->service->saveReview(
                amendmentRequest: $resolvedRequest,
                actor: $actor,
                expectedVersion: (int) $validated['expected_version'],
                expectedFingerprint: $validated['source_fingerprint'],
                idempotencyKey: $validated['idempotency_key'],
                requestCorrelationId: RequestCorrelation::existing($request),
            );
        } catch (OutpatientAmendmentDenied $denial) {
            return $this->failure($request, __($denial->getMessage()), $denial->status);
        } catch (OutpatientAmendmentAuditUnavailable $failure) {
            return $this->failure($request, __($failure->getMessage()), 503);
        }

        return back()
            ->with('success', $result->replayed ? 'The addendum review is already recorded.' : 'Addendum review saved.')
            ->with('last_amendment_review_public_id', $result->review->public_id);
    }

    public function signoff(Request $request, string $amendmentRequest): RedirectResponse
    {
        $actor = $this->authorizeRmik($request, Capability::RMIK_COMPLETENESS_SIGNOFF);
        $resolvedRequest = OutpatientPostClosureAmendmentRequest::query()
            ->where('public_id', $amendmentRequest)
            ->firstOrFail();
        $validated = $this->validateOperation($request, versionMayBeZero: false);

        try {
            $result = $this->service->signoff(
                amendmentRequest: $resolvedRequest,
                actor: $actor,
                expectedVersion: (int) $validated['expected_version'],
                expectedFingerprint: $validated['source_fingerprint'],
                idempotencyKey: $validated['idempotency_key'],
                requestCorrelationId: RequestCorrelation::existing($request),
            );
        } catch (OutpatientAmendmentDenied $denial) {
            return $this->failure($request, __($denial->getMessage()), $denial->status);
        } catch (OutpatientAmendmentAuditUnavailable $failure) {
            return $this->failure($request, __($failure->getMessage()), 503);
        }

        return back()
            ->with('success', $result->replayed ? 'The addendum review sign-off is already recorded.' : 'Addendum review signed off.')
            ->with('last_amendment_review_public_id', $result->review->public_id);
    }

    /** @return array{expected_version: int, source_fingerprint: string, idempotency_key: string} */
    private function validateOperation(Request $request, bool $versionMayBeZero): array
    {
        /** @var array{expected_version: int, source_fingerprint: string, idempotency_key: string} $validated */
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:'.($versionMayBeZero ? '0' : '1')],
            'source_fingerprint' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'idempotency_key' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/'],
        ]);

        return $validated;
    }

    private function authorizeRmik(Request $request, string $capability): User
    {
        Gate::authorize($capability);
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(403);
        }
        $this->actorPolicy->authorizeRmik($actor, $capability);

        return $actor;
    }

    private function failure(Request $request, string $message, int $status): RedirectResponse
    {
        if ($request->header('X-Inertia') === 'true') {
            return back()->withErrors(['amendment' => $message]);
        }

        abort($status, $message);
    }
}
