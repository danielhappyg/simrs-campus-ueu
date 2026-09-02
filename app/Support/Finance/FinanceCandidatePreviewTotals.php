<?php

namespace App\Support\Finance;

use App\Models\PharmacyFinancialSourceEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class FinanceCandidatePreviewTotals
{
    /**
     * @param  Collection<int, PharmacyFinancialSourceEvent>  $pharmacyEvents
     * @param  array{items?:list<array<string, mixed>>}  $readiness
     * @return array{source_event_count:int,gross_amount:int,reversal_amount:int,net_amount:int,latest_source_at:?string}
     */
    public function summarize(Collection $pharmacyEvents, array $readiness): array
    {
        $readyItems = collect($readiness['items'] ?? [])->filter(
            static fn (array $item): bool => ($item['state'] ?? null) === 'SIAP_DISINKRONKAN'
                && is_int($item['preview_signed_amount'] ?? null)
                && is_string($item['service_at'] ?? null),
        );
        $pharmacyGross = (int) $pharmacyEvents
            ->where('event_type', PharmacyFinancialSourceEvent::CHARGE)->sum('amount');
        $pharmacyReversal = (int) $pharmacyEvents
            ->where('event_type', PharmacyFinancialSourceEvent::REVERSAL)->sum('amount');
        $readyGross = (int) $readyItems->sum('preview_signed_amount');
        $latest = $pharmacyEvents->map(
            static fn (PharmacyFinancialSourceEvent $event): CarbonInterface => $event->occurred_at,
        )->concat($readyItems->map(
            static fn (array $item): CarbonInterface => Carbon::parse($item['service_at']),
        ))->sortByDesc(static fn (CarbonInterface $at): int => $at->getTimestamp())->first();

        return [
            'source_event_count' => $pharmacyEvents->count() + $readyItems->count(),
            'gross_amount' => $pharmacyGross + $readyGross,
            'reversal_amount' => $pharmacyReversal,
            'net_amount' => $pharmacyGross + $readyGross + $pharmacyReversal,
            'latest_source_at' => $latest?->toIso8601String(),
        ];
    }
}
