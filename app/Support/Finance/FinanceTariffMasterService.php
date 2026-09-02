<?php

namespace App\Support\Finance;

use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceCostComponentGroupVersion;
use App\Models\FinanceCostComponentVersion;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffCatalogueVersion;
use App\Models\FinanceTariffCodeReservation;
use App\Models\FinanceTariffItem;
use App\Models\FinanceTariffItemVersion;
use App\Models\FinanceTariffOperationReceipt;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinanceTariffMasterService
{
    public const OP_GROUP_CREATE = 'FINANCE_COST_COMPONENT_GROUP_CREATE';

    public const OP_GROUP_REVISE = 'FINANCE_COST_COMPONENT_GROUP_REVISE';

    public const OP_GROUP_RETIRE = 'FINANCE_COST_COMPONENT_GROUP_RETIRE';

    public const OP_COMPONENT_CREATE = 'FINANCE_COST_COMPONENT_CREATE';

    public const OP_COMPONENT_REVISE = 'FINANCE_COST_COMPONENT_REVISE';

    public const OP_COMPONENT_RETIRE = 'FINANCE_COST_COMPONENT_RETIRE';

    public const OP_CATALOGUE_CREATE = 'FINANCE_TARIFF_CATALOGUE_CREATE';

    public const OP_CATALOGUE_REVISE = 'FINANCE_TARIFF_CATALOGUE_REVISE';

    public const OP_CATALOGUE_RETIRE = 'FINANCE_TARIFF_CATALOGUE_RETIRE';

    public const OP_TARIFF_CREATE = 'FINANCE_TARIFF_ITEM_CREATE';

    public const OP_TARIFF_APPEND = 'FINANCE_TARIFF_ITEM_APPEND_VERSION';

    public const OP_TARIFF_RETIRE = 'FINANCE_TARIFF_ITEM_RETIRE';

    private const DEFINITION = 'GOVERNED_EFFECTIVE_DATED_FINANCE_TARIFF_COMPONENT_MASTER_V1';

    public function __construct(
        private readonly FinanceTariffActorPolicy $policy,
        private readonly AuditRecorder $audit,
        private readonly FinanceTariffContentDigest $digests,
    ) {}

    public function createGroup(User $actor, string $code, string $displayName, string $reason, string $key, ?string $correlationId = null): FinanceTariffMutationResult
    {
        return $this->audited($actor, self::OP_GROUP_CREATE, null, function () use ($actor, $code, $displayName, $reason, $key, $correlationId): FinanceTariffMutationResult {
            $this->policy->manage($actor);
            $code = FinanceCostComponentGroup::normalizeCode($code);
            $name = $this->name($displayName);
            $reason = $this->reason($reason);
            $this->code($code);
            $this->correlation($correlationId);

            return $this->execute($actor, self::OP_GROUP_CREATE, $key, compact('code', 'name', 'reason'), FinanceTariffOperationReceipt::RESULT_GROUP, $correlationId, function () use ($actor, $code, $name, $reason, $correlationId): FinanceCostComponentGroup {
                $this->reserve($actor, FinanceTariffCodeReservation::TYPE_GROUP, $code);
                $group = new FinanceCostComponentGroup([
                    'group_code' => $code, 'display_name' => $name,
                    'state' => FinanceCostComponentGroup::ACTIVE, 'version' => 1,
                    'current_content_digest' => str_repeat('0', 64),
                ]);
                $group->current_content_digest = $this->digests->group($group);
                $group->save();
                $this->groupVersion($group, $actor, $reason, null, $correlationId);

                return $group;
            });
        });
    }

    public function reviseGroup(User $actor, string $publicId, string $displayName, int $expectedVersion, string $expectedDigest, string $reason, string $key, ?string $correlationId = null): FinanceTariffMutationResult
    {
        return $this->audited($actor, self::OP_GROUP_REVISE, $publicId, function () use ($actor, $publicId, $displayName, $expectedVersion, $expectedDigest, $reason, $key, $correlationId): FinanceTariffMutationResult {
            $this->policy->manage($actor);
            $name = $this->name($displayName);
            $reason = $this->reason($reason);
            $this->expected($expectedVersion, $expectedDigest);
            $this->correlation($correlationId);

            return $this->execute($actor, self::OP_GROUP_REVISE, $key, compact('publicId', 'name', 'expectedVersion', 'expectedDigest', 'reason'), FinanceTariffOperationReceipt::RESULT_GROUP, $correlationId, function () use ($actor, $publicId, $name, $expectedVersion, $expectedDigest, $reason, $correlationId): FinanceCostComponentGroup {
                $group = FinanceCostComponentGroup::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
                $this->assertMutable($group, $expectedVersion, $expectedDigest);
                $before = $group->current_content_digest;
                $group->display_name = $name;
                $group->version++;
                $group->current_content_digest = $this->digests->group($group);
                $group->save();
                $this->groupVersion($group, $actor, $reason, $before, $correlationId);

                return $group;
            });
        });
    }

    public function retireGroup(User $actor, string $publicId, int $expectedVersion, string $expectedDigest, string $reason, string $key, ?string $correlationId = null): FinanceTariffMutationResult
    {
        return $this->audited($actor, self::OP_GROUP_RETIRE, $publicId, function () use ($actor, $publicId, $expectedVersion, $expectedDigest, $reason, $key, $correlationId): FinanceTariffMutationResult {
            $this->policy->manage($actor);
            $reason = $this->reason($reason);
            $this->expected($expectedVersion, $expectedDigest);
            $this->correlation($correlationId);

            return $this->execute($actor, self::OP_GROUP_RETIRE, $key, compact('publicId', 'expectedVersion', 'expectedDigest', 'reason'), FinanceTariffOperationReceipt::RESULT_GROUP, $correlationId, function () use ($actor, $publicId, $expectedVersion, $expectedDigest, $reason, $correlationId): FinanceCostComponentGroup {
                $group = FinanceCostComponentGroup::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
                $this->assertMutable($group, $expectedVersion, $expectedDigest);
                if (FinanceCostComponent::query()->where('group_id', $group->id)->where('state', FinanceCostComponent::ACTIVE)->lockForUpdate()->exists()) {
                    throw new FinanceTariffDenied('active_components_remain', 'Group masih memiliki komponen biaya aktif.');
                }
                $before = $group->current_content_digest;
                $group->state = FinanceCostComponentGroup::RETIRED;
                $group->version++;
                $group->current_content_digest = $this->digests->group($group);
                $group->save();
                $this->groupVersion($group, $actor, $reason, $before, $correlationId);

                return $group;
            });
        });
    }

    public function createComponent(User $actor, string $groupPublicId, string $code, string $displayName, ?string $description, ?string $terminologyLabel, string $reason, string $key, ?string $correlationId = null): FinanceTariffMutationResult
    {
        return $this->audited($actor, self::OP_COMPONENT_CREATE, null, function () use ($actor, $groupPublicId, $code, $displayName, $description, $terminologyLabel, $reason, $key, $correlationId): FinanceTariffMutationResult {
            $this->policy->manage($actor);
            $code = FinanceCostComponent::normalizeCode($code);
            $name = $this->name($displayName);
            $description = $this->optional($description, 4000);
            $terminologyLabel = $this->optional($terminologyLabel, 160);
            $reason = $this->reason($reason);
            $this->code($code);
            $this->correlation($correlationId);

            return $this->execute($actor, self::OP_COMPONENT_CREATE, $key, compact('groupPublicId', 'code', 'name', 'description', 'terminologyLabel', 'reason'), FinanceTariffOperationReceipt::RESULT_COMPONENT, $correlationId, function () use ($actor, $groupPublicId, $code, $name, $description, $terminologyLabel, $reason, $correlationId): FinanceCostComponent {
                $group = FinanceCostComponentGroup::query()->where('public_id', $groupPublicId)->lockForUpdate()->firstOrFail();
                $this->assertUpstreamActive($group, 'group');
                $this->reserve($actor, FinanceTariffCodeReservation::TYPE_COMPONENT, $code);
                $component = new FinanceCostComponent([
                    'group_id' => $group->id, 'component_code' => $code, 'display_name' => $name,
                    'description' => $description, 'terminology_label' => $terminologyLabel,
                    'state' => FinanceCostComponent::ACTIVE, 'version' => 1,
                    'current_content_digest' => str_repeat('0', 64),
                ]);
                $component->current_content_digest = $this->digests->component($component, $group);
                $component->save();
                $this->componentVersion($component, $group, $actor, $reason, null, $correlationId);

                return $component;
            });
        });
    }

    public function reviseComponent(User $actor, string $publicId, string $displayName, ?string $description, ?string $terminologyLabel, int $expectedVersion, string $expectedDigest, string $reason, string $key, ?string $correlationId = null): FinanceTariffMutationResult
    {
        return $this->componentChange(false, $actor, $publicId, $displayName, $description, $terminologyLabel, $expectedVersion, $expectedDigest, $reason, $key, $correlationId);
    }

    public function retireComponent(User $actor, string $publicId, int $expectedVersion, string $expectedDigest, string $reason, string $key, ?string $correlationId = null): FinanceTariffMutationResult
    {
        return $this->componentChange(true, $actor, $publicId, '', null, null, $expectedVersion, $expectedDigest, $reason, $key, $correlationId);
    }

    private function componentChange(bool $retire, User $actor, string $publicId, string $displayName, ?string $description, ?string $terminologyLabel, int $expectedVersion, string $expectedDigest, string $reason, string $key, ?string $correlationId): FinanceTariffMutationResult
    {
        $operation = $retire ? self::OP_COMPONENT_RETIRE : self::OP_COMPONENT_REVISE;

        return $this->audited($actor, $operation, $publicId, function () use ($retire, $actor, $publicId, $displayName, $description, $terminologyLabel, $expectedVersion, $expectedDigest, $reason, $key, $correlationId, $operation): FinanceTariffMutationResult {
            $this->policy->manage($actor);
            $name = $retire ? null : $this->name($displayName);
            $description = $retire ? null : $this->optional($description, 4000);
            $terminologyLabel = $retire ? null : $this->optional($terminologyLabel, 160);
            $reason = $this->reason($reason);
            $this->expected($expectedVersion, $expectedDigest);
            $this->correlation($correlationId);

            return $this->execute($actor, $operation, $key, compact('publicId', 'name', 'description', 'terminologyLabel', 'expectedVersion', 'expectedDigest', 'reason'), FinanceTariffOperationReceipt::RESULT_COMPONENT, $correlationId, function () use ($retire, $actor, $publicId, $name, $description, $terminologyLabel, $expectedVersion, $expectedDigest, $reason, $correlationId): FinanceCostComponent {
                $candidate = FinanceCostComponent::query()->where('public_id', $publicId)->firstOrFail();
                $catalogueIds = $retire ? FinanceTariffItem::query()->where('component_id', $candidate->id)->pluck('catalogue_id')->unique()->sort()->values()->all() : [];
                if ($catalogueIds !== []) {
                    FinanceTariffCatalogue::query()->whereIn('id', $catalogueIds)->orderBy('id')->lockForUpdate()->get();
                }
                $group = FinanceCostComponentGroup::query()->whereKey($candidate->group_id)->lockForUpdate()->firstOrFail();
                $component = FinanceCostComponent::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                $this->assertMutable($component, $expectedVersion, $expectedDigest);
                if ($retire) {
                    $items = FinanceTariffItem::query()->where('component_id', $component->id)->orderBy('id')->lockForUpdate()->get();
                    if ($items->contains(fn (FinanceTariffItem $item): bool => $this->hasCurrentOrFutureDependency($item))) {
                        throw new FinanceTariffDenied('dependent_tariffs_remain', 'Komponen masih digunakan tarif saat ini atau masa depan.');
                    }
                }
                $before = $component->current_content_digest;
                if ($retire) {
                    $component->state = FinanceCostComponent::RETIRED;
                } else {
                    $component->display_name = $name;
                    $component->description = $description;
                    $component->terminology_label = $terminologyLabel;
                }
                $component->version++;
                $component->current_content_digest = $this->digests->component($component, $group);
                $component->save();
                $this->componentVersion($component, $group, $actor, $reason, $before, $correlationId);

                return $component;
            });
        });
    }

    public function createCatalogue(User $actor, string $code, string $displayName, string $reason, string $key, ?string $correlationId = null): FinanceTariffMutationResult
    {
        return $this->audited($actor, self::OP_CATALOGUE_CREATE, null, function () use ($actor, $code, $displayName, $reason, $key, $correlationId): FinanceTariffMutationResult {
            $this->policy->manage($actor);
            $code = FinanceTariffCatalogue::normalizeCode($code);
            $name = $this->name($displayName);
            $reason = $this->reason($reason);
            $this->code($code);
            $this->correlation($correlationId);

            return $this->execute($actor, self::OP_CATALOGUE_CREATE, $key, compact('code', 'name', 'reason'), FinanceTariffOperationReceipt::RESULT_CATALOGUE, $correlationId, function () use ($actor, $code, $name, $reason, $correlationId): FinanceTariffCatalogue {
                $this->reserve($actor, FinanceTariffCodeReservation::TYPE_CATALOGUE, $code);
                $catalogue = new FinanceTariffCatalogue(['catalogue_code' => $code, 'display_name' => $name, 'state' => FinanceTariffCatalogue::ACTIVE, 'version' => 1, 'current_content_digest' => str_repeat('0', 64)]);
                $catalogue->current_content_digest = $this->digests->catalogue($catalogue);
                $catalogue->save();
                $this->catalogueVersion($catalogue, $actor, $reason, null, $correlationId);

                return $catalogue;
            });
        });
    }

    public function reviseCatalogue(User $actor, string $publicId, string $displayName, int $expectedVersion, string $expectedDigest, string $reason, string $key, ?string $correlationId = null): FinanceTariffMutationResult
    {
        return $this->catalogueChange(false, $actor, $publicId, $displayName, $expectedVersion, $expectedDigest, $reason, $key, $correlationId);
    }

    public function retireCatalogue(User $actor, string $publicId, int $expectedVersion, string $expectedDigest, string $reason, string $key, ?string $correlationId = null): FinanceTariffMutationResult
    {
        return $this->catalogueChange(true, $actor, $publicId, '', $expectedVersion, $expectedDigest, $reason, $key, $correlationId);
    }

    private function catalogueChange(bool $retire, User $actor, string $publicId, string $displayName, int $expectedVersion, string $expectedDigest, string $reason, string $key, ?string $correlationId): FinanceTariffMutationResult
    {
        $operation = $retire ? self::OP_CATALOGUE_RETIRE : self::OP_CATALOGUE_REVISE;

        return $this->audited($actor, $operation, $publicId, function () use ($retire, $actor, $publicId, $displayName, $expectedVersion, $expectedDigest, $reason, $key, $correlationId, $operation): FinanceTariffMutationResult {
            $this->policy->manage($actor);
            $name = $retire ? null : $this->name($displayName);
            $reason = $this->reason($reason);
            $this->expected($expectedVersion, $expectedDigest);
            $this->correlation($correlationId);

            return $this->execute($actor, $operation, $key, compact('publicId', 'name', 'expectedVersion', 'expectedDigest', 'reason'), FinanceTariffOperationReceipt::RESULT_CATALOGUE, $correlationId, function () use ($retire, $actor, $publicId, $name, $expectedVersion, $expectedDigest, $reason, $correlationId): FinanceTariffCatalogue {
                $catalogue = FinanceTariffCatalogue::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
                $this->assertMutable($catalogue, $expectedVersion, $expectedDigest);
                if ($retire) {
                    $items = FinanceTariffItem::query()->where('catalogue_id', $catalogue->id)->orderBy('id')->lockForUpdate()->get();
                    if ($items->contains(fn (FinanceTariffItem $item): bool => $this->hasCurrentOrFutureDependency($item))) {
                        throw new FinanceTariffDenied('dependent_tariffs_remain', 'Katalog masih memiliki tarif saat ini atau masa depan.');
                    }
                }
                $before = $catalogue->current_content_digest;
                if ($retire) {
                    $catalogue->state = FinanceTariffCatalogue::RETIRED;
                } else {
                    $catalogue->display_name = $name;
                }
                $catalogue->version++;
                $catalogue->current_content_digest = $this->digests->catalogue($catalogue);
                $catalogue->save();
                $this->catalogueVersion($catalogue, $actor, $reason, $before, $correlationId);

                return $catalogue;
            });
        });
    }

    public function createTariffItem(User $actor, string $cataloguePublicId, string $componentPublicId, string $tariffCode, string $displayName, string $careSetting, string $serviceDomain, ?string $referenceLabel, ?string $wardClassLabel, int $amountRupiah, string $effectiveFrom, string $reason, string $key, ?string $correlationId = null): FinanceTariffMutationResult
    {
        return $this->audited($actor, self::OP_TARIFF_CREATE, null, function () use ($actor, $cataloguePublicId, $componentPublicId, $tariffCode, $displayName, $careSetting, $serviceDomain, $referenceLabel, $wardClassLabel, $amountRupiah, $effectiveFrom, $reason, $key, $correlationId): FinanceTariffMutationResult {
            $this->policy->manage($actor);
            $tariffCode = FinanceTariffItem::normalizeCode($tariffCode);
            $name = $this->name($displayName);
            $careSetting = mb_strtoupper(trim($careSetting));
            $serviceDomain = mb_strtoupper(trim($serviceDomain));
            $referenceLabel = $this->optional($referenceLabel, 160);
            $wardClassLabel = $this->optional($wardClassLabel, 120);
            $effectiveFrom = $this->date($effectiveFrom);
            $reason = $this->reason($reason);
            $this->code($tariffCode);
            $this->tariffValues($careSetting, $serviceDomain, $amountRupiah);
            if ($effectiveFrom < now()->toDateString()) {
                throw new FinanceTariffDenied('retroactive_effective_date', 'Versi pertama tarif tidak boleh berlaku sebelum tanggal server.');
            }
            $this->correlation($correlationId);

            $payload = compact('cataloguePublicId', 'componentPublicId', 'tariffCode', 'name', 'careSetting', 'serviceDomain', 'referenceLabel', 'wardClassLabel', 'amountRupiah', 'effectiveFrom', 'reason');

            return $this->execute($actor, self::OP_TARIFF_CREATE, $key, $payload, FinanceTariffOperationReceipt::RESULT_TARIFF_ITEM, $correlationId, function () use ($actor, $cataloguePublicId, $componentPublicId, $tariffCode, $name, $careSetting, $serviceDomain, $referenceLabel, $wardClassLabel, $amountRupiah, $effectiveFrom, $reason, $correlationId): FinanceTariffItem {
                $catalogue = FinanceTariffCatalogue::query()->where('public_id', $cataloguePublicId)->lockForUpdate()->firstOrFail();
                $componentCandidate = FinanceCostComponent::query()->where('public_id', $componentPublicId)->firstOrFail();
                $group = FinanceCostComponentGroup::query()->whereKey($componentCandidate->group_id)->lockForUpdate()->firstOrFail();
                $component = FinanceCostComponent::query()->whereKey($componentCandidate->id)->lockForUpdate()->firstOrFail();
                $this->assertUpstreamActive($catalogue, 'catalogue');
                $this->assertUpstreamActive($group, 'group');
                $this->assertUpstreamActive($component, 'component');
                $this->reserve($actor, FinanceTariffCodeReservation::TYPE_TARIFF_ITEM, $tariffCode);

                $item = new FinanceTariffItem([
                    'catalogue_id' => $catalogue->id, 'component_id' => $component->id,
                    'tariff_code' => $tariffCode, 'state' => FinanceTariffItem::ACTIVE,
                    'version' => 1, 'latest_effective_from' => $effectiveFrom,
                    'current_content_digest' => str_repeat('0', 64),
                ]);
                $attributes = $this->tariffAttributes($item, $catalogue, $component, $name, $careSetting, $serviceDomain, $referenceLabel, $wardClassLabel, $amountRupiah, FinanceTariffItem::ACTIVE, $effectiveFrom, $reason, null, $correlationId);
                $attributes['actor_user_id'] = $actor->id;
                $item->current_content_digest = $this->digests->tariff($attributes);
                $item->save();
                $attributes['tariff_item_id'] = $item->id;
                $attributes['content_digest'] = $item->current_content_digest;
                FinanceTariffItemVersion::query()->create($attributes);

                return $item;
            });
        });
    }

    public function appendTariffItemVersion(User $actor, string $itemPublicId, string $displayName, string $careSetting, string $serviceDomain, ?string $referenceLabel, ?string $wardClassLabel, int $amountRupiah, string $effectiveFrom, int $expectedVersion, string $expectedDigest, string $reason, string $key, ?string $correlationId = null): FinanceTariffMutationResult
    {
        return $this->tariffChange(false, $actor, $itemPublicId, $displayName, $careSetting, $serviceDomain, $referenceLabel, $wardClassLabel, $amountRupiah, $effectiveFrom, $expectedVersion, $expectedDigest, $reason, $key, $correlationId);
    }

    public function retireTariffItem(User $actor, string $itemPublicId, string $effectiveFrom, int $expectedVersion, string $expectedDigest, string $reason, string $key, ?string $correlationId = null): FinanceTariffMutationResult
    {
        return $this->tariffChange(true, $actor, $itemPublicId, '', '', '', null, null, 1, $effectiveFrom, $expectedVersion, $expectedDigest, $reason, $key, $correlationId);
    }

    private function tariffChange(bool $retire, User $actor, string $itemPublicId, string $displayName, string $careSetting, string $serviceDomain, ?string $referenceLabel, ?string $wardClassLabel, int $amountRupiah, string $effectiveFrom, int $expectedVersion, string $expectedDigest, string $reason, string $key, ?string $correlationId): FinanceTariffMutationResult
    {
        $operation = $retire ? self::OP_TARIFF_RETIRE : self::OP_TARIFF_APPEND;

        return $this->audited($actor, $operation, $itemPublicId, function () use ($retire, $actor, $itemPublicId, $displayName, $careSetting, $serviceDomain, $referenceLabel, $wardClassLabel, $amountRupiah, $effectiveFrom, $expectedVersion, $expectedDigest, $reason, $key, $correlationId, $operation): FinanceTariffMutationResult {
            $this->policy->manage($actor);
            $name = $retire ? null : $this->name($displayName);
            $careSetting = $retire ? null : mb_strtoupper(trim($careSetting));
            $serviceDomain = $retire ? null : mb_strtoupper(trim($serviceDomain));
            $referenceLabel = $retire ? null : $this->optional($referenceLabel, 160);
            $wardClassLabel = $retire ? null : $this->optional($wardClassLabel, 120);
            $effectiveFrom = $this->date($effectiveFrom);
            $reason = $this->reason($reason);
            $this->expected($expectedVersion, $expectedDigest);
            if (! $retire) {
                $this->tariffValues((string) $careSetting, (string) $serviceDomain, $amountRupiah);
            }
            if ($effectiveFrom < now()->toDateString()) {
                throw new FinanceTariffDenied('retroactive_effective_date', 'Versi tarif baru tidak boleh berlaku sebelum tanggal server.');
            }
            $this->correlation($correlationId);
            $payload = compact('itemPublicId', 'name', 'careSetting', 'serviceDomain', 'referenceLabel', 'wardClassLabel', 'amountRupiah', 'effectiveFrom', 'expectedVersion', 'expectedDigest', 'reason');

            return $this->execute($actor, $operation, $key, $payload, FinanceTariffOperationReceipt::RESULT_TARIFF_ITEM, $correlationId, function () use ($retire, $actor, $itemPublicId, $name, $careSetting, $serviceDomain, $referenceLabel, $wardClassLabel, $amountRupiah, $effectiveFrom, $expectedVersion, $expectedDigest, $reason, $correlationId): FinanceTariffItem {
                [$catalogue, $group, $component, $item, $latest] = $this->lockTariffGraph($itemPublicId);
                $this->assertMutable($item, $expectedVersion, $expectedDigest);
                if ($latest->version !== $item->version || ! hash_equals($item->current_content_digest, $latest->content_digest)) {
                    throw new FinanceTariffDenied('stale_digest', 'Kepala tarif tidak cocok dengan versi terakhir.');
                }
                if ($effectiveFrom <= $latest->effective_from->format('Y-m-d')) {
                    throw new FinanceTariffDenied('effective_date_not_after_latest', 'Tanggal berlaku harus setelah versi terakhir.');
                }
                $this->assertUpstreamActive($catalogue, 'catalogue');
                $this->assertUpstreamActive($group, 'group');
                $this->assertUpstreamActive($component, 'component');

                $before = $item->current_content_digest;
                $nextVersion = $item->version + 1;
                $attributes = $this->tariffAttributes(
                    $item, $catalogue, $component,
                    $retire ? $latest->display_name : (string) $name,
                    $retire ? $latest->care_setting : (string) $careSetting,
                    $retire ? $latest->service_domain : (string) $serviceDomain,
                    $retire ? $latest->reference_label : $referenceLabel,
                    $retire ? $latest->ward_class_label : $wardClassLabel,
                    $retire ? $latest->amount_rupiah : $amountRupiah,
                    $retire ? FinanceTariffItem::RETIRED : FinanceTariffItem::ACTIVE,
                    $effectiveFrom, $reason, $before, $correlationId, $nextVersion,
                );
                $attributes['actor_user_id'] = $actor->id;
                $digest = $this->digests->tariff($attributes);
                $item->state = $retire ? FinanceTariffItem::RETIRED : FinanceTariffItem::ACTIVE;
                $item->version = $nextVersion;
                $item->latest_effective_from = Carbon::parse($effectiveFrom);
                $item->current_content_digest = $digest;
                $item->save();
                $attributes['tariff_item_id'] = $item->id;
                $attributes['content_digest'] = $digest;
                FinanceTariffItemVersion::query()->create($attributes);

                return $item;
            });
        });
    }

    public function resolveEffectiveTariff(User $actor, string $tariffCode, string $serviceDate): ?FinanceTariffItemVersion
    {
        $this->policy->view($actor);
        $tariffCode = FinanceTariffItem::normalizeCode($tariffCode);
        $this->code($tariffCode);
        $serviceDate = $this->date($serviceDate);
        $item = FinanceTariffItem::query()->where('tariff_code', $tariffCode)->first();
        if (! $item instanceof FinanceTariffItem) {
            return null;
        }
        $version = FinanceTariffItemVersion::query()->where('tariff_item_id', $item->id)
            ->whereDate('effective_from', '<=', $serviceDate)
            ->orderByDesc('effective_from')->orderByDesc('version')->first();

        return $version instanceof FinanceTariffItemVersion && $version->state === FinanceTariffItem::ACTIVE
            ? $version->setRelation('tariffItem', $item)
            : null;
    }

    /** @param array<string, mixed> $payload */
    private function execute(User $actor, string $operation, string $key, array $payload, string $resultType, ?string $correlationId, callable $write): FinanceTariffMutationResult
    {
        $key = mb_strtolower(trim($key));
        if (! preg_match('/\A[a-z0-9][a-z0-9._:-]{7,254}\z/', $key)) {
            throw new FinanceTariffDenied('validation_failed', 'Kunci idempotensi tidak valid.');
        }
        $payloadDigest = FinanceCanonicalJson::digest([$operation, $payload, self::DEFINITION]);

        try {
            return DB::transaction(function () use ($actor, $operation, $key, $payloadDigest, $resultType, $correlationId, $write): FinanceTariffMutationResult {
                return FinanceTariffMutationScope::run(function () use ($actor, $operation, $key, $payloadDigest, $resultType, $correlationId, $write): FinanceTariffMutationResult {
                    if ($replay = $this->replay($actor, $operation, $key, $payloadDigest, $resultType)) {
                        $this->recordSuccess($actor, $operation, $resultType, $replay->record, true, $correlationId);

                        return $replay;
                    }
                    $record = $write();
                    $contentDigest = $this->retainedContentDigest($resultType, $record, (int) $record->getAttribute('version'));
                    $state = (string) $record->getAttribute('state');
                    $version = (int) $record->getAttribute('version');
                    $this->recordSuccess($actor, $operation, $resultType, $record, false, $correlationId);
                    FinanceTariffOperationReceipt::query()->create([
                        'actor_user_id' => $actor->id,
                        'operation' => $operation,
                        'idempotency_key' => $key,
                        'payload_digest' => $payloadDigest,
                        'result_type' => $resultType,
                        'result_public_id' => (string) $record->getAttribute('public_id'),
                        'result_version' => $version,
                        'result_state' => $state,
                        'result_digest' => $this->digests->retainedResult($resultType, (string) $record->getAttribute('public_id'), $version, $state, $contentDigest),
                        'request_correlation_id' => $correlationId,
                        'completed_at' => now(),
                    ]);

                    return new FinanceTariffMutationResult($record, false);
                });
            }, 3);
        } catch (UniqueConstraintViolationException) {
            if ($replay = $this->replay($actor, $operation, $key, $payloadDigest, $resultType)) {
                $this->recordSuccess($actor, $operation, $resultType, $replay->record, true, $correlationId);

                return $replay;
            }
            throw new FinanceTariffDenied('concurrent_state_conflict', 'Operasi bersamaan telah mengubah master tarif.');
        } catch (FinanceTariffDenied $denied) {
            if (in_array($denied->reason, ['stale_version', 'stale_digest', 'concurrent_state_conflict'], true)
                && ($replay = $this->replay($actor, $operation, $key, $payloadDigest, $resultType))) {
                $this->recordSuccess($actor, $operation, $resultType, $replay->record, true, $correlationId);

                return $replay;
            }
            throw $denied;
        }
    }

    private function replay(User $actor, string $operation, string $key, string $payloadDigest, string $resultType): ?FinanceTariffMutationResult
    {
        $query = FinanceTariffOperationReceipt::query()->where('actor_user_id', $actor->id)
            ->where('operation', $operation)->where('idempotency_key', $key);
        $receipt = $query->first();
        if (! $receipt instanceof FinanceTariffOperationReceipt) {
            return null;
        }
        if (! hash_equals($receipt->payload_digest, $payloadDigest) || $receipt->result_type !== $resultType) {
            throw new FinanceTariffDenied('idempotency_key_conflict', 'Kunci idempotensi sudah digunakan untuk muatan lain.');
        }
        $record = $this->resolveResult($resultType, $receipt->result_public_id);
        if (! $record instanceof Model) {
            throw new FinanceTariffDenied('receipt_corrupt', 'Bukti operasi tarif tidak dapat direkonsiliasi.');
        }
        $contentDigest = $this->retainedContentDigest($resultType, $record, $receipt->result_version);
        $computed = $this->digests->retainedResult($resultType, $receipt->result_public_id, $receipt->result_version, $receipt->result_state, $contentDigest);
        if (! hash_equals($receipt->result_digest, $computed)) {
            throw new FinanceTariffDenied('receipt_corrupt', 'Bukti operasi tarif tidak dapat direkonsiliasi.');
        }

        return new FinanceTariffMutationResult($this->retainedView($resultType, $record, $receipt->result_version), true);
    }

    private function resolveResult(string $type, string $publicId): ?Model
    {
        $class = match ($type) {
            FinanceTariffOperationReceipt::RESULT_GROUP => FinanceCostComponentGroup::class,
            FinanceTariffOperationReceipt::RESULT_COMPONENT => FinanceCostComponent::class,
            FinanceTariffOperationReceipt::RESULT_CATALOGUE => FinanceTariffCatalogue::class,
            FinanceTariffOperationReceipt::RESULT_TARIFF_ITEM => FinanceTariffItem::class,
            default => null,
        };

        return $class === null ? null : $class::query()->where('public_id', $publicId)->first();
    }

    private function retainedContentDigest(string $type, Model $record, int $version): string
    {
        $evidence = match ($type) {
            FinanceTariffOperationReceipt::RESULT_GROUP => FinanceCostComponentGroupVersion::query()->where('group_id', $record->getKey())->where('version', $version)->first(),
            FinanceTariffOperationReceipt::RESULT_COMPONENT => FinanceCostComponentVersion::query()->where('component_id', $record->getKey())->where('version', $version)->first(),
            FinanceTariffOperationReceipt::RESULT_CATALOGUE => FinanceTariffCatalogueVersion::query()->where('catalogue_id', $record->getKey())->where('version', $version)->first(),
            FinanceTariffOperationReceipt::RESULT_TARIFF_ITEM => FinanceTariffItemVersion::query()->where('tariff_item_id', $record->getKey())->where('version', $version)->first(),
            default => null,
        };
        if (! $evidence instanceof Model || ! is_string($evidence->getAttribute('content_digest'))) {
            throw new FinanceTariffDenied('receipt_corrupt', 'Versi hasil operasi tarif tidak ditemukan.');
        }
        $stored = (string) $evidence->getAttribute('content_digest');
        $computed = match (true) {
            $evidence instanceof FinanceCostComponentGroupVersion => FinanceCanonicalJson::digest([
                'group_code' => $record->getAttribute('group_code'), 'display_name' => $evidence->display_name,
                'state' => $evidence->state, 'version' => $evidence->version,
            ]),
            $evidence instanceof FinanceCostComponentVersion => FinanceCanonicalJson::digest([
                'component_code' => $record->getAttribute('component_code'),
                'group_public_id' => $evidence->group_public_id, 'group_code' => $evidence->group_code,
                'display_name' => $evidence->display_name, 'description' => $evidence->description,
                'terminology_label' => $evidence->terminology_label, 'state' => $evidence->state,
                'version' => $evidence->version,
            ]),
            $evidence instanceof FinanceTariffCatalogueVersion => FinanceCanonicalJson::digest([
                'catalogue_code' => $record->getAttribute('catalogue_code'), 'display_name' => $evidence->display_name,
                'state' => $evidence->state, 'version' => $evidence->version,
            ]),
            default => $this->digests->tariffVersion($evidence, (string) $record->getAttribute('tariff_code')),
        };
        if (! hash_equals($stored, $computed)) {
            throw new FinanceTariffDenied('receipt_corrupt', 'Digest versi hasil operasi tarif tidak valid.');
        }

        return $stored;
    }

    private function retainedView(string $type, Model $record, int $version): Model
    {
        $view = clone $record;
        $attributes = $view->getAttributes();
        $evidence = match ($type) {
            FinanceTariffOperationReceipt::RESULT_GROUP => FinanceCostComponentGroupVersion::query()->where('group_id', $record->getKey())->where('version', $version)->firstOrFail(),
            FinanceTariffOperationReceipt::RESULT_COMPONENT => FinanceCostComponentVersion::query()->where('component_id', $record->getKey())->where('version', $version)->firstOrFail(),
            FinanceTariffOperationReceipt::RESULT_CATALOGUE => FinanceTariffCatalogueVersion::query()->where('catalogue_id', $record->getKey())->where('version', $version)->firstOrFail(),
            FinanceTariffOperationReceipt::RESULT_TARIFF_ITEM => FinanceTariffItemVersion::query()->where('tariff_item_id', $record->getKey())->where('version', $version)->firstOrFail(),
            default => throw new FinanceTariffDenied('receipt_corrupt', 'Tipe hasil operasi tarif tidak valid.'),
        };
        foreach ($evidence->getAttributes() as $field => $value) {
            if (! in_array($field, ['id', 'public_id', 'group_id', 'component_id', 'catalogue_id', 'tariff_item_id', 'actor_user_id'], true)) {
                $attributes[$field] = $value;
            }
        }
        $attributes['version'] = $version;
        $attributes['current_content_digest'] = $evidence->getAttribute('content_digest');
        if ($evidence instanceof FinanceTariffItemVersion) {
            $attributes['latest_effective_from'] = $evidence->getAttribute('effective_from');
        }
        $view->setRawAttributes($attributes, true);

        return $view;
    }

    private function recordSuccess(User $actor, string $operation, string $resultType, Model $record, bool $replayed, ?string $correlationId): void
    {
        $effectiveFrom = $resultType === FinanceTariffOperationReceipt::RESULT_TARIFF_ITEM
            ? $record->getAttribute('latest_effective_from')
            : null;
        if ($effectiveFrom instanceof \DateTimeInterface) {
            $effectiveFrom = $effectiveFrom->format('Y-m-d');
        }
        $effectiveFrom = is_string($effectiveFrom) ? substr($effectiveFrom, 0, 10) : null;
        if ($this->audit->record(
            'finance.tariff.mutate',
            'finance_tariff_record',
            (string) $record->getAttribute('public_id'),
            $actor,
            'SUCCESS',
            metadata: [
                'operation' => $operation, 'entity_type' => $this->auditEntityType($resultType),
                'state' => (string) $record->getAttribute('state'),
                'version' => (int) $record->getAttribute('version'),
                'effective_from' => $effectiveFrom,
                'replayed' => $replayed,
                'future_activation' => $effectiveFrom !== null && $effectiveFrom > now()->toDateString(),
            ],
            request: $this->auditRequest($correlationId),
        ) === null) {
            throw new FinanceTariffAuditUnavailable('Perubahan tarif dibatalkan karena audit wajib tidak tersedia.');
        }
    }

    private function audited(User $actor, string $operation, ?string $resource, callable $callback): FinanceTariffMutationResult
    {
        try {
            return $callback();
        } catch (AuthorizationException $exception) {
            $this->recordDenial($actor, $operation, null, 'role_not_permitted');
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            $this->recordDenial($actor, $operation, $resource, 'resource_not_found');
            throw $exception;
        } catch (FinanceTariffDenied $exception) {
            $this->recordDenial($actor, $operation, $resource, $exception->reason);
            throw $exception;
        }
    }

    private function recordDenial(User $actor, string $operation, ?string $resource, string $reason): void
    {
        $resource = is_string($resource) && Str::isUlid($resource) ? $resource : null;
        if ($this->audit->record('finance.tariff.mutate', 'finance_tariff_record', $resource, $actor, 'DENIED', $reason, [
            'operation' => $operation,
            'entity_type' => $this->auditEntityTypeForOperation($operation),
        ]) === null) {
            throw new FinanceTariffAuditUnavailable('Penolakan operasi tarif tidak dapat diaudit.');
        }
    }

    private function auditEntityType(string $resultType): string
    {
        return match ($resultType) {
            FinanceTariffOperationReceipt::RESULT_GROUP => 'COST_COMPONENT_GROUP',
            FinanceTariffOperationReceipt::RESULT_COMPONENT => 'COST_COMPONENT',
            FinanceTariffOperationReceipt::RESULT_CATALOGUE => 'TARIFF_CATALOGUE',
            FinanceTariffOperationReceipt::RESULT_TARIFF_ITEM => 'TARIFF_ITEM',
            default => throw new FinanceTariffDenied('validation_failed', 'Tipe hasil tarif tidak valid.'),
        };
    }

    private function auditEntityTypeForOperation(string $operation): string
    {
        return match ($operation) {
            self::OP_GROUP_CREATE, self::OP_GROUP_REVISE, self::OP_GROUP_RETIRE => 'COST_COMPONENT_GROUP',
            self::OP_COMPONENT_CREATE, self::OP_COMPONENT_REVISE, self::OP_COMPONENT_RETIRE => 'COST_COMPONENT',
            self::OP_CATALOGUE_CREATE, self::OP_CATALOGUE_REVISE, self::OP_CATALOGUE_RETIRE => 'TARIFF_CATALOGUE',
            self::OP_TARIFF_CREATE, self::OP_TARIFF_APPEND, self::OP_TARIFF_RETIRE => 'TARIFF_ITEM',
            default => 'TARIFF_ITEM',
        };
    }

    private function auditRequest(?string $correlationId): Request
    {
        $request = request();
        if ($correlationId === null || $request->attributes->get('request_id') === $correlationId) {
            return $request;
        }
        $copy = $request->duplicate();
        $copy->attributes->set('request_id', $correlationId);

        return $copy;
    }

    private function reserve(User $actor, string $type, string $code): void
    {
        if (FinanceTariffCodeReservation::query()->where('reservation_type', $type)->where('normalized_code', $code)->exists()) {
            throw new FinanceTariffDenied('validation_failed', 'Kode tersebut telah digunakan dan tetap dicadangkan.');
        }
        FinanceTariffCodeReservation::query()->create([
            'actor_user_id' => $actor->id,
            'reservation_type' => $type,
            'normalized_code' => $code,
            'created_at' => now(),
        ]);
    }

    private function groupVersion(FinanceCostComponentGroup $group, User $actor, string $reason, ?string $previousDigest, ?string $correlationId): void
    {
        FinanceCostComponentGroupVersion::query()->create([
            'group_id' => $group->id, 'actor_user_id' => $actor->id,
            'version' => $group->version, 'display_name' => $group->display_name,
            'state' => $group->state, 'reason' => $reason,
            'previous_content_digest' => $previousDigest,
            'content_digest' => $group->current_content_digest,
            'request_correlation_id' => $correlationId, 'created_at' => now(),
        ]);
    }

    private function componentVersion(FinanceCostComponent $component, FinanceCostComponentGroup $group, User $actor, string $reason, ?string $previousDigest, ?string $correlationId): void
    {
        FinanceCostComponentVersion::query()->create([
            'component_id' => $component->id, 'actor_user_id' => $actor->id,
            'version' => $component->version, 'group_public_id' => $group->public_id,
            'group_code' => $group->group_code, 'display_name' => $component->display_name,
            'description' => $component->description, 'terminology_label' => $component->terminology_label,
            'state' => $component->state, 'reason' => $reason,
            'previous_content_digest' => $previousDigest,
            'content_digest' => $component->current_content_digest,
            'request_correlation_id' => $correlationId, 'created_at' => now(),
        ]);
    }

    private function catalogueVersion(FinanceTariffCatalogue $catalogue, User $actor, string $reason, ?string $previousDigest, ?string $correlationId): void
    {
        FinanceTariffCatalogueVersion::query()->create([
            'catalogue_id' => $catalogue->id, 'actor_user_id' => $actor->id,
            'version' => $catalogue->version, 'display_name' => $catalogue->display_name,
            'state' => $catalogue->state, 'reason' => $reason,
            'previous_content_digest' => $previousDigest,
            'content_digest' => $catalogue->current_content_digest,
            'request_correlation_id' => $correlationId, 'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function tariffAttributes(FinanceTariffItem $item, FinanceTariffCatalogue $catalogue, FinanceCostComponent $component, string $displayName, string $careSetting, string $serviceDomain, ?string $referenceLabel, ?string $wardClassLabel, int $amountRupiah, string $state, string $effectiveFrom, string $reason, ?string $previousDigest, ?string $correlationId, ?int $version = null): array
    {
        return [
            'actor_user_id' => null,
            'version' => $version ?? $item->version,
            'tariff_code' => $item->tariff_code,
            'catalogue_public_id' => $catalogue->public_id,
            'catalogue_code' => $catalogue->catalogue_code,
            'component_public_id' => $component->public_id,
            'component_code' => $component->component_code,
            'component_content_digest' => $component->current_content_digest,
            'display_name' => $displayName,
            'care_setting' => $careSetting,
            'service_domain' => $serviceDomain,
            'reference_label' => $referenceLabel,
            'ward_class_label' => $wardClassLabel,
            'amount_rupiah' => $amountRupiah,
            'state' => $state,
            'effective_from' => $effectiveFrom,
            'reason' => $reason,
            'previous_content_digest' => $previousDigest,
            'request_correlation_id' => $correlationId,
            'created_at' => now(),
        ];
    }

    /** @return array{FinanceTariffCatalogue, FinanceCostComponentGroup, FinanceCostComponent, FinanceTariffItem, FinanceTariffItemVersion} */
    private function lockTariffGraph(string $itemPublicId): array
    {
        $candidate = FinanceTariffItem::query()->where('public_id', $itemPublicId)->firstOrFail();
        $componentCandidate = FinanceCostComponent::query()->whereKey($candidate->component_id)->firstOrFail();
        $catalogue = FinanceTariffCatalogue::query()->whereKey($candidate->catalogue_id)->lockForUpdate()->firstOrFail();
        $group = FinanceCostComponentGroup::query()->whereKey($componentCandidate->group_id)->lockForUpdate()->firstOrFail();
        $component = FinanceCostComponent::query()->whereKey($componentCandidate->id)->lockForUpdate()->firstOrFail();
        $item = FinanceTariffItem::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
        $latest = FinanceTariffItemVersion::query()->where('tariff_item_id', $item->id)->orderByDesc('version')->firstOrFail();

        return [$catalogue, $group, $component, $item, $latest];
    }

    private function hasCurrentOrFutureDependency(FinanceTariffItem $item): bool
    {
        if ($item->state === FinanceTariffItem::ACTIVE) {
            return true;
        }

        return $item->latest_effective_from->format('Y-m-d') > now()->toDateString();
    }

    private function assertMutable(Model $record, int $expectedVersion, string $expectedDigest): void
    {
        if ((string) $record->getAttribute('state') === FinanceTariffItem::RETIRED) {
            throw new FinanceTariffDenied('master_retired', 'Master yang telah dipensiunkan tidak dapat dibuka kembali.');
        }
        if ((int) $record->getAttribute('version') !== $expectedVersion) {
            throw new FinanceTariffDenied('stale_version', 'Versi master sudah berubah. Muat ulang sebelum melanjutkan.');
        }
        if (! hash_equals((string) $record->getAttribute('current_content_digest'), $expectedDigest)) {
            throw new FinanceTariffDenied('stale_digest', 'Fingerprint master sudah berubah. Muat ulang sebelum melanjutkan.');
        }
    }

    private function assertUpstreamActive(Model $record, string $label): void
    {
        if ((string) $record->getAttribute('state') !== FinanceTariffItem::ACTIVE) {
            throw new FinanceTariffDenied('upstream_inactive', ucfirst($label).' sumber tarif sudah dipensiunkan.');
        }
    }

    private function expected(int $version, string $digest): void
    {
        if ($version < 1 || ! preg_match('/\A[a-f0-9]{64}\z/', $digest)) {
            throw new FinanceTariffDenied('validation_failed', 'Versi atau fingerprint yang diharapkan tidak valid.');
        }
    }

    private function code(string $code): void
    {
        if (! preg_match('/\A[A-Z0-9][A-Z0-9._-]{1,63}\z/', $code)) {
            throw new FinanceTariffDenied('validation_failed', 'Kode master harus berupa identitas stabil 2-64 karakter.');
        }
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new FinanceTariffDenied('validation_failed', 'Nama tampilan master tidak valid.');
        }

        return $name;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new FinanceTariffDenied('validation_failed', 'Alasan perubahan wajib berisi 3-500 karakter.');
        }

        return $reason;
    }

    private function optional(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new FinanceTariffDenied('validation_failed', 'Label referensi terlalu panjang.');
        }

        return $value === '' ? null : $value;
    }

    private function tariffValues(string $careSetting, string $serviceDomain, int $amountRupiah): void
    {
        if (! in_array($careSetting, FinanceTariffItemVersion::CARE_SETTINGS, true)
            || ! in_array($serviceDomain, FinanceTariffItemVersion::SERVICE_DOMAINS, true)
            || $amountRupiah < 1) {
            throw new FinanceTariffDenied('validation_failed', 'Tarif memerlukan konteks tertutup dan nilai rupiah bulat positif.');
        }
    }

    private function date(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
        $errors = \DateTimeImmutable::getLastErrors();
        if (! $parsed || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== trim($date)) {
            throw new FinanceTariffDenied('validation_failed', 'Tanggal berlaku harus menggunakan format YYYY-MM-DD.');
        }

        return $parsed->format('Y-m-d');
    }

    private function correlation(?string $correlationId): void
    {
        if ($correlationId !== null && ! Str::isUlid($correlationId)) {
            throw new FinanceTariffDenied('validation_failed', 'ID korelasi permintaan tidak valid.');
        }
    }
}
