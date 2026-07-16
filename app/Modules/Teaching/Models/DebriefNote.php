<?php

namespace App\Modules\Teaching\Models;

use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\DebriefNoteType;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Support\Models\HasPublicUlid;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $encounter_id
 * @property int $created_by_assignment_id
 * @property string $request_key
 * @property DebriefNoteType $note_type
 * @property-read Encounter $encounter
 * @property-read Assignment $creatorAssignment
 * @property-read Collection<int, DebriefNoteVersion> $versions
 */
class DebriefNote extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'encounter_id',
        'created_by_assignment_id',
        'request_key',
        'note_type',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $note): void {
            $encounter = Encounter::query()->with('session')->find($note->encounter_id);
            $assignment = Assignment::query()->active()->find($note->created_by_assignment_id);

            if (! $encounter
                || ! $assignment
                || $encounter->status !== EncounterStatus::Finalized
                || $encounter->environment_mode !== EnvironmentMode::Simulation
                || $encounter->session->status !== SessionStatus::Active
                || $assignment->session_id !== $encounter->session_id
                || ! $assignment->hasCapability(Capability::DebriefWrite)
                || ! self::assignmentMatchesEncounter($assignment, $encounter)) {
                throw new DomainException('A debrief note requires a finalized synthetic encounter and a matching active debrief author assignment.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Debrief note identity and type are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Debrief notes cannot be deleted.');
        });
    }

    public static function assignmentMatchesEncounter(Assignment $assignment, Encounter $encounter): bool
    {
        $matchesCase = $assignment->patient_id === $encounter->patient_id
            && $assignment->encounter_id === $encounter->getKey();
        $sessionWide = $assignment->patient_id === null
            && $assignment->encounter_id === null;

        return $matchesCase || $sessionWide;
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<Assignment, $this> */
    public function creatorAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'created_by_assignment_id');
    }

    /** @return HasMany<DebriefNoteVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DebriefNoteVersion::class);
    }

    protected function casts(): array
    {
        return [
            'note_type' => DebriefNoteType::class,
        ];
    }
}
