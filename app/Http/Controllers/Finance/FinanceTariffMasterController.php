<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Finance\FinanceTariffActorPolicy;
use App\Support\Finance\FinanceTariffAuditUnavailable;
use App\Support\Finance\FinanceTariffDenied;
use App\Support\Finance\FinanceTariffMasterService;
use App\Support\Finance\FinanceTariffProjection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class FinanceTariffMasterController extends Controller
{
    public function __construct(
        private readonly FinanceTariffActorPolicy $policy,
        private readonly FinanceTariffMasterService $service,
        private readonly FinanceTariffProjection $projection,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->policy->view($actor);
        $asOfDate = $request->validate(['as_of_date' => ['nullable', 'date_format:Y-m-d']])['as_of_date'] ?? null;

        return $this->workspace($actor, $asOfDate);
    }

    public function groupHistory(Request $request, string $group): Response
    {
        return $this->history($request, 'group', $group);
    }

    public function componentHistory(Request $request, string $component): Response
    {
        return $this->history($request, 'component', $component);
    }

    public function catalogueHistory(Request $request, string $catalogue): Response
    {
        return $this->history($request, 'catalogue', $catalogue);
    }

    public function tariffHistory(Request $request, string $tariff): Response
    {
        return $this->history($request, 'tariff', $tariff);
    }

    public function createGroup(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate($this->createRules());

        return $this->mutate($request, fn () => $this->service->createGroup(
            $actor, $data['code'], $data['display_name'], $data['reason'], $data['idempotency_key'], $this->correlation($request),
        ), 'Charge component group added.');
    }

    public function reviseGroup(Request $request, string $group): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate($this->reviseRules());

        return $this->mutate($request, fn () => $this->service->reviseGroup(
            $actor, $group, $data['display_name'], $data['expected_version'], $data['expected_digest'], $data['reason'], $data['idempotency_key'], $this->correlation($request),
        ), 'Charge component group version added.');
    }

    public function retireGroup(Request $request, string $group): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate($this->retireRules());

        return $this->mutate($request, fn () => $this->service->retireGroup(
            $actor, $group, $data['expected_version'], $data['expected_digest'], $data['reason'], $data['idempotency_key'], $this->correlation($request),
        ), 'Charge component group retired.');
    }

    public function createComponent(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([...$this->createRules(), ...$this->componentRules(), 'group_public_id' => ['required', 'string', 'max:26']]);

        return $this->mutate($request, fn () => $this->service->createComponent(
            $actor, $data['group_public_id'], $data['code'], $data['display_name'], $data['description'] ?? null, $data['terminology_label'] ?? null,
            $data['reason'], $data['idempotency_key'], $this->correlation($request),
        ), 'Charge component added.');
    }

    public function reviseComponent(Request $request, string $component): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([...$this->reviseRules(), ...$this->componentRules()]);

        return $this->mutate($request, fn () => $this->service->reviseComponent(
            $actor, $component, $data['display_name'], $data['description'] ?? null, $data['terminology_label'] ?? null,
            $data['expected_version'], $data['expected_digest'], $data['reason'], $data['idempotency_key'], $this->correlation($request),
        ), 'Charge component version added.');
    }

    public function retireComponent(Request $request, string $component): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate($this->retireRules());

        return $this->mutate($request, fn () => $this->service->retireComponent(
            $actor, $component, $data['expected_version'], $data['expected_digest'], $data['reason'], $data['idempotency_key'], $this->correlation($request),
        ), 'Charge component retired.');
    }

    public function createCatalogue(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate($this->createRules());

        return $this->mutate($request, fn () => $this->service->createCatalogue(
            $actor, $data['code'], $data['display_name'], $data['reason'], $data['idempotency_key'], $this->correlation($request),
        ), 'Tariff catalogue added.');
    }

    public function reviseCatalogue(Request $request, string $catalogue): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate($this->reviseRules());

        return $this->mutate($request, fn () => $this->service->reviseCatalogue(
            $actor, $catalogue, $data['display_name'], $data['expected_version'], $data['expected_digest'], $data['reason'], $data['idempotency_key'], $this->correlation($request),
        ), 'Tariff catalogue version added.');
    }

    public function retireCatalogue(Request $request, string $catalogue): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate($this->retireRules());

        return $this->mutate($request, fn () => $this->service->retireCatalogue(
            $actor, $catalogue, $data['expected_version'], $data['expected_digest'], $data['reason'], $data['idempotency_key'], $this->correlation($request),
        ), 'Tariff catalogue retired.');
    }

    public function createTariff(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([...$this->createRules(), ...$this->tariffRules(),
            'catalogue_public_id' => ['required', 'string', 'max:26'], 'component_public_id' => ['required', 'string', 'max:26'],
        ]);

        return $this->mutate($request, fn () => $this->service->createTariffItem(
            $actor, $data['catalogue_public_id'], $data['component_public_id'], $data['code'], $data['display_name'],
            $data['care_setting'], $data['service_domain'], $data['reference_label'] ?? null, $data['ward_class_label'] ?? null,
            $data['amount_rupiah'], $data['effective_from'], $data['reason'], $data['idempotency_key'], $this->correlation($request),
        ), 'Tariff added.');
    }

    public function reviseTariff(Request $request, string $tariff): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([...$this->reviseRules(), ...$this->tariffRules()]);

        return $this->mutate($request, fn () => $this->service->appendTariffItemVersion(
            $actor, $tariff, $data['display_name'], $data['care_setting'], $data['service_domain'], $data['reference_label'] ?? null,
            $data['ward_class_label'] ?? null, $data['amount_rupiah'], $data['effective_from'], $data['expected_version'],
            $data['expected_digest'], $data['reason'], $data['idempotency_key'], $this->correlation($request),
        ), 'Effective tariff version added.');
    }

    public function retireTariff(Request $request, string $tariff): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([...$this->retireRules(), 'effective_from' => ['required', 'date_format:Y-m-d']]);

        return $this->mutate($request, fn () => $this->service->retireTariffItem(
            $actor, $tariff, $data['effective_from'], $data['expected_version'], $data['expected_digest'], $data['reason'],
            $data['idempotency_key'], $this->correlation($request),
        ), 'Tariff retired from the selected date.');
    }

    private function history(Request $request, string $kind, string $publicId): Response
    {
        $actor = $this->actor($request);
        $this->policy->view($actor);
        $asOfDate = $request->validate(['as_of_date' => ['nullable', 'date_format:Y-m-d']])['as_of_date'] ?? null;
        $history = match ($kind) {
            'group' => $this->projection->groupHistory($actor, $publicId),
            'component' => $this->projection->componentHistory($actor, $publicId),
            'catalogue' => $this->projection->catalogueHistory($actor, $publicId),
            'tariff' => $this->projection->tariffHistory($actor, $publicId),
            default => throw new \LogicException('Unsupported tariff master history kind.'),
        };

        return $this->workspace($actor, $asOfDate, $history);
    }

    /** @param array<string, mixed>|null $history */
    private function workspace(User $actor, ?string $asOfDate, ?array $history = null): Response
    {
        $overview = $this->projection->overview($actor, $asOfDate);
        $canManage = $this->policy->canManage($actor);
        $overview['groups'] = array_map(fn (array $record): array => $this->withActions($record, 'group', $canManage), $overview['groups']);
        $overview['components'] = array_map(fn (array $record): array => $this->withActions($record, 'component', $canManage), $overview['components']);
        $overview['catalogues'] = array_map(fn (array $record): array => $this->withActions($record, 'catalogue', $canManage), $overview['catalogues']);
        $overview['tariffs'] = array_map(fn (array $record): array => $this->withActions($record, 'tariff', $canManage), $overview['tariffs']);

        return Inertia::render('manajemen-data/tarif-komponen-biaya/index', [
            ...$overview,
            'history' => $history,
            'permissions' => ['can_manage' => $canManage],
            'commands' => [
                'create_group_url' => $canManage ? route('finance.tariff.groups.create', absolute: false) : null,
                'create_component_url' => $canManage ? route('finance.tariff.components.create', absolute: false) : null,
                'create_catalogue_url' => $canManage ? route('finance.tariff.catalogues.create', absolute: false) : null,
                'create_tariff_url' => $canManage ? route('finance.tariff.items.create', absolute: false) : null,
            ],
            'read_error' => null,
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

    private function manager(Request $request): User
    {
        $actor = $this->actor($request);
        $this->policy->manage($actor);

        return $actor;
    }

    private function mutate(Request $request, callable $operation, string $success): RedirectResponse
    {
        try {
            $result = $operation();
        } catch (FinanceTariffDenied $denied) {
            throw ValidationException::withMessages(['master' => __($denied->getMessage())]);
        } catch (FinanceTariffAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Audit recording for tariffs is unavailable.');
        }

        return back()->with('success', $result->replayed ? 'The same operation is shown again.' : $success);
    }

    /** @return array<string, array<int, mixed>> */
    private function createRules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:/\A[A-Z0-9][A-Z0-9._-]*\z/'],
            'display_name' => ['required', 'string', 'min:2', 'max:160'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'idempotency_key' => $this->idempotencyRules(),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function reviseRules(): array
    {
        return [
            'display_name' => ['required', 'string', 'min:2', 'max:160'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'expected_digest' => $this->digestRules(),
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'idempotency_key' => $this->idempotencyRules(),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function retireRules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'expected_digest' => $this->digestRules(),
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirm' => ['accepted'],
            'idempotency_key' => $this->idempotencyRules(),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function componentRules(): array
    {
        return ['description' => ['nullable', 'string', 'max:500'], 'terminology_label' => ['nullable', 'string', 'max:160']];
    }

    /** @return array<string, array<int, mixed>> */
    private function tariffRules(): array
    {
        return [
            'care_setting' => ['required', Rule::in(['OUTPATIENT', 'EMERGENCY', 'INPATIENT'])],
            'service_domain' => ['required', Rule::in(['GENERAL_SERVICE', 'LABORATORY', 'RADIOLOGY', 'ACCOMMODATION'])],
            'reference_label' => ['nullable', 'string', 'max:160'],
            'ward_class_label' => ['nullable', 'string', 'max:100'],
            'amount_rupiah' => ['required', 'integer', 'min:1'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
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

    /** @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function withActions(array $record, string $kind, bool $canManage): array
    {
        [$routeGroup, $parameter] = match ($kind) {
            'group' => ['groups', 'group'],
            'component' => ['components', 'component'],
            'catalogue' => ['catalogues', 'catalogue'],
            'tariff' => ['items', 'tariff'],
            default => throw new \LogicException('Unsupported tariff master action kind.'),
        };
        $mutationState = $kind === 'tariff' ? $record['latest_head_state'] : $record['state'];
        $activeAndManageable = $canManage && $mutationState === 'ACTIVE';
        $parameters = [$parameter => $record['public_id']];

        return [
            ...$record,
            'actions' => [
                'history_url' => route("finance.tariff.{$routeGroup}.history", $parameters, false),
                'revise_url' => $activeAndManageable ? route("finance.tariff.{$routeGroup}.revise", $parameters, false) : null,
                'retire_url' => $activeAndManageable ? route("finance.tariff.{$routeGroup}.retire", $parameters, false) : null,
            ],
        ];
    }
}
