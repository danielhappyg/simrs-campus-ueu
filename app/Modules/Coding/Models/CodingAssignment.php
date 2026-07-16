<?php

namespace App\Modules\Coding\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalProcedureStatus;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\ClinicalProcedure;
use App\Modules\Coding\Enums\CodingAssignmentStatus;
use App\Modules\Coding\Enums\CodingSelectionMethod;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
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
 * @property CodingSourceType $source_type
 * @property int|null $source_condition_id
 * @property int|null $source_entry_version_id
 * @property int|null $source_procedure_id
 * @property int $terminology_release_id
 * @property int $terminology_concept_id
 * @property int $coder_user_id
 * @property int $coder_assignment_id
 * @property CodingAssignmentStatus $status
 * @property CodingSelectionMethod $selection_method
 * @property int|null $source_suggestion_run_id
 * @property int|null $source_candidate_id
 * @property string $source_clinical_content_hash
 * @property string $source_statement_hash
 * @property string $terminology_source_hash
 * @property string $content_hash
 * @property string|null $rationale
 * @property string|null $change_reason
 * @property int|null $supersedes_assignment_id
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $reviewed_at
 * @property-read ClinicalCondition|null $sourceCondition
 * @property-read ClinicalEntryVersion|null $sourceEntryVersion
 * @property-read ClinicalProcedure|null $sourceProcedure
 * @property-read TerminologyRelease $release
 * @property-read TerminologyConcept $concept
 * @property-read Assignment $coderAssignment
 */
class CodingAssignment extends Model
{
    use HasPublicUlid;

    private bool $lifecycleTransitionInProgress = false;

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
        'terminology_concept_id',
        'coder_user_id',
        'coder_assignment_id',
        'status',
        'selection_method',
        'source_suggestion_run_id',
        'source_candidate_id',
        'source_clinical_content_hash',
        'source_statement_hash',
        'terminology_source_hash',
        'content_hash',
        'rationale',
        'change_reason',
        'supersedes_assignment_id',
        'recorded_at',
        'submitted_at',
        'reviewed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $coding): void {
            $condition = $coding->source_condition_id === null
                ? null
                : ClinicalCondition::query()->find($coding->source_condition_id);
            $version = $coding->source_entry_version_id === null
                ? null
                : ClinicalEntryVersion::query()->with('clinicalEntry')->find($coding->source_entry_version_id);
            $procedure = $coding->source_procedure_id === null
                ? null
                : ClinicalProcedure::query()->with('closure')->find($coding->source_procedure_id);
            $release = TerminologyRelease::query()->find($coding->terminology_release_id);
            $concept = TerminologyConcept::query()->find($coding->terminology_concept_id);
            $coder = Assignment::query()->active()->find($coding->coder_assignment_id);
            $run = $coding->source_suggestion_run_id === null
                ? null
                : CodingSuggestionRun::query()->find($coding->source_suggestion_run_id);
            $candidate = $coding->source_candidate_id === null
                ? null
                : CodingSuggestionCandidate::query()->find($coding->source_candidate_id);

            $suggestedIsValid = $coding->selection_method === CodingSelectionMethod::Suggested
                && $run
                && $candidate
                && $candidate->coding_suggestion_run_id === $run->getKey()
                && $candidate->terminology_concept_id === $concept?->getKey()
                && $run->source_type === $coding->source_type
                && $run->source_condition_id === $coding->source_condition_id
                && $run->source_entry_version_id === $coding->source_entry_version_id
                && $run->source_procedure_id === $coding->source_procedure_id;
            $manualIsValid = $coding->selection_method === CodingSelectionMethod::Manual
                && $run === null
                && $candidate === null;
            $diagnosisSourceIsValid = $coding->source_type === CodingSourceType::Diagnosis
                && $condition
                && $version
                && $procedure === null
                && $condition->source_entry_version_id === $version->getKey()
                && $condition->patient_id === $coding->patient_id
                && $condition->encounter_id === $coding->encounter_id
                && $version->clinicalEntry->session_id === $coding->session_id
                && hash_equals($version->content_hash, $coding->source_clinical_content_hash)
                && hash_equals(hash('sha256', $condition->authored_text), $coding->source_statement_hash);
            $procedureSourceIsValid = $coding->source_type === CodingSourceType::Procedure
                && $condition === null
                && $version === null
                && $procedure
                && $procedure->session_id === $coding->session_id
                && $procedure->patient_id === $coding->patient_id
                && $procedure->encounter_id === $coding->encounter_id
                && $procedure->getRawOriginal('status') === ClinicalProcedureStatus::Completed->value
                && $procedure->closure->status === EncounterClosureStatus::Approved
                && hash_equals($procedure->content_hash, $coding->source_clinical_content_hash)
                && hash_equals(hash('sha256', $procedure->authored_text), $coding->source_statement_hash);

            if (! $release
                || ! $concept
                || ! $coder
                || (! $diagnosisSourceIsValid && ! $procedureSourceIsValid)
                || $concept->terminology_release_id !== $release->getKey()
                || $release->classification_system !== $coding->source_type->terminologySystem()
                || $coder->user_id !== $coding->coder_user_id
                || $coder->session_id !== $coding->session_id
                || $coder->patient_id !== $coding->patient_id
                || $coder->encounter_id !== $coding->encounter_id
                || ! $coder->hasCapability(Capability::CodingWrite)
                || $coding->status !== CodingAssignmentStatus::Draft
                || (! $suggestedIsValid && ! $manualIsValid)
                || ! hash_equals($release->source_sha256, $coding->terminology_source_hash)
                || ! hash_equals(hash('sha256', CanonicalJson::encode($coding->integrityPayload())), $coding->content_hash)) {
                throw new DomainException('A code assignment must preserve its exact clinical source, matching terminology, coder, selection method, and integrity hashes.');
            }
        });

        static::updating(function (self $coding): void {
            if (! $coding->lifecycleTransitionInProgress) {
                throw new DomainException('Code assignments are immutable outside their review lifecycle.');
            }

            $mutable = ['status', 'submitted_at', 'reviewed_at', 'updated_at'];
            $dirtyOutsideLifecycle = collect(array_keys($coding->getDirty()))
                ->reject(fn (string $field): bool => in_array($field, $mutable, true))
                ->isNotEmpty();

            if ($dirtyOutsideLifecycle) {
                throw new DomainException('Code-assignment content and provenance are immutable.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Code assignments are append-only.');
        });
    }

    public function persistStatus(CodingAssignmentStatus $target, CarbonImmutable $at): void
    {
        $allowed = match ($this->status) {
            CodingAssignmentStatus::Draft => [CodingAssignmentStatus::Submitted, CodingAssignmentStatus::ReviewRequired],
            CodingAssignmentStatus::Submitted => [CodingAssignmentStatus::Approved, CodingAssignmentStatus::ChangesRequested, CodingAssignmentStatus::ReviewRequired],
            CodingAssignmentStatus::Approved => [CodingAssignmentStatus::ReviewRequired],
            CodingAssignmentStatus::ChangesRequested => [CodingAssignmentStatus::ReviewRequired],
            CodingAssignmentStatus::ReviewRequired => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw new DomainException("Code assignment cannot transition from {$this->status->value} to {$target->value}.");
        }

        $this->lifecycleTransitionInProgress = true;

        try {
            $attributes = ['status' => $target];

            if ($target === CodingAssignmentStatus::Submitted) {
                $attributes['submitted_at'] = $at;
            }

            if (in_array($target, [CodingAssignmentStatus::Approved, CodingAssignmentStatus::ChangesRequested], true)) {
                $attributes['reviewed_at'] = $at;
            }

            $this->forceFill($attributes)->save();
        } finally {
            $this->lifecycleTransitionInProgress = false;
        }
    }

    /** @return array<string, mixed> */
    public function integrityPayload(): array
    {
        return [
            'sourceType' => $this->source_type->value,
            'sourceConditionId' => $this->source_condition_id,
            'sourceEntryVersionId' => $this->source_entry_version_id,
            'sourceProcedureId' => $this->source_procedure_id,
            'terminologyReleaseId' => $this->terminology_release_id,
            'terminologyConceptId' => $this->terminology_concept_id,
            'coderAssignmentId' => $this->coder_assignment_id,
            'selectionMethod' => $this->selection_method->value,
            'sourceSuggestionRunId' => $this->source_suggestion_run_id,
            'sourceCandidateId' => $this->source_candidate_id,
            'sourceClinicalContentHash' => $this->source_clinical_content_hash,
            'sourceStatementHash' => $this->source_statement_hash,
            'terminologySourceHash' => $this->terminology_source_hash,
            'rationale' => $this->rationale,
            'changeReason' => $this->change_reason,
            'supersedesAssignmentId' => $this->supersedes_assignment_id,
        ];
    }

    /** @return BelongsTo<ClinicalCondition, $this> */
    public function sourceCondition(): BelongsTo
    {
        return $this->belongsTo(ClinicalCondition::class, 'source_condition_id');
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
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

    /** @return BelongsTo<TerminologyRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(TerminologyRelease::class, 'terminology_release_id');
    }

    /** @return BelongsTo<TerminologyConcept, $this> */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(TerminologyConcept::class, 'terminology_concept_id');
    }

    /** @return BelongsTo<User, $this> */
    public function coder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coder_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function coderAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'coder_assignment_id');
    }

    /** @return BelongsTo<CodingSuggestionRun, $this> */
    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(CodingSuggestionRun::class, 'source_suggestion_run_id');
    }

    /** @return BelongsTo<CodingSuggestionCandidate, $this> */
    public function sourceCandidate(): BelongsTo
    {
        return $this->belongsTo(CodingSuggestionCandidate::class, 'source_candidate_id');
    }

    /** @return HasMany<CodingReviewActionModel, $this> */
    public function reviewActions(): HasMany
    {
        return $this->hasMany(CodingReviewActionModel::class);
    }

    protected function casts(): array
    {
        return [
            'source_type' => CodingSourceType::class,
            'status' => CodingAssignmentStatus::class,
            'selection_method' => CodingSelectionMethod::class,
            'recorded_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
