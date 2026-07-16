<?php

namespace App\Modules\Coding\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalProcedureStatus;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Clinical\Models\ClinicalProcedure;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Coding\Enums\CodingDecisionType;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Coding\Enums\ProcedureDocumentationCorrectionStatus;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\RecordQuality\Enums\RecordQualityReviewStatus;
use App\Modules\RecordQuality\Models\RecordQualityReview;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $coding_suggestion_decision_id
 * @property int $session_id
 * @property int $patient_id
 * @property int $encounter_id
 * @property int $source_procedure_id
 * @property int $source_closure_id
 * @property int $superseded_record_quality_review_id
 * @property int $record_quality_reviewer_assignment_id
 * @property int $requested_by_user_id
 * @property int $requested_by_assignment_id
 * @property int $responsible_user_id
 * @property int $responsible_assignment_id
 * @property ProcedureDocumentationCorrectionStatus $status
 * @property string $reason
 * @property string $requested_source_hash
 * @property string $requested_closure_hash
 * @property string $requested_statement_hash
 * @property CarbonImmutable $requested_at
 * @property int|null $response_closure_id
 * @property CarbonImmutable|null $closure_responded_at
 * @property int|null $replacement_record_quality_review_id
 * @property CarbonImmutable|null $resolved_at
 */
class ProcedureDocumentationCorrection extends Model
{
    use HasPublicUlid;

    private bool $lifecycleTransitionInProgress = false;

    protected $fillable = [
        'coding_suggestion_decision_id',
        'session_id',
        'patient_id',
        'encounter_id',
        'source_procedure_id',
        'source_closure_id',
        'superseded_record_quality_review_id',
        'record_quality_reviewer_assignment_id',
        'requested_by_user_id',
        'requested_by_assignment_id',
        'responsible_user_id',
        'responsible_assignment_id',
        'status',
        'reason',
        'requested_source_hash',
        'requested_closure_hash',
        'requested_statement_hash',
        'requested_at',
        'response_closure_id',
        'closure_responded_at',
        'replacement_record_quality_review_id',
        'resolved_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $correction): void {
            $decision = CodingSuggestionDecision::query()->with('run')->find($correction->coding_suggestion_decision_id);
            $procedure = ClinicalProcedure::query()->find($correction->source_procedure_id);
            $sourceClosure = EncounterClosure::query()->find($correction->source_closure_id);
            $quality = RecordQualityReview::query()->find($correction->superseded_record_quality_review_id);
            $requester = Assignment::query()->active()->find($correction->requested_by_assignment_id);
            $responsible = Assignment::query()->active()->find($correction->responsible_assignment_id);

            if (! $decision
                || $decision->decision !== CodingDecisionType::CorrectionRequested
                || $decision->run->source_type !== CodingSourceType::Procedure
                || $decision->run->source_procedure_id !== $correction->source_procedure_id
                || ! $procedure
                || $procedure->getRawOriginal('status') !== ClinicalProcedureStatus::Completed->value
                || ! $sourceClosure
                || $procedure->encounter_closure_id !== $sourceClosure->getKey()
                || $sourceClosure->encounter_id !== $correction->encounter_id
                || ! $quality
                || $quality->encounter_id !== $correction->encounter_id
                || $quality->reviewer_assignment_id !== $correction->record_quality_reviewer_assignment_id
                || ! $requester
                || $requester->user_id !== $correction->requested_by_user_id
                || $requester->getKey() !== $decision->decided_by_assignment_id
                || ! $requester->hasCapability(Capability::CodingWrite)
                || ! $responsible
                || $responsible->user_id !== $correction->responsible_user_id
                || $responsible->getKey() !== $procedure->recorder_assignment_id
                || $responsible->getKey() !== $sourceClosure->author_assignment_id
                || ! $responsible->hasCapability(Capability::MedicalAssessmentWrite)
                || ! hash_equals($procedure->content_hash, $correction->requested_source_hash)
                || ! hash_equals($sourceClosure->content_hash, $correction->requested_closure_hash)
                || ! hash_equals(hash('sha256', $procedure->authored_text), $correction->requested_statement_hash)) {
                throw new DomainException('A procedure correction must preserve its exact coder, closure author, performed source, hashes, and approved RMIK provenance.');
            }

            if (! $correction->exists && $correction->status !== ProcedureDocumentationCorrectionStatus::Open) {
                throw new DomainException('A new procedure documentation correction must begin open.');
            }

            if ($correction->exists && $correction->isDirty([
                'coding_suggestion_decision_id',
                'session_id',
                'patient_id',
                'encounter_id',
                'source_procedure_id',
                'source_closure_id',
                'superseded_record_quality_review_id',
                'record_quality_reviewer_assignment_id',
                'requested_by_user_id',
                'requested_by_assignment_id',
                'responsible_user_id',
                'responsible_assignment_id',
                'reason',
                'requested_source_hash',
                'requested_closure_hash',
                'requested_statement_hash',
                'requested_at',
            ])) {
                throw new DomainException('Procedure documentation correction provenance is immutable.');
            }

            if ($correction->exists
                && $correction->isDirty([
                    'status',
                    'response_closure_id',
                    'closure_responded_at',
                    'replacement_record_quality_review_id',
                    'resolved_at',
                ])
                && ! $correction->lifecycleTransitionInProgress) {
                throw new DomainException('Procedure documentation correction lifecycle changes must use the workflow service.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Procedure documentation corrections are append-only.');
        });
    }

    /** @internal */
    public function persistClosureResponse(EncounterClosure $closure): void
    {
        if (! in_array($this->status, [
            ProcedureDocumentationCorrectionStatus::Open,
            ProcedureDocumentationCorrectionStatus::ClosureResponseSubmitted,
        ], true)
            || $closure->encounter_id !== $this->encounter_id
            || $closure->author_assignment_id !== $this->responsible_assignment_id
            || $closure->status !== EncounterClosureStatus::Submitted
            || $closure->version_number <= $this->sourceClosure->version_number
            || data_get($closure->content, 'correctionContext.type') !== 'PROCEDURE_DOCUMENTATION_CORRECTION'
            || data_get($closure->content, 'correctionContext.procedureDocumentationCorrectionPublicId') !== $this->public_id
            || data_get($closure->content, 'correctionContext.requestedSourceHash') !== $this->requested_source_hash
            || data_get($closure->content, 'correctionContext.requestedClosureHash') !== $this->requested_closure_hash) {
            throw new DomainException('Only an exact submitted successor closure can answer this procedure correction.');
        }

        $this->transition(function () use ($closure): void {
            $this->status = ProcedureDocumentationCorrectionStatus::ClosureResponseSubmitted;
            $this->response_closure_id = $closure->getKey();
            $this->closure_responded_at = CarbonImmutable::now();
        });
    }

    /** @internal */
    public function persistReadyForRmik(EncounterClosure $closure): void
    {
        if ($this->status !== ProcedureDocumentationCorrectionStatus::ClosureResponseSubmitted
            || $this->response_closure_id !== $closure->getKey()
            || $closure->status !== EncounterClosureStatus::Approved) {
            throw new DomainException('Only the linked approved successor closure can return this procedure correction to RMIK.');
        }

        $this->transition(function (): void {
            $this->status = ProcedureDocumentationCorrectionStatus::ReadyForRmik;
        });
    }

    /** @internal */
    public function persistResolved(RecordQualityReview $review): void
    {
        if ($this->status !== ProcedureDocumentationCorrectionStatus::ReadyForRmik
            || $review->status !== RecordQualityReviewStatus::Approved
            || $review->encounter_id !== $this->encounter_id
            || $review->reviewer_assignment_id !== $this->record_quality_reviewer_assignment_id
            || data_get($review->content, 'assemblySnapshot.closure.publicId') !== $this->responseClosure?->public_id
            || data_get($review->content, 'assemblySnapshot.closure.contentHash') !== $this->responseClosure?->content_hash) {
            throw new DomainException('Only the exact approved replacement RMIK review can resolve this procedure correction.');
        }

        $this->transition(function () use ($review): void {
            $this->status = ProcedureDocumentationCorrectionStatus::Resolved;
            $this->replacement_record_quality_review_id = $review->getKey();
            $this->resolved_at = CarbonImmutable::now();
        });
    }

    /** @param callable(): void $mutation */
    private function transition(callable $mutation): void
    {
        $this->lifecycleTransitionInProgress = true;

        try {
            $mutation();
            $this->save();
        } finally {
            $this->lifecycleTransitionInProgress = false;
        }
    }

    /** @return BelongsTo<CodingSuggestionDecision, $this> */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(CodingSuggestionDecision::class, 'coding_suggestion_decision_id');
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

    /** @return BelongsTo<ClinicalProcedure, $this> */
    public function sourceProcedure(): BelongsTo
    {
        return $this->belongsTo(ClinicalProcedure::class, 'source_procedure_id');
    }

    /** @return BelongsTo<EncounterClosure, $this> */
    public function sourceClosure(): BelongsTo
    {
        return $this->belongsTo(EncounterClosure::class, 'source_closure_id');
    }

    /** @return BelongsTo<RecordQualityReview, $this> */
    public function supersededRecordQualityReview(): BelongsTo
    {
        return $this->belongsTo(RecordQualityReview::class, 'superseded_record_quality_review_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function recordQualityReviewerAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'record_quality_reviewer_assignment_id');
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

    /** @return BelongsTo<User, $this> */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function responsibleAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'responsible_assignment_id');
    }

    /** @return BelongsTo<EncounterClosure, $this> */
    public function responseClosure(): BelongsTo
    {
        return $this->belongsTo(EncounterClosure::class, 'response_closure_id');
    }

    /** @return BelongsTo<RecordQualityReview, $this> */
    public function replacementRecordQualityReview(): BelongsTo
    {
        return $this->belongsTo(RecordQualityReview::class, 'replacement_record_quality_review_id');
    }

    protected function casts(): array
    {
        return [
            'status' => ProcedureDocumentationCorrectionStatus::class,
            'requested_at' => 'immutable_datetime',
            'closure_responded_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
