<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\DispensePreparationReviewAction;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $medication_dispense_preparation_id
 * @property DispensePreparationReviewAction $action
 * @property string $source_content_hash
 * @property string|null $comment
 * @property int $checker_assignment_id
 * @property CarbonImmutable $reviewed_at
 */
class MedicationDispensePreparationReview extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'request_key',
        'medication_dispense_preparation_id',
        'action',
        'source_content_hash',
        'comment',
        'checker_user_id',
        'checker_assignment_id',
        'reviewed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $action): void {
            $preparation = MedicationDispensePreparation::query()
                ->find($action->medication_dispense_preparation_id);
            $preparer = $preparation
                ? Assignment::query()->active()->find($preparation->preparer_assignment_id)
                : null;
            $checker = Assignment::query()->active()->find($action->checker_assignment_id);

            if (! $preparation
                || ! $preparer
                || ! $checker
                || $action->checker_user_id !== $checker->user_id
                || $preparation->preparer_assignment_id === $checker->getKey()
                || $preparer->user_id === $checker->user_id
                || $preparer->supervisor_assignment_id !== $checker->getKey()
                || ! $checker->hasCapability(Capability::SupervisionReview)
                || $checker->session_id !== $preparation->session_id
                || $checker->patient_id !== $preparation->patient_id
                || $checker->encounter_id !== $preparation->encounter_id
                || ! hash_equals($preparation->content_hash, $action->source_content_hash)
                || ($action->action === DispensePreparationReviewAction::RequestChanges && blank($action->comment))) {
                throw new DomainException('A preparation decision must be made by the distinct linked pharmacy supervisor against the exact content hash.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Medication preparation review actions are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Medication preparation review actions are append-only.');
        });
    }

    /** @return BelongsTo<MedicationDispensePreparation, $this> */
    public function preparation(): BelongsTo
    {
        return $this->belongsTo(MedicationDispensePreparation::class, 'medication_dispense_preparation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checker_user_id');
    }

    protected function casts(): array
    {
        return [
            'action' => DispensePreparationReviewAction::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
