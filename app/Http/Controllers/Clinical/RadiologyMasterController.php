<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\RadiologyExaminationMaster;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Radiology\RadiologyActorPolicy;
use App\Support\Radiology\RadiologyAuditUnavailable;
use App\Support\Radiology\RadiologyDenied;
use App\Support\Radiology\RadiologyMasterService;
use App\Support\Radiology\RadiologyProjection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class RadiologyMasterController extends Controller
{
    public function __construct(
        private readonly RadiologyActorPolicy $policy,
        private readonly RadiologyMasterService $masters,
        private readonly RadiologyProjection $projection,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->policy->master($actor);

        return Inertia::render('manajemen-data/radiologi/index', $this->projection->masters($actor));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'RADIOLOGY_MASTER_CREATE', null);
        $payload = $this->validateMutation($request, $actor, 'RADIOLOGY_MASTER_CREATE', null, ['code', 'display_name', 'preparation_instruction', 'idempotency_key'], [
            'code' => ['required', 'string', 'min:2', 'max:64', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]+\z/'],
            'display_name' => ['required', 'string', 'max:160'],
            'preparation_instruction' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->run(fn () => $this->masters->create(
            $actor,
            $payload['code'],
            $payload['display_name'],
            $payload['preparation_instruction'] ?? null,
            $payload['idempotency_key'],
        ));

        return back()->with('success', 'Pemeriksaan radiologi ditambahkan.');
    }

    public function update(Request $request, string $master): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'RADIOLOGY_MASTER_REVISE', $master);
        $payload = $this->validateMutation($request, $actor, 'RADIOLOGY_MASTER_REVISE', $master, ['expected_version', 'display_name', 'preparation_instruction', 'idempotency_key'], [
            'expected_version' => ['required', 'integer', 'min:1'],
            'display_name' => ['required', 'string', 'max:160'],
            'preparation_instruction' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->run(fn () => $this->masters->revise(
            $master,
            $actor,
            (int) $payload['expected_version'],
            $payload['display_name'],
            $payload['preparation_instruction'] ?? null,
            RadiologyExaminationMaster::ACTIVE,
            $payload['idempotency_key'],
        ));

        return back()->with('success', 'Pemeriksaan radiologi diperbarui.');
    }

    public function retire(Request $request, string $master): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'RADIOLOGY_MASTER_REVISE', $master);
        $payload = $this->validateMutation($request, $actor, 'RADIOLOGY_MASTER_REVISE', $master, ['expected_version', 'idempotency_key'], [
            'expected_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $current = RadiologyExaminationMaster::query()->where('public_id', $master)->first();
        if (! $current instanceof RadiologyExaminationMaster) {
            $this->recordDenial($actor, 'RADIOLOGY_MASTER_REVISE', $master, 'resource_not_found');
            abort(404);
        }
        $this->run(fn () => $this->masters->revise(
            $master,
            $actor,
            (int) $payload['expected_version'],
            $current->display_name,
            $current->preparation_instruction,
            RadiologyExaminationMaster::RETIRED,
            $payload['idempotency_key'],
        ));

        return back()->with('success', 'Pemeriksaan radiologi dinonaktifkan.');
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

    private function authorizeMutation(User $actor, string $operation, ?string $resource): void
    {
        try {
            $this->policy->master($actor);
        } catch (AuthorizationException $denial) {
            $this->recordDenial($actor, $operation, $resource, 'role_not_permitted');
            throw $denial;
        }
    }

    private function recordDenial(User $actor, string $operation, ?string $resource, string $reason): void
    {
        $resource = is_string($resource) && Str::isUlid($resource) ? $resource : null;
        if ($this->audit->record('radiology.workflow.mutate', 'radiology_record', $resource, $actor, 'DENIED', $reason, ['operation' => $operation]) === null) {
            throw new RadiologyAuditUnavailable('Audit penolakan master radiologi gagal.');
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
            throw ValidationException::withMessages(['radiology' => $denial->getMessage()]);
        }
    }
}
