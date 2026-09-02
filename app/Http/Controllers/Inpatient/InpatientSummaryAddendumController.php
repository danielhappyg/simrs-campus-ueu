<?php

namespace App\Http\Controllers\Inpatient;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\InpatientSummaryAddendum;
use App\Models\InpatientSummaryCorrectionRequest;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Http\RequestCorrelation;
use App\Support\Inpatient\InpatientSummaryAddendumActorPolicy;
use App\Support\Inpatient\InpatientSummaryAddendumAuditUnavailable;
use App\Support\Inpatient\InpatientSummaryAddendumDenied;
use App\Support\Inpatient\InpatientSummaryAddendumResult;
use App\Support\Inpatient\InpatientSummaryAddendumService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class InpatientSummaryAddendumController extends Controller
{
    public function __construct(
        private readonly InpatientSummaryAddendumService $service,
        private readonly InpatientSummaryAddendumActorPolicy $actorPolicy,
    ) {}

    public function submit(Request $request, string $encounter): RedirectResponse
    {
        $actor = $this->authorizePhysician($request, Capability::INPATIENT_SUMMARY_ADDENDUM_REQUEST);
        $resolvedEncounter = Encounter::query()
            ->syntheticOnly()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->where('public_id', $encounter)
            ->firstOrFail();
        $validated = $request->validate([
            'reason_code' => ['required', Rule::in([
                InpatientSummaryCorrectionRequest::REASON_CLINICAL_CORRECTION,
                InpatientSummaryCorrectionRequest::REASON_MISSING_INFORMATION,
                InpatientSummaryCorrectionRequest::REASON_WRONG_ENTRY,
                InpatientSummaryCorrectionRequest::REASON_OTHER,
            ])],
            'note' => [
                Rule::requiredIf($request->input('reason_code') === InpatientSummaryCorrectionRequest::REASON_OTHER),
                'nullable', 'string', 'max:500',
            ],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        return $this->run(
            request: $request,
            action: fn (): InpatientSummaryAddendumResult => $this->service->submit(
                $resolvedEncounter,
                $actor,
                $validated['reason_code'],
                $validated['note'] ?? null,
                $validated['idempotency_key'],
                RequestCorrelation::existing($request),
            ),
            success: 'Permintaan koreksi ringkasan pulang berhasil dikirim.',
            replayed: 'Permintaan koreksi ringkasan pulang sudah tercatat.',
        );
    }

    public function decide(Request $request, string $correctionRequest): RedirectResponse
    {
        $actor = $this->authorizePhysician($request, Capability::INPATIENT_SUMMARY_ADDENDUM_APPROVE);
        $resolvedRequest = $this->request($correctionRequest);
        $validated = $request->validate([
            'decision' => ['required', Rule::in([
                InpatientSummaryCorrectionRequest::STATE_APPROVED,
                InpatientSummaryCorrectionRequest::STATE_DENIED,
            ])],
            'decision_note' => [
                Rule::requiredIf($request->input('decision') === InpatientSummaryCorrectionRequest::STATE_DENIED),
                'nullable', 'string', 'max:500',
            ],
            'expected_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        return $this->run(
            $request,
            fn (): InpatientSummaryAddendumResult => $this->service->decide(
                $resolvedRequest,
                $actor,
                $validated['decision'],
                $validated['decision_note'] ?? null,
                (int) $validated['expected_version'],
                $validated['idempotency_key'],
                RequestCorrelation::existing($request),
            ),
            'Keputusan koreksi ringkasan pulang berhasil disimpan.',
            'Keputusan koreksi ringkasan pulang sudah tercatat.',
        );
    }

    public function draft(Request $request, string $correctionRequest): RedirectResponse
    {
        $actor = $this->authorizePhysician($request, Capability::INPATIENT_SUMMARY_ADDENDUM_WRITE);
        $resolvedRequest = $this->request($correctionRequest);
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'fields' => ['required', 'array:'.implode(',', InpatientSummaryAddendum::FIELDS)],
            'fields.admission_reason' => ['nullable', 'string', 'max:5000'],
            'fields.significant_findings' => ['nullable', 'string', 'max:5000'],
            'fields.care_and_treatment_summary' => ['nullable', 'string', 'max:5000'],
            'fields.condition_at_discharge' => ['nullable', 'string', 'max:5000'],
            'fields.follow_up_plan' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        return $this->run(
            $request,
            fn (): InpatientSummaryAddendumResult => $this->service->saveDraft(
                $resolvedRequest,
                $actor,
                $validated['fields'],
                (int) $validated['expected_version'],
                $validated['idempotency_key'],
                RequestCorrelation::existing($request),
            ),
            'Draf addendum ringkasan pulang berhasil disimpan.',
            'Draf addendum ringkasan pulang sudah tercatat.',
        );
    }

    public function finalize(Request $request, string $correctionRequest): RedirectResponse
    {
        $actor = $this->authorizePhysician($request, Capability::INPATIENT_SUMMARY_ADDENDUM_FINALIZE);
        $resolvedRequest = $this->request($correctionRequest);
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        return $this->run(
            $request,
            fn (): InpatientSummaryAddendumResult => $this->service->finalize(
                $resolvedRequest,
                $actor,
                (int) $validated['expected_version'],
                $validated['idempotency_key'],
                RequestCorrelation::existing($request),
            ),
            'Addendum ringkasan pulang berhasil dijadikan Final.',
            'Finalisasi addendum ringkasan pulang sudah tercatat.',
        );
    }

    public function review(Request $request, string $correctionRequest): RedirectResponse
    {
        return $this->reviewAction($request, $correctionRequest, false);
    }

    public function signoff(Request $request, string $correctionRequest): RedirectResponse
    {
        return $this->reviewAction($request, $correctionRequest, true);
    }

    private function reviewAction(Request $request, string $correctionRequest, bool $signoff): RedirectResponse
    {
        $capability = $signoff
            ? Capability::INPATIENT_SUMMARY_ADDENDUM_RMIK_SIGNOFF
            : Capability::INPATIENT_SUMMARY_ADDENDUM_RMIK_REVIEW;
        $actor = $this->authorizeRmik($request, $capability);
        $resolvedRequest = $this->request($correctionRequest);
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'source_fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        return $this->run(
            $request,
            fn (): InpatientSummaryAddendumResult => $signoff
                ? $this->service->signoff(
                    $resolvedRequest,
                    $actor,
                    (int) $validated['expected_version'],
                    $validated['source_fingerprint'],
                    $validated['idempotency_key'],
                    RequestCorrelation::existing($request),
                )
                : $this->service->saveReview(
                    $resolvedRequest,
                    $actor,
                    (int) $validated['expected_version'],
                    $validated['source_fingerprint'],
                    $validated['idempotency_key'],
                    RequestCorrelation::existing($request),
                ),
            $signoff ? 'Koreksi rekam medis berhasil di-sign-off.' : 'Hasil review koreksi rekam medis berhasil disimpan.',
            $signoff ? 'Sign-off koreksi rekam medis sudah tercatat.' : 'Hasil review koreksi rekam medis sudah tercatat.',
        );
    }

    private function authorizePhysician(Request $request, string $capability): User
    {
        Gate::authorize($capability);
        $actor = $this->actor($request);
        $this->actorPolicy->physician($actor, $capability);

        return $actor;
    }

    private function authorizeRmik(Request $request, string $capability): User
    {
        Gate::authorize($capability);
        $actor = $this->actor($request);
        $this->actorPolicy->rmik($actor, $capability);

        return $actor;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function request(string $publicId): InpatientSummaryCorrectionRequest
    {
        return InpatientSummaryCorrectionRequest::query()
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /** @return list<string> */
    private function idempotencyRules(): array
    {
        return ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/'];
    }

    private function run(
        Request $request,
        callable $action,
        string $success,
        string $replayed,
    ): RedirectResponse {
        try {
            /** @var InpatientSummaryAddendumResult $result */
            $result = $action();
        } catch (InpatientSummaryAddendumDenied $denial) {
            return $this->failure($request, $denial->getMessage(), $denial->status);
        } catch (InpatientSummaryAddendumAuditUnavailable $failure) {
            return $this->failure($request, $failure->getMessage(), 503);
        }

        return back()->with('success', $result->replayed ? $replayed : $success);
    }

    private function failure(Request $request, string $message, int $status): RedirectResponse
    {
        if ($request->header('X-Inertia') === 'true') {
            return back()->withErrors(['inpatient_summary_addendum' => $message]);
        }

        abort($status, $message);
    }
}
