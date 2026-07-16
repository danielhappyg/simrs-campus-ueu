<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\EncounterClosureReviewAction;
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
 * @property int $encounter_closure_id
 * @property int $reviewer_assignment_id
 * @property EncounterClosureReviewAction $action
 * @property array<int, array<string, mixed>>|null $findings
 * @property string|null $comment
 * @property string $reviewed_content_hash
 * @property CarbonImmutable $reviewed_at
 */
class EncounterClosureReviewActionModel extends Model
{
    use HasPublicUlid;

    protected $table = 'encounter_closure_review_actions';

    protected $fillable = [
        'request_key',
        'encounter_closure_id',
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
        static::creating(function (self $review): void {
            $closure = EncounterClosure::query()->with('authorAssignment')->find($review->encounter_closure_id);
            $reviewer = Assignment::query()->active()->find($review->reviewer_assignment_id);

            if (! $closure
                || ! $reviewer
                || $review->reviewer_user_id !== $reviewer->user_id
                || $reviewer->getKey() !== $closure->authorAssignment->supervisor_assignment_id
                || $reviewer->session_id !== $closure->session_id
                || $reviewer->patient_id !== $closure->patient_id
                || $reviewer->encounter_id !== $closure->encounter_id
                || ! $reviewer->hasCapability(Capability::SupervisionReview)
                || ! hash_equals($closure->content_hash, $review->reviewed_content_hash)) {
                throw new DomainException('A closure review must reference the exact content hash and linked medical supervisor.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Encounter closure review actions are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Encounter closure review actions are append-only.');
        });
    }

    /** @return BelongsTo<EncounterClosure, $this> */
    public function closure(): BelongsTo
    {
        return $this->belongsTo(EncounterClosure::class, 'encounter_closure_id');
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
            'action' => EncounterClosureReviewAction::class,
            'findings' => 'array',
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
