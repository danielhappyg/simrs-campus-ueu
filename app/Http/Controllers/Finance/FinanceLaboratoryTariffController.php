<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceLaboratoryTariffBinding;
use App\Models\FinanceLaboratoryTariffBindingVersion;
use App\Models\FinanceTariffItem;
use App\Models\FinanceTariffItemVersion;
use App\Models\LaboratoryExaminationMaster;
use App\Models\LaboratoryExaminationMasterVersion;
use App\Models\User;
use App\Support\Finance\FinanceCanonicalJson;
use App\Support\Finance\FinanceLaboratoryTariffActorPolicy;
use App\Support\Finance\FinanceLaboratoryTariffBindingService;
use App\Support\Finance\FinanceLaboratoryTariffProjection;
use App\Support\Finance\FinanceTariffAuditUnavailable;
use App\Support\Finance\FinanceTariffDenied;
use App\Support\Finance\FinanceTariffProjection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class FinanceLaboratoryTariffController extends Controller
{
    public function __construct(
        private readonly FinanceLaboratoryTariffActorPolicy $policy,
        private readonly FinanceLaboratoryTariffBindingService $service,
        private readonly FinanceLaboratoryTariffProjection $projection,
        private readonly FinanceTariffProjection $tariffs,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->policy->view($actor);
        $asOfDate = $request->validate(['as_of_date' => ['nullable', 'date_format:Y-m-d']])['as_of_date']
            ?? now()->toDateString();

        return $this->workspace($actor, $asOfDate);
    }

    public function history(Request $request, string $binding): Response
    {
        $actor = $this->actor($request);
        $this->policy->view($actor);
        $asOfDate = $request->validate(['as_of_date' => ['nullable', 'date_format:Y-m-d']])['as_of_date']
            ?? now()->toDateString();

        return $this->workspace($actor, $asOfDate, $this->historyView($binding));
    }

    public function create(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            ...$this->sourceRules(),
            'care_setting' => ['required', Rule::in(['OUTPATIENT', 'EMERGENCY', 'INPATIENT'])],
            'tariff_item_public_id' => ['required', 'string', 'size:26'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirm' => ['accepted'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->assertSourceSnapshot($data);

        return $this->mutate(fn () => $this->service->create(
            $actor,
            $data['laboratory_master_version_public_id'],
            $data['care_setting'],
            $data['tariff_item_public_id'],
            $data['effective_from'],
            $data['reason'],
            $data['idempotency_key'],
            $this->correlation($request),
        ), 'Pemetaan tarif laboratorium dibuat.');
    }

    public function revise(Request $request, string $binding): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'tariff_item_public_id' => ['required', 'string', 'size:26'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'expected_digest' => $this->digestRules(),
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirm' => ['accepted'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        return $this->mutate(fn () => $this->service->appendVersion(
            $actor,
            $binding,
            $data['tariff_item_public_id'],
            $data['effective_from'],
            $data['expected_version'],
            $data['expected_digest'],
            $data['reason'],
            $data['idempotency_key'],
            $this->correlation($request),
        ), 'Versi pemetaan tarif laboratorium ditambahkan.');
    }

    public function retire(Request $request, string $binding): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'expected_digest' => $this->digestRules(),
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirm' => ['accepted'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);

        return $this->mutate(fn () => $this->service->retire(
            $actor,
            $binding,
            $data['effective_from'],
            $data['expected_version'],
            $data['expected_digest'],
            $data['reason'],
            $data['idempotency_key'],
            $this->correlation($request),
        ), 'Pemetaan tarif laboratorium dijadwalkan nonaktif.');
    }

    /** @param array<string, mixed>|null $history */
    private function workspace(User $actor, string $asOfDate, ?array $history = null): Response
    {
        $sources = $this->sources();
        $tariffOptions = array_map(
            fn (array $tariff): array => $this->tariffOption($tariff),
            $this->tariffs->effectiveTariffs($actor, $asOfDate, ['service_domain' => 'LABORATORY']),
        );
        $canManage = $this->policy->canManage($actor);
        $mappings = array_values(array_filter(array_map(
            fn (array $mapping): ?array => $this->mapping($mapping, $asOfDate, $canManage),
            $this->projection->overview($actor, $asOfDate),
        )));
        $effectiveKeys = collect($mappings)
            ->filter(fn (array $mapping): bool => $mapping['state'] === FinanceLaboratoryTariffBinding::ACTIVE
                && $mapping['effective_from'] <= $asOfDate
                && ($mapping['effective_until'] === null || $asOfDate < $mapping['effective_until']))
            ->mapWithKeys(fn (array $mapping): array => [
                $mapping['source']['master_version_public_id'].'|'.$mapping['care_setting'] => true,
            ]);
        $gaps = [];
        foreach ($sources as $source) {
            if ($source['state'] !== LaboratoryExaminationMaster::ACTIVE) {
                continue;
            }
            foreach (['OUTPATIENT', 'EMERGENCY', 'INPATIENT'] as $careSetting) {
                if (! $effectiveKeys->has($source['master_version_public_id'].'|'.$careSetting)) {
                    $gaps[] = [
                        'source' => $source,
                        'care_setting' => $careSetting,
                        'reason_code' => 'TARIF_BELUM_DIPETAKAN',
                        'reason_label' => 'Tariff not mapped',
                        'detail' => 'No effective mapping has been deliberately created for this master version and care setting.',
                    ];
                }
            }
        }

        return Inertia::render('manajemen-data/tarif-komponen-biaya/pemetaan-laboratorium/index', [
            'as_of_date' => $asOfDate,
            'source_master_version' => 'LABORATORY_EXAMINATION_MASTER_V1',
            'source_master_content_digest' => FinanceCanonicalJson::digest($sources),
            'source_trigger' => [
                'code' => 'ORIGINAL_VERIFIED_RESULT_V1',
                'label' => 'The original result with VERIFIED status at verified_at is the only billing trigger.',
            ],
            'sources' => $sources,
            'tariff_options' => $tariffOptions,
            'mappings' => $mappings,
            'gaps' => $gaps,
            'history' => $history,
            'permissions' => ['can_manage' => $canManage],
            'commands' => [
                'create_url' => $canManage ? route('finance.laboratory-tariff.create', absolute: false) : null,
            ],
            'read_error' => null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function sources(): array
    {
        return array_values(LaboratoryExaminationMaster::query()->with('versions')->orderBy('examination_code')->get()
            ->map(function (LaboratoryExaminationMaster $master): array {
                $version = $master->versions->firstWhere('version', $master->version);
                if (! $version instanceof LaboratoryExaminationMasterVersion) {
                    throw new \LogicException('The active laboratory master version is unavailable.');
                }

                return $this->source($master, $version);
            })->all());
    }

    /** @return array<string, mixed> */
    private function source(LaboratoryExaminationMaster $master, LaboratoryExaminationMasterVersion $version): array
    {
        return [
            'public_id' => $master->public_id,
            'code' => $master->examination_code,
            'display_name' => $version->display_name,
            'specimen_type' => $version->specimen_type,
            'component_count' => count($version->components),
            'state' => $master->state,
            'master_version_public_id' => $version->public_id,
            'master_version' => $version->version,
            'master_content_digest' => $version->content_digest,
        ];
    }

    /** @param array<string, mixed> $view
     * @return array<string, mixed>|null
     */
    private function mapping(array $view, string $asOfDate, bool $canManage): ?array
    {
        $binding = FinanceLaboratoryTariffBinding::query()->with(['laboratoryMaster', 'laboratoryMasterVersion', 'versions'])
            ->where('public_id', $view['public_id'])->first();
        if (! $binding instanceof FinanceLaboratoryTariffBinding) {
            return null;
        }
        $version = $binding->versions->firstWhere('version', $view['version'])
            ?? $binding->versions->sortByDesc('version')->first();
        if (! $version instanceof FinanceLaboratoryTariffBindingVersion) {
            return null;
        }
        $next = $binding->versions->where('effective_from', '>', $version->effective_from)
            ->sortBy('effective_from')->first();
        $tariff = $this->tariffAt($version->tariff_item_id, $asOfDate)
            ?? $this->tariffAt($version->tariff_item_id, $version->effective_from->format('Y-m-d'));
        if ($tariff === null) {
            return null;
        }

        return [
            'public_id' => $binding->public_id,
            'state' => $version->state,
            'version' => $version->version,
            'content_digest' => $version->content_digest,
            'latest_head_version' => $binding->version,
            'latest_head_content_digest' => $binding->current_content_digest,
            'latest_head_state' => $binding->state,
            'source' => $this->source($binding->laboratoryMaster, $binding->laboratoryMasterVersion),
            'care_setting' => $binding->care_setting,
            'tariff' => $tariff,
            'effective_from' => $version->effective_from->format('Y-m-d'),
            'effective_until' => $next instanceof FinanceLaboratoryTariffBindingVersion
                ? $next->effective_from->format('Y-m-d') : null,
            'actions' => [
                'history_url' => route('finance.laboratory-tariff.history', ['binding' => $binding->public_id], false),
                'revise_url' => $canManage && $binding->state === FinanceLaboratoryTariffBinding::ACTIVE
                    ? route('finance.laboratory-tariff.revise', ['binding' => $binding->public_id], false) : null,
                'retire_url' => $canManage && $binding->state === FinanceLaboratoryTariffBinding::ACTIVE
                    ? route('finance.laboratory-tariff.retire', ['binding' => $binding->public_id], false) : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function historyView(string $publicId): array
    {
        $binding = FinanceLaboratoryTariffBinding::query()
            ->with(['laboratoryMaster', 'laboratoryMasterVersion', 'versions.actor'])
            ->where('public_id', $publicId)->firstOrFail();
        $versions = $binding->versions->sortBy('version')->values();

        return [
            'binding_public_id' => $binding->public_id,
            'source' => $this->source($binding->laboratoryMaster, $binding->laboratoryMasterVersion),
            'care_setting' => $binding->care_setting,
            'versions' => $versions->map(function (FinanceLaboratoryTariffBindingVersion $version, int $index) use ($versions): array {
                $tariff = $this->tariffAt($version->tariff_item_id, $version->effective_from->format('Y-m-d'));
                if ($tariff === null) {
                    throw new \LogicException('The historical tariff mapping version is unavailable.');
                }
                $next = $versions->get($index + 1);

                return [
                    'public_id' => $version->public_id,
                    'version' => $version->version,
                    'state' => $version->state,
                    'effective_from' => $version->effective_from->format('Y-m-d'),
                    'effective_until' => $next instanceof FinanceLaboratoryTariffBindingVersion
                        ? $next->effective_from->format('Y-m-d') : null,
                    'tariff' => $tariff,
                    'reason' => $version->reason,
                    'authored_at' => $version->created_at->toIso8601String(),
                    'authored_by' => $version->actor->name,
                    'previous_content_digest' => $version->previous_content_digest,
                    'content_digest' => $version->content_digest,
                ];
            })->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function tariffAt(int $tariffItemId, string $date): ?array
    {
        $item = FinanceTariffItem::query()->whereKey($tariffItemId)->first();
        $version = FinanceTariffItemVersion::query()->where('tariff_item_id', $tariffItemId)
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->orderByDesc('version')->first();

        return $item instanceof FinanceTariffItem && $version instanceof FinanceTariffItemVersion
            ? $this->tariffOption([
                'public_id' => $item->public_id,
                'code' => $item->tariff_code,
                'display_name' => $version->display_name,
                'care_setting' => $version->care_setting,
                'state' => $version->state,
                'version_public_id' => $version->public_id,
                'version' => $version->version,
                'content_digest' => $version->content_digest,
                'amount_rupiah' => $version->amount_rupiah,
                'effective_from' => $version->effective_from->format('Y-m-d'),
            ]) : null;
    }

    /** @param array<string, mixed> $tariff
     * @return array<string, mixed>
     */
    private function tariffOption(array $tariff): array
    {
        return [
            'public_id' => $tariff['public_id'],
            'code' => $tariff['code'],
            'display_name' => $tariff['display_name'],
            'care_setting' => $tariff['care_setting'],
            'state' => $tariff['state'],
            'version_public_id' => $tariff['version_public_id'],
            'version' => $tariff['version'],
            'content_digest' => $tariff['content_digest'],
            'amount_rupiah' => $tariff['amount_rupiah'],
            'effective_from' => $tariff['effective_from'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function assertSourceSnapshot(array $data): void
    {
        $master = LaboratoryExaminationMaster::query()->where('public_id', $data['laboratory_master_public_id'])->first();
        $version = LaboratoryExaminationMasterVersion::query()
            ->where('public_id', $data['laboratory_master_version_public_id'])->first();
        if (! $master instanceof LaboratoryExaminationMaster
            || ! $version instanceof LaboratoryExaminationMasterVersion
            || $version->laboratory_examination_master_id !== $master->id
            || $version->version !== $data['laboratory_master_version']
            || ! hash_equals($version->content_digest, $data['laboratory_master_content_digest'])) {
            throw ValidationException::withMessages(['master' => 'The laboratory master version has changed. Reload the page.']);
        }
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }

    private function manager(Request $request): User
    {
        $actor = $this->actor($request);
        $this->policy->manage($actor);

        return $actor;
    }

    private function mutate(callable $operation, string $success): RedirectResponse
    {
        try {
            $result = $operation();
        } catch (FinanceTariffDenied $denied) {
            throw ValidationException::withMessages(['master' => __($denied->getMessage())]);
        } catch (FinanceTariffAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Audit recording for tariff mapping is unavailable.');
        }

        return back()->with('success', $result->replayed ? 'Operasi yang sama ditampilkan kembali.' : $success);
    }

    /** @return array<string, array<int, mixed>> */
    private function sourceRules(): array
    {
        return [
            'laboratory_master_public_id' => ['required', 'string', 'size:26'],
            'laboratory_master_version_public_id' => ['required', 'string', 'size:26'],
            'laboratory_master_version' => ['required', 'integer', 'min:1'],
            'laboratory_master_content_digest' => $this->digestRules(),
        ];
    }

    /** @return array<int, string> */
    private function idempotencyRules(): array
    {
        return ['required', 'string', 'min:8', 'max:255', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,254}\z/'];
    }

    /** @return array<int, string> */
    private function digestRules(): array
    {
        return ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'];
    }

    private function correlation(Request $request): ?string
    {
        $correlation = $request->attributes->get('request_id');

        return is_string($correlation) && $correlation !== '' ? $correlation : null;
    }
}
