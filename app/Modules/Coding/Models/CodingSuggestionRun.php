<?php

namespace App\Modules\Coding\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalProcedureStatus;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\ClinicalProcedure;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Coding\Enums\CodingSuggestionOutcome;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
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
 * @property CodingSourceType $source_type
 * @property int|null $source_condition_id
 * @property int|null $source_entry_version_id
 * @property int|null $source_procedure_id
 * @property int $terminology_release_id
 * @property int $requested_by_user_id
 * @property int $requested_by_assignment_id
 * @property string $engine_type
 * @property string $engine_version
 * @property string $configuration_hash
 * @property string $normalized_input
 * @property string $normalized_input_hash
 * @property CodingSuggestionOutcome $outcome
 * @property CarbonImmutable $generated_at
 * @property-read ClinicalCondition|null $sourceCondition
 * @property-read ClinicalEntryVersion|null $sourceEntryVersion
 * @property-read ClinicalProcedure|null $sourceProcedure
 * @property-read TerminologyRelease $release
 */
class CodingSuggestionRun extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'request_key',
        'session_id',
        'patient_id',
        'encounter_id',
        'source_type',
        'source_condition_id',
        'source_entry_version_id',
        'source_procedure_id',
        'terminology_release_id',
        'requested_by_user_id',
        'requested_by_assignment_id',
        'engine_type',
        'engine_version',
        'configuration_hash',
        'normalized_input',
        'normalized_input_hash',
        'outcome',
        'generated_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $condition = $run->source_condition_id === null
                ? null
                : ClinicalCondition::query()->find($run->source_condition_id);
            $version = $run->source_entry_version_id === null
                ? null
                : ClinicalEntryVersion::query()->with('clinicalEntry')->find($run->source_entry_version_id);
            $procedure = $run->source_procedure_id === null
                ? null
                : ClinicalProcedure::query()->with('closure')->find($run->source_procedure_id);
            $release = TerminologyRelease::query()->find($run->terminology_release_id);
            $assignment = Assignment::query()->active()->find($run->requested_by_assignment_id);
            $diagnosisSourceIsValid = $run->source_type === CodingSourceType::Diagnosis
                && $condition
                && $version
                && $procedure === null
                && $condition->source_entry_version_id === $version->getKey()
                && $condition->patient_id === $run->patient_id
                && $condition->encounter_id === $run->encounter_id
                && $version->clinicalEntry->session_id === $run->session_id;
            $procedureSourceIsValid = $run->source_type === CodingSourceType::Procedure
                && $condition === null
                && $version === null
                && $procedure
                && $procedure->session_id === $run->session_id
                && $procedure->patient_id === $run->patient_id
                && $procedure->encounter_id === $run->encounter_id
                && $procedure->getRawOriginal('status') === ClinicalProcedureStatus::Completed->value
                && $procedure->closure->status === EncounterClosureStatus::Approved;

            if (! $release
                || ! $assignment
                || (! $diagnosisSourceIsValid && ! $procedureSourceIsValid)
                || $assignment->user_id !== $run->requested_by_user_id
                || $assignment->session_id !== $run->session_id
                || $assignment->patient_id !== $run->patient_id
                || $assignment->encounter_id !== $run->encounter_id
                || ! $assignment->hasCapability(Capability::CodingWrite)
                || $release->classification_system !== $run->source_type->terminologySystem()
                || $release->status !== TerminologyReleaseStatus::Active
                || ! hash_equals(hash('sha256', $run->normalized_input), $run->normalized_input_hash)) {
                throw new DomainException('A coding suggestion run must bind one exact clinical source, its matching active terminology release, and an authorized coder.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Coding suggestion runs are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Coding suggestion runs are append-only.');
        });
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<ClinicalCondition, $this> */
    public function sourceCondition(): BelongsTo
    {
        return $this->belongsTo(ClinicalCondition::class, 'source_condition_id');
    }

    /** @return BelongsTo<ClinicalEntryVersion, $this> */
    public function sourceEntryVersion(): BelongsTo
    {
        return $this->belongsTo(ClinicalEntryVersion::class, 'source_entry_version_id');
    }

    /** @return BelongsTo<ClinicalProcedure, $this> */
    public function sourceProcedure(): BelongsTo
    {
        return $this->belongsTo(ClinicalProcedure::class, 'source_procedure_id');
    }

    public function sourcePublicId(): string
    {
        return match ($this->source_type) {
            CodingSourceType::Diagnosis => $this->sourceCondition->public_id,
            CodingSourceType::Procedure => $this->sourceProcedure->public_id,
        };
    }

    public function sourceAuthoredText(): string
    {
        return match ($this->source_type) {
            CodingSourceType::Diagnosis => $this->sourceCondition->authored_text,
            CodingSourceType::Procedure => $this->sourceProcedure->authored_text,
        };
    }

    public function sourceContentHash(): string
    {
        return match ($this->source_type) {
            CodingSourceType::Diagnosis => $this->sourceEntryVersion->content_hash,
            CodingSourceType::Procedure => $this->sourceProcedure->content_hash,
        };
    }

    /** @return BelongsTo<TerminologyRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(TerminologyRelease::class, 'terminology_release_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function requestedByAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'requested_by_assignment_id');
    }

    /** @return HasMany<CodingSuggestionCandidate, $this> */
    public function candidates(): HasMany
    {
        return $this->hasMany(CodingSuggestionCandidate::class);
    }

    /** @return HasMany<CodingSuggestionDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(CodingSuggestionDecision::class);
    }

    protected function casts(): array
    {
        return [
            'source_type' => CodingSourceType::class,
            'outcome' => CodingSuggestionOutcome::class,
            'generated_at' => 'immutable_datetime',
        ];
    }
}
