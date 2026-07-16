<?php

namespace App\Modules\Encounter\Models;

use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property EncounterStatus|null $from_status
 * @property EncounterStatus $to_status
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 * @property-read Encounter $encounter
 * @property-read Assignment $actorAssignment
 */
class EncounterTransition extends Model
{
    use HasPublicUlid;

    public $timestamps = false;

    protected $fillable = [
        'encounter_id',
        'actor_assignment_id',
        'from_status',
        'to_status',
        'reason',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $transition): void {
            $encounter = Encounter::query()->find($transition->encounter_id);
            $assignment = Assignment::query()->find($transition->actor_assignment_id);

            if (! $encounter
                || ! $assignment
                || $encounter->session_id !== $assignment->session_id
                || ($assignment->patient_id !== null && $assignment->patient_id !== $encounter->patient_id)
                || ($assignment->encounter_id !== null && $assignment->encounter_id !== $encounter->getKey())) {
                throw new DomainException('Encounter transition actor and case context must match.');
            }
        });

        static::updating(function (): never {
            throw new LogicException('Encounter transitions are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Encounter transitions are append-only.');
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
     * @return BelongsTo<Assignment, $this>
     */
    public function actorAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'actor_assignment_id');
    }

    protected function casts(): array
    {
        return [
            'from_status' => EncounterStatus::class,
            'to_status' => EncounterStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
