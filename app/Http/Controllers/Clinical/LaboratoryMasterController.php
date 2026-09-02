<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryExaminationMaster;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Laboratory\LaboratoryActorPolicy;
use App\Support\Laboratory\LaboratoryAuditUnavailable;
use App\Support\Laboratory\LaboratoryDenied;
use App\Support\Laboratory\LaboratoryMasterService;
use App\Support\Laboratory\LaboratoryProjection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class LaboratoryMasterController extends Controller
{
    public function __construct(
        private readonly LaboratoryActorPolicy $policy,
        private readonly LaboratoryMasterService $masters,
        private readonly LaboratoryProjection $projection,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->policy->master($actor);

        return Inertia::render('manajemen-data/laboratorium/index', $this->projection->masters($actor));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'LABORATORY_MASTER_CREATE', null);
        $payload = $this->validateMutation($request, $actor, 'LABORATORY_MASTER_CREATE', null, [
            'code', 'display_name', 'specimen_type', 'collection_instruction', 'components', 'idempotency_key',
        ], $this->masterRules(includeCode: true));

        $this->run(fn () => $this->masters->create(
            $actor,
            $payload['code'],
            $payload['display_name'],
            $payload['specimen_type'],
            $payload['collection_instruction'] ?? null,
            $payload['components'],
            $payload['idempotency_key'],
        ));

        return back()->with('success', 'Pemeriksaan laboratorium ditambahkan.');
    }

    public function update(Request $request, string $master): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'LABORATORY_MASTER_REVISE', $master);
        $payload = $this->validateMutation($request, $actor, 'LABORATORY_MASTER_REVISE', $master, [
            'expected_version', 'display_name', 'specimen_type', 'collection_instruction', 'components', 'idempotency_key',
        ], ['expected_version' => ['required', 'integer', 'min:1'], ...$this->masterRules(includeCode: false)]);

        $this->run(fn () => $this->masters->revise(
            $master,
            $actor,
            (int) $payload['expected_version'],
            $payload['display_name'],
            $payload['specimen_type'],
            $payload['collection_instruction'] ?? null,
            $payload['components'],
            LaboratoryExaminationMaster::ACTIVE,
            $payload['idempotency_key'],
        ));

        return back()->with('success', 'Pemeriksaan laboratorium diperbarui.');
    }

    public function retire(Request $request, string $master): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeMutation($actor, 'LABORATORY_MASTER_REVISE', $master);
        $payload = $this->validateMutation($request, $actor, 'LABORATORY_MASTER_REVISE', $master, [
            'expected_version', 'idempotency_key',
        ], [
            'expected_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        $current = LaboratoryExaminationMaster::query()->where('public_id', $master)->first();
        if (! $current instanceof LaboratoryExaminationMaster) {
            $this->recordDenial($actor, 'LABORATORY_MASTER_REVISE', $master, 'resource_not_found');
            abort(404);
        }

        $this->run(fn () => $this->masters->revise(
            $master,
            $actor,
            (int) $payload['expected_version'],
            $current->display_name,
            $current->specimen_type,
            $current->collection_instruction,
            $current->components,
            LaboratoryExaminationMaster::RETIRED,
            $payload['idempotency_key'],
        ));

        return back()->with('success', 'Pemeriksaan laboratorium dinonaktifkan.');
    }

    /** @return array<string, array<int, mixed>> */
    private function masterRules(bool $includeCode): array
    {
        $rules = [
            'display_name' => ['required', 'string', 'max:160'],
            'specimen_type' => ['required', 'string', 'max:120'],
            'collection_instruction' => ['nullable', 'string', 'max:2000'],
            'components' => ['required', 'array', 'min:1', 'max:12'],
            'components.*' => ['required', 'array:code,display_name,value_kind,unit_text,reference_text,critical_allowed'],
            'components.*.code' => ['required', 'string', 'min:2', 'max:64', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]+\z/'],
            'components.*.display_name' => ['required', 'string', 'max:160'],
            'components.*.value_kind' => ['required', Rule::in(['TEXT', 'NUMERIC', 'QUALITATIVE'])],
            'components.*.unit_text' => ['nullable', 'string', 'max:80'],
            'components.*.reference_text' => ['nullable', 'string', 'max:240'],
            'components.*.critical_allowed' => ['required', 'boolean'],
            'idempotency_key' => $this->idempotencyRules(),
        ];
        if ($includeCode) {
            $rules = ['code' => ['required', 'string', 'min:2', 'max:64', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]+\z/'], ...$rules];
        }

        return $rules;
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
        if ($this->audit->record('laboratory.workflow.mutate', 'laboratory_record', $resource, $actor, 'DENIED', $reason, ['operation' => $operation]) === null) {
            throw new LaboratoryAuditUnavailable('Audit penolakan master laboratorium gagal.');
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
