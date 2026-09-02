<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientLocationEvent;
use App\Models\InpatientWard;
use App\Models\User;

final class InpatientLocationHistoryProjection
{
    public function __construct(private readonly InpatientBedTransferActorPolicy $actorPolicy) {}

    /** @return array<string, mixed> */
    public function forEncounter(Encounter $encounter, User $actor): array
    {
        $encounter->loadMissing(['inpatientBed.ward', 'inpatientLocationEvents.actor']);
        $events = $encounter->inpatientLocationEvents->sortBy('sequence')->values();
        $bed = $encounter->inpatientBed;
        $ward = $bed instanceof InpatientBed ? $bed->ward : null;
        $current = $bed instanceof InpatientBed && $ward instanceof InpatientWard ? $this->snapshot($ward, $bed) : null;
        $currentSequence = (int) ($events->max('sequence') ?? 0);
        $sourceClaimCount = $bed instanceof InpatientBed
            ? Encounter::query()
                ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
                ->where(function ($query) use ($bed): void {
                    $query->where('inpatient_bed_id', $bed->id)
                        ->orWhere('bed_code', $bed->code);
                })
                ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
                ->count()
            : 0;
        $eligible = $current !== null
            && $this->actorPolicy->can($actor)
            && in_array($encounter->status, Encounter::BED_OCCUPYING_STATUSES, true)
            && ! $encounter->cancellation()->exists()
            && $encounter->patient()->where('is_synthetic', true)->exists()
            && $bed->state === InpatientBed::STATE_ACTIVE
            && $ward->state === InpatientWard::STATE_ACTIVE
            && $encounter->inpatient_bed_id === $bed->id
            && $encounter->bed_code === $bed->code;
        $eligible = $eligible && $sourceClaimCount === 1;

        $candidateBeds = $eligible ? InpatientBed::query()->with('ward')
            ->where('state', InpatientBed::STATE_ACTIVE)->where('service_class', $bed->service_class)
            ->whereKeyNot($bed->id)->orderBy('code')->get()
            : collect();
        $occupiedClaims = $candidateBeds->isEmpty() ? collect() : Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
            ->where(function ($query) use ($candidateBeds): void {
                $query->whereIn('inpatient_bed_id', $candidateBeds->pluck('id'))
                    ->orWhereIn('bed_code', $candidateBeds->pluck('code'));
            })
            ->get(['inpatient_bed_id', 'bed_code']);
        $occupiedBedIds = $occupiedClaims->pluck('inpatient_bed_id')->filter()->mapWithKeys(
            fn (mixed $id): array => [(int) $id => true],
        );
        $occupiedBedCodes = $occupiedClaims->pluck('bed_code')->filter()->mapWithKeys(
            fn (string $code): array => [$code => true],
        );
        $targets = $candidateBeds
            ->filter(fn (InpatientBed $candidate): bool => $candidate->ward->state === InpatientWard::STATE_ACTIVE
                && ! $occupiedBedIds->has($candidate->id)
                && ! $occupiedBedCodes->has($candidate->code))
            ->map(fn (InpatientBed $candidate): array => $this->snapshot($candidate->ward, $candidate))->values()->all();

        return [
            'history_baseline' => $events->isEmpty() && $current !== null ? 'LEGACY_CURRENT_PLACEMENT' : null,
            'history_complete' => $events->first()?->event_type === InpatientLocationEvent::TYPE_ADMISSION,
            'current_placement' => $current,
            'current_sequence' => $currentSequence,
            'events' => $events->map(fn (InpatientLocationEvent $event): array => [
                'public_id' => $event->public_id, 'event_type' => $event->event_type, 'sequence' => $event->sequence,
                'from_placement' => $event->event_type === InpatientLocationEvent::TYPE_ADMISSION ? null : $this->eventSnapshot($event, 'from'),
                'to_placement' => $this->eventSnapshot($event, 'to'),
                'actor' => ['public_id' => $event->actor->public_id, 'name' => $event->actor->name],
                'reason' => $event->reason, 'request_correlation_id' => $event->request_correlation_id,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ])->all(),
            'transfer' => [
                'allowed' => $eligible,
                'url' => $eligible ? route('pendaftaran.rawat-inap.bed-transfer', $encounter, false) : null,
                'expected_location_sequence' => $currentSequence,
                'expected_source_bed_public_id' => $eligible ? $bed->public_id : null,
                'target_beds' => $targets,
            ],
        ];
    }

    /** @return array<string, string> */
    private function snapshot(InpatientWard $ward, InpatientBed $bed): array
    {
        return ['ward_public_id' => $ward->public_id, 'ward_code' => $ward->code, 'ward_display_name' => $ward->display_name,
            'bed_public_id' => $bed->public_id, 'bed_code' => $bed->code, 'bed_display_name' => $bed->display_name,
            'room_label' => $bed->room_label, 'service_class' => $bed->service_class];
    }

    /** @return array<string, string> */
    private function eventSnapshot(InpatientLocationEvent $event, string $prefix): array
    {
        return ['ward_public_id' => $event->{$prefix.'_ward_public_id'}, 'ward_code' => $event->{$prefix.'_ward_code'},
            'ward_display_name' => $event->{$prefix.'_ward_display_name'}, 'bed_public_id' => $event->{$prefix.'_bed_public_id'},
            'bed_code' => $event->{$prefix.'_bed_code'}, 'bed_display_name' => $event->{$prefix.'_bed_display_name'},
            'room_label' => $event->{$prefix.'_room_label'}, 'service_class' => $event->{$prefix.'_service_class'}];
    }
}
