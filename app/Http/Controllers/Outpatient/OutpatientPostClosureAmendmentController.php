<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\OutpatientPostClosureAmendmentRequest;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Clinical\OutpatientAmendmentActorPolicy;
use App\Support\Clinical\OutpatientAmendmentAuditUnavailable;
use App\Support\Clinical\OutpatientAmendmentDenied;
use App\Support\Clinical\OutpatientPostClosureAmendmentService;
use App\Support\Http\RequestCorrelation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class OutpatientPostClosureAmendmentController extends Controller
{
    public function __construct(
        private readonly OutpatientPostClosureAmendmentService $service,
        private readonly OutpatientAmendmentActorPolicy $actorPolicy,
    ) {}

    public function submit(Request $request, string $encounter): RedirectResponse
    {
        $actor = $this->authorizePhysician($request, Capability::CLINICAL_OUTPATIENT_AMENDMENT_REQUEST);
        $resolvedEncounter = Encounter::query()
            ->syntheticOnly()
            ->where('care_setting', Encounter::CARE_SETTING_OUTPATIENT)
            ->where('public_id', $encounter)
            ->firstOrFail();
        $validated = $request->validate([
            'original_document_public_id' => ['required', 'string', 'size:26', 'regex:/\A[0-9A-HJKMNP-TV-Z]{26}\z/i'],
            'original_document_version' => ['required', 'integer', 'min:1'],
            'reason_code' => ['required', Rule::in(OutpatientPostClosureAmendmentRequest::REASON_CODES)],
            'note' => [
                Rule::requiredIf($request->input('reason_code') === OutpatientPostClosureAmendmentRequest::REASON_OTHER),
                'nullable', 'string', 'max:500',
            ],
            'idempotency_key' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/'],
        ]);

        try {
            $result = $this->service->submit(
                encounter: $resolvedEncounter,
                actor: $actor,
                originalDocumentPublicId: $validated['original_document_public_id'],
                originalDocumentVersion: (int) $validated['original_document_version'],
                reasonCode: $validated['reason_code'],
                note: $validated['note'] ?? null,
                idempotencyKey: $validated['idempotency_key'],
                requestCorrelationId: RequestCorrelation::existing($request),
            );
        } catch (OutpatientAmendmentDenied $denial) {
            return $this->failure($request, $denial->getMessage(), $denial->status);
        } catch (OutpatientAmendmentAuditUnavailable $failure) {
            return $this->failure($request, $failure->getMessage(), 503);
        }

        return back()
            ->with('success', $result->replayed
                ? 'Permintaan addendum sudah tercatat.'
                : 'Permintaan addendum berhasil dikirim.')
            ->with('last_amendment_request_public_id', $result->request->public_id);
    }

    public function decide(Request $request, string $amendmentRequest): RedirectResponse
    {
        $actor = $this->authorizePhysician($request, Capability::CLINICAL_OUTPATIENT_AMENDMENT_APPROVE);
        $resolvedRequest = OutpatientPostClosureAmendmentRequest::query()
            ->where('public_id', $amendmentRequest)
            ->firstOrFail();
        $validated = $request->validate([
            'decision' => ['required', Rule::in([
                OutpatientPostClosureAmendmentRequest::STATE_APPROVED,
                OutpatientPostClosureAmendmentRequest::STATE_DENIED,
            ])],
            'decision_note' => [
                Rule::requiredIf($request->input('decision') === OutpatientPostClosureAmendmentRequest::STATE_DENIED),
                'nullable', 'string', 'max:500',
            ],
            'expected_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/'],
        ]);

        try {
            $result = $this->service->decide(
                amendmentRequest: $resolvedRequest,
                actor: $actor,
                decision: $validated['decision'],
                decisionNote: $validated['decision_note'] ?? null,
                expectedVersion: (int) $validated['expected_version'],
                idempotencyKey: $validated['idempotency_key'],
                requestCorrelationId: RequestCorrelation::existing($request),
            );
        } catch (OutpatientAmendmentDenied $denial) {
            return $this->failure($request, $denial->getMessage(), $denial->status);
        } catch (OutpatientAmendmentAuditUnavailable $failure) {
            return $this->failure($request, $failure->getMessage(), 503);
        }

        return back()
            ->with('success', $result->replayed
                ? 'Keputusan addendum sudah tercatat.'
                : ($result->request->request_state === OutpatientPostClosureAmendmentRequest::STATE_APPROVED
                    ? 'Permintaan addendum disetujui.'
                    : 'Permintaan addendum ditolak.'));
    }

    public function saveAddendum(Request $request, string $amendmentRequest): RedirectResponse
    {
        $actor = $this->authorizePhysician($request, Capability::CLINICAL_OUTPATIENT_AMENDMENT_ADDENDUM_WRITE);
        $resolvedRequest = OutpatientPostClosureAmendmentRequest::query()
            ->where('public_id', $amendmentRequest)
            ->firstOrFail();
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'fields' => ['required', 'array:addendum_text'],
            'fields.addendum_text' => ['required', 'string', 'max:5000'],
            'idempotency_key' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/'],
        ]);

        try {
            $result = $this->service->saveAddendum(
                amendmentRequest: $resolvedRequest,
                actor: $actor,
                fields: $validated['fields'],
                expectedVersion: (int) $validated['expected_version'],
                idempotencyKey: $validated['idempotency_key'],
                requestCorrelationId: RequestCorrelation::existing($request),
            );
        } catch (OutpatientAmendmentDenied $denial) {
            return $this->failure($request, $denial->getMessage(), $denial->status);
        } catch (OutpatientAmendmentAuditUnavailable $failure) {
            return $this->failure($request, $failure->getMessage(), 503);
        }

        return back()
            ->with('success', $result->replayed ? 'Draf addendum sudah tercatat.' : 'Draf addendum berhasil disimpan.')
            ->with('last_addendum_public_id', $result->addendum->public_id);
    }

    public function finalizeAddendum(Request $request, string $amendmentRequest): RedirectResponse
    {
        $actor = $this->authorizePhysician($request, Capability::CLINICAL_OUTPATIENT_AMENDMENT_ADDENDUM_FINALIZE);
        $resolvedRequest = OutpatientPostClosureAmendmentRequest::query()
            ->where('public_id', $amendmentRequest)
            ->firstOrFail();
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/'],
        ]);

        try {
            $result = $this->service->finalizeAddendum(
                amendmentRequest: $resolvedRequest,
                actor: $actor,
                expectedVersion: (int) $validated['expected_version'],
                idempotencyKey: $validated['idempotency_key'],
                requestCorrelationId: RequestCorrelation::existing($request),
            );
        } catch (OutpatientAmendmentDenied $denial) {
            return $this->failure($request, $denial->getMessage(), $denial->status);
        } catch (OutpatientAmendmentAuditUnavailable $failure) {
            return $this->failure($request, $failure->getMessage(), 503);
        }

        return back()
            ->with('success', $result->replayed ? 'Finalisasi addendum sudah tercatat.' : 'Addendum berhasil difinalisasi.')
            ->with('last_addendum_public_id', $result->addendum->public_id);
    }

    private function authorizePhysician(Request $request, string $capability): User
    {
        Gate::authorize($capability);
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(403);
        }
        $this->actorPolicy->authorizePhysician($actor, $capability);

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
