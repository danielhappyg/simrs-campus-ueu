<?php

namespace App\Support\Finance;

use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffItemVersion;

final class FinanceTariffContentDigest
{
    public function group(FinanceCostComponentGroup $group): string
    {
        return FinanceCanonicalJson::digest([
            'group_code' => $group->group_code,
            'display_name' => $group->display_name,
            'state' => $group->state,
            'version' => (int) $group->version,
        ]);
    }

    public function component(FinanceCostComponent $component, FinanceCostComponentGroup $group): string
    {
        return FinanceCanonicalJson::digest([
            'component_code' => $component->component_code,
            'group_public_id' => $group->public_id,
            'group_code' => $group->group_code,
            'display_name' => $component->display_name,
            'description' => $component->description,
            'terminology_label' => $component->terminology_label,
            'state' => $component->state,
            'version' => (int) $component->version,
        ]);
    }

    public function catalogue(FinanceTariffCatalogue $catalogue): string
    {
        return FinanceCanonicalJson::digest([
            'catalogue_code' => $catalogue->catalogue_code,
            'display_name' => $catalogue->display_name,
            'state' => $catalogue->state,
            'version' => (int) $catalogue->version,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function tariff(array $attributes): string
    {
        return FinanceCanonicalJson::digest([
            'tariff_code' => $attributes['tariff_code'],
            'catalogue_public_id' => $attributes['catalogue_public_id'],
            'catalogue_code' => $attributes['catalogue_code'],
            'component_public_id' => $attributes['component_public_id'],
            'component_code' => $attributes['component_code'],
            'component_content_digest' => $attributes['component_content_digest'],
            'display_name' => $attributes['display_name'],
            'care_setting' => $attributes['care_setting'],
            'service_domain' => $attributes['service_domain'],
            'reference_label' => $attributes['reference_label'],
            'ward_class_label' => $attributes['ward_class_label'],
            'amount_rupiah' => (int) $attributes['amount_rupiah'],
            'state' => $attributes['state'],
            'effective_from' => $attributes['effective_from'],
            'version' => (int) $attributes['version'],
        ]);
    }

    public function retainedResult(string $type, string $publicId, int $version, string $state, string $contentDigest): string
    {
        return FinanceCanonicalJson::digest([$type, $publicId, $version, $state, $contentDigest]);
    }

    public function tariffVersion(FinanceTariffItemVersion $version, string $tariffCode): string
    {
        return $this->tariff([
            'tariff_code' => $tariffCode,
            'catalogue_public_id' => $version->catalogue_public_id,
            'catalogue_code' => $version->catalogue_code,
            'component_public_id' => $version->component_public_id,
            'component_code' => $version->component_code,
            'component_content_digest' => $version->component_content_digest,
            'display_name' => $version->display_name,
            'care_setting' => $version->care_setting,
            'service_domain' => $version->service_domain,
            'reference_label' => $version->reference_label,
            'ward_class_label' => $version->ward_class_label,
            'amount_rupiah' => $version->amount_rupiah,
            'state' => $version->state,
            'effective_from' => $version->effective_from->format('Y-m-d'),
            'version' => $version->version,
        ]);
    }
}
