<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceCashDepositHandoff;
use App\Models\FinanceCashierCollectionActiveSlot;
use App\Models\FinanceCashierCollectionBatch;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceAuditUnavailable;
use App\Support\Finance\FinanceCashierCollectionActorPolicy;
use App\Support\Finance\FinanceCashierCollectionProjection;
use App\Support\Finance\FinanceCashierCollectionService;
use App\Support\Finance\FinanceDenied;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class FinanceCashierCollectionController extends Controller
{
    private const DEFINITION_VERSION = 'APPEND_ONLY_CASHIER_COLLECTION_BATCH_V1';

    public function __construct(
        private readonly FinanceCashierCollectionActorPolicy $policy,
        private readonly FinanceCashierCollectionService $service,
        private readonly FinanceCashierCollectionProjection $projection,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->policy->view($actor);

        try {
            $batches = array_map(fn (array $batch): array => $this->linkBatch($batch), $this->projection->worklist($actor));
        } catch (FinanceDenied $denied) {
            report($denied);
            abort(503, 'Daftar batch penerimaan kas belum dapat direkonsiliasi.');
        }

        $isCashier = $this->role($actor) === RoleCapabilityMatrix::ROLE_CASHIER;
        $hasActiveBatch = $isCashier && FinanceCashierCollectionActiveSlot::query()->whereKey($actor->id)->exists();

        return Inertia::render('kasir/batch-penerimaan-kas/index', [
            'definition_version' => self::DEFINITION_VERSION,
            'generated_at' => now()->isoFormat('D MMM YYYY, HH.mm'),
            'actor_role' => $this->role($actor),
            'batches' => $batches,
            'open_batch' => $this->action(
                $isCashier && ! $hasActiveBatch,
                route('finance.cashier-collections.open', absolute: false),
                $isCashier
                    ? 'Kasir sudah memiliki satu batch penerimaan kas aktif.'
                    : 'Hanya kasir yang dapat membuka batch penerimaan kas.',
            ),
            'read_error' => null,
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->policy->open($actor);
        $data = $request->validate([
            'confirm_open' => ['accepted'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        try {
            $result = $this->service->open($actor, $data['idempotency_key']);
        } catch (FinanceDenied $denied) {
            $this->raiseMutationDenial($denied);
        } catch (FinanceAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Pencatatan audit pembukaan batch belum tersedia.');
        }

        /** @var FinanceCashierCollectionBatch $batch */
        $batch = $result->record;
        $batchPublicId = (string) $batch->getAttribute('public_id');

        return redirect()->route('finance.cashier-collections.show', ['batch' => $batchPublicId])
            ->with('success', $result->replayed
                ? 'Batch penerimaan kas yang sama ditampilkan kembali.'
                : 'Batch penerimaan kas dibuka.');
    }

    public function show(Request $request, string $batch): Response
    {
        $actor = $this->actor($request);
        $this->policy->view($actor);
        $record = $this->ownedBatchQuery($actor)->where('public_id', $batch)->firstOrFail();

        try {
            $projection = $this->linkBatch($this->projection->batch($batch, $actor));
        } catch (FinanceDenied $denied) {
            report($denied);
            abort(503, 'Batch penerimaan kas belum dapat direkonsiliasi.');
        }

        $role = $this->role($actor);
        $ownerId = (int) $record->getAttribute('cashier_user_id');
        $isOwner = $role === RoleCapabilityMatrix::ROLE_CASHIER && $ownerId === $actor->id;
        $isSupervisor = $role === RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR;
        $integrityOk = ($projection['integrity']['status'] ?? null) === 'OK';

        return Inertia::render('kasir/batch-penerimaan-kas/show', [
            'definition_version' => self::DEFINITION_VERSION,
            'generated_at' => now()->isoFormat('D MMM YYYY, HH.mm'),
            'actor_role' => $role,
            'batch' => $projection,
            'actions' => [
                'request_close' => $this->action(
                    $integrityOk && $isOwner && $projection['state'] === 'OPEN',
                    route('finance.cashier-collections.close', ['batch' => $batch], false),
                    $isOwner ? 'Batch tidak lagi terbuka.' : 'Hanya kasir pemilik batch yang dapat mengajukan tutup batch.',
                ),
                'recount' => $this->action(
                    $integrityOk && $isOwner && $projection['state'] === 'RECOUNT_REQUIRED',
                    route('finance.cashier-collections.recount', ['batch' => $batch], false),
                    $isOwner ? 'Batch belum memerlukan hitung ulang.' : 'Hanya kasir pemilik batch yang dapat mencatat hitung ulang.',
                ),
                'verify' => $this->action(
                    $integrityOk && $isSupervisor && $ownerId !== $actor->id && $projection['state'] === 'AWAITING_SUPERVISOR',
                    route('finance.cashier-collections.verify', ['batch' => $batch], false),
                    $isSupervisor ? 'Batch belum siap diverifikasi dengan selisih nol.' : 'Hanya supervisor kasir yang dapat memverifikasi tutup batch.',
                ),
                'create_handoff' => $this->action(
                    $integrityOk && $isOwner && $projection['state'] === 'VERIFIED',
                    route('finance.cashier-collections.handoff', ['batch' => $batch], false),
                    $isOwner ? 'Batch belum diverifikasi atau penyerahan sudah dicatat.' : 'Hanya kasir pemilik batch yang dapat menyerahkan setoran.',
                ),
            ],
            'back_url' => route('finance.cashier-collections.index', absolute: false),
            'read_error' => null,
        ]);
    }

    public function requestClose(Request $request, string $batch): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->policy->closeRequest($actor);
        $data = $request->validate([
            'counted_amount' => ['required', 'integer', 'min:0'],
            'expected_state_fingerprint' => $this->digestRules(),
            'confirm_close' => ['accepted'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        try {
            $result = $this->service->requestClose(
                $batch,
                $actor,
                (int) $data['counted_amount'],
                $data['expected_state_fingerprint'],
                $data['idempotency_key'],
            );
        } catch (FinanceDenied $denied) {
            $this->raiseMutationDenial($denied);
        } catch (FinanceAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Pencatatan audit tutup batch belum tersedia.');
        }

        return back()->with('success', $result->replayed
            ? 'Pengajuan tutup batch yang sama ditampilkan kembali.'
            : 'Keanggotaan batch dibekukan dan pengajuan tutup batch dicatat.');
    }

    public function recount(Request $request, string $batch): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->policy->recount($actor);
        $data = $request->validate([
            'counted_amount' => ['required', 'integer', 'min:0'],
            'explanation' => ['required', 'string', 'min:8', 'max:500'],
            'expected_state_fingerprint' => $this->digestRules(),
            'confirm_recount' => ['accepted'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        try {
            $result = $this->service->recount(
                $batch,
                $actor,
                (int) $data['counted_amount'],
                $data['expected_state_fingerprint'],
                $data['idempotency_key'],
                $data['explanation'],
            );
        } catch (FinanceDenied $denied) {
            $this->raiseMutationDenial($denied);
        } catch (FinanceAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Pencatatan audit hitung ulang belum tersedia.');
        }

        return back()->with('success', $result->replayed
            ? 'Hasil hitung ulang yang sama ditampilkan kembali.'
            : 'Hasil hitung ulang dicatat sebagai bukti baru.');
    }

    public function verify(Request $request, string $batch): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->policy->verify($actor);
        $data = $request->validate([
            'expected_state_fingerprint' => $this->digestRules(),
            'confirm_action' => ['accepted'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        try {
            $result = $this->service->verify(
                $batch,
                $actor,
                $data['expected_state_fingerprint'],
                $data['idempotency_key'],
            );
        } catch (FinanceDenied $denied) {
            $this->raiseMutationDenial($denied);
        } catch (FinanceAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Pencatatan audit verifikasi batch belum tersedia.');
        }

        return back()->with('success', $result->replayed
            ? 'Verifikasi tutup batch yang sama ditampilkan kembali.'
            : 'Tutup batch diverifikasi dengan selisih nol.');
    }

    public function handoff(Request $request, string $batch): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->policy->createHandoff($actor);
        $data = $request->validate([
            'expected_state_fingerprint' => $this->digestRules(),
            'confirm_action' => ['accepted'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        try {
            $result = $this->service->createHandoff(
                $batch,
                $actor,
                $data['expected_state_fingerprint'],
                $data['idempotency_key'],
            );
        } catch (FinanceDenied $denied) {
            $this->raiseMutationDenial($denied);
        } catch (FinanceAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Pencatatan audit penyerahan setoran belum tersedia.');
        }

        /** @var FinanceCashDepositHandoff $handoff */
        $handoff = $result->record;
        $handoffPublicId = (string) $handoff->getAttribute('public_id');

        return redirect()->route('finance.cashier-collections.handoff-receipt', ['handoff' => $handoffPublicId])
            ->with('success', $result->replayed
                ? 'Bukti penyerahan setoran yang sama ditampilkan kembali.'
                : 'Penyerahan setoran internal dicatat.');
    }

    public function handoffReceipt(Request $request, string $handoff): Response
    {
        $actor = $this->actor($request);
        $this->policy->viewHandoff($actor);

        try {
            $receipt = $this->projection->handoffReceipt($handoff, $actor);
        } catch (FinanceDenied $denied) {
            report($denied);
            abort(503, 'Bukti penyerahan setoran belum dapat direkonsiliasi.');
        }

        return Inertia::render('kasir/batch-penerimaan-kas/bukti-penyerahan', [
            'definition_version' => self::DEFINITION_VERSION,
            'generated_at' => now()->isoFormat('D MMM YYYY, HH.mm'),
            'receipt' => $receipt,
            'back_url' => route('finance.cashier-collections.show', ['batch' => $receipt['batch_public_id']], false),
        ]);
    }

    /** @return Builder<FinanceCashierCollectionBatch> */
    private function ownedBatchQuery(User $actor): Builder
    {
        $query = FinanceCashierCollectionBatch::query();
        if ($this->role($actor) === RoleCapabilityMatrix::ROLE_CASHIER) {
            $query->where('cashier_user_id', $actor->id);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $batch
     * @return array<string, mixed>
     */
    private function linkBatch(array $batch): array
    {
        $batch['show_url'] = route('finance.cashier-collections.show', ['batch' => $batch['public_id']], false);
        if (is_array($batch['handoff'] ?? null)) {
            $batch['handoff']['receipt_url'] = route('finance.cashier-collections.handoff-receipt', [
                'handoff' => $batch['handoff']['public_id'],
            ], false);
        }

        return $batch;
    }

    /** @return array{allowed: bool, url: string|null, denial_reason: string|null} */
    private function action(bool $allowed, string $url, string $denial): array
    {
        return [
            'allowed' => $allowed,
            'url' => $allowed ? $url : null,
            'denial_reason' => $allowed ? null : $denial,
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
            'batch_integrity_failure',
            'settlement_integrity_failure',
            'correction_integrity_failure',
            'receipt_corrupt',
        ], true)) {
            report($denied);
            abort(503, 'Bukti batch penerimaan kas belum dapat direkonsiliasi.');
        }

        throw ValidationException::withMessages(['collection' => $denied->getMessage()]);
    }

    private function role(User $actor): string
    {
        return $actor->roleSlugs()[0];
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
