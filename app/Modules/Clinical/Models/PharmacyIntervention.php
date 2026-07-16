<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\PharmacyInterventionStatus;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
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
 * @property int $source_pharmacy_review_id
 * @property int $opened_by_assignment_id
 * @property PharmacyInterventionStatus $status
 * @property string $issue_category
 * @property string $urgency
 * @property string $question
 * @property string|null $recommendation
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $responded_at
 * @property CarbonImmutable|null $resolved_at
 */
class PharmacyIntervention extends Model
{
    use HasPublicUlid;

    private bool $statusTransitionInProgress = false;

    protected $fillable = [
        'request_key',
        'session_id',
        'patient_id',
        'encounter_id',
        'medication_request_id',
        'source_pharmacy_review_id',
        'opened_by_user_id',
        'opened_by_assignment_id',
        'status',
        'issue_category',
        'urgency',
        'question',
        'recommendation',
        'opened_at',
        'responded_at',
        'resolved_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $intervention): void {
            $request = MedicationRequest::query()->find($intervention->medication_request_id);
            $review = PharmacyReview::query()->find($intervention->source_pharmacy_review_id);
            $actor = Assignment::query()->active()->find($intervention->opened_by_assignment_id);

            if (! $request
                || ! $review
                || ! $actor
                || $review->medication_request_id !== $request->getKey()
                || $intervention->opened_by_user_id !== $actor->user_id
                || $intervention->session_id !== $request->session_id
                || $intervention->patient_id !== $request->patient_id
                || $intervention->encounter_id !== $request->encounter_id
                || $actor->session_id !== $request->session_id
                || $actor->patient_id !== $request->patient_id
                || $actor->encounter_id !== $request->encounter_id
                || ! $actor->hasCapability(Capability::PharmacyReview)) {
                throw new DomainException('A pharmacy intervention must match its review, medication request, opener, and exact case context.');
            }

            if (! $intervention->exists && $intervention->status !== PharmacyInterventionStatus::Open) {
                throw new DomainException('A new pharmacy intervention must begin open.');
            }

            if ($intervention->exists && $intervention->isDirty([
                'session_id',
                'patient_id',
                'encounter_id',
                'medication_request_id',
                'source_pharmacy_review_id',
                'opened_by_user_id',
                'opened_by_assignment_id',
                'issue_category',
                'urgency',
                'question',
                'recommendation',
                'opened_at',
            ])) {
                throw new DomainException('Pharmacy intervention source content and provenance are immutable.');
            }

            if ($intervention->exists
                && $intervention->isDirty(['status', 'responded_at', 'resolved_at'])
                && ! $intervention->statusTransitionInProgress) {
                throw new DomainException('Pharmacy intervention lifecycle changes must use the workflow service.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Pharmacy interventions are append-only and cannot be deleted.');
        });
    }

    /** @internal */
    public function persistStatus(PharmacyInterventionStatus $target): void
    {
        $allowed = match ($this->status) {
            PharmacyInterventionStatus::Open => [PharmacyInterventionStatus::Responded, PharmacyInterventionStatus::Resolved],
            PharmacyInterventionStatus::Responded => [PharmacyInterventionStatus::Resolved],
            PharmacyInterventionStatus::Resolved => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw new DomainException("Pharmacy intervention cannot transition from {$this->status->value} to {$target->value}.");
        }

        $this->statusTransitionInProgress = true;

        try {
            $this->status = $target;
            $this->responded_at ??= now();
            $this->resolved_at = $target === PharmacyInterventionStatus::Resolved ? now() : $this->resolved_at;
            $this->save();
        } finally {
            $this->statusTransitionInProgress = false;
        }
    }

    /** @return BelongsTo<MedicationRequest, $this> */
    public function medicationRequest(): BelongsTo
    {
        return $this->belongsTo(MedicationRequest::class);
    }

    /** @return BelongsTo<PharmacyReview, $this> */
    public function sourceReview(): BelongsTo
    {
        return $this->belongsTo(PharmacyReview::class, 'source_pharmacy_review_id');
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function openedByAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'opened_by_assignment_id');
    }

    /** @return HasMany<PharmacyInterventionMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(PharmacyInterventionMessage::class);
    }

    protected function casts(): array
    {
        return [
            'status' => PharmacyInterventionStatus::class,
            'opened_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
