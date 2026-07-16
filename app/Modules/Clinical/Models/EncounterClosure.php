<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Support\CanonicalJson;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $request_key
 * @property int $session_id
 * @property int $patient_id
 * @property int $encounter_id
 * @property int $author_assignment_id
 * @property int $version_number
 * @property string $schema_version
 * @property EncounterClosureStatus $status
 * @property array<string, mixed> $content
 * @property string $content_hash
 * @property string|null $change_reason
 * @property int|null $supersedes_closure_id
 * @property CarbonImmutable $clinical_occurrence_at
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $reviewed_at
 */
class EncounterClosure extends Model
{
    use HasPublicUlid;

    private bool $statusTransitionInProgress = false;

    protected $fillable = [
        'request_key',
        'session_id',
        'patient_id',
        'encounter_id',
        'author_user_id',
        'author_assignment_id',
        'version_number',
        'schema_version',
        'status',
        'content',
        'content_hash',
        'change_reason',
        'supersedes_closure_id',
        'clinical_occurrence_at',
        'recorded_at',
        'submitted_at',
        'reviewed_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $closure): void {
            $encounter = Encounter::query()->find($closure->encounter_id);
            $assignment = Assignment::query()->active()->find($closure->author_assignment_id);
            $current = self::query()
                ->where('encounter_id', $closure->encounter_id)
                ->orderByDesc('version_number')
                ->first();

            if (! $encounter
                || ! $assignment
                || $closure->author_user_id !== $assignment->user_id
                || $closure->session_id !== $encounter->session_id
                || $closure->patient_id !== $encounter->patient_id
                || $assignment->session_id !== $encounter->session_id
                || $assignment->patient_id !== $encounter->patient_id
                || $assignment->encounter_id !== $encounter->getKey()
                || ! $assignment->hasCapability(Capability::MedicalAssessmentWrite)) {
                throw new DomainException('An encounter closure must match its medical author and exact case context.');
            }

            if (! $closure->exists
                && (($current === null && ($closure->version_number !== 1 || $closure->supersedes_closure_id !== null))
                    || ($current !== null && ($closure->version_number !== $current->version_number + 1
                        || $closure->supersedes_closure_id !== $current->getKey())))) {
                throw new DomainException('An encounter closure must be the next immutable version in the encounter lineage.');
            }

            if (! $closure->exists
                && ! in_array($closure->status, [EncounterClosureStatus::Draft, EncounterClosureStatus::Submitted], true)) {
                throw new DomainException('A new encounter closure version must be saved as a draft or submitted version.');
            }

            if (! $closure->exists
                && (($closure->status === EncounterClosureStatus::Submitted && $closure->submitted_at === null)
                    || ($closure->status === EncounterClosureStatus::Draft && $closure->submitted_at !== null))) {
                throw new DomainException('Encounter closure submission status and timestamp must agree.');
            }

            if (! $closure->exists
                && in_array($current?->status, [EncounterClosureStatus::ChangesRequested, EncounterClosureStatus::Approved], true)
                && blank($closure->change_reason)) {
                throw new DomainException('A closure correction requires an attributed change reason.');
            }

            if (! hash_equals(hash('sha256', CanonicalJson::encode($closure->content)), $closure->content_hash)) {
                throw new DomainException('Encounter closure content does not match its integrity hash.');
            }

            if ($closure->exists && $closure->isDirty([
                'request_key',
                'session_id',
                'patient_id',
                'encounter_id',
                'author_user_id',
                'author_assignment_id',
                'version_number',
                'schema_version',
                'content',
                'content_hash',
                'change_reason',
                'supersedes_closure_id',
                'clinical_occurrence_at',
                'recorded_at',
                'submitted_at',
            ])) {
                throw new DomainException('Encounter closure content and provenance are immutable.');
            }

            if ($closure->exists
                && $closure->isDirty(['status', 'reviewed_at'])
                && ! $closure->statusTransitionInProgress) {
                throw new DomainException('Encounter closure lifecycle changes must use the workflow service.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Encounter closure versions are append-only.');
        });
    }

    /** @internal */
    public function persistReviewStatus(EncounterClosureStatus $target, CarbonImmutable $reviewedAt): void
    {
        if ($this->status !== EncounterClosureStatus::Submitted
            || ! in_array($target, [EncounterClosureStatus::ChangesRequested, EncounterClosureStatus::Approved], true)) {
            throw new DomainException("Encounter closure cannot transition from {$this->status->value} to {$target->value}.");
        }

        $this->statusTransitionInProgress = true;

        try {
            $this->status = $target;
            $this->reviewed_at = $reviewedAt;
            $this->save();
        } finally {
            $this->statusTransitionInProgress = false;
        }
    }

    /** @return BelongsTo<SimulationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SimulationSession::class);
    }

    /** @return BelongsTo<SyntheticPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(SyntheticPatient::class);
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function authorAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'author_assignment_id');
    }

    /** @return BelongsTo<EncounterClosure, $this> */
    public function supersedesClosure(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_closure_id');
    }

    /** @return HasMany<EncounterClosureReviewActionModel, $this> */
    public function reviewActions(): HasMany
    {
        return $this->hasMany(EncounterClosureReviewActionModel::class);
    }

    /** @return HasMany<ClinicalProcedure, $this> */
    public function procedures(): HasMany
    {
        return $this->hasMany(ClinicalProcedure::class);
    }

    protected function casts(): array
    {
        return [
            'status' => EncounterClosureStatus::class,
            'content' => 'array',
            'clinical_occurrence_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
