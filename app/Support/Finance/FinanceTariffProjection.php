<?php

namespace App\Support\Finance;

use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffItem;
use App\Models\FinanceTariffItemVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class FinanceTariffProjection
{
    public function __construct(private readonly FinanceTariffActorPolicy $policy) {}

    /** @return array<string, mixed> */
    public function overview(User $actor, ?string $asOfDate = null): array
    {
        $this->policy->view($actor);
        $asOfDate = $this->date($asOfDate ?? now()->toDateString());
        $groups = FinanceCostComponentGroup::query()->withCount('components')->orderBy('group_code')->get();
        $components = FinanceCostComponent::query()->with(['group'])->withCount('tariffItems')->orderBy('component_code')->get();
        $catalogues = FinanceTariffCatalogue::query()->withCount('tariffItems')->orderBy('catalogue_code')->get();
        $items = FinanceTariffItem::query()->with(['catalogue', 'component'])->orderBy('tariff_code')->get();

        return [
            'as_of_date' => $asOfDate,
            'groups' => $groups->map(fn (FinanceCostComponentGroup $group): array => [
                ...$this->head($group, $group->group_code),
                'component_count' => (int) $group->components_count,
            ])->values()->all(),
            'components' => $components->map(fn (FinanceCostComponent $component): array => [
                ...$this->head($component, $component->component_code),
                'description' => $component->description,
                'terminology_label' => $component->terminology_label,
                'group' => $this->reference($component->group, 'group_code'),
                'tariff_count' => (int) $component->tariff_items_count,
            ])->values()->all(),
            'catalogues' => $catalogues->map(fn (FinanceTariffCatalogue $catalogue): array => [
                ...$this->head($catalogue, $catalogue->catalogue_code),
                'tariff_count' => (int) $catalogue->tariff_items_count,
            ])->values()->all(),
            'tariffs' => $items->map(fn (FinanceTariffItem $item): array => $this->tariff($item, $this->overviewVersion($item, $asOfDate), $asOfDate))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function groupHistory(User $actor, string $publicId): array
    {
        $this->policy->view($actor);
        $head = FinanceCostComponentGroup::query()->where('public_id', $publicId)->firstOrFail();
        $versions = $head->versions()->with('actor')->orderBy('version')->get();

        return $this->history('group', $head->group_code, $head->display_name, $versions, false);
    }

    /** @return array<string, mixed> */
    public function componentHistory(User $actor, string $publicId): array
    {
        $this->policy->view($actor);
        $head = FinanceCostComponent::query()->where('public_id', $publicId)->firstOrFail();
        $versions = $head->versions()->with('actor')->orderBy('version')->get();

        return $this->history('component', $head->component_code, $head->display_name, $versions, false);
    }

    /** @return array<string, mixed> */
    public function catalogueHistory(User $actor, string $publicId): array
    {
        $this->policy->view($actor);
        $head = FinanceTariffCatalogue::query()->where('public_id', $publicId)->firstOrFail();
        $versions = $head->versions()->with('actor')->orderBy('version')->get();

        return $this->history('catalogue', $head->catalogue_code, $head->display_name, $versions, false);
    }

    /** @return array<string, mixed> */
    public function tariffHistory(User $actor, string $publicId): array
    {
        $this->policy->view($actor);
        $head = FinanceTariffItem::query()->where('public_id', $publicId)->firstOrFail();
        $versions = $head->versions()->with('actor')->orderBy('version')->get();
        $latest = $versions->last();

        return $this->history('tariff', $head->tariff_code, (string) $latest?->getAttribute('display_name'), $versions, true);
    }

    /** @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function effectiveTariffs(User $actor, string $serviceDate, array $filters = []): array
    {
        $this->policy->view($actor);
        $serviceDate = $this->date($serviceDate);
        $query = FinanceTariffItem::query()->with(['catalogue', 'component'])->orderBy('tariff_code');
        foreach ([
            'catalogue_public_id' => ['catalogue', 'public_id'],
            'catalogue_code' => ['catalogue', 'catalogue_code'],
            'component_public_id' => ['component', 'public_id'],
            'component_code' => ['component', 'component_code'],
        ] as $filter => [$relation, $column]) {
            if (isset($filters[$filter]) && is_string($filters[$filter]) && trim($filters[$filter]) !== '') {
                $value = trim($filters[$filter]);
                $query->whereHas($relation, fn ($related) => $related->where($column, $value));
            }
        }
        $items = $query->get();
        $result = [];
        foreach ($items as $item) {
            $versionQuery = FinanceTariffItemVersion::query()->where('tariff_item_id', $item->id)
                ->whereDate('effective_from', '<=', $serviceDate)
                ->orderByDesc('effective_from')->orderByDesc('version');
            $version = $versionQuery->first();
            if (! $version instanceof FinanceTariffItemVersion || $version->state !== FinanceTariffItem::ACTIVE) {
                continue;
            }
            if (isset($filters['care_setting']) && is_string($filters['care_setting'])
                && $version->care_setting !== mb_strtoupper(trim($filters['care_setting']))) {
                continue;
            }
            if (isset($filters['service_domain']) && is_string($filters['service_domain'])
                && $version->service_domain !== mb_strtoupper(trim($filters['service_domain']))) {
                continue;
            }
            $result[] = $this->tariff($item, $version, $serviceDate);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function head(Model $head, string $code, ?Model $version = null): array
    {
        return [
            'public_id' => (string) $head->getAttribute('public_id'),
            'code' => $code,
            'display_name' => (string) ($version?->getAttribute('display_name') ?? $head->getAttribute('display_name')),
            'state' => (string) ($version?->getAttribute('state') ?? $head->getAttribute('state')),
            'version' => (int) ($version?->getAttribute('version') ?? $head->getAttribute('version')),
            'content_digest' => (string) ($version?->getAttribute('content_digest') ?? $head->getAttribute('current_content_digest')),
        ];
    }

    /** @return array<string, mixed> */
    private function tariff(FinanceTariffItem $item, FinanceTariffItemVersion $version, string $asOfDate): array
    {
        $next = FinanceTariffItemVersion::query()->where('tariff_item_id', $item->id)
            ->whereDate('effective_from', '>', $version->effective_from->format('Y-m-d'))
            ->orderBy('effective_from')->orderBy('version')->first();
        $nextDate = $next?->effective_from?->format('Y-m-d');
        $effectiveFrom = $version->effective_from->format('Y-m-d');

        return [
            ...$this->head($item, $item->tariff_code, $version),
            'version_public_id' => $version->public_id,
            'latest_head_state' => $item->state,
            'latest_head_version' => (int) $item->version,
            'latest_head_content_digest' => $item->current_content_digest,
            'catalogue' => $this->reference($item->catalogue, 'catalogue_code'),
            'component' => $this->reference($item->component, 'component_code'),
            'care_setting' => $version->care_setting,
            'service_domain' => $version->service_domain,
            'reference_label' => $version->reference_label,
            'ward_class_label' => $version->ward_class_label,
            'amount_rupiah' => (int) $version->amount_rupiah,
            'effective_from' => $effectiveFrom,
            'next_effective_from' => $nextDate,
            'is_effective' => $version->state === FinanceTariffItem::ACTIVE
                && $effectiveFrom <= $asOfDate
                && ($nextDate === null || $asOfDate < $nextDate),
        ];
    }

    /** @return array{public_id:string,code:string,display_name:string} */
    private function reference(Model $record, string $codeColumn): array
    {
        return [
            'public_id' => (string) $record->getAttribute('public_id'),
            'code' => (string) $record->getAttribute($codeColumn),
            'display_name' => (string) $record->getAttribute('display_name'),
        ];
    }

    private function overviewVersion(FinanceTariffItem $item, string $asOfDate): FinanceTariffItemVersion
    {
        $effective = $item->versions()->whereDate('effective_from', '<=', $asOfDate)
            ->orderByDesc('effective_from')->orderByDesc('version')->first();

        return $effective ?? $item->versions()->orderBy('effective_from')->orderBy('version')->firstOrFail();
    }

    /**
     * @template TVersion of Model
     *
     * @param  Collection<int, TVersion>  $versions
     * @return array<string, mixed>
     */
    private function history(string $kind, string $code, string $displayName, Collection $versions, bool $effectiveDated): array
    {
        $values = $versions->values();

        return [
            'kind' => $kind,
            'code' => $code,
            'display_name' => $displayName,
            'versions' => $values->map(function (Model $version, int $index) use ($values, $effectiveDated): array {
                $next = $values->get($index + 1);
                $effectiveFrom = $effectiveDated ? $version->getAttribute('effective_from')?->format('Y-m-d') : null;
                $effectiveUntil = $effectiveDated && $next instanceof Model ? $next->getAttribute('effective_from')?->format('Y-m-d') : null;
                $actor = $version->getRelation('actor');

                return [
                    'public_id' => (string) $version->getAttribute('public_id'),
                    'version' => (int) $version->getAttribute('version'),
                    'state' => (string) $version->getAttribute('state'),
                    'effective_from' => $effectiveFrom,
                    'effective_until' => $effectiveUntil,
                    'display_name' => (string) $version->getAttribute('display_name'),
                    'amount_rupiah' => $effectiveDated ? (int) $version->getAttribute('amount_rupiah') : null,
                    'reason' => (string) $version->getAttribute('reason'),
                    'authored_at' => $version->getAttribute('created_at')->toIso8601String(),
                    'authored_by' => (string) ($actor?->getAttribute('name') ?? 'Pengguna tidak tersedia'),
                    'content_digest' => (string) $version->getAttribute('content_digest'),
                ];
            })->all(),
        ];
    }

    private function date(string $date): string
    {
        $date = trim($date);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if (! $parsed || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date) {
            throw new FinanceTariffDenied('validation_failed', 'Tanggal harus menggunakan format YYYY-MM-DD.');
        }

        return $date;
    }
}
