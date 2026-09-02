<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceBill;
use App\Models\FinanceBillVersion;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Finance\FinanceActorPolicy;
use App\Support\Finance\FinanceAuditUnavailable;
use App\Support\Finance\FinanceBillService;
use App\Support\Finance\FinanceCashSettlementProjection;
use App\Support\Finance\FinanceDenied;
use App\Support\Finance\FinanceProjection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class FinanceBillController extends Controller
{
    public function __construct(
        private readonly FinanceActorPolicy $policy,
        private readonly FinanceBillService $service,
        private readonly FinanceProjection $projection,
        private readonly FinanceCashSettlementProjection $settlements,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->policy->view($actor);

        $projection = $this->projection->worklist($actor);

        return Inertia::render('kasir/tagihan/index', [
            'definition_version' => 'CROSS_SETTING_VERSIONED_ENCOUNTER_BILL_V1',
            'generated_at' => now()->isoFormat('D MMM YYYY, HH.mm'),
            'bills' => array_map(fn (array $bill): array => $this->summary($bill), $projection['bills']),
            'synchronization_candidates' => array_map(fn (array $candidate): array => [
                ...$candidate,
                'synchronize_url' => route('finance.sources.synchronize', ['encounter' => $candidate['encounter_public_id']], false),
            ], $projection['synchronization_candidates']),
            'coverage' => $this->coverage(),
            'read_error' => null,
        ]);
    }

    public function show(Request $request, string $encounter): Response
    {
        $actor = $this->actor($request);
        $this->policy->view($actor);
        $bill = $this->billForEncounter($encounter);

        $projection = $this->projection->bill($bill, $actor);
        $settlement = $this->settlements->forBill($bill, $actor);
        $canRequestCorrection = $settlement['settlement'] !== null
            && $settlement['settlement']['correction_request_available']
            && $this->policy->can($actor, Capability::FINANCE_SETTLEMENT_CORRECTION_REQUEST);

        return Inertia::render('kasir/tagihan/show', [
            'definition_version' => 'CROSS_SETTING_VERSIONED_ENCOUNTER_BILL_V1',
            'generated_at' => now()->isoFormat('D MMM YYYY, HH.mm'),
            'bill' => [
                ...$this->summary($projection),
                'current_sources' => array_map(fn (array $source): array => $this->source($source), $projection['sources']),
                'versions' => array_reverse(array_map(fn (array $version): array => [
                    'public_id' => $version['public_id'],
                    'version' => $version['version'],
                    'source_event_count' => $version['source_event_count'],
                    'gross_amount' => $version['gross_amount'],
                    'reversal_amount' => $version['reversal_amount'],
                    'net_amount' => $version['net_amount'],
                    'issue_reason' => $version['issue_reason'],
                    'issued_by_user_id' => $version['issued_by_user_id'],
                    'issued_at' => $version['issued_at'],
                    'coverage_profile' => $version['coverage_profile'],
                    'coverage_label' => $version['coverage_label'],
                    'lines' => array_map(fn (array $line): array => $this->source($line), $version['lines']),
                ], $projection['versions'])),
            ],
            'coverage' => $this->coverage($projection['coverage_profile'], $projection['coverage_label']),
            'settlement' => [
                ...$settlement,
                'settlement' => $settlement['settlement'] === null ? null : [
                    ...$settlement['settlement'],
                    'receipt_url' => route('finance.settlements.receipt', [
                        'settlement' => $settlement['settlement']['public_id'],
                    ], false),
                    'correction_url' => $settlement['settlement']['correction_public_id'] === null
                        ? null
                        : route('finance.settlement-corrections.show', [
                            'correction' => $settlement['settlement']['correction_public_id'],
                        ], false),
                ],
            ],
            'permissions' => [
                'can_issue' => $projection['actions']['can_issue'],
                'can_settle' => $settlement['settlement_available'],
                'can_request_correction' => $canRequestCorrection,
            ],
            'commands' => [
                'issue_url' => $projection['actions']['can_issue']
                    ? route('finance.bills.issue', ['encounter' => $encounter], false)
                    : null,
                'settlement_url' => $settlement['settlement_available']
                    ? route('finance.settlements.store', ['encounter' => $encounter], false)
                    : null,
                'correction_request_url' => $canRequestCorrection
                    ? route('finance.settlement-corrections.store', [
                        'settlement' => $settlement['settlement']['public_id'],
                    ], false)
                    : null,
            ],
            'read_error' => null,
        ]);
    }

    public function issue(Request $request, string $encounter): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->policy->issue($actor);
        $data = $request->validate([
            'expected_fingerprint' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'issue_reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirm_issue' => ['accepted'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:255', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,254}\z/'],
        ]);
        $bill = $this->billForEncounter($encounter);

        try {
            $result = $this->service->issue(
                $bill->public_id,
                $actor,
                $data['expected_fingerprint'],
                $data['issue_reason'],
                $data['idempotency_key'],
            );
        } catch (FinanceDenied $denied) {
            throw ValidationException::withMessages(['finance' => $denied->getMessage()]);
        } catch (FinanceAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Pencatatan audit Kasir belum tersedia.');
        }

        return back()->with(
            'success',
            $result->replayed ? 'Versi tagihan yang sama ditampilkan kembali.' : 'Versi tagihan diterbitkan.',
        );
    }

    public function synchronize(Request $request, string $encounter): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->policy->issue($actor);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:255', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,254}\z/'],
        ]);

        try {
            $result = $this->service->synchronize($encounter, $actor, $data['idempotency_key']);
        } catch (FinanceDenied $denied) {
            throw ValidationException::withMessages(['finance' => $denied->getMessage()]);
        } catch (FinanceAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Pencatatan audit Kasir belum tersedia.');
        }

        return redirect()->route('finance.bills.show', ['encounter' => $encounter])
            ->with('success', $result->replayed
                ? 'Sumber biaya yang sama ditampilkan kembali.'
                : 'Sumber biaya valid disinkronkan. Kesenjangan yang tersisa tetap ditampilkan.');
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

    /** @param array<string, mixed> $bill
     * @return array<string, mixed>
     */
    private function summary(array $bill): array
    {
        return [
            'public_id' => $bill['public_id'],
            'bill_number' => $bill['bill_number'],
            'state' => $bill['state'],
            'current_version' => $bill['current_version'],
            'current_source_event_count' => $bill['current_source_event_count'],
            'pending_source_count' => $bill['pending_source_count'],
            'synchronization_available' => $bill['synchronization_available'],
            'latest_source_at' => $bill['latest_source_at'],
            'source_readiness' => $bill['source_readiness'],
            'fingerprint' => $bill['fingerprint'] ?? '',
            'encounter' => [
                'public_id' => $bill['encounter']['public_id'],
                'encounter_number' => $bill['encounter']['public_id'],
                'care_setting' => $bill['encounter']['care_setting'],
                'service_location' => $bill['encounter']['location_label'],
                'patient' => $bill['patient'],
            ],
            'totals' => $bill['control_totals'],
            'actions' => [
                'show_url' => route('finance.bills.show', ['encounter' => $bill['encounter']['public_id']], false),
                'synchronize_url' => $bill['synchronization_available']
                    ? route('finance.sources.synchronize', ['encounter' => $bill['encounter']['public_id']], false)
                    : null,
            ],
        ];
    }

    /** @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function source(array $source): array
    {
        return [
            'public_id' => $source['public_id'],
            'source_domain' => $source['source_domain'],
            'source_reference' => $source['source_public_id'],
            'event_type' => $source['event_type'],
            'description' => $source['description'],
            'quantity' => $source['quantity'],
            'unit_amount' => $source['unit_amount'],
            'signed_amount' => $source['signed_amount'],
            'occurred_at' => $source['occurred_at'],
            'tariff_provenance' => $source['tariff_provenance'] ?? null,
        ];
    }

    /** @return array{profile:string,label:string,excluded_label:string,domains:list<array{domain:string,label:string}>} */
    private function coverage(
        string $profile = FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1,
        string $label = 'Obat yang diserahkan atau diretur, pemeriksaan radiologi selesai, hasil laboratorium terverifikasi bertarif, dan hari akomodasi rawat inap tertutup',
    ): array {
        $domains = [['domain' => 'PHARMACY', 'label' => 'Apotek']];
        if (in_array($profile, [
            FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_V1,
            FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_V1,
            FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1,
        ], true)) {
            $domains[] = ['domain' => 'RADIOLOGY', 'label' => 'Radiologi'];
        }
        if (in_array($profile, [
            FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_V1,
            FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1,
        ], true)) {
            $domains[] = ['domain' => 'LABORATORY', 'label' => 'Laboratorium'];
        }
        if ($profile === FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1) {
            $domains[] = ['domain' => 'ACCOMMODATION', 'label' => 'Akomodasi rawat inap'];
        }

        return [
            'profile' => $profile,
            'label' => $label,
            'excluded_label' => 'Tindakan lain, pembayaran, dan klaim.',
            'domains' => $domains,
        ];
    }
}
