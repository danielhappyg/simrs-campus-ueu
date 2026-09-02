<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\LaboratoryOrder;
use App\Models\LaboratorySpecimenAttempt;
use App\Models\LabServiceRequest;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Http\InertiaPagination;
use App\Support\Laboratory\LaboratoryActorPolicy;
use App\Support\Laboratory\LaboratoryAuditUnavailable;
use App\Support\Laboratory\LaboratoryDenied;
use App\Support\Laboratory\LaboratoryProjection;
use App\Support\Laboratory\LaboratoryWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class LaboratoryWorkflowController extends Controller
{
    public function __construct(
        private readonly LaboratoryActorPolicy $policy,
        private readonly LaboratoryWorkflowService $workflow,
        private readonly LaboratoryProjection $projection,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $actor = $this->actor($request);
        $canUseWorklist = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_NURSE, Capability::LABORATORY_SPECIMEN_COLLECT)
            || $this->policy->can($actor, RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST, Capability::LABORATORY_SPECIMEN_PROCESS)
            || $this->policy->can($actor, RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST, Capability::LABORATORY_RESULT_WRITE)
            || $this->policy->can($actor, RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER, Capability::LABORATORY_RESULT_VERIFY);

        if (! $canUseWorklist) {
            throw new AuthorizationException;
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'care_setting' => ['nullable', Rule::in(Encounter::CARE_SETTINGS)],
            'state' => ['nullable', Rule::in([
                LaboratoryOrder::ORDERED,
                LaboratoryOrder::SPECIMEN_ACCEPTED,
                LaboratoryOrder::REPORTED_VERIFIED,
                LaboratoryOrder::CANCELLED,
            ])],
            'priority' => ['nullable', Rule::in(['ROUTINE', 'URGENT'])],
        ]);

        $filters = [
            'q' => trim((string) ($validated['q'] ?? '')),
            'care_setting' => (string) ($validated['care_setting'] ?? ''),
            'state' => (string) ($validated['state'] ?? ''),
            'priority' => (string) ($validated['priority'] ?? ''),
        ];

        try {
            $metadataFilters = [...$filters, 'state' => '__EMPTY_WORKLIST_METADATA__'];
            $props = $this->projection->worklist($actor, $metadataFilters);
            $props['filters'] = [
                'q' => $filters['q'],
                'care_setting' => $filters['care_setting'],
                'state' => $filters['state'],
                'priority' => $filters['priority'],
            ];
            [$page, $orders] = $this->paginatedOrders($actor, $filters);
            if ($redirect = InertiaPagination::redirectIfOutOfRange($page, $request)) {
                return $redirect;
            }
            $props['orders'] = $orders;
            $props['pagination'] = InertiaPagination::from($page);
            $props['read_error'] = null;
        } catch (\Throwable $exception) {
            report($exception);
            $props = $this->emptyWorklist($actor, $filters);
            $props['read_error'] = 'Worklist laboratorium belum dapat dimuat.';
        }

        return Inertia::render('pemeriksaan/laboratorium/index', $props);
    }

    public function storeOutpatientOrder(Request $request, string $encounter): RedirectResponse
    {
        return $this->storeOrder($request, $encounter, Encounter::CARE_SETTING_OUTPATIENT);
    }

    public function storeEmergencyOrder(Request $request, string $encounter): RedirectResponse
    {
        return $this->storeOrder($request, $encounter, Encounter::CARE_SETTING_EMERGENCY);
    }

    public function storeInpatientOrder(Request $request, string $encounter): RedirectResponse
    {
        return $this->storeOrder($request, $encounter, Encounter::CARE_SETTING_INPATIENT);
    }

    public function cancel(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'LABORATORY_ORDER_CANCEL', $order, fn () => $this->policy->cancel($actor));
        $payload = $this->validateMutation($request, $actor, 'LABORATORY_ORDER_CANCEL', $order, ['expected_order_version', 'reason_code', 'note', 'idempotency_key'], [
            'expected_order_version' => ['required', 'integer', 'min:1'],
            'reason_code' => ['required', Rule::in(LaboratoryWorkflowService::CANCELLATION_REASONS)],
            'note' => ['nullable', 'string', 'max:500', 'required_if:reason_code,OTHER'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        $this->run(fn () => $this->workflow->cancel($order, $actor, (int) $payload['expected_order_version'], $payload['reason_code'], $payload['note'] ?? null, $payload['idempotency_key']));

        return back()->with('success', 'Permintaan laboratorium dibatalkan.');
    }

    public function collectSpecimen(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'LABORATORY_SPECIMEN_COLLECT', $order, fn () => $this->policy->collect($actor));
        $payload = $this->validateMutation($request, $actor, 'LABORATORY_SPECIMEN_COLLECT', $order, ['expected_order_version', 'note', 'idempotency_key'], [
            'expected_order_version' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        $this->run(fn () => $this->workflow->collectSpecimen($order, $actor, (int) $payload['expected_order_version'], $payload['note'] ?? null, $payload['idempotency_key']));

        return back()->with('success', 'Spesimen laboratorium dicatat terkumpul.');
    }

    public function receiveSpecimen(Request $request, string $attempt): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'LABORATORY_SPECIMEN_RECEIVE', $attempt, fn () => $this->policy->receive($actor));
        $payload = $this->validateMutation($request, $actor, 'LABORATORY_SPECIMEN_RECEIVE', $attempt, ['specimen_public_id', 'idempotency_key'], [
            'specimen_public_id' => ['required', Rule::in([$attempt])],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        $this->run(fn () => $this->workflow->receiveSpecimen($attempt, $actor, $payload['idempotency_key']));

        return back()->with('success', 'Spesimen diterima unit laboratorium.');
    }

    public function acceptSpecimen(Request $request, string $attempt): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'LABORATORY_SPECIMEN_ACCEPT', $attempt, fn () => $this->policy->assess($actor));
        $payload = $this->validateMutation($request, $actor, 'LABORATORY_SPECIMEN_ACCEPT', $attempt, ['expected_order_version', 'idempotency_key'], [
            'expected_order_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        $this->run(fn () => $this->workflow->acceptSpecimen($attempt, $actor, (int) $payload['expected_order_version'], $payload['idempotency_key']));

        return back()->with('success', 'Spesimen dinyatakan layak diperiksa.');
    }

    public function rejectSpecimen(Request $request, string $attempt): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'LABORATORY_SPECIMEN_REJECT', $attempt, fn () => $this->policy->assess($actor));
        $payload = $this->validateMutation($request, $actor, 'LABORATORY_SPECIMEN_REJECT', $attempt, ['expected_order_version', 'reason_code', 'note', 'idempotency_key'], [
            'expected_order_version' => ['required', 'integer', 'min:1'],
            'reason_code' => ['required', Rule::in(LaboratoryWorkflowService::REJECTION_REASONS)],
            'note' => ['nullable', 'string', 'max:500', 'required_if:reason_code,OTHER'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        $this->run(fn () => $this->workflow->rejectSpecimen($attempt, $actor, (int) $payload['expected_order_version'], $payload['reason_code'], $payload['note'] ?? null, $payload['idempotency_key']));

        return back()->with('success', 'Spesimen ditolak dan riwayatnya dipertahankan.');
    }

    public function saveResult(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'LABORATORY_RESULT_DRAFT_SAVE', $order, fn () => $this->policy->write($actor));
        $payload = $this->validateMutation($request, $actor, 'LABORATORY_RESULT_DRAFT_SAVE', $order, ['expected_order_version', 'expected_result_version', 'specimen_public_id', 'results', 'idempotency_key'], [
            'expected_order_version' => ['required', 'integer', 'min:1'],
            'expected_result_version' => ['required', 'integer', 'min:0'],
            'specimen_public_id' => ['required', 'string', 'size:26'],
            ...$this->resultRules(),
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->assertOrderContext($actor, 'LABORATORY_RESULT_DRAFT_SAVE', $order, (int) $payload['expected_order_version'], $payload['specimen_public_id']);

        $this->run(fn () => $this->workflow->saveDraft($order, $actor, (int) $payload['expected_result_version'], $payload['results'], $payload['idempotency_key']));

        return back()->with('success', 'Draft hasil laboratorium disimpan.');
    }

    public function verifyResult(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'LABORATORY_RESULT_VERIFY', $order, fn () => $this->policy->verify($actor));
        $payload = $this->validateMutation($request, $actor, 'LABORATORY_RESULT_VERIFY', $order, ['expected_order_version', 'expected_result_version', 'critical_communication', 'idempotency_key'], [
            'expected_order_version' => ['required', 'integer', 'min:1'],
            'expected_result_version' => ['required', 'integer', 'min:1'],
            'critical_communication' => ['nullable', 'array:recipient_user_public_id,communication_method,outcome,note,communicated_at'],
            'critical_communication.recipient_user_public_id' => ['nullable', 'string', 'size:26'],
            'critical_communication.communication_method' => ['nullable', Rule::in(LaboratoryWorkflowService::COMMUNICATION_METHODS)],
            'critical_communication.outcome' => ['nullable', Rule::in(LaboratoryWorkflowService::COMMUNICATION_OUTCOMES)],
            'critical_communication.note' => ['nullable', 'string', 'max:1000'],
            'critical_communication.communicated_at' => ['nullable', 'date', 'before_or_equal:now'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->assertOrderContext($actor, 'LABORATORY_RESULT_VERIFY', $order, (int) $payload['expected_order_version']);
        $communication = $this->criticalCommunication($actor, 'LABORATORY_RESULT_VERIFY', $order, $payload['critical_communication'] ?? null);

        $this->run(fn () => $this->workflow->verify($order, $actor, (int) $payload['expected_result_version'], $communication, $payload['idempotency_key']));

        return back()->with('success', 'Hasil laboratorium diverifikasi.');
    }

    public function amendResult(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'LABORATORY_RESULT_AMEND_VERIFIED', $order, fn () => $this->policy->verify($actor));
        $payload = $this->validateMutation($request, $actor, 'LABORATORY_RESULT_AMEND_VERIFIED', $order, ['expected_order_version', 'expected_result_version', 'reason_code', 'results', 'critical_communication', 'idempotency_key'], [
            'expected_order_version' => ['required', 'integer', 'min:1'],
            'expected_result_version' => ['required', 'integer', 'min:1'],
            'reason_code' => ['required', Rule::in(LaboratoryWorkflowService::CORRECTION_REASONS)],
            ...$this->resultRules(),
            'critical_communication' => ['nullable', 'array:recipient_user_public_id,communication_method,outcome,note,communicated_at'],
            'critical_communication.recipient_user_public_id' => ['nullable', 'string', 'size:26'],
            'critical_communication.communication_method' => ['nullable', Rule::in(LaboratoryWorkflowService::COMMUNICATION_METHODS)],
            'critical_communication.outcome' => ['nullable', Rule::in(LaboratoryWorkflowService::COMMUNICATION_OUTCOMES)],
            'critical_communication.note' => ['nullable', 'string', 'max:1000'],
            'critical_communication.communicated_at' => ['nullable', 'date', 'before_or_equal:now'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->assertOrderContext($actor, 'LABORATORY_RESULT_AMEND_VERIFIED', $order, (int) $payload['expected_order_version']);
        $communication = $this->criticalCommunication($actor, 'LABORATORY_RESULT_AMEND_VERIFIED', $order, $payload['critical_communication'] ?? null);
        $this->run(fn () => $this->workflow->amendVerified($order, $actor, (int) $payload['expected_result_version'], $payload['reason_code'], $payload['results'], $communication, $payload['idempotency_key']));

        return back()->with('success', 'Amandemen hasil laboratorium ditambahkan.');
    }

    public function acknowledgeResult(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'LABORATORY_RESULT_ACKNOWLEDGE', $order, fn () => $this->policy->acknowledge($actor));
        $payload = $this->validateMutation($request, $actor, 'LABORATORY_RESULT_ACKNOWLEDGE', $order, ['expected_order_version', 'expected_result_version', 'idempotency_key'], [
            'expected_order_version' => ['required', 'integer', 'min:1'],
            'expected_result_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        $this->run(fn () => $this->workflow->acknowledge($order, $actor, (int) $payload['expected_order_version'], (int) $payload['expected_result_version'], $payload['idempotency_key']));

        return back()->with('success', 'Hasil laboratorium ditandai sudah diketahui.');
    }

    private function storeOrder(Request $request, string $encounter, string $careSetting): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'LABORATORY_ORDER_CREATE', $encounter, fn () => $this->policy->order($actor));
        $payload = $this->validateMutation($request, $actor, 'LABORATORY_ORDER_CREATE', $encounter, ['definition_version', 'examination_public_id', 'priority', 'clinical_question', 'idempotency_key'], [
            'definition_version' => ['required', Rule::in(['CROSS_SETTING_LABORATORY_SPECIMEN_RESULT_V1'])],
            'examination_public_id' => ['required', 'string', 'size:26'],
            'priority' => ['required', Rule::in(['ROUTINE', 'URGENT'])],
            'clinical_question' => ['required', 'string', 'min:3', 'max:2000'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        $resolved = Encounter::query()->where('public_id', $encounter)->first();
        if (! $resolved instanceof Encounter || $resolved->care_setting !== $careSetting) {
            $this->recordDenial($actor, 'LABORATORY_ORDER_CREATE', $encounter, 'resource_not_found');
            abort(404);
        }

        $this->run(fn () => $this->workflow->createOrder($encounter, $payload['examination_public_id'], $actor, $payload['priority'], $payload['clinical_question'], $payload['idempotency_key']));

        return back()->with('success', 'Permintaan laboratorium disimpan.');
    }

    /** @return array<string, array<int, mixed>> */
    private function resultRules(): array
    {
        return [
            'results' => ['required', 'array', 'min:1', 'max:12'],
            'results.*' => ['required', 'array:code,value,interpretation,note'],
            'results.*.code' => ['required', 'string', 'min:2', 'max:64'],
            'results.*.value' => ['required', 'string', 'max:2000'],
            'results.*.interpretation' => ['required', Rule::in(['NORMAL', 'ABNORMAL', 'CRITICAL'])],
            'results.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function assertOrderContext(User $actor, string $operation, string $publicId, int $expectedVersion, ?string $specimenPublicId = null): LaboratoryOrder
    {
        $order = LaboratoryOrder::query()->where('public_id', $publicId)->first();
        if (! $order instanceof LaboratoryOrder) {
            $this->recordDenial($actor, $operation, $publicId, 'resource_not_found');
            abort(404);
        }
        if ($order->version !== $expectedVersion) {
            $this->denyValidation($actor, $operation, $publicId, 'stale_version', 'Versi pesanan berubah.');
        }
        if ($specimenPublicId !== null && ! $order->specimenAttempts()->where('public_id', $specimenPublicId)->where('state', LaboratorySpecimenAttempt::ACCEPTED)->exists()) {
            $this->denyValidation($actor, $operation, $publicId, 'accepted_specimen_required', 'Spesimen diterima tidak sesuai.');
        }

        return $order;
    }

    /** @param array<string, mixed>|null $input
     * @return array<string, mixed>|null
     */
    private function criticalCommunication(User $actor, string $operation, string $order, ?array $input): ?array
    {
        if ($input === null || collect($input)->every(fn ($value): bool => trim((string) ($value ?? '')) === '')) {
            return null;
        }

        foreach (['recipient_user_public_id', 'communication_method', 'outcome', 'communicated_at'] as $field) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                $this->recordDenial($actor, $operation, $order, 'validation_failed');
                throw ValidationException::withMessages(["critical_communication.{$field}" => 'Data komunikasi kritis wajib dilengkapi.']);
            }
        }

        return $input;
    }

    /** @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private function emptyWorklist(User $actor, array $filters): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'care_setting' => $filters['care_setting'] ?? '',
                'state' => $filters['state'] ?? '',
                'priority' => $filters['priority'] ?? '',
            ],
            'filter_options' => [
                'care_settings' => $this->options(Encounter::CARE_SETTINGS),
                'states' => $this->options([
                    LaboratoryOrder::ORDERED,
                    LaboratoryOrder::SPECIMEN_ACCEPTED,
                    LaboratoryOrder::REPORTED_VERIFIED,
                    LaboratoryOrder::CANCELLED,
                ]),
                'priorities' => $this->options(['ROUTINE', 'URGENT']),
            ],
            'orders' => [],
            'permissions' => [
                'can_collect' => $this->policy->can($actor, RoleCapabilityMatrix::ROLE_NURSE, Capability::LABORATORY_SPECIMEN_COLLECT),
                'can_process_specimen' => $this->policy->can($actor, RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST, Capability::LABORATORY_SPECIMEN_PROCESS),
                'can_save_result' => $this->policy->can($actor, RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST, Capability::LABORATORY_RESULT_WRITE),
                'can_verify_result' => $this->policy->can($actor, RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER, Capability::LABORATORY_RESULT_VERIFY),
            ],
            'rejection_reason_options' => $this->options(LaboratoryWorkflowService::REJECTION_REASONS),
            'interpretation_options' => $this->options(['NORMAL', 'ABNORMAL', 'CRITICAL']),
            'communication_method_options' => $this->options(LaboratoryWorkflowService::COMMUNICATION_METHODS),
            'communication_outcome_options' => $this->options(LaboratoryWorkflowService::COMMUNICATION_OUTCOMES),
            'critical_communication_recipient_options' => [],
            'amendment_reason_options' => $this->options(LaboratoryWorkflowService::CORRECTION_REASONS),
        ];
    }

    /** @param array<string, string> $filters
     * @return array{LengthAwarePaginator<int, object>, list<array<string, mixed>>}
     */
    private function paginatedOrders(User $actor, array $filters): array
    {
        $governed = LaboratoryOrder::query()
            ->whereHas('encounter.patient', fn ($patient) => $patient->where('is_synthetic', true))
            ->when(($filters['care_setting'] ?? '') !== '', fn ($builder) => $builder->where('care_setting', $filters['care_setting']))
            ->when(($filters['state'] ?? '') !== '', fn ($builder) => $builder->where('status', $filters['state']))
            ->when(($filters['priority'] ?? '') !== '', fn ($builder) => $builder->where('priority', $filters['priority']));
        $legacy = LabServiceRequest::query()
            ->syntheticOnly()
            ->where('status', LabServiceRequest::STATUS_ACTIVE)
            ->whereHas('encounter', fn ($encounter) => $encounter->whereIn('status', Encounter::ACTIVE_STATUSES))
            ->when(
                ($filters['care_setting'] ?? '') !== '',
                fn ($builder) => $builder->whereHas('encounter', fn ($encounter) => $encounter->where('care_setting', $filters['care_setting'])),
            );
        if (($filters['state'] ?? '') !== '' && $filters['state'] !== LaboratoryOrder::ORDERED) {
            $legacy->whereRaw('1 = 0');
        }
        if (($filters['priority'] ?? '') === 'URGENT') {
            $legacy->whereRaw('1 = 0');
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $governed->where(function ($builder) use ($like, $q): void {
                $builder->where('master_code', $like, '%'.$q.'%')
                    ->orWhere('master_display_name', $like, '%'.$q.'%')
                    ->orWhere('encounter_number_snapshot', $like, '%'.$q.'%')
                    ->orWhereHas('encounter.patient', function ($patient) use ($like, $q): void {
                        $patient->where('full_name', $like, '%'.$q.'%')
                            ->orWhere('medical_record_number', $like, '%'.$q.'%');
                    });
            });
            $legacy->where(function ($builder) use ($like, $q): void {
                $builder->where('test_code', $like, '%'.$q.'%')
                    ->orWhere('test_label', $like, '%'.$q.'%')
                    ->orWhereHas('encounter.patient', function ($patient) use ($like, $q): void {
                        $patient->where('full_name', $like, '%'.$q.'%')
                            ->orWhere('medical_record_number', $like, '%'.$q.'%');
                    });
            });
        }

        $governed = $governed->selectRaw("public_id, ordered_at AS sort_at, 'GOVERNED' AS source");
        $legacy = $legacy->selectRaw("public_id, requested_at AS sort_at, 'LEGACY_READ_ONLY' AS source");
        $page = DB::query()
            ->fromSub($governed->toBase()->unionAll($legacy->toBase()), 'laboratory_worklist')
            ->orderByDesc('sort_at')
            ->orderByDesc('public_id')
            ->paginate(100)
            ->withQueryString();

        $rows = collect($page->items());
        $governedIds = $rows->where('source', 'GOVERNED')->pluck('public_id');
        $legacyIds = $rows->where('source', 'LEGACY_READ_ONLY')->pluck('public_id');
        $governedOrders = LaboratoryOrder::query()
            ->whereIn('public_id', $governedIds)
            ->with([
                'encounter.patient', 'encounter.cancellation', 'master', 'orderingPhysician', 'cancellation',
                'specimenAttempts.collector', 'specimenAttempts.events.actor',
                'resultVersions.author', 'resultVersions.acknowledgement.actor',
                'resultVersions.criticalCommunication.recipient',
            ])->get()->keyBy('public_id');
        $legacyOrders = LabServiceRequest::query()->whereIn('public_id', $legacyIds)->with(['encounter.patient', 'requestedBy'])->get()->keyBy('public_id');
        $orders = array_values($rows->map(function ($row) use ($actor, $governedOrders, $legacyOrders): ?array {
            if ($row->source === 'GOVERNED') {
                $order = $governedOrders->get($row->public_id);

                return $order instanceof LaboratoryOrder ? $this->projection->order($order, $actor) : null;
            }
            $order = $legacyOrders->get($row->public_id);

            return $order instanceof LabServiceRequest ? $this->legacyOrder($order) : null;
        })->filter()->all());

        return [$page, $orders];
    }

    /** @return array<string, mixed> */
    private function legacyOrder(LabServiceRequest $order): array
    {
        $encounter = $order->encounter;

        return [
            'source' => 'LEGACY_READ_ONLY',
            'public_id' => $order->public_id,
            'version' => 1,
            'state' => LaboratoryOrder::ORDERED,
            'priority' => 'ROUTINE',
            'ordered_at' => $order->requested_at->toIso8601String(),
            'ordering_physician_name' => $order->requestedBy->name,
            'ordering_physician_public_id' => $order->requestedBy->public_id,
            'care_setting' => $encounter->care_setting,
            'care_location_label' => $encounter->ward_name ?: ($encounter->clinic_name ?: 'Lokasi tidak tersedia'),
            'encounter_number' => $encounter->public_id,
            'encounter_url' => $this->encounterUrl($encounter),
            'patient' => [
                'medical_record_number' => $encounter->patient->medical_record_number,
                'display_name' => $encounter->patient->full_name,
            ],
            'examination' => [
                'public_id' => '',
                'code' => $order->test_code,
                'display_name' => $order->test_label,
                'specimen_type' => 'Arsip laboratorium',
                'collection_instruction' => null,
                'components' => [],
            ],
            'clinical_question' => $order->clinical_question ?? '',
            'cancellation' => null,
            'specimens' => [],
            'accepted_specimen_public_id' => null,
            'result' => null,
            'actions' => [
                'cancel_url' => null,
                'collect_url' => null,
                'receive_url' => null,
                'accept_url' => null,
                'reject_url' => null,
                'save_result_url' => null,
                'verify_result_url' => null,
                'amend_result_url' => null,
                'acknowledge_url' => null,
            ],
        ];
    }

    private function encounterUrl(?Encounter $encounter): string
    {
        if (! $encounter) {
            return '#';
        }

        return route(match ($encounter->care_setting) {
            Encounter::CARE_SETTING_OUTPATIENT => 'pemeriksaan.rawat-jalan.show',
            Encounter::CARE_SETTING_EMERGENCY => 'pemeriksaan.igd.show',
            Encounter::CARE_SETTING_INPATIENT => 'pemeriksaan.rawat-inap.show',
            default => throw new \LogicException('Pengaturan layanan pertemuan tidak didukung.'),
        }, $encounter);
    }

    /**
     * @param  list<string>  $values
     * @return array<int, array{value: string, label: string}>
     */
    private function options(array $values): array
    {
        return array_map(
            fn (string $value): array => [
                'value' => $value,
                'label' => str($value)->replace('_', ' ')->lower()->title()->toString(),
            ],
            $values,
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }

    /** @param list<string> $keys */
    private function assertOnlyKeys(Request $request, array $keys): void
    {
        if (array_diff(array_keys($request->all()), $keys) !== []) {
            throw ValidationException::withMessages(['request' => 'Permintaan memuat bidang yang tidak didukung.']);
        }
    }

    /** @param list<string> $keys
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>
     */
    private function validateMutation(Request $request, User $actor, string $operation, ?string $resource, array $keys, array $rules): array
    {
        try {
            $this->assertOnlyKeys($request, $keys);

            return $request->validate($rules);
        } catch (ValidationException $denial) {
            $this->recordDenial($actor, $operation, $resource, 'validation_failed');
            throw $denial;
        }
    }

    private function authorizeMutation(User $actor, string $operation, ?string $resource, callable $authorization): void
    {
        try {
            $authorization();
        } catch (AuthorizationException $denial) {
            $this->recordDenial($actor, $operation, $resource, 'role_not_permitted');
            throw $denial;
        }
    }

    private function denyValidation(User $actor, string $operation, ?string $resource, string $reason, string $message): never
    {
        $this->recordDenial($actor, $operation, $resource, $reason);
        throw ValidationException::withMessages(['laboratory' => $message]);
    }

    private function recordDenial(User $actor, string $operation, ?string $resource, string $reason): void
    {
        $resource = is_string($resource) && Str::isUlid($resource) ? $resource : null;
        if ($this->audit->record('laboratory.workflow.mutate', 'laboratory_record', $resource, $actor, 'DENIED', $reason, ['operation' => $operation]) === null) {
            throw new LaboratoryAuditUnavailable('Audit penolakan laboratorium gagal.');
        }
    }

    /** @return array<int, string> */
    private function idempotencyRules(): array
    {
        return ['required', 'string', 'min:8', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]+\z/'];
    }

    private function run(callable $operation): void
    {
        try {
            $operation();
        } catch (LaboratoryDenied $denial) {
            throw ValidationException::withMessages(['laboratory' => $denial->getMessage()]);
        }
    }
}
