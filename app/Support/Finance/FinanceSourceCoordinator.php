<?php

namespace App\Support\Finance;

use App\Models\Encounter;
use App\Models\FinanceChargeEvent;
use App\Models\User;
use Illuminate\Support\Collection;

final class FinanceSourceCoordinator
{
    public function __construct(
        private readonly FinancePharmacySourceAdapter $pharmacy,
        private readonly FinanceRadiologySourceAdapter $radiology,
        private readonly FinanceLaboratorySourceAdapter $laboratory,
        private readonly FinanceAccommodationSourceAdapter $accommodation,
        private readonly FinanceSourceReadinessProjection $readiness,
    ) {}

    public function synchronize(Encounter $encounter, User $actor): FinanceSourceSynchronization
    {
        $this->pharmacy->synchronize($encounter, $actor);
        $this->radiology->synchronize($encounter, $actor);
        $this->laboratory->synchronize($encounter, $actor);
        $this->accommodation->synchronize($encounter, $actor);

        $events = $this->events($encounter);
        $this->verifyRetained($encounter, $events);
        $items = $this->readiness->items($encounter);
        $unresolved = array_filter($items, static fn (array $item): bool => ! in_array(
            $item['state'], ['SIAP_DISINKRONKAN', 'TERSINKRONISASI'], true,
        ));

        if ($events->isEmpty()) {
            $laboratoryUnresolved = collect($unresolved)->contains(
                static fn (array $item): bool => ($item['source_domain'] ?? null) === FinanceChargeEvent::SOURCE_LABORATORY,
            );
            $accommodationUnresolved = collect($unresolved)->contains(
                static fn (array $item): bool => ($item['source_domain'] ?? null) === FinanceChargeEvent::SOURCE_ACCOMMODATION,
            );
            throw new FinanceDenied(
                $unresolved === []
                    ? 'no_valued_source'
                    : ($accommodationUnresolved
                        ? 'unresolved_accommodation_source'
                        : ($laboratoryUnresolved ? 'unresolved_laboratory_source' : 'unresolved_radiology_source')),
                $unresolved === []
                    ? 'Belum ada sumber biaya bernilai yang dapat disinkronkan.'
                    : 'Satu atau lebih pemeriksaan selesai belum memiliki sumber biaya yang dapat diterbitkan.',
            );
        }

        return new FinanceSourceSynchronization($events, $items);
    }

    /** @param Collection<int, FinanceChargeEvent> $events */
    public function verifyRetained(Encounter $encounter, Collection $events): void
    {
        $unsupported = $events->reject(static fn (FinanceChargeEvent $event): bool => in_array(
            $event->source_domain,
            [
                FinanceChargeEvent::SOURCE_PHARMACY,
                FinanceChargeEvent::SOURCE_RADIOLOGY,
                FinanceChargeEvent::SOURCE_LABORATORY,
                FinanceChargeEvent::SOURCE_ACCOMMODATION,
            ],
            true,
        ));
        if ($unsupported->isNotEmpty()) {
            throw new FinanceDenied('unsupported_source', 'Jenis sumber biaya belum diizinkan.');
        }

        $this->pharmacy->verifyRetained(
            $encounter,
            $events->where('source_domain', FinanceChargeEvent::SOURCE_PHARMACY)->values(),
        );
        $this->radiology->verifyRetained(
            $encounter,
            $events->where('source_domain', FinanceChargeEvent::SOURCE_RADIOLOGY)->values(),
        );
        $this->laboratory->verifyRetained(
            $encounter,
            $events->where('source_domain', FinanceChargeEvent::SOURCE_LABORATORY)->values(),
        );
        $this->accommodation->verifyRetained(
            $encounter,
            $events->where('source_domain', FinanceChargeEvent::SOURCE_ACCOMMODATION)->values(),
        );

        $gross = (int) $events->where('event_type', FinanceChargeEvent::CHARGE)->sum('signed_amount');
        $reversal = (int) $events->where('event_type', FinanceChargeEvent::REVERSAL)->sum('signed_amount');
        if ($gross < 0 || $reversal > 0 || $gross + $reversal < 0) {
            throw new FinanceDenied('over_reversal', 'Rekonsiliasi nilai tagihan tidak valid.');
        }
    }

    /** @return array{resolved_count:int,unresolved_count:int,issue_blocked:bool,issue_blocker:?string,items:list<array<string,mixed>>} */
    public function readiness(Encounter $encounter): array
    {
        return $this->readiness->summary($encounter);
    }

    /** @return Collection<int, FinanceChargeEvent> */
    public function events(Encounter $encounter): Collection
    {
        return FinanceChargeEvent::query()->where('encounter_id', $encounter->id)
            ->orderBy('occurred_at')->orderBy('source_domain')->orderBy('source_public_id')->orderBy('id')->get();
    }
}
