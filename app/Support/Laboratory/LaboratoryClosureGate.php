<?php

namespace App\Support\Laboratory;

use App\Models\Encounter;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryResultVersion;
use App\Models\LaboratorySpecimenAttempt;

final class LaboratoryClosureGate
{
    /** @return array{active_order_public_ids:list<string>,unresolved_specimen_order_public_ids:list<string>,stale_acknowledgement_order_public_ids:list<string>} */
    public function inspect(Encounter $encounter): array
    {
        $orders = $encounter->relationLoaded('laboratoryOrders')
            ? $encounter->laboratoryOrders->sortBy('public_id')
            : LaboratoryOrder::query()->where('encounter_id', $encounter->id)->with(['specimenAttempts', 'resultVersions.acknowledgement'])->orderBy('public_id')->get();
        $active = [];
        $unresolved = [];
        $stale = [];
        foreach ($orders as $order) {
            if ($order->status === LaboratoryOrder::CANCELLED) {
                continue;
            }
            if (in_array($order->status, [LaboratoryOrder::ORDERED, LaboratoryOrder::SPECIMEN_ACCEPTED], true)) {
                $active[] = $order->public_id;
            }
            $accepted = $order->specimenAttempts->firstWhere('state', LaboratorySpecimenAttempt::ACCEPTED);
            $latest = $order->resultVersions->sortByDesc('version')->first();
            if (! $accepted || ! $latest || ! in_array($latest->state, [LaboratoryResultVersion::VERIFIED, LaboratoryResultVersion::AMENDED_VERIFIED], true)) {
                $unresolved[] = $order->public_id;

                continue;
            }
            $expected = app(LaboratoryEvidenceFingerprint::class)->current($latest);
            if (! $latest->acknowledgement || ! hash_equals($expected, (string) $latest->acknowledgement->result_fingerprint)) {
                $stale[] = $order->public_id;
            }
        }

        return ['active_order_public_ids' => $active, 'unresolved_specimen_order_public_ids' => $unresolved, 'stale_acknowledgement_order_public_ids' => $stale];
    }
}
