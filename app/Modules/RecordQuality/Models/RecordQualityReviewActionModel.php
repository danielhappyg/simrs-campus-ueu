<?php

namespace App\Modules\RecordQuality\Models;

use App\Models\User;
use App\Modules\RecordQuality\Enums\RecordQualityReviewAction;
use App\Modules\Teaching\Enums\Capability;
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
 * @property int $record_quality_review_id
 * @property int $reviewer_assignment_id
 * @property RecordQualityReviewAction $action
 * @property array<int, array<string, mixed>>|null $findings
 * @property string|null $comment
 * @property string $reviewed_content_hash
 * @property CarbonImmutable $reviewed_at
 */
class RecordQualityReviewActionModel extends Model
{
    use HasPublicUlid;

    protected $table = 'record_quality_review_actions';

    protected $fillable = [
        'request_key',
        'record_quality_review_id',
        'reviewer_user_id',
        'reviewer_assignment_id',
        'action',
        'findings',
        'comment',
        'reviewed_content_hash',
        'reviewed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $action): void {
            $review = RecordQualityReview::query()->with('reviewerAssignment')->find($action->record_quality_review_id);
            $reviewer = Assignment::query()->active()->find($action->reviewer_assignment_id);

            if (! $review
                || ! $reviewer
                || $action->reviewer_user_id !== $reviewer->user_id
                || $reviewer->getKey() !== $review->reviewerAssignment->supervisor_assignment_id
                || $reviewer->session_id !== $review->session_id
                || $reviewer->patient_id !== $review->patient_id
                || $reviewer->encounter_id !== $review->encounter_id
                || ! $reviewer->hasCapability(Capability::SupervisionReview)
                || ! hash_equals($review->content_hash, $action->reviewed_content_hash)) {
                throw new DomainException('A record-quality review action must reference the exact hash and linked RMIK supervisor.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Record-quality review actions are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Record-quality review actions are append-only.');
        });
    }

    /** @return BelongsTo<RecordQualityReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(RecordQualityReview::class, 'record_quality_review_id');
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

    protected function casts(): array
    {
        return [
            'action' => RecordQualityReviewAction::class,
            'findings' => 'array',
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
