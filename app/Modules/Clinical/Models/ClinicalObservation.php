<?php

namespace App\Modules\Clinical\Models;

use App\Modules\Clinical\Enums\ObservationStatus;
use App\Modules\Clinical\Enums\ObservationValueType;
use App\Modules\Teaching\Models\Assignment;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $public_id
 * @property ObservationValueType $value_type
 * @property ObservationStatus $status
 * @property CarbonImmutable $occurrence_at
 * @property CarbonImmutable $recorded_at
 * @property-read ClinicalEntryVersion $sourceEntryVersion
 */
class ClinicalObservation extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'source_entry_version_id',
        'patient_id',
        'encounter_id',
        'author_assignment_id',
        'category',
        'code_system',
        'code',
        'display',
        'code_version',
        'mapping_version',
        'value_type',
        'value_numeric',
        'value_text',
        'value_code',
        'value_boolean',
        'unit_system',
        'unit_code',
        'unit_display',
        'occurrence_at',
        'recorded_at',
        'status',
        'data_quality_flags',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $observation): void {
            $version = ClinicalEntryVersion::query()->with('clinicalEntry')->find($observation->source_entry_version_id);
            $assignment = Assignment::query()->find($observation->author_assignment_id);

            if (! $version
                || ! $assignment
                || $observation->patient_id !== $version->clinicalEntry->patient_id
                || $observation->encounter_id !== $version->clinicalEntry->encounter_id
                || $observation->author_assignment_id !== $version->author_assignment_id
                || $assignment->session_id !== $version->clinicalEntry->session_id) {
                throw new DomainException('An observation must match its source clinical version and case context.');
            }

            $values = [
                'value_numeric' => $observation->value_numeric,
                'value_text' => $observation->value_text,
                'value_code' => $observation->value_code,
                'value_boolean' => $observation->value_boolean,
            ];
            $expected = match ($observation->value_type) {
                ObservationValueType::Quantity => 'value_numeric',
                ObservationValueType::Text => 'value_text',
                ObservationValueType::Coded => 'value_code',
                ObservationValueType::Boolean => 'value_boolean',
            };

            foreach ($values as $field => $value) {
                if (($field === $expected && $value === null) || ($field !== $expected && $value !== null)) {
                    throw new DomainException('An observation must populate exactly the value field declared by its value type.');
                }
            }

            if ($observation->value_type === ObservationValueType::Quantity
                && (! $observation->unit_system || ! $observation->unit_code || ! $observation->unit_display)) {
                throw new DomainException('A quantity observation requires an explicit unit system, code, and display.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Clinical observations are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new DomainException('Clinical observations are immutable and cannot be deleted.');
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
            'value_type' => ObservationValueType::class,
            'value_numeric' => 'decimal:3',
            'value_boolean' => 'boolean',
            'status' => ObservationStatus::class,
            'occurrence_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'data_quality_flags' => 'array',
        ];
    }
}
