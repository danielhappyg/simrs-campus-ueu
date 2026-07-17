<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Clinical\Enums\OutpatientSafetyDispositionOutcome;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Models\Assignment;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property string $request_key
 * @property int $encounter_id
 * @property int $source_clinical_entry_version_id
 * @property string $source_content_hash
 * @property int $actor_user_id
 * @property int $actor_assignment_id
 * @property OutpatientSafetyDispositionOutcome $outcome
 * @property string $rationale
 * @property CarbonImmutable $occurred_at
 * @property-read Encounter $encounter
 * @property-read ClinicalEntryVersion $sourceVersion
 * @property-read User $actor
 * @property-read Assignment $actorAssignment
 */
class OutpatientSafetyDisposition extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'request_key',
        'encounter_id',
        'source_clinical_entry_version_id',
        'source_content_hash',
        'actor_user_id',
        'actor_assignment_id',
        'outcome',
        'rationale',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $disposition): void {
            $encounter = Encounter::query()->find($disposition->encounter_id);
            $source = ClinicalEntryVersion::query()
                ->with('clinicalEntry')
                ->find($disposition->source_clinical_entry_version_id);
            $assignment = Assignment::query()
                ->active()
                ->find($disposition->actor_assignment_id);

            $matchesCase = $assignment
                && $encounter
                && $assignment->patient_id === $encounter->patient_id
                && $assignment->encounter_id === $encounter->getKey();
            $sessionWide = $assignment
                && $assignment->patient_id === null
                && $assignment->encounter_id === null;

            if (! $encounter
                || ! $source
                || ! $assignment
                || $encounter->status !== EncounterStatus::Escalated
                || $source->clinicalEntry->encounter_id !== $encounter->getKey()
                || $source->clinicalEntry->document_type !== ClinicalDocumentType::NursingIntake
                || $source->status !== ClinicalEntryStatus::Approved
                || data_get($source->content, 'safetyDecision') !== IntakeSafetyDecision::EscalateToSupervisor->value
                || ! hash_equals($source->content_hash, $disposition->source_content_hash)
                || $assignment->user_id !== $disposition->actor_user_id
                || $assignment->session_id !== $encounter->session_id
                || (! $matchesCase && ! $sessionWide)) {
                throw new DomainException(
                    'A safety disposition must match its escalated encounter, approved nursing source version and integrity hash, and active actor context.',
                );
            }
        });

        static::updating(function (): never {
            throw new DomainException('Outpatient safety dispositions are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new DomainException('Outpatient safety dispositions are append-only and cannot be deleted.');
        });
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<ClinicalEntryVersion, $this> */
    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(ClinicalEntryVersion::class, 'source_clinical_entry_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function actorAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'actor_assignment_id');
    }

    protected function casts(): array
    {
        return [
            'outcome' => OutpatientSafetyDispositionOutcome::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
