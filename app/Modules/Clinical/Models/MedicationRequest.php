<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\MedicationRequestStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $session_id
 * @property int $patient_id
 * @property int $encounter_id
 * @property int $source_entry_version_id
 * @property int|null $source_condition_id
 * @property int|null $replaces_medication_request_id
 * @property int $requester_assignment_id
 * @property string $public_id
 * @property int $sequence_number
 * @property int $revision_number
 * @property string $authored_medication
 * @property string|null $form
 * @property string|null $strength
 * @property string $dose_value
 * @property string $dose_unit
 * @property string $route
 * @property string $frequency
 * @property string $duration
 * @property string $quantity_value
 * @property string $quantity_unit
 * @property string $directions
 * @property string|null $indication_text
 * @property string|null $replacement_reason
 * @property string|null $cancellation_reason
 * @property MedicationRequestStatus $status
 * @property CarbonImmutable $authored_at
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $completed_at
 * @property-read ClinicalEntryVersion $sourceEntryVersion
 */
class MedicationRequest extends Model
{
    use HasPublicUlid;

    private bool $statusTransitionInProgress = false;

    protected $fillable = [
        'session_id',
        'patient_id',
        'encounter_id',
        'source_entry_version_id',
        'source_condition_id',
        'replaces_medication_request_id',
        'requester_user_id',
        'requester_assignment_id',
        'sequence_number',
        'revision_number',
        'authored_medication',
        'form',
        'strength',
        'dose_value',
        'dose_unit',
        'route',
        'frequency',
        'duration',
        'quantity_value',
        'quantity_unit',
        'directions',
        'indication_text',
        'replacement_reason',
        'cancellation_reason',
        'status',
        'authored_at',
        'activated_at',
        'completed_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            $version = ClinicalEntryVersion::query()->with('clinicalEntry')->find($request->source_entry_version_id);
            $assignment = Assignment::query()->find($request->requester_assignment_id);
            $condition = $request->source_condition_id === null
                ? null
                : ClinicalCondition::query()->find($request->source_condition_id);
            $replaced = $request->replaces_medication_request_id === null
                ? null
                : self::query()->find($request->replaces_medication_request_id);

            if (! $version
                || ! $assignment
                || $version->clinicalEntry->document_type !== ClinicalDocumentType::MedicalAssessment
                || $request->requester_user_id !== $assignment->user_id
                || $request->requester_assignment_id !== $version->author_assignment_id
                || $request->session_id !== $version->clinicalEntry->session_id
                || $request->patient_id !== $version->clinicalEntry->patient_id
                || $request->encounter_id !== $version->clinicalEntry->encounter_id
                || ($condition && $condition->source_entry_version_id !== $version->getKey())
                || ($request->source_condition_id !== null && ! $condition)
                || ($replaced && ($replaced->session_id !== $request->session_id
                    || $replaced->patient_id !== $request->patient_id
                    || $replaced->encounter_id !== $request->encounter_id
                    || $replaced->source_entry_version_id !== $request->source_entry_version_id
                    || $replaced->requester_assignment_id !== $request->requester_assignment_id))
                || ($request->replaces_medication_request_id !== null && ! $replaced)) {
                throw new DomainException('A medication request must match its medical source version, author, diagnosis, and case context.');
            }

            if (! $request->exists && $request->status !== MedicationRequestStatus::Draft) {
                throw new DomainException('A new medication request must begin as a draft.');
            }

            if (! $request->exists
                && (($replaced === null && $request->revision_number !== 1)
                    || ($replaced !== null && ($request->revision_number !== $replaced->revision_number + 1
                        || blank($request->replacement_reason))))) {
                throw new DomainException('A replacement medication request must be the next attributed revision with a reason.');
            }

            if ($request->exists && $request->isDirty([
                'session_id',
                'patient_id',
                'encounter_id',
                'source_entry_version_id',
                'source_condition_id',
                'replaces_medication_request_id',
                'requester_user_id',
                'requester_assignment_id',
                'sequence_number',
                'revision_number',
                'authored_medication',
                'form',
                'strength',
                'dose_value',
                'dose_unit',
                'route',
                'frequency',
                'duration',
                'quantity_value',
                'quantity_unit',
                'directions',
                'indication_text',
                'replacement_reason',
                'authored_at',
            ])) {
                throw new DomainException('Medication request content and provenance are immutable.');
            }

            if ($request->exists
                && $request->isDirty(['status', 'activated_at', 'completed_at', 'cancellation_reason'])
                && ! $request->statusTransitionInProgress) {
                throw new DomainException('Medication request lifecycle changes must use the workflow service.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Medication requests are append-only and cannot be deleted.');
        });
    }

    /** @internal */
    public function persistStatus(MedicationRequestStatus $target, ?string $reason = null): void
    {
        $allowed = match ($this->status) {
            MedicationRequestStatus::Draft => [MedicationRequestStatus::Active, MedicationRequestStatus::Cancelled],
            MedicationRequestStatus::Active => [
                MedicationRequestStatus::OnHold,
                MedicationRequestStatus::Accepted,
                MedicationRequestStatus::CancellationRecommended,
                MedicationRequestStatus::Cancelled,
            ],
            MedicationRequestStatus::OnHold,
            MedicationRequestStatus::CancellationRecommended => [MedicationRequestStatus::Active, MedicationRequestStatus::Cancelled],
            MedicationRequestStatus::Accepted => [
                MedicationRequestStatus::Completed,
                MedicationRequestStatus::Partial,
                MedicationRequestStatus::NotDispensed,
                MedicationRequestStatus::Cancelled,
            ],
            default => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw new DomainException("Medication request cannot transition from {$this->status->value} to {$target->value}.");
        }

        if ($target === MedicationRequestStatus::Cancelled && blank($reason)) {
            throw new DomainException('A medication request cancellation requires an attributed reason.');
        }

        $this->statusTransitionInProgress = true;

        try {
            $this->status = $target;
            $this->cancellation_reason = $target === MedicationRequestStatus::Cancelled
                ? trim((string) $reason)
                : $this->cancellation_reason;
            $this->activated_at = $target === MedicationRequestStatus::Active ? now() : $this->activated_at;
            $this->completed_at = in_array($target, [
                MedicationRequestStatus::Completed,
                MedicationRequestStatus::Partial,
                MedicationRequestStatus::NotDispensed,
                MedicationRequestStatus::Cancelled,
            ], true) ? now() : $this->completed_at;
            $this->save();
        } finally {
            $this->statusTransitionInProgress = false;
        }
    }

    /** @return BelongsTo<SimulationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SimulationSession::class);
    }

    /** @return BelongsTo<SyntheticPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(SyntheticPatient::class);
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<ClinicalEntryVersion, $this> */
    public function sourceEntryVersion(): BelongsTo
    {
        return $this->belongsTo(ClinicalEntryVersion::class, 'source_entry_version_id');
    }

    /** @return BelongsTo<ClinicalCondition, $this> */
    public function sourceCondition(): BelongsTo
    {
        return $this->belongsTo(ClinicalCondition::class, 'source_condition_id');
    }

    /** @return BelongsTo<MedicationRequest, $this> */
    public function replacesRequest(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_medication_request_id');
    }

    /** @return HasMany<MedicationRequest, $this> */
    public function replacementRequests(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_medication_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function requesterAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'requester_assignment_id');
    }

    /** @return HasMany<PharmacyReview, $this> */
    public function pharmacyReviews(): HasMany
    {
        return $this->hasMany(PharmacyReview::class);
    }

    /** @return HasMany<PharmacyIntervention, $this> */
    public function pharmacyInterventions(): HasMany
    {
        return $this->hasMany(PharmacyIntervention::class);
    }

    /** @return HasMany<MedicationDispensePreparation, $this> */
    public function dispensePreparations(): HasMany
    {
        return $this->hasMany(MedicationDispensePreparation::class);
    }

    /** @return HasMany<MedicationDispense, $this> */
    public function dispenses(): HasMany
    {
        return $this->hasMany(MedicationDispense::class);
    }

    protected function casts(): array
    {
        return [
            'dose_value' => 'decimal:3',
            'quantity_value' => 'decimal:3',
            'status' => MedicationRequestStatus::class,
            'authored_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
