<?php

namespace App\Support\Finance;

use App\Models\FinanceRadiologyTariffBinding;
use App\Models\FinanceRadiologyTariffBindingVersion;

final class FinanceRadiologyTariffContentDigest
{
    /** @param array<string, mixed> $attributes */
    public function binding(array $attributes): string
    {
        $effectiveFrom = $attributes['effective_from'];
        if ($effectiveFrom instanceof \DateTimeInterface) {
            $effectiveFrom = $effectiveFrom->format('Y-m-d');
        }

        return FinanceCanonicalJson::digest([
            'radiology_master_public_id' => $attributes['radiology_master_public_id'],
            'radiology_master_version_public_id' => $attributes['radiology_master_version_public_id'],
            'radiology_master_version' => (int) $attributes['radiology_master_version'],
            'radiology_master_content_digest' => $attributes['radiology_master_content_digest'],
            'radiology_master_code' => $attributes['radiology_master_code'],
            'care_setting' => $attributes['care_setting'],
            'tariff_item_public_id' => $attributes['tariff_item_public_id'],
            'tariff_item_code' => $attributes['tariff_item_code'],
            'state' => $attributes['state'],
            'effective_from' => $effectiveFrom,
            'version' => (int) $attributes['version'],
        ]);
    }

    public function bindingVersion(FinanceRadiologyTariffBinding $binding, FinanceRadiologyTariffBindingVersion $version): string
    {
        return $this->binding([
            'radiology_master_public_id' => $binding->radiologyMaster->public_id,
            'radiology_master_version_public_id' => $binding->radiology_master_version_public_id,
            'radiology_master_version' => $binding->radiology_master_version,
            'radiology_master_content_digest' => $binding->radiology_master_content_digest,
            'radiology_master_code' => $binding->radiology_master_code,
            'care_setting' => $binding->care_setting,
            'tariff_item_public_id' => $version->tariff_item_public_id,
            'tariff_item_code' => $version->tariff_item_code,
            'state' => $version->state,
            'effective_from' => $version->effective_from,
            'version' => $version->version,
        ]);
    }

    public function retainedResult(string $publicId, int $version, string $state, string $contentDigest): string
    {
        return FinanceCanonicalJson::digest([
            FinanceRadiologyTariffOperation::RESULT_BINDING, $publicId, $version, $state, $contentDigest,
        ]);
    }

    public function radiologyMasterVersion(string $displayName, ?string $preparationInstruction, string $state): string
    {
        return hash('sha256', json_encode(
            [$displayName, $preparationInstruction, $state],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }
}
