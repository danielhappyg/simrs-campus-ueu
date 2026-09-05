<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceBill;
use App\Models\FinanceCashSettlement;
use App\Models\User;
use App\Support\Finance\FinanceAuditUnavailable;
use App\Support\Finance\FinanceCashSettlementActorPolicy;
use App\Support\Finance\FinanceCashSettlementProjection;
use App\Support\Finance\FinanceCashSettlementService;
use App\Support\Finance\FinanceDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class FinanceCashSettlementController extends Controller
{
    public function __construct(
        private readonly FinanceCashSettlementActorPolicy $policy,
        private readonly FinanceCashSettlementService $service,
        private readonly FinanceCashSettlementProjection $projection,
    ) {}

    public function store(Request $request, string $encounter): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->policy->settle($actor);
        $data = $request->validate([
            'expected_bill_fingerprint' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'expected_bill_version_content_digest' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'confirm_settlement' => ['accepted'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:255', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,254}\z/'],
        ]);
        $bill = $this->billForEncounter($encounter);

        try {
            $result = $this->service->settle(
                $bill->public_id,
                $actor,
                $data['expected_bill_fingerprint'],
                $data['expected_bill_version_content_digest'],
                $data['idempotency_key'],
            );
        } catch (FinanceDenied $denied) {
            throw ValidationException::withMessages(['settlement' => __($denied->getMessage())]);
        } catch (FinanceAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Audit recording for the settlement is unavailable.');
        }

        /** @var FinanceCashSettlement $settlement */
        $settlement = $result->record;

        return redirect()->route('finance.settlements.receipt', ['settlement' => $settlement->public_id])
            ->with('success', $result->replayed
                ? 'The same settlement receipt is shown again.'
                : 'Cash settlement recorded.');
    }

    public function receipt(Request $request, string $settlement): Response
    {
        $actor = $this->actor($request);
        $this->policy->viewReceipt($actor);
        try {
            $receipt = $this->projection->receipt($settlement, $actor);
        } catch (FinanceDenied $denied) {
            report($denied);
            abort(503, 'The settlement receipt could not be reconciled.');
        }

        return Inertia::render('kasir/pelunasan/kuitansi', [
            'definition_version' => 'EXACT_CASH_SETTLEMENT_AND_RECEIPT_V1',
            'generated_at' => now()->isoFormat('D MMM YYYY, HH.mm'),
            'receipt' => [
                ...$receipt,
                'correction_url' => $receipt['correction_public_id'] === null
                    ? null
                    : route('finance.settlement-corrections.show', [
                        'correction' => $receipt['correction_public_id'],
                    ], false),
            ],
            'back_url' => route('finance.bills.show', ['encounter' => $receipt['encounter_public_id']], false),
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }

    private function billForEncounter(string $encounterPublicId): FinanceBill
    {
        return FinanceBill::query()
            ->whereHas('encounter', fn ($query) => $query->where('public_id', $encounterPublicId))
            ->firstOrFail();
    }
}
