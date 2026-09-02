<?php

namespace App\Support\Radiology;

use App\Models\RadiologyReportVersion;
use Illuminate\Support\Facades\DB;

final class RadiologyEvidenceFingerprint
{
    public function current(RadiologyReportVersion $latest): string
    {
        if ($latest->state === RadiologyReportVersion::VERIFIED) {
            $base = $latest;
        } else {
            $baseQuery = RadiologyReportVersion::query()->whereKey($latest->base_verified_version_id)
                ->where('radiology_order_id', $latest->radiology_order_id);
            if (DB::connection()->getDriverName() === 'mysql') {
                $baseQuery->sharedLock();
            }
            $base = $baseQuery->firstOrFail();
        }
        $baseDigest = $this->contentDigest($base);
        if ($base->state !== RadiologyReportVersion::VERIFIED
            || ! hash_equals($baseDigest, (string) $base->content_digest)
            || ! hash_equals($baseDigest, (string) ($latest->base_verified_digest ?? $baseDigest))) {
            throw new RadiologyDenied('evidence_fingerprint_invalid', 'Rantai bukti laporan tidak valid.');
        }

        $priorDigest = null;
        $amendmentsQuery = RadiologyReportVersion::query()
            ->where('radiology_order_id', $latest->radiology_order_id)
            ->where('state', RadiologyReportVersion::AMENDED_VERIFIED)
            ->where('version', '<=', $latest->version)
            ->orderBy('version');
        if (DB::connection()->getDriverName() === 'mysql') {
            $amendmentsQuery->sharedLock();
        }
        $amendments = $amendmentsQuery->get();
        foreach ($amendments as $amendment) {
            $computed = $this->contentDigest($amendment);
            if ($amendment->base_verified_version_id !== $base->id
                || ! hash_equals($baseDigest, (string) $amendment->base_verified_digest)
                || $amendment->prior_amendment_digest !== $priorDigest
                || ! hash_equals($computed, (string) $amendment->content_digest)) {
                throw new RadiologyDenied('evidence_fingerprint_invalid', 'Rantai bukti laporan tidak valid.');
            }
            $priorDigest = $computed;
        }
        if ($latest->state === RadiologyReportVersion::AMENDED_VERIFIED
            && ($amendments->isEmpty() || $amendments->last()->isNot($latest))) {
            throw new RadiologyDenied('evidence_fingerprint_invalid', 'Rantai bukti laporan tidak lengkap.');
        }

        return hash('sha256', implode('|', [$base->public_id, $base->version, $baseDigest, $priorDigest ?? '']));
    }

    public function contentDigest(RadiologyReportVersion $version): string
    {
        return $this->digest(
            $version->state,
            $version->findings,
            $version->impression,
            $version->recommendation,
            $version->amendment_reason,
            $version->base_verified_version_id,
            $version->amended_statement,
            $version->base_verified_digest,
            $version->prior_amendment_digest,
        );
    }

    public function digest(
        string $state,
        ?string $findings,
        ?string $impression,
        ?string $recommendation,
        ?string $amendmentReason,
        ?int $baseVersionId,
        ?string $amendedStatement,
        ?string $baseDigest,
        ?string $priorDigest,
    ): string {
        return hash('sha256', json_encode([
            $state,
            $findings,
            $impression,
            $recommendation,
            $amendmentReason,
            $baseVersionId,
            $amendedStatement,
            $baseDigest,
            $priorDigest,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
