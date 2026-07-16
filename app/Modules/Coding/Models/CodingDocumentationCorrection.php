<?php

namespace App\Modules\Coding\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Coding\Enums\CodingDecisionType;
use App\Modules\Coding\Enums\CodingDocumentationCorrectionStatus;
use App\Modules\Encounter\Models\Encounter;
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
 * @property int $source_condition_id
 * @property int $source_entry_version_id
 * @property int $superseded_record_quality_review_id
 * @property int $record_quality_reviewer_assignment_id
 * @property int $requested_by_user_id
 * @property int $requested_by_assignment_id
 * @property int $responsible_user_id
 * @property int $responsible_assignment_id
 * @property CodingDocumentationCorrectionStatus $status
 * @property string $reason
 * @property string $requested_source_hash
 * @property string $requested_statement_hash
 * @property CarbonImmutable $requested_at
 * @property int|null $response_medical_version_id
 * @property CarbonImmutable|null $medical_responded_at
 * @property int|null $response_closure_id
 * @property CarbonImmutable|null $closure_responded_at
 * @property int|null $replacement_record_quality_review_id
 * @property CarbonImmutable|null $resolved_at
 */
class CodingDocumentationCorrection extends Model
{
    use HasPublicUlid;

    private bool $lifecycleTransitionInProgress = false;

    protected $fillable = [
        'coding_suggestion_decision_id',
        'session_id',
        'patient_id',
        'encounter_id',
        'source_condition_id',
        'source_entry_version_id',
        'superseded_record_quality_review_id',
        'record_quality_reviewer_assignment_id',
        'requested_by_user_id',
        'requested_by_assignment_id',
        'responsible_user_id',
        'responsible_assignment_id',
        'status',
        'reason',
        'requested_source_hash',
        'requested_statement_hash',
        'requested_at',
        'response_medical_version_id',
        'medical_responded_at',
        'response_closure_id',
        'closure_responded_at',
        'replacement_record_quality_review_id',
        'resolved_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $correction): void {
            $decision = CodingSuggestionDecision::query()->with(['run.sourceCondition', 'run.sourceEntryVersion'])->find($correction->coding_suggestion_decision_id);
            $quality = RecordQualityReview::query()->find($correction->superseded_record_quality_review_id);
            $requester = Assignment::query()->active()->find($correction->requested_by_assignment_id);
            $responsible = Assignment::query()->active()->find($correction->responsible_assignment_id);

            if (! $decision
                || ! $quality
                || ! $requester
                || ! $responsible
                || $decision->decision !== CodingDecisionType::CorrectionRequested
                || $decision->decided_by_user_id !== $correction->requested_by_user_id
                || $decision->decided_by_assignment_id !== $correction->requested_by_assignment_id
                || $decision->run->session_id !== $correction->session_id
                || $decision->run->patient_id !== $correction->patient_id
                || $decision->run->encounter_id !== $correction->encounter_id
                || $decision->run->source_condition_id !== $correction->source_condition_id
                || $decision->run->source_entry_version_id !== $correction->source_entry_version_id
                || $quality->encounter_id !== $correction->encounter_id
                || $quality->reviewer_assignment_id !== $correction->record_quality_reviewer_assignment_id
                || $quality->status !== RecordQualityReviewStatus::Approved
                || $requester->user_id !== $correction->requested_by_user_id
                || $requester->session_id !== $correction->session_id
                || $requester->patient_id !== $correction->patient_id
                || $requester->encounter_id !== $correction->encounter_id
                || ! $requester->hasCapability(Capability::CodingWrite)
                || $responsible->user_id !== $correction->responsible_user_id
                || $responsible->getKey() !== $decision->run->sourceEntryVersion->author_assignment_id
                || ! $responsible->hasCapability(Capability::MedicalAssessmentWrite)
                || ! hash_equals($decision->run->sourceEntryVersion->content_hash, $correction->requested_source_hash)
                || ! hash_equals(hash('sha256', $decision->run->sourceCondition->authored_text), $correction->requested_statement_hash)) {
                throw new DomainException('A coding documentation correction must preserve its exact coder, medical author, source statement, and approved RMIK provenance.');
            }

            if (! $correction->exists && $correction->status !== CodingDocumentationCorrectionStatus::Open) {
                throw new DomainException('A new coding documentation correction must begin open.');
            }

            if ($correction->exists && $correction->isDirty([
                'coding_suggestion_decision_id',
                'session_id',
                'patient_id',
                'encounter_id',
                'source_condition_id',
                'source_entry_version_id',
                'superseded_record_quality_review_id',
                'record_quality_reviewer_assignment_id',
                'requested_by_user_id',
                'requested_by_assignment_id',
                'responsible_user_id',
                'responsible_assignment_id',
                'reason',
                'requested_source_hash',
                'requested_statement_hash',
                'requested_at',
            ])) {
                throw new DomainException('Coding documentation correction provenance is immutable.');
            }

            if ($correction->exists
                && $correction->isDirty([
                    'status',
                    'response_medical_version_id',
                    'medical_responded_at',
                    'response_closure_id',
                    'closure_responded_at',
                    'replacement_record_quality_review_id',
                    'resolved_at',
                ])
                && ! $correction->lifecycleTransitionInProgress) {
                throw new DomainException('Coding documentation correction lifecycle changes must use the workflow service.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Coding documentation corrections are append-only.');
        });
    }

    /** @internal */
    public function persistMedicalResponse(ClinicalEntryVersion $version): void
    {
        $version->loadMissing('clinicalEntry');

        if (! in_array($this->status, [
            CodingDocumentationCorrectionStatus::Open,
            CodingDocumentationCorrectionStatus::MedicalResponseSubmitted,
        ], true)
            || $version->clinicalEntry->document_type !== ClinicalDocumentType::MedicalAssessment
            || $version->clinicalEntry->encounter_id !== $this->encounter_id
            || $version->author_assignment_id !== $this->responsible_assignment_id
            || $version->status !== ClinicalEntryStatus::Submitted
            || $version->version_number <= $this->sourceEntryVersion->version_number
            || hash_equals($version->content_hash, $this->requested_source_hash)) {
            throw new DomainException('Only a changed submitted successor medical version can answer this coding correction.');
        }

        $this->transition(function () use ($version): void {
            $this->status = CodingDocumentationCorrectionStatus::MedicalResponseSubmitted;
            $this->response_medical_version_id = $version->getKey();
            $this->medical_responded_at = CarbonImmutable::now();
        });
    }

    /** @internal */
    public function persistMedicalApproved(ClinicalEntryVersion $version): void
    {
        if ($this->status !== CodingDocumentationCorrectionStatus::MedicalResponseSubmitted
            || $this->response_medical_version_id !== $version->getKey()
            || $version->status !== ClinicalEntryStatus::Approved) {
            throw new DomainException('Only the linked approved medical successor can advance this coding correction.');
        }

        $this->transition(function (): void {
            $this->status = CodingDocumentationCorrectionStatus::MedicalApproved;
        });
    }

    /** @internal */
    public function persistClosureResponse(EncounterClosure $closure): void
    {
        $medical = $this->responseMedicalVersion;

        if (! in_array($this->status, [
            CodingDocumentationCorrectionStatus::MedicalApproved,
            CodingDocumentationCorrectionStatus::ClosureResponseSubmitted,
        ], true)
            || ! $medical
            || $closure->encounter_id !== $this->encounter_id
            || $closure->author_assignment_id !== $this->responsible_assignment_id
            || $closure->status !== EncounterClosureStatus::Submitted
            || data_get($closure->content, 'sourceSnapshot.medical.contentHash') !== $medical->content_hash) {
            throw new DomainException('Only a submitted successor closure linked to the approved medical amendment can answer this coding correction.');
        }

        $this->transition(function () use ($closure): void {
            $this->status = CodingDocumentationCorrectionStatus::ClosureResponseSubmitted;
            $this->response_closure_id = $closure->getKey();
            $this->closure_responded_at = CarbonImmutable::now();
        });
    }

    /** @internal */
    public function persistReadyForRmik(EncounterClosure $closure): void
    {
        if ($this->status !== CodingDocumentationCorrectionStatus::ClosureResponseSubmitted
            || $this->response_closure_id !== $closure->getKey()
            || $closure->status !== EncounterClosureStatus::Approved) {
            throw new DomainException('Only the linked approved successor closure can return this correction to RMIK.');
        }

        $this->transition(function (): void {
            $this->status = CodingDocumentationCorrectionStatus::ReadyForRmik;
        });
    }

    /** @internal */
    public function persistResolved(RecordQualityReview $review): void
    {
        if ($this->status !== CodingDocumentationCorrectionStatus::ReadyForRmik
            || $review->status !== RecordQualityReviewStatus::Approved
            || $review->encounter_id !== $this->encounter_id
            || $review->reviewer_assignment_id !== $this->record_quality_reviewer_assignment_id
            || data_get($review->content, 'assemblySnapshot.closure.publicId') !== $this->responseClosure?->public_id
            || data_get($review->content, 'assemblySnapshot.closure.contentHash') !== $this->responseClosure?->content_hash) {
            throw new DomainException('Only the exact approved replacement RMIK review can resolve this correction.');
        }

        $this->transition(function () use ($review): void {
            $this->status = CodingDocumentationCorrectionStatus::Resolved;
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

    /** @return BelongsTo<ClinicalEntryVersion, $this> */
    public function responseMedicalVersion(): BelongsTo
    {
        return $this->belongsTo(ClinicalEntryVersion::class, 'response_medical_version_id');
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
            'status' => CodingDocumentationCorrectionStatus::class,
            'requested_at' => 'immutable_datetime',
            'medical_responded_at' => 'immutable_datetime',
            'closure_responded_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
