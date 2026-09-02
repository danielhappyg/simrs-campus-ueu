<?php

namespace App\Support\Finance;

use App\Models\FinanceAccommodationTariffBinding;
use App\Models\FinanceAccommodationTariffBindingVersion;
use App\Support\CanonicalJson;

final class FinanceAccommodationTariffContentDigest
{
    /** @param array<string, mixed> $attributes */
    public function binding(array $attributes): string
    {
        $effective = $attributes['effective_from'];
        if ($effective instanceof \DateTimeInterface) {
            $effective = $effective->format('Y-m-d');
        }

        return FinanceCanonicalJson::digest([
            'bed_public_id' => $attributes['bed_public_id'],
            'inpatient_bed_version_public_id' => $attributes['inpatient_bed_version_public_id'],
            'inpatient_bed_version' => (int) $attributes['inpatient_bed_version'],
            'inpatient_bed_content_digest' => $attributes['inpatient_bed_content_digest'],
            'bed_code' => $attributes['bed_code'],
            'care_setting' => 'INPATIENT', 'pricing_unit' => 'OCCUPANCY_DAY',
            'tariff_item_public_id' => $attributes['tariff_item_public_id'],
            'tariff_item_code' => $attributes['tariff_item_code'],
            'state' => $attributes['state'], 'effective_from' => $effective, 'version' => (int) $attributes['version'],
        ]);
    }

    public function bindingVersion(FinanceAccommodationTariffBinding $binding, FinanceAccommodationTariffBindingVersion $version): string
    {
        return $this->binding([
            'bed_public_id' => $binding->bed_public_id,
            'inpatient_bed_version_public_id' => $binding->inpatient_bed_version_public_id,
            'inpatient_bed_version' => $binding->inpatient_bed_version,
            'inpatient_bed_content_digest' => $binding->inpatient_bed_content_digest,
            'bed_code' => $binding->bed_code,
            'tariff_item_public_id' => $version->tariff_item_public_id,
            'tariff_item_code' => $version->tariff_item_code,
            'state' => $version->state, 'effective_from' => $version->effective_from, 'version' => $version->version,
        ]);
    }

    public function retainedResult(string $publicId, int $version, string $state, string $digest): string
    {
        return FinanceCanonicalJson::digest([FinanceAccommodationTariffOperation::RESULT_BINDING, $publicId, $version, $state, $digest]);
    }

    public function inpatientBedVersion(string $bedCode, string $displayName, string $roomLabel, string $serviceClass, string $state, int $version): string
    {
        return hash('sha256', CanonicalJson::encode([
            'code' => $bedCode,
            'display_name' => $displayName,
            'room_label' => $roomLabel,
            'service_class' => $serviceClass,
            'state' => $state,
            'version' => $version,
        ]));
    }
}
