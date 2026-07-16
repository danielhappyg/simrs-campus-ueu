<?php

namespace App\Modules\Clinical\Models;

use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Support\Models\HasPublicUlid;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $session_id
 * @property int $patient_id
 * @property int $encounter_id
 * @property string $public_id
 * @property ClinicalDocumentType $document_type
 * @property ClinicalEntryStatus $lifecycle_status
 * @property-read SimulationSession $session
 * @property-read SyntheticPatient $patient
 * @property-read Encounter $encounter
 * @property-read Assignment $createdByAssignment
 */
class ClinicalEntry extends Model
{
    use HasPublicUlid;

    private bool $lifecycleTransitionInProgress = false;

    protected $fillable = [
        'session_id',
        'patient_id',
        'encounter_id',
        'created_by_assignment_id',
        'document_type',
        'sensitivity_class',
        'lifecycle_status',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $entry): void {
            $encounter = Encounter::query()->find($entry->encounter_id);
            $patient = SyntheticPatient::query()->find($entry->patient_id);
            $assignment = Assignment::query()->find($entry->created_by_assignment_id);

            if (! $encounter
                || ! $patient
                || ! $assignment
                || $encounter->session_id !== $entry->session_id
                || $encounter->patient_id !== $entry->patient_id
                || $patient->session_id !== $entry->session_id
                || $assignment->session_id !== $entry->session_id
                || $assignment->patient_id !== $entry->patient_id
                || $assignment->encounter_id !== $entry->encounter_id
                || ! $assignment->hasCapability($entry->document_type->authorCapability())) {
                throw new DomainException('A clinical entry must stay inside its encounter, patient, session, and author capability context.');
            }

            if ($entry->exists && $entry->isDirty(['session_id', 'patient_id', 'encounter_id', 'created_by_assignment_id', 'document_type'])) {
                throw new DomainException('Clinical entry identity and context are immutable.');
            }

            if ($entry->exists && $entry->isDirty('lifecycle_status') && ! $entry->lifecycleTransitionInProgress) {
                throw new DomainException('Clinical entry lifecycle changes must use the documentation service.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Clinical entries are append-only records and cannot be deleted.');
        });
    }

    /** @internal Clinical lifecycle transitions are persisted by ClinicalDocumentationService. */
    public function persistLifecycleStatus(ClinicalEntryStatus $target): void
    {
        $allowed = match ($this->lifecycle_status) {
            ClinicalEntryStatus::Draft => [ClinicalEntryStatus::Submitted],
            ClinicalEntryStatus::Submitted => [ClinicalEntryStatus::ChangesRequested, ClinicalEntryStatus::Approved],
            ClinicalEntryStatus::ChangesRequested => [ClinicalEntryStatus::Draft],
            ClinicalEntryStatus::Approved => [ClinicalEntryStatus::Amended],
            ClinicalEntryStatus::Amended => [ClinicalEntryStatus::Draft],
            default => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw new DomainException("Clinical entry cannot transition from {$this->lifecycle_status->value} to {$target->value}.");
        }

        $this->lifecycleTransitionInProgress = true;

        try {
            $this->lifecycle_status = $target;
            $this->save();
        } finally {
            $this->lifecycleTransitionInProgress = false;
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

    /** @return BelongsTo<Assignment, $this> */
    public function createdByAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'created_by_assignment_id');
    }

    /** @return HasMany<ClinicalEntryVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ClinicalEntryVersion::class);
    }

    /** @return HasOne<ClinicalEntryVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(ClinicalEntryVersion::class)->ofMany('version_number', 'max');
    }

    protected function casts(): array
    {
        return [
            'document_type' => ClinicalDocumentType::class,
            'lifecycle_status' => ClinicalEntryStatus::class,
        ];
    }
}
