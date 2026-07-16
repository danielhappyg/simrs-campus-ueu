<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\PharmacyReviewOutcome;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
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
 * @property int $medication_request_id
 * @property int $reviewer_assignment_id
 * @property int $version_number
 * @property PharmacyReviewOutcome $overall_outcome
 * @property array<string, mixed> $domain_results
 * @property string $content_hash
 * @property int|null $supersedes_review_id
 * @property CarbonImmutable $reviewed_at
 * @property-read MedicationRequest $medicationRequest
 */
class PharmacyReview extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'request_key',
        'session_id',
        'patient_id',
        'encounter_id',
        'medication_request_id',
        'reviewer_user_id',
        'reviewer_assignment_id',
        'version_number',
        'overall_outcome',
        'domain_results',
        'content_hash',
        'supersedes_review_id',
        'reviewed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $review): void {
            $request = MedicationRequest::query()->find($review->medication_request_id);
            $actor = Assignment::query()->active()->find($review->reviewer_assignment_id);
            $current = self::query()
                ->where('medication_request_id', $review->medication_request_id)
                ->orderByDesc('version_number')
                ->first();

            if (! $request
                || ! $actor
                || $review->reviewer_user_id !== $actor->user_id
                || $review->session_id !== $request->session_id
                || $review->patient_id !== $request->patient_id
                || $review->encounter_id !== $request->encounter_id
                || $actor->session_id !== $request->session_id
                || $actor->patient_id !== $request->patient_id
                || $actor->encounter_id !== $request->encounter_id
                || ! $actor->hasCapability(Capability::PharmacyReview)) {
                throw new DomainException('A pharmacy review must match an authorized reviewer and exact medication-request case context.');
            }

            if (($current === null && ($review->version_number !== 1 || $review->supersedes_review_id !== null))
                || ($current !== null && ($review->version_number !== $current->version_number + 1
                    || $review->supersedes_review_id !== $current->getKey()))) {
                throw new DomainException('A pharmacy review must be the next immutable version in its request lineage.');
            }

            $content = [
                'overallOutcome' => $review->overall_outcome->value,
                'domainResults' => $review->domain_results,
            ];

            if (! hash_equals(hash('sha256', CanonicalJson::encode($content)), $review->content_hash)) {
                throw new DomainException('Pharmacy review content does not match its integrity hash.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Pharmacy review versions are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new DomainException('Pharmacy review versions are immutable and cannot be deleted.');
        });
    }

    /** @return BelongsTo<MedicationRequest, $this> */
    public function medicationRequest(): BelongsTo
    {
        return $this->belongsTo(MedicationRequest::class);
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

    /** @return BelongsTo<PharmacyReview, $this> */
    public function supersedesReview(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_review_id');
    }

    /** @return HasMany<PharmacyIntervention, $this> */
    public function interventions(): HasMany
    {
        return $this->hasMany(PharmacyIntervention::class, 'source_pharmacy_review_id');
    }

    protected function casts(): array
    {
        return [
            'overall_outcome' => PharmacyReviewOutcome::class,
            'domain_results' => 'array',
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
