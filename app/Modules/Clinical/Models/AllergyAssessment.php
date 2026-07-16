<?php

namespace App\Modules\Clinical\Models;

use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Teaching\Models\Assignment;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $public_id
 * @property AllergyAssessmentState $assessment_state
 * @property CarbonImmutable $assessed_at
 * @property-read ClinicalEntryVersion $sourceEntryVersion
 */
class AllergyAssessment extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'source_entry_version_id',
        'patient_id',
        'encounter_id',
        'author_assignment_id',
        'assessment_state',
        'details',
        'assessed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $assessment): void {
            $version = ClinicalEntryVersion::query()->with('clinicalEntry')->find($assessment->source_entry_version_id);
            $assignment = Assignment::query()->find($assessment->author_assignment_id);

            if (! $version
                || ! $assignment
                || $assessment->patient_id !== $version->clinicalEntry->patient_id
                || $assessment->encounter_id !== $version->clinicalEntry->encounter_id
                || $assessment->author_assignment_id !== $version->author_assignment_id
                || $assignment->session_id !== $version->clinicalEntry->session_id) {
                throw new DomainException('An allergy assessment must match its source clinical version and case context.');
            }

            if ($assessment->assessment_state === AllergyAssessmentState::KnownAllergy
                && blank($assessment->details)) {
                throw new DomainException('Known allergy status requires authored allergy details.');
            }

            if ($assessment->assessment_state === AllergyAssessmentState::NoKnownAllergyReported
                && filled($assessment->details)) {
                throw new DomainException('No-known-allergy status cannot carry contradictory allergy details.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Allergy assessments are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new DomainException('Allergy assessments are immutable and cannot be deleted.');
        });
    }

    /** @return BelongsTo<ClinicalEntryVersion, $this> */
    public function sourceEntryVersion(): BelongsTo
    {
        return $this->belongsTo(ClinicalEntryVersion::class, 'source_entry_version_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function authorAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'author_assignment_id');
    }

    protected function casts(): array
    {
        return [
            'assessment_state' => AllergyAssessmentState::class,
            'assessed_at' => 'immutable_datetime',
        ];
    }
}
