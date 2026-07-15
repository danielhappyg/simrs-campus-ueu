<?php

namespace App\Modules\Teaching\Models;

use App\Models\User;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\Program;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property Program $program
 * @property ApplicationRole $application_role
 * @property array<int, string> $capabilities
 */
class Assignment extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'session_id',
        'user_id',
        'program',
        'application_role',
        'capabilities',
        'supervisor_assignment_id',
        'active_from',
        'active_until',
        'revoked_at',
        'revoked_by_user_id',
        'revocation_reason',
    ];

    /**
     * @param  Builder<Assignment>  $query
     * @return Builder<Assignment>
     */
    public function scopeActive(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->whereNull('revoked_at')
            ->where('active_from', '<=', $at)
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('active_until')->orWhere('active_until', '>=', $at);
            });
    }

    public function hasCapability(Capability|string $capability): bool
    {
        $value = $capability instanceof Capability ? $capability->value : $capability;

        return in_array($value, $this->capabilities, true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
    public function supervisorAssignment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_assignment_id');
    }

    /**
     * @return HasMany<WorkTask, $this>
     */
    public function workTasks(): HasMany
    {
        return $this->hasMany(WorkTask::class);
    }

    protected function casts(): array
    {
        return [
            'program' => Program::class,
            'application_role' => ApplicationRole::class,
            'capabilities' => 'array',
            'active_from' => 'datetime',
            'active_until' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
