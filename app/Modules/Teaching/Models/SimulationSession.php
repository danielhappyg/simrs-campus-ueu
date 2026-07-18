<?php

namespace App\Modules\Teaching\Models;

use App\Models\User;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $scenario_id
 * @property string $code
 * @property string $course_code
 * @property string $cohort_code
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int $facilitator_user_id
 * @property int|null $source_session_id
 * @property EnvironmentMode $environment_mode
 * @property SessionStatus $status
 * @property-read SimulationScenario $scenario
 * @property-read User $facilitator
 * @property-read SimulationSession|null $sourceSession
 */
class SimulationSession extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'scenario_id',
        'code',
        'course_code',
        'cohort_code',
        'environment_mode',
        'status',
        'starts_at',
        'ends_at',
        'facilitator_user_id',
        'source_session_id',
    ];

    /**
     * @return BelongsTo<SimulationScenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(SimulationScenario::class, 'scenario_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'facilitator_user_id');
    }

    /**
     * @return BelongsTo<SimulationSession, $this>
     */
    public function sourceSession(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_session_id');
    }

    /**
     * @return HasMany<SimulationSession, $this>
     */
    public function clones(): HasMany
    {
        return $this->hasMany(self::class, 'source_session_id');
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'session_id');
    }

    /**
     * @return HasMany<WorkTask, $this>
     */
    public function workTasks(): HasMany
    {
        return $this->hasMany(WorkTask::class, 'session_id');
    }

    /**
     * @return HasMany<SyntheticPatient, $this>
     */
    public function patients(): HasMany
    {
        return $this->hasMany(SyntheticPatient::class, 'session_id');
    }

    /**
     * @return HasMany<Encounter, $this>
     */
    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class, 'session_id');
    }

    protected function casts(): array
    {
        return [
            'environment_mode' => EnvironmentMode::class,
            'status' => SessionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
