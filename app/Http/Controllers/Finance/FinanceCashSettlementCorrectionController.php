<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceSettlementCorrectionCase;
use App\Models\FinanceSettlementCorrectionEvent;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Finance\FinanceAuditUnavailable;
use App\Support\Finance\FinanceCashSettlementCorrectionActorPolicy;
use App\Support\Finance\FinanceCashSettlementCorrectionProjection;
use App\Support\Finance\FinanceCashSettlementCorrectionService;
use App\Support\Finance\FinanceDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class FinanceCashSettlementCorrectionController extends Controller
{
    public function __construct(
        private readonly FinanceCashSettlementCorrectionActorPolicy $policy,
        private readonly FinanceCashSettlementCorrectionService $service,
        private readonly FinanceCashSettlementCorrectionProjection $projection,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->policy->view($actor);
        try {
            $cases = $this->projection->worklist($actor);
        } catch (FinanceDenied $denied) {
            report($denied);
            abort(503, 'Daftar koreksi pelunasan belum dapat direkonsiliasi.');
        }

        return Inertia::render('kasir/koreksi-pelunasan/index', [
            'definition_version' => 'APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1',
            'generated_at' => now()->isoFormat('D MMM YYYY, HH.mm'),
            'cases' => array_map(fn (array $case): array => $this->caseLinks($case), $cases),
        ]);
    }

    public function show(Request $request, string $correction): Response
    {
        $actor = $this->actor($request);
        $this->policy->view($actor);
        try {
            $case = $this->projection->case($correction, $actor);
        } catch (FinanceDenied $denied) {
            report($denied);
            abort(503, 'Perkara koreksi belum dapat direkonsiliasi.');
        }

        return Inertia::render('kasir/koreksi-pelunasan/show', [
            'definition_version' => 'APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1',
            'generated_at' => now()->isoFormat('D MMM YYYY, HH.mm'),
            'case' => $this->caseLinks($case),
            'permissions' => [
                'can_review' => ! $case['requester_is_actor']
                    && $actor->canCapability(Capability::FINANCE_SETTLEMENT_CORRECTION_REVIEW),
                'can_complete_refund' => ! $case['requester_is_actor']
                    && $actor->canCapability(Capability::FINANCE_SETTLEMENT_REFUND_COMPLETE),
            ],
            'commands' => [
                'review_url' => route('finance.settlement-corrections.review', ['correction' => $correction], false),
                'complete_refund_url' => route('finance.settlement-corrections.complete-refund', ['correction' => $correction], false),
            ],
            'back_url' => route('finance.settlement-corrections.index', absolute: false),
        ]);
    }

    public function store(Request $request, string $settlement): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->policy->request($actor);
        $data = $request->validate([
            'reason_code' => ['required', 'string', Rule::in(FinanceSettlementCorrectionCase::REASON_CODES)],
            'explanation' => ['required', 'string', 'min:8', 'max:500'],
            'expected_settlement_digest' => $this->digestRules(),
            'confirm_request' => ['accepted'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        try {
            $result = $this->service->request(
                $settlement,
                $actor,
                $data['reason_code'],
                $data['explanation'],
                $data['expected_settlement_digest'],
                $data['idempotency_key'],
            );
        } catch (FinanceDenied $denied) {
            $this->raiseMutationDenial($denied);
        } catch (FinanceAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Pencatatan audit koreksi pelunasan belum tersedia.');
        }

        /** @var FinanceSettlementCorrectionCase $case */
        $case = $result->record;

        return redirect()->route('finance.settlement-corrections.show', ['correction' => $case->public_id])
            ->with('success', $result->replayed
                ? 'Permintaan koreksi yang sama ditampilkan kembali.'
                : 'Permintaan koreksi dikirim untuk tinjauan supervisor kasir.');
    }

    public function review(Request $request, string $correction): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->policy->review($actor);
        $data = $request->validate([
            'decision' => ['required', 'string', Rule::in([
                FinanceSettlementCorrectionEvent::REVIEW_REJECTED,
                FinanceSettlementCorrectionEvent::REFUND_APPROVED,
            ])],
            'explanation' => ['required', 'string', 'min:8', 'max:500'],
            'expected_case_fingerprint' => $this->digestRules(),
            'confirm_review' => ['accepted'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        try {
            $result = $this->service->review(
                $correction,
                $actor,
                $data['decision'],
                $data['explanation'],
                $data['expected_case_fingerprint'],
                $data['idempotency_key'],
            );
        } catch (FinanceDenied $denied) {
            $this->raiseMutationDenial($denied);
        } catch (FinanceAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Pencatatan audit tinjauan koreksi belum tersedia.');
        }

        return back()->with('success', $result->replayed
            ? 'Keputusan tinjauan yang sama ditampilkan kembali.'
            : ($data['decision'] === FinanceSettlementCorrectionEvent::REVIEW_REJECTED
                ? 'Permintaan koreksi ditolak.'
                : 'Pengembalian tunai disetujui dan menunggu penyerahan kas.'));
    }

    public function completeRefund(Request $request, string $correction): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->policy->completeRefund($actor);
        $data = $request->validate([
            'expected_case_fingerprint' => $this->digestRules(),
            'confirm_cash_returned' => ['accepted'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        try {
            $result = $this->service->completeRefund(
                $correction,
                $actor,
                $data['expected_case_fingerprint'],
                $data['idempotency_key'],
            );
        } catch (FinanceDenied $denied) {
            $this->raiseMutationDenial($denied);
        } catch (FinanceAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Pencatatan audit pengembalian tunai belum tersedia.');
        }

        return redirect()->route('finance.settlement-corrections.refund-receipt', ['correction' => $correction])
            ->with('success', $result->replayed
                ? 'Bukti pengembalian yang sama ditampilkan kembali.'
                : 'Pengembalian tunai selesai dicatat.');
    }

    public function refundReceipt(Request $request, string $correction): Response
    {
        $actor = $this->actor($request);
        $this->policy->viewReceipt($actor);
        try {
            $receipt = $this->projection->refundReceipt($correction, $actor);
        } catch (FinanceDenied $denied) {
            report($denied);
            abort(503, 'Bukti pengembalian belum dapat direkonsiliasi.');
        }

        return Inertia::render('kasir/koreksi-pelunasan/bukti-pengembalian', [
            'definition_version' => 'APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1',
            'generated_at' => now()->isoFormat('D MMM YYYY, HH.mm'),
            'receipt' => $receipt,
            'back_url' => route('finance.settlement-corrections.show', ['correction' => $correction], false),
        ]);
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function caseLinks(array $case): array
    {
        return [
            ...$case,
            'show_url' => route('finance.settlement-corrections.show', ['correction' => $case['public_id']], false),
            'refund_receipt_url' => $case['state'] === FinanceSettlementCorrectionEvent::REFUND_COMPLETED
                ? route('finance.settlement-corrections.refund-receipt', ['correction' => $case['public_id']], false)
                : null,
        ];
    }

    /** @return list<string> */
    private function digestRules(): array
    {
        return ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'];
    }

    /** @return list<string> */
    private function idempotencyRules(): array
    {
        return ['required', 'string', 'min:8', 'max:255', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,254}\z/'];
    }

    private function raiseMutationDenial(FinanceDenied $denied): never
    {
        if (in_array($denied->reason, [
            'settlement_integrity_failure',
            'correction_integrity_failure',
            'prior_settlement_corrupt',
            'receipt_corrupt',
        ], true)) {
            report($denied);
            abort(503, 'Bukti koreksi pelunasan belum dapat direkonsiliasi.');
        }

        throw ValidationException::withMessages(['correction' => $denied->getMessage()]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }
}
