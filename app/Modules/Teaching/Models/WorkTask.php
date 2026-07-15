<?php

namespace App\Modules\Teaching\Models;

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
