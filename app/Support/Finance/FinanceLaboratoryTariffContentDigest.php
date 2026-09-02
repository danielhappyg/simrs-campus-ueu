<?php

namespace App\Support\Finance;

use App\Models\FinanceLaboratoryTariffBinding;
use App\Models\FinanceLaboratoryTariffBindingVersion;
use App\Support\Laboratory\LaboratoryCanonicalJson;

final class FinanceLaboratoryTariffContentDigest
{
    /** @param array<string, mixed> $attributes */
    public function binding(array $attributes): string
    {
        $effectiveFrom = $attributes['effective_from'];
        if ($effectiveFrom instanceof \DateTimeInterface) {
            $effectiveFrom = $effectiveFrom->format('Y-m-d');
        }

        return FinanceCanonicalJson::digest([
            'laboratory_master_public_id' => $attributes['laboratory_master_public_id'],
            'laboratory_master_version_public_id' => $attributes['laboratory_master_version_public_id'],
            'laboratory_master_version' => (int) $attributes['laboratory_master_version'],
            'laboratory_master_content_digest' => $attributes['laboratory_master_content_digest'],
            'laboratory_master_code' => $attributes['laboratory_master_code'],
            'care_setting' => $attributes['care_setting'],
            'tariff_item_public_id' => $attributes['tariff_item_public_id'],
            'tariff_item_code' => $attributes['tariff_item_code'],
            'state' => $attributes['state'],
            'effective_from' => $effectiveFrom,
            'version' => (int) $attributes['version'],
        ]);
    }

    public function bindingVersion(FinanceLaboratoryTariffBinding $binding, FinanceLaboratoryTariffBindingVersion $version): string
    {
        return $this->binding([
            'laboratory_master_public_id' => $binding->laboratoryMaster->public_id,
            'laboratory_master_version_public_id' => $binding->laboratory_master_version_public_id,
            'laboratory_master_version' => $binding->laboratory_master_version,
            'laboratory_master_content_digest' => $binding->laboratory_master_content_digest,
            'laboratory_master_code' => $binding->laboratory_master_code,
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
            FinanceLaboratoryTariffOperation::RESULT_BINDING, $publicId, $version, $state, $contentDigest,
        ]);
    }

    /** @param array<int, array<string, mixed>> $components */
    public function laboratoryMasterVersion(
        string $displayName,
        string $specimenType,
        ?string $collectionInstruction,
        array $components,
        string $state,
    ): string {
        return LaboratoryCanonicalJson::digest([
            $displayName, $specimenType, $collectionInstruction, $components, $state,
        ]);
    }
}
