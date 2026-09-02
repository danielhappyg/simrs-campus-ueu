<?php

namespace App\Support\Radiology;

use App\Models\Encounter;
use App\Models\RadiologyOrder;
use App\Models\RadiologyReportVersion;

final class RadiologyClosureGate
{
    /** @return array{active_order_public_ids:list<string>,stale_acknowledgement_order_public_ids:list<string>} */
    public function inspect(Encounter $encounter): array
    {
        $orders = $encounter->relationLoaded('radiologyOrders')
            ? $encounter->radiologyOrders->sortBy('public_id')
            : RadiologyOrder::query()->where('encounter_id', $encounter->id)->with(['reportVersions.acknowledgement'])->orderBy('public_id')->get();
        $active = [];
        $stale = [];
        foreach ($orders as $order) {
            if (in_array($order->status, [RadiologyOrder::ORDERED, RadiologyOrder::PERFORMED], true)) {
                $active[] = $order->public_id;

                continue;
            }
            if ($order->status !== RadiologyOrder::REPORTED_VERIFIED) {
                continue;
            }
            /** @var RadiologyReportVersion|null $latest */ $latest = $order->reportVersions->sortByDesc('version')->first();
            if (! $latest || ! in_array($latest->state, [RadiologyReportVersion::VERIFIED, RadiologyReportVersion::AMENDED_VERIFIED], true)) {
                $stale[] = $order->public_id;

                continue;
            }
            $expected = app(RadiologyEvidenceFingerprint::class)->current($latest);
            if (! $latest->acknowledgement || ! hash_equals($expected, (string) $latest->acknowledgement->report_fingerprint)) {
                $stale[] = $order->public_id;
            }
        }

        return ['active_order_public_ids' => $active, 'stale_acknowledgement_order_public_ids' => $stale];
    }
}
