<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\ServiceRequestStatus;
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
 * @property int $requester_assignment_id
 * @property string $public_id
 * @property int $sequence_number
 * @property string $request_type
 * @property string $authored_service
 * @property string $clinical_question
 * @property string $priority
 * @property ServiceRequestStatus $status
 * @property CarbonImmutable $authored_at
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $completed_at
 * @property-read ClinicalEntryVersion $sourceEntryVersion
 * @property-read ClinicalCondition|null $sourceCondition
 */
class ServiceRequest extends Model
{
    use HasPublicUlid;

    private bool $statusTransitionInProgress = false;

    protected $fillable = [
        'session_id',
        'patient_id',
        'encounter_id',
        'source_entry_version_id',
        'source_condition_id',
        'requester_user_id',
        'requester_assignment_id',
        'sequence_number',
        'request_type',
        'authored_service',
        'clinical_question',
        'priority',
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

            if (! $version
                || ! $assignment
                || $version->clinicalEntry->document_type !== ClinicalDocumentType::MedicalAssessment
                || $request->requester_user_id !== $assignment->user_id
                || $request->requester_assignment_id !== $version->author_assignment_id
                || $request->session_id !== $version->clinicalEntry->session_id
                || $request->patient_id !== $version->clinicalEntry->patient_id
                || $request->encounter_id !== $version->clinicalEntry->encounter_id
                || ($condition && $condition->source_entry_version_id !== $version->getKey())
                || ($request->source_condition_id !== null && ! $condition)) {
                throw new DomainException('A service request must match its medical source version, author, diagnosis, and case context.');
            }

            if (! $request->exists && $request->status !== ServiceRequestStatus::Draft) {
                throw new DomainException('A new service request must begin as a draft.');
            }

            if ($request->exists && $request->isDirty([
                'session_id',
                'patient_id',
                'encounter_id',
                'source_entry_version_id',
                'source_condition_id',
                'requester_user_id',
                'requester_assignment_id',
                'sequence_number',
                'request_type',
                'authored_service',
                'clinical_question',
                'priority',
                'authored_at',
            ])) {
                throw new DomainException('Service request content and provenance are immutable.');
            }

            if ($request->exists
                && $request->isDirty(['status', 'activated_at', 'completed_at'])
                && ! $request->statusTransitionInProgress) {
                throw new DomainException('Service request lifecycle changes must use the workflow service.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Service requests are append-only and cannot be deleted.');
        });
    }

    /** @internal */
    public function persistStatus(ServiceRequestStatus $target): void
    {
        $allowed = match ($this->status) {
            ServiceRequestStatus::Draft => [ServiceRequestStatus::Active, ServiceRequestStatus::Cancelled],
            ServiceRequestStatus::Active => [ServiceRequestStatus::Completed, ServiceRequestStatus::Cancelled],
            default => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw new DomainException("Service request cannot transition from {$this->status->value} to {$target->value}.");
        }

        $this->statusTransitionInProgress = true;

        try {
            $this->status = $target;
            $this->activated_at = $target === ServiceRequestStatus::Active ? now() : $this->activated_at;
            $this->completed_at = in_array($target, [ServiceRequestStatus::Completed, ServiceRequestStatus::Cancelled], true)
                ? now()
                : $this->completed_at;
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

    /** @return HasMany<DiagnosticResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(DiagnosticResult::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ServiceRequestStatus::class,
            'authored_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
