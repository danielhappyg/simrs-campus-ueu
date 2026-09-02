<?php

namespace App\Support\Warehouse;

use App\Models\WarehouseSupplierVersion;

final class WarehouseEvidenceFingerprint
{
    public const DEFINITION = 'MEDICATION_REPLENISHMENT_AND_WAREHOUSE_DEPOT_CUSTODY_V1';

    public function supplierVersion(WarehouseSupplierVersion $version, string $supplierPublicId): string
    {
        return WarehouseCanonicalJson::digest([
            'definition' => self::DEFINITION,
            'entity_type' => 'SUPPLIER_VERSION',
            'supplier_public_id' => $supplierPublicId,
            'version_public_id' => $version->public_id,
            'actor_user_id' => (int) $version->actor_user_id,
            'version' => (int) $version->version,
            'supplier_code' => $version->supplier_code_snapshot,
            'display_name' => $version->display_name,
            'synthetic_contact_name' => $version->synthetic_contact_name,
            'synthetic_email' => $version->synthetic_email,
            'synthetic_phone' => $version->synthetic_phone,
            'synthetic_reference' => $version->synthetic_reference,
            'state' => $version->state,
            'reason_code' => $version->reason_code,
            'previous_version_id' => $version->previous_version_id === null ? null : (int) $version->previous_version_id,
            'previous_version_number' => $version->previous_version_number === null ? null : (int) $version->previous_version_number,
            'previous_content_digest' => $version->previous_content_digest,
            'request_correlation_id' => $version->request_correlation_id,
        ]);
    }

    public function operationResult(
        string $resultType,
        string $resultPublicId,
        int $version,
        string $state,
        string $contentDigest,
    ): string {
        return WarehouseCanonicalJson::digest([
            'definition' => self::DEFINITION,
            'result_type' => $resultType,
            'result_public_id' => $resultPublicId,
            'version' => $version,
            'state' => $state,
            'content_digest' => $contentDigest,
        ]);
    }
}
