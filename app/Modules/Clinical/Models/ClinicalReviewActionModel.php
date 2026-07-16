<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalReviewAction;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $public_id
 * @property ClinicalReviewAction $action
 * @property array<int, array<string, mixed>>|null $findings
 * @property CarbonImmutable $occurred_at
 * @property-read ClinicalEntryVersion $clinicalEntryVersion
 * @property-read Assignment $actorAssignment
 */
class ClinicalReviewActionModel extends Model
{
    use HasPublicUlid;

    protected $table = 'clinical_review_actions';

    protected $fillable = [
        'request_key',
        'clinical_entry_version_id',
        'actor_user_id',
        'actor_assignment_id',
        'action',
        'findings',
        'comment',
        'reviewed_content_hash',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $review): void {
            $version = ClinicalEntryVersion::query()
                ->with(['clinicalEntry', 'authorAssignment'])
                ->find($review->clinical_entry_version_id);
            $actor = Assignment::query()->find($review->actor_assignment_id);

            if (! $version
                || ! $actor
                || $review->actor_user_id !== $actor->user_id
                || $actor->session_id !== $version->clinicalEntry->session_id
                || $actor->patient_id !== $version->clinicalEntry->patient_id
                || $actor->encounter_id !== $version->clinicalEntry->encounter_id
                || ! hash_equals($version->content_hash, $review->reviewed_content_hash)) {
                throw new DomainException('A clinical review action must match the exact version, actor, hash, and case context.');
            }

            if ($review->action === ClinicalReviewAction::Submit) {
                if ($actor->getKey() !== $version->author_assignment_id) {
                    throw new DomainException('Only the version author assignment can submit it.');
                }

                return;
            }

            if (! $actor->hasCapability(Capability::SupervisionReview)
                || $version->authorAssignment->supervisor_assignment_id !== $actor->getKey()) {
                throw new DomainException('Only the linked supervisor assignment can review this clinical version.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Clinical review actions are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new DomainException('Clinical review actions are append-only and cannot be deleted.');
        });
    }

    /** @return BelongsTo<ClinicalEntryVersion, $this> */
    public function clinicalEntryVersion(): BelongsTo
    {
        return $this->belongsTo(ClinicalEntryVersion::class);
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
            'action' => ClinicalReviewAction::class,
            'findings' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
