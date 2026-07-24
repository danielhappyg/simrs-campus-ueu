<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\DispensePreparationReviewAction;
use App\Modules\Clinical\Enums\MedicationDispenseOutcome;
use App\Modules\Clinical\Enums\PharmacyReviewOutcome;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use App\Support\CanonicalJson;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $public_id
 * @property string $request_key
 * @property int $session_id
 * @property int $patient_id
 * @property int $encounter_id
 * @property int $medication_request_id
 * @property int|null $medication_dispense_preparation_id
 * @property int $pharmacy_review_id
 * @property int|null $medication_stock_id
 * @property MedicationDispenseOutcome $outcome
 * @property string $quantity
 * @property string $unit
 * @property string|null $outcome_reason
 * @property array<string, mixed> $content
 * @property string $content_hash
 * @property int $preparer_assignment_id
 * @property int $checker_assignment_id
 * @property CarbonImmutable $prepared_at
 * @property CarbonImmutable $checked_at
 * @property CarbonImmutable|null $handed_over_at
 */
class MedicationDispense extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'request_key',
        'session_id',
        'patient_id',
        'encounter_id',
        'medication_request_id',
        'medication_dispense_preparation_id',
        'pharmacy_review_id',
        'medication_stock_id',
        'outcome',
        'quantity',
        'unit',
        'outcome_reason',
        'content',
        'content_hash',
        'preparer_user_id',
        'preparer_assignment_id',
        'checker_user_id',
        'checker_assignment_id',
        'prepared_at',
        'checked_at',
        'handed_over_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $dispense): void {
            $request = MedicationRequest::query()->find($dispense->medication_request_id);
            $preparation = $dispense->medication_dispense_preparation_id === null
                ? null
                : MedicationDispensePreparation::query()
                    ->with('reviewAction')
                    ->find($dispense->medication_dispense_preparation_id);
            $review = PharmacyReview::query()->find($dispense->pharmacy_review_id);
            $preparer = Assignment::query()->active()->find($dispense->preparer_assignment_id);
            $checker = Assignment::query()->active()->find($dispense->checker_assignment_id);
            $stock = $dispense->medication_stock_id === null
                ? null
                : MedicationStock::query()->find($dispense->medication_stock_id);

            if (! $request
                || ! $preparation
                || ! $review
                || ! $preparer
                || ! $checker
                || $review->medication_request_id !== $request->getKey()
                || $review->overall_outcome !== PharmacyReviewOutcome::Accept
                || ($preparation->medication_request_id !== $request->getKey()
                    || $preparation->pharmacy_review_id !== $review->getKey()
                    || $preparation->preparer_assignment_id !== $preparer->getKey()
                    || $preparation->reviewAction?->checker_assignment_id !== $checker->getKey()
                    || $preparation->reviewAction?->action !== DispensePreparationReviewAction::ApproveSimulation
                    || ! hash_equals(
                        $preparation->content_hash,
                        (string) data_get($dispense->content, 'preparationContentHash'),
                    ))
                || $dispense->preparer_user_id !== $preparer->user_id
                || $dispense->checker_user_id !== $checker->user_id
                || $dispense->session_id !== $request->session_id
                || $dispense->patient_id !== $request->patient_id
                || $dispense->encounter_id !== $request->encounter_id
                || ! self::actorMatches($preparer, $request)
                || ! self::actorMatches($checker, $request)
                || ! $preparer->hasCapability(Capability::Dispense)
                || ! $checker->hasCapability(Capability::SupervisionReview)
                || ($stock && ($stock->session_id !== $request->session_id || ! $stock->synthetic_flag))
                || ($dispense->medication_stock_id !== null && ! $stock)) {
                throw new DomainException('A dispense record must match an accepted review, authorized actors, synthetic stock, and exact case context.');
            }

            if (! hash_equals(hash('sha256', CanonicalJson::encode($dispense->content)), $dispense->content_hash)) {
                throw new DomainException('Medication dispense content does not match its integrity hash.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Medication dispense records are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Medication dispense records are append-only.');
        });
    }

    private static function actorMatches(Assignment $actor, MedicationRequest $request): bool
    {
        return $actor->session_id === $request->session_id
            && $actor->patient_id === $request->patient_id
            && $actor->encounter_id === $request->encounter_id;
    }

    /** @return BelongsTo<MedicationRequest, $this> */
    public function medicationRequest(): BelongsTo
    {
        return $this->belongsTo(MedicationRequest::class);
    }

    /** @return BelongsTo<MedicationDispensePreparation, $this> */
    public function preparation(): BelongsTo
    {
        return $this->belongsTo(MedicationDispensePreparation::class, 'medication_dispense_preparation_id');
    }

    /** @return BelongsTo<PharmacyReview, $this> */
    public function pharmacyReview(): BelongsTo
    {
        return $this->belongsTo(PharmacyReview::class);
    }

    /** @return BelongsTo<MedicationStock, $this> */
    public function medicationStock(): BelongsTo
    {
        return $this->belongsTo(MedicationStock::class);
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preparer_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checker_user_id');
    }

    /** @return HasOne<MedicationStockMovement, $this> */
    public function stockMovement(): HasOne
    {
        return $this->hasOne(MedicationStockMovement::class);
    }

    protected function casts(): array
    {
        return [
            'outcome' => MedicationDispenseOutcome::class,
            'quantity' => 'decimal:3',
            'content' => 'array',
            'prepared_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
            'handed_over_at' => 'immutable_datetime',
        ];
    }
}
