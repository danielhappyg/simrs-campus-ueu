<?php

namespace App\Http\Controllers\Inpatient;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Http\RequestCorrelation;
use App\Support\Inpatient\InpatientMasterActorPolicy;
use App\Support\Inpatient\InpatientMasterAuditUnavailable;
use App\Support\Inpatient\InpatientMasterDenied;
use App\Support\Inpatient\InpatientMasterService;
use App\Support\Inpatient\InpatientOccupancyProjection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class InpatientWardBedMasterController extends Controller
{
    public function __construct(
        private readonly InpatientMasterActorPolicy $actorPolicy,
        private readonly InpatientMasterService $service,
        private readonly InpatientOccupancyProjection $projection,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->authorizeView($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'ward_code' => ['nullable', 'string', 'max:64'],
            'service_class' => ['nullable', 'string', 'max:120'],
            'occupancy_state' => ['nullable', Rule::in(['AVAILABLE', 'OCCUPIED', 'RETIRED'])],
            'master_state' => ['nullable', Rule::in(['ACTIVE', 'RETIRED'])],
        ]);

        $normalizedFilters = [
            'q' => trim((string) ($filters['q'] ?? '')),
            'ward_code' => trim((string) ($filters['ward_code'] ?? '')),
            'service_class' => trim((string) ($filters['service_class'] ?? '')),
            'occupancy_state' => (string) ($filters['occupancy_state'] ?? ''),
            'master_state' => (string) ($filters['master_state'] ?? ''),
        ];

        try {
            $props = $this->projection->forActor($actor, $normalizedFilters);
        } catch (Throwable $exception) {
            report($exception);
            $props = $this->readFailureProps($actor, $normalizedFilters);
        }

        return Inertia::render('manajemen-data/bangsal', $props);
    }

    public function storeWard(Request $request): RedirectResponse
    {
        $actor = $this->authorizeManage($request);
        $data = $request->validate($this->wardCreateRules());

        return $this->respond($request, fn () => $this->service->createWard($actor, $data['code'], $data['display_name'], $data['reason_code'], $data['idempotency_key'], RequestCorrelation::existing($request)), 'Bangsal berhasil dibuat.');
    }

    public function updateWard(Request $request, string $ward): RedirectResponse
    {
        $actor = $this->authorizeManage($request);
        $data = $request->validate($this->wardUpdateRules());

        return $this->respond($request, fn () => $this->service->updateWard($actor, $ward, $data['display_name'], (int) $data['expected_version'], $data['reason_code'], $data['idempotency_key'], RequestCorrelation::existing($request)), 'Bangsal berhasil diperbarui.');
    }

    public function retireWard(Request $request, string $ward): RedirectResponse
    {
        $actor = $this->authorizeManage($request);
        $data = $request->validate($this->retireRules());

        return $this->respond($request, fn () => $this->service->retireWard($actor, $ward, (int) $data['expected_version'], $data['reason_code'], $data['idempotency_key'], RequestCorrelation::existing($request)), 'Bangsal berhasil dipensiunkan.');
    }

    public function storeBed(Request $request, string $ward): RedirectResponse
    {
        $actor = $this->authorizeManage($request);
        $data = $request->validate($this->bedCreateRules());

        return $this->respond($request, fn () => $this->service->createBed($actor, $ward, $data['code'], $data['display_name'], $data['room_label'], $data['service_class'], $data['reason_code'], $data['idempotency_key'], RequestCorrelation::existing($request)), 'Tempat tidur berhasil dibuat.');
    }

    public function updateBed(Request $request, string $bed): RedirectResponse
    {
        $actor = $this->authorizeManage($request);
        $data = $request->validate($this->bedUpdateRules());

        return $this->respond($request, fn () => $this->service->updateBed($actor, $bed, $data['display_name'], $data['room_label'], $data['service_class'], (int) $data['expected_version'], $data['reason_code'], $data['idempotency_key'], RequestCorrelation::existing($request)), 'Tempat tidur berhasil diperbarui.');
    }

    public function retireBed(Request $request, string $bed): RedirectResponse
    {
        $actor = $this->authorizeManage($request);
        $data = $request->validate($this->retireRules());

        return $this->respond($request, fn () => $this->service->retireBed($actor, $bed, (int) $data['expected_version'], $data['reason_code'], $data['idempotency_key'], RequestCorrelation::existing($request)), 'Tempat tidur berhasil dipensiunkan.');
    }

    private function authorizeManage(Request $request): User
    {
        Gate::authorize(Capability::INPATIENT_WARD_BED_MANAGE);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $this->actorPolicy->authorizeManage($actor);

        return $actor;
    }

    private function authorizeView(Request $request): User
    {
        Gate::authorize(Capability::INPATIENT_OCCUPANCY_VIEW);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $this->actorPolicy->authorizeView($actor);

        return $actor;
    }

    private function respond(Request $request, callable $operation, string $message): RedirectResponse
    {
        try {
            $result = $operation();
        } catch (InpatientMasterDenied $denial) {
            return back()->withErrors(['master' => $denial->getMessage()])->withInput();
        } catch (InpatientMasterAuditUnavailable $failure) {
            if ($request->header('X-Inertia') !== 'true') {
                abort(503, $failure->getMessage());
            }

            return back()->withErrors(['master' => $failure->getMessage()])->withInput();
        }

        return back()->with('success', $result->replayed ? 'Permintaan ini sudah diproses.' : $message);
    }

    /** @return array<string, list<mixed>> */
    private function commonRules(): array
    {
        return [
            'reason_code' => ['required', Rule::in(InpatientMasterService::REASON_CODES)],
            'idempotency_key' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function wardCreateRules(): array
    {
        return [...$this->commonRules(), 'code' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/'], 'display_name' => ['required', 'string', 'max:120']];
    }

    /** @return array<string, list<mixed>> */
    private function wardUpdateRules(): array
    {
        return [...$this->commonRules(), 'display_name' => ['required', 'string', 'max:120'], 'expected_version' => ['required', 'integer', 'min:1']];
    }

    /** @return array<string, list<mixed>> */
    private function bedCreateRules(): array
    {
        return [...$this->commonRules(), 'code' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/'], 'display_name' => ['required', 'string', 'max:120'], 'room_label' => ['required', 'string', 'max:120'], 'service_class' => ['required', 'string', 'max:120']];
    }

    /** @return array<string, list<mixed>> */
    private function bedUpdateRules(): array
    {
        return [...$this->commonRules(), 'display_name' => ['required', 'string', 'max:120'], 'room_label' => ['required', 'string', 'max:120'], 'service_class' => ['required', 'string', 'max:120'], 'expected_version' => ['required', 'integer', 'min:1']];
    }

    /** @return array<string, list<mixed>> */
    private function retireRules(): array
    {
        return ['reason_code' => ['required', Rule::in([InpatientMasterService::REASON_RETIREMENT])], 'expected_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/']];
    }

    /**
     * @param  array{q: string, ward_code: string, service_class: string, occupancy_state: string, master_state: string}  $filters
     * @return array<string, mixed>
     */
    private function readFailureProps(User $actor, array $filters): array
    {
        $canManage = $this->actorPolicy->canManage($actor);

        return [
            'generated_at' => now((string) config('app.timezone', 'Asia/Jakarta'))->toIso8601String(),
            'filters' => $filters,
            'totals' => [
                'active_wards' => 0,
                'active_beds' => 0,
                'occupied_beds' => 0,
                'available_beds' => 0,
            ],
            'wards' => [],
            'permissions' => [
                'can_view_census' => true,
                'can_manage_master' => $canManage,
            ],
            'commands' => ['create_ward_url' => null],
            'reason_options' => array_map(
                static fn (string $code): array => ['value' => $code, 'label' => InpatientMasterService::REASON_LABELS[$code]],
                InpatientMasterService::REASON_CODES,
            ),
            'filter_options' => ['wards' => [], 'service_classes' => []],
            'read_error' => 'Sensus tempat tidur belum dapat dimuat. Silakan coba lagi.',
        ];
    }
}
