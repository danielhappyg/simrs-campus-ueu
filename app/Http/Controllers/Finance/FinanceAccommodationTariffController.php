<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceAccommodationTariffBinding;
use App\Models\FinanceAccommodationTariffBindingVersion;
use App\Models\FinanceTariffItem;
use App\Models\FinanceTariffItemVersion;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\User;
use App\Support\Finance\FinanceAccommodationTariffActorPolicy;
use App\Support\Finance\FinanceAccommodationTariffBindingService;
use App\Support\Finance\FinanceAccommodationTariffProjection;
use App\Support\Finance\FinanceCanonicalJson;
use App\Support\Finance\FinanceTariffAuditUnavailable;
use App\Support\Finance\FinanceTariffDenied;
use App\Support\Finance\FinanceTariffProjection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class FinanceAccommodationTariffController extends Controller
{
    public function __construct(
        private readonly FinanceAccommodationTariffActorPolicy $policy,
        private readonly FinanceAccommodationTariffBindingService $service,
        private readonly FinanceAccommodationTariffProjection $projection,
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
            'tariff_item_public_id' => ['required', 'string', 'size:26'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirm' => ['accepted'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->assertSourceSnapshot($data);

        return $this->mutate(fn (): mixed => $this->service->create(
            $actor,
            $data['inpatient_bed_version_public_id'],
            'INPATIENT',
            $data['tariff_item_public_id'],
            $data['effective_from'],
            $data['reason'],
            $data['idempotency_key'],
            $this->correlation($request),
        ), 'Pemetaan tarif akomodasi dibuat.');
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

        return $this->mutate(fn (): mixed => $this->service->appendVersion(
            $actor,
            $binding,
            $data['tariff_item_public_id'],
            $data['effective_from'],
            $data['expected_version'],
            $data['expected_digest'],
            $data['reason'],
            $data['idempotency_key'],
            $this->correlation($request),
        ), 'Versi pemetaan tarif akomodasi ditambahkan.');
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

        return $this->mutate(fn (): mixed => $this->service->retire(
            $actor,
            $binding,
            $data['effective_from'],
            $data['expected_version'],
            $data['expected_digest'],
            $data['reason'],
            $data['idempotency_key'],
            $this->correlation($request),
        ), 'Pemetaan tarif akomodasi dijadwalkan nonaktif.');
    }

    /** @param array<string, mixed>|null $history */
    private function workspace(User $actor, string $asOfDate, ?array $history = null): Response
    {
        $sources = $this->sources();
        $tariffOptions = array_map(
            fn (array $tariff): array => $this->tariffOption($tariff),
            $this->tariffs->effectiveTariffs($actor, $asOfDate, [
                'service_domain' => 'ACCOMMODATION',
                'care_setting' => 'INPATIENT',
            ]),
        );
        $canManage = $this->policy->canManage($actor);
        $overview = collect($this->projection->overview($actor, $asOfDate))
            ->keyBy('public_id');
        $mappings = FinanceAccommodationTariffBinding::query()
            ->with(['bed.ward', 'bedVersion', 'versions'])
            ->orderBy('bed_code')->get()
            ->map(fn (FinanceAccommodationTariffBinding $binding): ?array => $this->mapping(
                $binding,
                $asOfDate,
                $canManage,
                $overview->get($binding->public_id),
            ))
            ->filter()->values()->all();
        $effectiveKeys = collect($mappings)
            ->filter(fn (array $mapping): bool => $mapping['state'] === FinanceAccommodationTariffBinding::ACTIVE
                && $mapping['effective_from'] <= $asOfDate
                && ($mapping['effective_until'] === null || $asOfDate < $mapping['effective_until']))
            ->mapWithKeys(fn (array $mapping): array => [
                $mapping['source']['bed_version_public_id'].'|'.$mapping['source']['bed_content_digest'] => true,
            ]);
        $gaps = [];
        foreach ($sources as $source) {
            if ($source['state'] === InpatientBed::STATE_ACTIVE
                && ! $effectiveKeys->has($source['bed_version_public_id'].'|'.$source['bed_content_digest'])) {
                $gaps[] = [
                    'source' => $source,
                    'reason_code' => 'TARIF_BELUM_DIPETAKAN',
                    'reason_label' => 'Tarif belum dipetakan',
                    'detail' => 'Belum ada pemetaan efektif yang sengaja dibuat untuk versi tempat tidur tepat ini.',
                ];
            }
        }

        return Inertia::render('manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi/index', [
            'as_of_date' => $asOfDate,
            'source_master_version' => 'MANAGED_INPATIENT_WARD_BED_MASTER_V1',
            'source_master_content_digest' => FinanceCanonicalJson::digest($sources),
            'source_trigger' => [
                'code' => 'CLOSED_OCCUPANCY_DAY_V1',
                'label' => 'Hanya interval okupansi tertutup dengan jangkar hari yang dapat menjadi sumber biaya.',
            ],
            'sources' => $sources,
            'tariff_options' => $tariffOptions,
            'mappings' => $mappings,
            'gaps' => $gaps,
            'history' => $history,
            'permissions' => ['can_manage' => $canManage],
            'commands' => [
                'create_url' => $canManage ? route('finance.accommodation-tariff.create', absolute: false) : null,
            ],
            'read_error' => null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function sources(): array
    {
        return array_values(InpatientBed::query()->with(['ward', 'versions'])->orderBy('code')->get()
            ->map(function (InpatientBed $bed): array {
                $version = $bed->versions->firstWhere('version', $bed->version);
                if (! $version instanceof InpatientBedVersion) {
                    throw new \LogicException('Versi master tempat tidur aktif tidak tersedia.');
                }

                return $this->source($bed, $version);
            })->values()->all());
    }

    /** @return array<string, mixed> */
    private function source(InpatientBed $bed, InpatientBedVersion $version): array
    {
        return [
            'public_id' => $bed->public_id,
            'code' => $bed->code,
            'display_name' => $version->display_name,
            'ward_code' => $bed->ward->code,
            'ward_display_name' => $bed->ward->display_name,
            'room_label' => $version->room_label,
            'service_class_label' => $version->service_class,
            'state' => $bed->state,
            'bed_version_public_id' => $version->public_id,
            'bed_version' => $version->version,
            'bed_content_digest' => $version->after_digest,
        ];
    }

    /** @return array<string, mixed>|null */
    private function mapping(FinanceAccommodationTariffBinding $binding, string $asOfDate, bool $canManage, mixed $view): ?array
    {
        $versions = $binding->versions->sortBy('version')->values();
        $version = is_array($view)
            ? $versions->firstWhere('version', $view['version'] ?? null)
            : null;
        $version = $version instanceof FinanceAccommodationTariffBindingVersion ? $version : $versions
            ->filter(fn (FinanceAccommodationTariffBindingVersion $item): bool => $item->effective_from->format('Y-m-d') <= $asOfDate)
            ->sortByDesc('effective_from')->sortByDesc('version')->first()
            ?? $versions->last();
        if (! $version instanceof FinanceAccommodationTariffBindingVersion) {
            return null;
        }
        $next = $versions->filter(fn (FinanceAccommodationTariffBindingVersion $item): bool => $item->effective_from->isAfter($version->effective_from))
            ->sortBy('effective_from')->sortBy('version')->first();
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
            'source' => $this->source($binding->bed, $binding->bedVersion),
            'tariff' => $tariff,
            'effective_from' => $version->effective_from->format('Y-m-d'),
            'effective_until' => $next instanceof FinanceAccommodationTariffBindingVersion
                ? $next->effective_from->format('Y-m-d') : null,
            'actions' => [
                'history_url' => route('finance.accommodation-tariff.history', ['binding' => $binding->public_id], false),
                'revise_url' => $canManage && $binding->state === FinanceAccommodationTariffBinding::ACTIVE
                    ? route('finance.accommodation-tariff.revise', ['binding' => $binding->public_id], false) : null,
                'retire_url' => $canManage && $binding->state === FinanceAccommodationTariffBinding::ACTIVE
                    ? route('finance.accommodation-tariff.retire', ['binding' => $binding->public_id], false) : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function historyView(string $publicId): array
    {
        $binding = FinanceAccommodationTariffBinding::query()
            ->with(['bed.ward', 'bedVersion', 'versions.actor'])
            ->where('public_id', $publicId)->firstOrFail();
        $versions = $binding->versions->sortBy('version')->values();

        return [
            'binding_public_id' => $binding->public_id,
            'source' => $this->source($binding->bed, $binding->bedVersion),
            'versions' => $versions->map(function (FinanceAccommodationTariffBindingVersion $version, int $index) use ($versions): array {
                $tariff = $this->tariffAt($version->tariff_item_id, $version->effective_from->format('Y-m-d'));
                if ($tariff === null) {
                    throw new \LogicException('Versi tarif historis pemetaan akomodasi tidak tersedia.');
                }
                $next = $versions->get($index + 1);

                return [
                    'public_id' => $version->public_id,
                    'version' => $version->version,
                    'state' => $version->state,
                    'effective_from' => $version->effective_from->format('Y-m-d'),
                    'effective_until' => $next instanceof FinanceAccommodationTariffBindingVersion
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
        $bed = InpatientBed::query()->where('public_id', $data['inpatient_bed_public_id'])->first();
        $version = InpatientBedVersion::query()->where('public_id', $data['inpatient_bed_version_public_id'])->first();
        if (! $bed instanceof InpatientBed
            || ! $version instanceof InpatientBedVersion
            || $version->bed_id !== $bed->id
            || $version->version !== $data['inpatient_bed_version']
            || ! hash_equals($version->after_digest, $data['inpatient_bed_content_digest'])) {
            throw ValidationException::withMessages(['master' => 'Versi master tempat tidur telah berubah. Muat ulang halaman.']);
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
            throw ValidationException::withMessages(['master' => $denied->getMessage()]);
        } catch (FinanceTariffAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Pencatatan audit pemetaan tarif belum tersedia.');
        }

        return back()->with('success', $result->replayed ? 'Operasi yang sama ditampilkan kembali.' : $success);
    }

    /** @return array<string, array<int, mixed>> */
    private function sourceRules(): array
    {
        return [
            'inpatient_bed_public_id' => ['required', 'string', 'size:26'],
            'inpatient_bed_version_public_id' => ['required', 'string', 'size:26'],
            'inpatient_bed_version' => ['required', 'integer', 'min:1'],
            'inpatient_bed_content_digest' => $this->digestRules(),
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
