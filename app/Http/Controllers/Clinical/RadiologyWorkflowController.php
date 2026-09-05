<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\RadiologyOrder;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Radiology\RadiologyActorPolicy;
use App\Support\Radiology\RadiologyAuditUnavailable;
use App\Support\Radiology\RadiologyDenied;
use App\Support\Radiology\RadiologyProjection;
use App\Support\Radiology\RadiologyWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class RadiologyWorkflowController extends Controller
{
    public function __construct(
        private readonly RadiologyActorPolicy $policy,
        private readonly RadiologyWorkflowService $workflow,
        private readonly RadiologyProjection $projection,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $canPerform = $this->policy->can(
            $actor,
            RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST,
            Capability::RADIOLOGY_WORKLIST_PERFORM,
        );
        $canReport = $this->policy->can(
            $actor,
            RoleCapabilityMatrix::ROLE_RADIOLOGIST,
            Capability::RADIOLOGY_REPORT_WRITE,
        );
        if (! $canPerform && ! $canReport) {
            throw new AuthorizationException;
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'care_setting' => ['nullable', Rule::in(Encounter::CARE_SETTINGS)],
            'state' => ['nullable', Rule::in([
                RadiologyOrder::ORDERED,
                RadiologyOrder::PERFORMED,
                RadiologyOrder::REPORTED_VERIFIED,
                RadiologyOrder::CANCELLED,
            ])],
        ]);
        $q = trim((string) ($filters['q'] ?? ''));
        $careSetting = (string) ($filters['care_setting'] ?? '');
        $state = (string) ($filters['state'] ?? '');
        $orders = [];
        $readError = null;

        try {
            $query = RadiologyOrder::query()
                ->whereHas('encounter.patient', fn ($patient) => $patient->where('is_synthetic', true))
                ->when(
                    $canPerform,
                    fn ($builder) => $builder->where('status', RadiologyOrder::ORDERED),
                    fn ($builder) => $builder->whereIn('status', [
                        RadiologyOrder::PERFORMED,
                        RadiologyOrder::REPORTED_VERIFIED,
                    ]),
                )
                ->when($careSetting !== '', fn ($builder) => $builder->where('care_setting', $careSetting))
                ->when(
                    $state !== '',
                    fn ($builder) => $builder->where('status', $state),
                    fn ($builder) => $builder->whereNot('status', RadiologyOrder::CANCELLED),
                );

            if ($q !== '') {
                $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $query->where(function ($builder) use ($like, $q): void {
                    $builder->where('master_code', $like, '%'.$q.'%')
                        ->orWhere('master_display_name', $like, '%'.$q.'%')
                        ->orWhereHas('encounter.patient', function ($patient) use ($like, $q): void {
                            $patient->where('full_name', $like, '%'.$q.'%')
                                ->orWhere('medical_record_number', $like, '%'.$q.'%');
                        });
                });
            }

            $orders = $query
                ->orderByRaw("CASE status WHEN 'ORDERED' THEN 1 WHEN 'PERFORMED' THEN 2 ELSE 3 END")
                ->orderBy('ordered_at')
                ->orderBy('id')
                ->limit(100)
                ->get()
                ->map(fn (RadiologyOrder $order): array => $this->projection->order($order, $actor))
                ->all();
        } catch (\Throwable $exception) {
            report($exception);
            $readError = 'The radiology worklist could not be loaded.';
        }

        return Inertia::render('pemeriksaan/radiologi/index', [
            'generated_at' => now()->toIso8601String(),
            'filters' => ['q' => $q, 'care_setting' => $careSetting, 'state' => $state],
            'filter_options' => [
                'care_settings' => $this->options([
                    Encounter::CARE_SETTING_OUTPATIENT => 'Rawat jalan',
                    Encounter::CARE_SETTING_EMERGENCY => 'IGD',
                    Encounter::CARE_SETTING_INPATIENT => 'Rawat inap',
                ]),
                'states' => $canPerform
                    ? $this->options([RadiologyOrder::ORDERED => 'Menunggu pemeriksaan'])
                    : $this->options([
                        RadiologyOrder::PERFORMED => 'Sudah diperiksa',
                        RadiologyOrder::REPORTED_VERIFIED => 'Laporan terverifikasi',
                    ]),
            ],
            'orders' => $orders,
            'permissions' => ['can_perform' => $canPerform, 'can_report' => $canReport],
            'amendment_reason_options' => $this->options([
                'TYPOGRAPHICAL_CORRECTION' => 'Koreksi penulisan',
                'CLINICAL_CLARIFICATION' => 'Klarifikasi klinis',
                'ADDITIONAL_FINDING' => 'Temuan tambahan',
                'OTHER' => 'Lainnya',
            ]),
            'read_error' => $readError,
        ]);
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
        $this->authorizeMutation($actor, 'RADIOLOGY_ORDER_CANCEL', $order, fn () => $this->policy->cancel($actor));
        $payload = $this->validateMutation($request, $actor, 'RADIOLOGY_ORDER_CANCEL', $order, ['expected_version', 'reason_code', 'note', 'idempotency_key'], [
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason_code' => ['required', Rule::in(RadiologyWorkflowService::CANCELLATION_REASONS)],
            'note' => ['nullable', 'string', 'max:500', 'required_if:reason_code,OTHER'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        $this->run(fn () => $this->workflow->cancel(
            $order,
            $actor,
            (int) $payload['expected_version'],
            $payload['reason_code'],
            $payload['note'] ?? null,
            $payload['idempotency_key'],
        ));

        return back()->with('success', 'Permintaan radiologi dibatalkan.');
    }

    public function perform(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'RADIOLOGY_ORDER_PERFORM', $order, fn () => $this->policy->perform($actor));
        $payload = $this->validateMutation($request, $actor, 'RADIOLOGY_ORDER_PERFORM', $order, ['expected_version', 'idempotency_key'], [
            'expected_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->run(fn () => $this->workflow->perform(
            $order,
            $actor,
            (int) $payload['expected_version'],
            $payload['idempotency_key'],
        ));

        return back()->with('success', 'Pemeriksaan radiologi dicatat selesai.');
    }

    public function saveReport(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'RADIOLOGY_REPORT_DRAFT_SAVE', $order, fn () => $this->policy->write($actor));
        $payload = $this->validateMutation($request, $actor, 'RADIOLOGY_REPORT_DRAFT_SAVE', $order, ['expected_version', 'fields', 'idempotency_key'], [
            'expected_version' => ['required', 'integer', 'min:0'],
            'fields' => ['required', 'array:findings,impression,recommendation'],
            'fields.findings' => ['nullable', 'string', 'max:10000'],
            'fields.impression' => ['nullable', 'string', 'max:10000'],
            'fields.recommendation' => ['nullable', 'string', 'max:10000'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->run(fn () => $this->workflow->saveDraft(
            $order,
            $actor,
            (int) $payload['expected_version'],
            $payload['fields']['findings'] ?? null,
            $payload['fields']['impression'] ?? null,
            $payload['fields']['recommendation'] ?? null,
            $payload['idempotency_key'],
        ));

        return back()->with('success', 'Draft laporan radiologi disimpan.');
    }

    public function verifyReport(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'RADIOLOGY_REPORT_VERIFY', $order, fn () => $this->policy->verify($actor));
        $payload = $this->validateMutation($request, $actor, 'RADIOLOGY_REPORT_VERIFY', $order, ['expected_version', 'idempotency_key'], [
            'expected_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->run(fn () => $this->workflow->verify(
            $order,
            $actor,
            (int) $payload['expected_version'],
            $payload['idempotency_key'],
        ));

        return back()->with('success', 'Laporan radiologi diverifikasi.');
    }

    public function amendReport(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'RADIOLOGY_REPORT_AMEND_VERIFIED', $order, fn () => $this->policy->verify($actor));
        $payload = $this->validateMutation($request, $actor, 'RADIOLOGY_REPORT_AMEND_VERIFIED', $order, ['expected_report_version', 'reason', 'amended_statement', 'idempotency_key'], [
            'expected_report_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in(RadiologyWorkflowService::AMENDMENT_REASONS)],
            'amended_statement' => ['required', 'string', 'min:5', 'max:10000'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->run(fn () => $this->workflow->amendVerified(
            $order,
            $actor,
            (int) $payload['expected_report_version'],
            $payload['reason'],
            $payload['amended_statement'],
            $payload['idempotency_key'],
        ));

        return back()->with('success', 'Adendum laporan radiologi ditambahkan.');
    }

    public function acknowledgeReport(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'RADIOLOGY_REPORT_ACKNOWLEDGE', $order, fn () => $this->policy->acknowledge($actor));
        $payload = $this->validateMutation($request, $actor, 'RADIOLOGY_REPORT_ACKNOWLEDGE', $order, ['expected_order_version', 'expected_report_version', 'idempotency_key'], [
            'expected_order_version' => ['required', 'integer', 'min:1'],
            'expected_report_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->run(fn () => $this->workflow->acknowledge(
            $order,
            $actor,
            (int) $payload['expected_order_version'],
            (int) $payload['expected_report_version'],
            $payload['idempotency_key'],
        ));

        return back()->with('success', 'Laporan radiologi ditandai sudah diketahui.');
    }

    private function storeOrder(Request $request, string $encounter, string $careSetting): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'RADIOLOGY_ORDER_CREATE', $encounter, fn () => $this->policy->order($actor));
        $payload = $this->validateMutation($request, $actor, 'RADIOLOGY_ORDER_CREATE', $encounter, ['definition_version', 'examination_public_id', 'clinical_question', 'idempotency_key'], [
            'definition_version' => ['required', Rule::in(['CROSS_SETTING_RADIOLOGY_ORDER_REPORT_V1'])],
            'examination_public_id' => ['required', 'string', 'size:26'],
            'clinical_question' => ['required', 'string', 'min:5', 'max:2000'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        $resolved = Encounter::query()->where('public_id', $encounter)->first();
        if (! $resolved instanceof Encounter || $resolved->care_setting !== $careSetting) {
            $this->recordDenial($actor, 'RADIOLOGY_ORDER_CREATE', $encounter, 'resource_not_found');
            abort(404);
        }
        $this->run(fn () => $this->workflow->createOrder(
            $encounter,
            $payload['examination_public_id'],
            $actor,
            $payload['clinical_question'],
            $payload['idempotency_key'],
        ));

        return back()->with('success', 'Permintaan radiologi disimpan.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }

    /** @param list<string> $keys */
    private function assertOnlyKeys(Request $request, array $keys): void
    {
        $unexpected = array_diff(array_keys($request->all()), $keys);
        if ($unexpected !== []) {
            throw ValidationException::withMessages(['request' => 'The request contains unsupported fields.']);
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

    private function recordDenial(User $actor, string $operation, ?string $resource, string $reason): void
    {
        $resource = is_string($resource) && Str::isUlid($resource) ? $resource : null;
        if ($this->audit->record('radiology.workflow.mutate', 'radiology_record', $resource, $actor, 'DENIED', $reason, ['operation' => $operation]) === null) {
            throw new RadiologyAuditUnavailable('Audit penolakan radiologi gagal.');
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
        } catch (RadiologyDenied $denial) {
            throw ValidationException::withMessages(['radiology' => __($denial->getMessage())]);
        }
    }

    /**
     * @param  array<string, string>  $values
     * @return list<array{value:string,label:string}>
     */
    private function options(array $values): array
    {
        return array_values(collect($values)
            ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
            ->values()
            ->all());
    }
}
