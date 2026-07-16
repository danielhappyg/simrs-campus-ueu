<?php

namespace App\Modules\RecordQuality\Models;

use App\Models\User;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\RecordQuality\Enums\RecordQualityReviewStatus;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\Program;
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
 * @property int $reviewer_user_id
 * @property int $reviewer_assignment_id
 * @property int $version_number
 * @property string $checklist_version
 * @property RecordQualityReviewStatus $status
 * @property array<string, mixed> $content
 * @property string $content_hash
 * @property string|null $change_reason
 * @property int|null $supersedes_review_id
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $reviewed_at
 */
class RecordQualityReview extends Model
{
    use HasPublicUlid;

    private bool $statusTransitionInProgress = false;

    protected $fillable = [
        'request_key',
        'session_id',
        'patient_id',
        'encounter_id',
        'reviewer_user_id',
        'reviewer_assignment_id',
        'version_number',
        'checklist_version',
        'status',
        'content',
        'content_hash',
        'change_reason',
        'supersedes_review_id',
        'recorded_at',
        'submitted_at',
        'reviewed_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $review): void {
            $encounter = Encounter::query()->find($review->encounter_id);
            $assignment = Assignment::query()->active()->find($review->reviewer_assignment_id);
            $current = self::query()
                ->where('encounter_id', $review->encounter_id)
                ->orderByDesc('version_number')
                ->first();

            if (! $encounter
                || ! $assignment
                || $review->reviewer_user_id !== $assignment->user_id
                || $review->session_id !== $encounter->session_id
                || $review->patient_id !== $encounter->patient_id
                || $assignment->session_id !== $encounter->session_id
                || $assignment->patient_id !== $encounter->patient_id
                || $assignment->encounter_id !== $encounter->getKey()
                || $assignment->program !== Program::Rmik
                || ! $assignment->hasCapability(Capability::RecordReview)) {
                throw new DomainException('A record-quality review must match an exact RMIK reviewer and case context.');
            }

            if (! $review->exists
                && (($current === null && ($review->version_number !== 1 || $review->supersedes_review_id !== null))
                    || ($current !== null && ($review->version_number !== $current->version_number + 1
                        || $review->supersedes_review_id !== $current->getKey())))) {
                throw new DomainException('A record-quality review must be the next immutable version in the encounter lineage.');
            }

            if (! $review->exists
                && ! in_array($review->status, [RecordQualityReviewStatus::Draft, RecordQualityReviewStatus::Submitted], true)) {
                throw new DomainException('A new record-quality review must be a draft or submitted version.');
            }

            if (! $review->exists
                && (($review->status === RecordQualityReviewStatus::Submitted && $review->submitted_at === null)
                    || ($review->status === RecordQualityReviewStatus::Draft && $review->submitted_at !== null))) {
                throw new DomainException('Record-quality review submission status and timestamp must agree.');
            }

            if (! $review->exists
                && $current?->status === RecordQualityReviewStatus::ChangesRequested
                && blank($review->change_reason)) {
                throw new DomainException('A corrected record-quality review requires an attributed change reason.');
            }

            if (! $review->exists
                && $current?->status === RecordQualityReviewStatus::Approved
                && blank($review->change_reason)) {
                throw new DomainException('A replacement for an approved record-quality review requires an attributed change reason.');
            }

            if (! hash_equals(hash('sha256', CanonicalJson::encode($review->content)), $review->content_hash)) {
                throw new DomainException('Record-quality review content does not match its integrity hash.');
            }

            if ($review->exists && $review->isDirty([
                'request_key',
                'session_id',
                'patient_id',
                'encounter_id',
                'reviewer_user_id',
                'reviewer_assignment_id',
                'version_number',
                'checklist_version',
                'content',
                'content_hash',
                'change_reason',
                'supersedes_review_id',
                'recorded_at',
                'submitted_at',
            ])) {
                throw new DomainException('Record-quality review content and provenance are immutable.');
            }

            if ($review->exists
                && $review->isDirty(['status', 'reviewed_at'])
                && ! $review->statusTransitionInProgress) {
                throw new DomainException('Record-quality review lifecycle changes must use the workflow service.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Record-quality review versions are append-only.');
        });
    }

    /** @internal */
    public function persistReviewStatus(RecordQualityReviewStatus $target, CarbonImmutable $reviewedAt): void
    {
        if ($this->status !== RecordQualityReviewStatus::Submitted
            || ! in_array($target, [RecordQualityReviewStatus::ChangesRequested, RecordQualityReviewStatus::Approved], true)) {
            throw new DomainException("Record-quality review cannot transition from {$this->status->value} to {$target->value}.");
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
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function reviewerAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'reviewer_assignment_id');
    }

    /** @return BelongsTo<RecordQualityReview, $this> */
    public function supersedesReview(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_review_id');
    }

    /** @return HasMany<RecordQualityFinding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(RecordQualityFinding::class);
    }

    /** @return HasMany<RecordQualityReviewActionModel, $this> */
    public function reviewActions(): HasMany
    {
        return $this->hasMany(RecordQualityReviewActionModel::class);
    }

    protected function casts(): array
    {
        return [
            'status' => RecordQualityReviewStatus::class,
            'content' => 'array',
            'recorded_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
