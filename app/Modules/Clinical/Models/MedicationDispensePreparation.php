<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
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
 * @property int $medication_request_id
 * @property int $pharmacy_review_id
 * @property int|null $medication_stock_id
 * @property int $version_number
 * @property MedicationDispenseOutcome $outcome
 * @property string $quantity
 * @property string $unit
 * @property string|null $outcome_reason
 * @property array<string, mixed> $content
 * @property string $content_hash
 * @property string|null $change_reason
 * @property int|null $supersedes_preparation_id
 * @property int $preparer_assignment_id
 * @property CarbonImmutable $prepared_at
 */
class MedicationDispensePreparation extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'request_key',
        'session_id',
        'patient_id',
        'encounter_id',
        'medication_request_id',
        'pharmacy_review_id',
        'medication_stock_id',
        'version_number',
        'outcome',
        'quantity',
        'unit',
        'outcome_reason',
        'content',
        'content_hash',
        'change_reason',
        'supersedes_preparation_id',
        'preparer_user_id',
        'preparer_assignment_id',
        'prepared_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $preparation): void {
            $request = MedicationRequest::query()->find($preparation->medication_request_id);
            $review = PharmacyReview::query()->find($preparation->pharmacy_review_id);
            $preparer = Assignment::query()->active()->find($preparation->preparer_assignment_id);
            $stock = $preparation->medication_stock_id === null
                ? null
                : MedicationStock::query()->find($preparation->medication_stock_id);
            $superseded = $preparation->supersedes_preparation_id === null
                ? null
                : self::query()->find($preparation->supersedes_preparation_id);

            if (! $request
                || ! $review
                || ! $preparer
                || $review->medication_request_id !== $request->getKey()
                || $review->overall_outcome !== PharmacyReviewOutcome::Accept
                || $preparation->preparer_user_id !== $preparer->user_id
                || $preparation->session_id !== $request->session_id
                || $preparation->patient_id !== $request->patient_id
                || $preparation->encounter_id !== $request->encounter_id
                || ! self::actorMatches($preparer, $request)
                || ! $preparer->hasCapability(Capability::Dispense)
                || ($stock && ($stock->session_id !== $request->session_id || ! $stock->synthetic_flag))
                || ($preparation->medication_stock_id !== null && ! $stock)
                || ($superseded && ($superseded->medication_request_id !== $request->getKey()
                    || $preparation->version_number !== $superseded->version_number + 1
                    || blank($preparation->change_reason)))
                || (! $superseded && $preparation->version_number !== 1)) {
                throw new DomainException('A dispense preparation must preserve an accepted review, authorized preparer, version chain, synthetic stock, and exact case context.');
            }

            if (! hash_equals(hash('sha256', CanonicalJson::encode($preparation->content)), $preparation->content_hash)) {
                throw new DomainException('Medication preparation content does not match its integrity hash.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Medication preparation records are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Medication preparation records are append-only.');
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

    /** @return BelongsTo<Assignment, $this> */
    public function preparerAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'preparer_assignment_id');
    }

    /** @return BelongsTo<self, $this> */
    public function supersedesPreparation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_preparation_id');
    }

    /** @return HasOne<MedicationDispensePreparationReview, $this> */
    public function reviewAction(): HasOne
    {
        return $this->hasOne(MedicationDispensePreparationReview::class);
    }

    /** @return HasOne<MedicationDispense, $this> */
    public function dispense(): HasOne
    {
        return $this->hasOne(MedicationDispense::class);
    }

    protected function casts(): array
    {
        return [
            'outcome' => MedicationDispenseOutcome::class,
            'quantity' => 'decimal:3',
            'content' => 'array',
            'prepared_at' => 'immutable_datetime',
        ];
    }
}
