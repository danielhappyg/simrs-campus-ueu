<?php

namespace App\Modules\Teaching\Models;

use App\Modules\Clinical\Models\ClinicalEntry;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\Program;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $session_id
 * @property int $assignment_id
 * @property int|null $encounter_id
 * @property int|null $clinical_entry_id
 * @property int|null $clinical_entry_version_id
 * @property string $public_id
 * @property WorkTaskType $task_type
 * @property string $title
 * @property string|null $description
 * @property WorkTaskStatus $status
 * @property int $priority
 * @property Program|null $source_program
 * @property array<string, mixed>|null $context
 * @property CarbonImmutable|null $available_at
 * @property CarbonImmutable|null $completed_at
 * @property-read Assignment $assignment
 * @property-read SimulationSession $session
 */
class WorkTask extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'session_id',
        'assignment_id',
        'encounter_id',
        'clinical_entry_id',
        'clinical_entry_version_id',
        'task_type',
        'title',
        'description',
        'status',
        'priority',
        'source_program',
        'context',
        'available_at',
        'completed_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $task): void {
            $assignment = Assignment::query()->findOrFail($task->assignment_id);

            if ($assignment->session_id !== $task->session_id) {
                throw new DomainException('A work task and its assignment must belong to the same simulation session.');
            }

            if (! $assignment->hasCapability($task->task_type->requiredCapability())) {
                throw new DomainException('The assignment does not grant the capability required by this work task.');
            }

            if ($task->encounter_id === null) {
                return;
            }

            $encounter = Encounter::query()->find($task->encounter_id);

            if (! $encounter || $encounter->session_id !== $task->session_id) {
                throw new DomainException('A work task encounter must belong to the same simulation session.');
            }

            if (($assignment->patient_id !== null && $assignment->patient_id !== $encounter->patient_id)
                || ($assignment->encounter_id !== null && $assignment->encounter_id !== $encounter->getKey())) {
                throw new DomainException('A work task cannot cross its assignment case scope.');
            }

            if ($task->clinical_entry_id === null && $task->clinical_entry_version_id === null) {
                return;
            }

            $entry = $task->clinical_entry_id === null ? null : ClinicalEntry::query()->find($task->clinical_entry_id);
            $version = $task->clinical_entry_version_id === null ? null : ClinicalEntryVersion::query()->find($task->clinical_entry_version_id);

            if (! $entry
                || $entry->session_id !== $task->session_id
                || $entry->encounter_id !== $task->encounter_id
                || ($version && $version->clinical_entry_id !== $entry->getKey())
                || ($task->clinical_entry_version_id !== null && ! $version)) {
                throw new DomainException('A work task clinical entry/version must match its session and encounter context.');
            }
        });
    }

    /**
     * @return BelongsTo<SimulationSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SimulationSession::class);
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<ClinicalEntry, $this> */
    public function clinicalEntry(): BelongsTo
    {
        return $this->belongsTo(ClinicalEntry::class);
    }

    /** @return BelongsTo<ClinicalEntryVersion, $this> */
    public function clinicalEntryVersion(): BelongsTo
    {
        return $this->belongsTo(ClinicalEntryVersion::class);
    }

    protected function casts(): array
    {
        return [
            'task_type' => WorkTaskType::class,
            'status' => WorkTaskStatus::class,
            'source_program' => Program::class,
            'context' => 'array',
            'available_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
