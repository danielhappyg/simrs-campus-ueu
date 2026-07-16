<?php

namespace App\Modules\Encounter\Models;

use App\Modules\Encounter\Enums\QueueEventStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property string $ticket_number
 * @property QueueEventStatus $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property-read Encounter $encounter
 * @property-read ServiceLocation $location
 * @property-read Assignment $actorAssignment
 */
class QueueEvent extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'encounter_id',
        'location_id',
        'actor_assignment_id',
        'ticket_number',
        'status',
        'reason',
        'started_at',
        'ended_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            $encounter = Encounter::query()->find($event->encounter_id);
            $assignment = Assignment::query()->find($event->actor_assignment_id);

            if (! $encounter
                || ! $assignment
                || $encounter->location_id !== $event->location_id
                || $encounter->session_id !== $assignment->session_id
                || ($assignment->patient_id !== null && $assignment->patient_id !== $encounter->patient_id)
                || ($assignment->encounter_id !== null && $assignment->encounter_id !== $encounter->getKey())) {
                throw new DomainException('Queue event actor, location, and encounter context must match.');
            }
        });
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /**
     * @return BelongsTo<ServiceLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ServiceLocation::class);
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function actorAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'actor_assignment_id');
    }

    protected function casts(): array
    {
        return [
            'status' => QueueEventStatus::class,
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }
}
