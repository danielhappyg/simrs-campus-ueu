<?php

namespace App\Modules\Clinical\Models;

use App\Modules\Clinical\Enums\DiagnosisCertainty;
use App\Modules\Clinical\Enums\DiagnosisRole;
use App\Modules\Teaching\Models\Assignment;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property string $authored_text
 * @property DiagnosisCertainty $certainty
 * @property DiagnosisRole $role
 * @property string $clinical_status
 * @property string|null $code_system
 * @property string|null $code
 * @property string|null $display
 * @property string|null $code_version
 * @property CarbonImmutable|null $onset_at
 * @property CarbonImmutable $recorded_at
 */
class ClinicalCondition extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'source_entry_version_id',
        'patient_id',
        'encounter_id',
        'author_assignment_id',
        'authored_text',
        'certainty',
        'role',
        'clinical_status',
        'code_system',
        'code',
        'display',
        'code_version',
        'onset_at',
        'recorded_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $condition): void {
            $version = ClinicalEntryVersion::query()->with('clinicalEntry')->find($condition->source_entry_version_id);
            $assignment = Assignment::query()->find($condition->author_assignment_id);

            if (! $version
                || ! $assignment
                || $condition->patient_id !== $version->clinicalEntry->patient_id
                || $condition->encounter_id !== $version->clinicalEntry->encounter_id
                || $condition->author_assignment_id !== $version->author_assignment_id
                || $assignment->session_id !== $version->clinicalEntry->session_id) {
                throw new DomainException('A condition must match its source clinical version and case context.');
            }

            $codedFields = [$condition->code_system, $condition->code, $condition->display];
            $codedCount = collect($codedFields)->filter(fn (mixed $value): bool => filled($value))->count();

            if (! in_array($codedCount, [0, 3], true)) {
                throw new DomainException('An optional condition code requires system, code, and authoritative display together.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Clinical conditions are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new DomainException('Clinical conditions are immutable and cannot be deleted.');
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
            'certainty' => DiagnosisCertainty::class,
            'role' => DiagnosisRole::class,
            'onset_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
