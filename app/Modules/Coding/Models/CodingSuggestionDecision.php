<?php

namespace App\Modules\Coding\Models;

use App\Models\User;
use App\Modules\Coding\Enums\CodingDecisionType;
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
 * @property int $coding_suggestion_run_id
 * @property int|null $coding_suggestion_candidate_id
 * @property CodingDecisionType $decision
 * @property int $decided_by_user_id
 * @property int $decided_by_assignment_id
 * @property string|null $reason
 * @property int|null $resulting_assignment_id
 * @property CarbonImmutable $decided_at
 */
class CodingSuggestionDecision extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'request_key',
        'coding_suggestion_run_id',
        'coding_suggestion_candidate_id',
        'decision',
        'decided_by_user_id',
        'decided_by_assignment_id',
        'reason',
        'resulting_assignment_id',
        'decided_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $decision): void {
            $run = CodingSuggestionRun::query()->find($decision->coding_suggestion_run_id);
            $candidate = $decision->coding_suggestion_candidate_id === null
                ? null
                : CodingSuggestionCandidate::query()->find($decision->coding_suggestion_candidate_id);
            $actor = Assignment::query()->active()->find($decision->decided_by_assignment_id);
            $assignment = $decision->resulting_assignment_id === null
                ? null
                : CodingAssignment::query()->find($decision->resulting_assignment_id);
            $requiresAssignment = in_array($decision->decision, [
                CodingDecisionType::AcceptedToDraft,
                CodingDecisionType::ManualAlternative,
            ], true);

            if (! $run
                || ! $actor
                || $actor->user_id !== $decision->decided_by_user_id
                || $actor->getKey() !== $run->requested_by_assignment_id
                || ($candidate && $candidate->coding_suggestion_run_id !== $run->getKey())
                || ($decision->decision === CodingDecisionType::AcceptedToDraft && ! $candidate)
                || ($requiresAssignment && ! $assignment)
                || (! $requiresAssignment && $assignment)
                || ($assignment && ($assignment->source_type !== $run->source_type
                    || $assignment->source_condition_id !== $run->source_condition_id
                    || $assignment->source_entry_version_id !== $run->source_entry_version_id
                    || $assignment->source_procedure_id !== $run->source_procedure_id))) {
                throw new DomainException('A coding decision must preserve the exact run, candidate, coder, and resulting draft relationship.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Coding suggestion decisions are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Coding suggestion decisions are append-only.');
        });
    }

    /** @return BelongsTo<CodingSuggestionRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(CodingSuggestionRun::class, 'coding_suggestion_run_id');
    }

    /** @return BelongsTo<CodingSuggestionCandidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(CodingSuggestionCandidate::class, 'coding_suggestion_candidate_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function decidedByAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'decided_by_assignment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** @return BelongsTo<CodingAssignment, $this> */
    public function resultingAssignment(): BelongsTo
    {
        return $this->belongsTo(CodingAssignment::class, 'resulting_assignment_id');
    }

    protected function casts(): array
    {
        return [
            'decision' => CodingDecisionType::class,
            'decided_at' => 'immutable_datetime',
        ];
    }
}
