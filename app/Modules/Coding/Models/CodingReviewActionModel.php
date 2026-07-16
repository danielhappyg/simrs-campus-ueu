<?php

namespace App\Modules\Coding\Models;

use App\Models\User;
use App\Modules\Coding\Enums\CodingReviewAction;
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
 * @property int $coding_assignment_id
 * @property int $reviewer_user_id
 * @property int $reviewer_assignment_id
 * @property CodingReviewAction $action
 * @property array<string, mixed>|null $findings
 * @property string|null $comment
 * @property string $reviewed_content_hash
 * @property CarbonImmutable $reviewed_at
 */
class CodingReviewActionModel extends Model
{
    use HasPublicUlid;

    protected $table = 'coding_review_actions';

    protected $fillable = [
        'request_key',
        'coding_assignment_id',
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
            $coding = CodingAssignment::query()->with('coderAssignment')->find($action->coding_assignment_id);
            $reviewer = Assignment::query()->active()->find($action->reviewer_assignment_id);

            if (! $coding
                || ! $reviewer
                || $reviewer->user_id !== $action->reviewer_user_id
                || $coding->coderAssignment->supervisor_assignment_id !== $reviewer->getKey()
                || ! $reviewer->hasCapability(Capability::SupervisionReview)
                || ! hash_equals($coding->content_hash, $action->reviewed_content_hash)) {
                throw new DomainException('A coding review action must reference the exact submitted hash and linked RMIK supervisor.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Coding review actions are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Coding review actions are append-only.');
        });
    }

    /** @return BelongsTo<CodingAssignment, $this> */
    public function codingAssignment(): BelongsTo
    {
        return $this->belongsTo(CodingAssignment::class);
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
            'action' => CodingReviewAction::class,
            'findings' => 'array',
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
