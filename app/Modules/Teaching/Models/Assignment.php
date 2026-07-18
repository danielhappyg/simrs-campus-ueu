<?php

namespace App\Modules\Teaching\Models;

use App\Models\User;
use App\Modules\Clinical\Models\ClinicalEntry;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\Program;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $patient_id
 * @property int|null $encounter_id
 * @property string $public_id
 * @property int $session_id
 * @property int $user_id
 * @property int|null $supervisor_assignment_id
 * @property Program $program
 * @property ApplicationRole $application_role
 * @property array<int, string> $capabilities
 * @property CarbonImmutable $active_from
 * @property CarbonImmutable|null $active_until
 * @property CarbonImmutable|null $revoked_at
 * @property-read User $user
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
        'patient_id',
        'encounter_id',
        'supervisor_assignment_id',
        'active_from',
        'active_until',
        'revoked_at',
        'revoked_by_user_id',
        'revocation_reason',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $assignment): void {
            $patient = $assignment->patient_id === null
                ? null
                : SyntheticPatient::query()->find($assignment->patient_id);

            if ($assignment->patient_id !== null && (! $patient || $patient->session_id !== $assignment->session_id)) {
                throw new DomainException('An assignment patient must belong to the same simulation session.');
            }

            if ($assignment->encounter_id === null) {
                self::assertSupervisorContext($assignment);

                return;
            }

            $encounter = Encounter::query()->find($assignment->encounter_id);

            if (! $encounter
                || $encounter->session_id !== $assignment->session_id
                || $assignment->patient_id === null
                || $encounter->patient_id !== $assignment->patient_id) {
                throw new DomainException('An assignment encounter must match its session and patient scope.');
            }

            self::assertSupervisorContext($assignment);
        });
    }

    private static function assertSupervisorContext(self $assignment): void
    {
        if ($assignment->supervisor_assignment_id === null) {
            return;
        }

        if ($assignment->exists && $assignment->supervisor_assignment_id === $assignment->getKey()) {
            throw new DomainException('An assignment cannot supervise itself.');
        }

        $supervisor = self::query()->find($assignment->supervisor_assignment_id);

        if (! $supervisor
            || $supervisor->session_id !== $assignment->session_id
            || $supervisor->user_id === $assignment->user_id
            || ! $supervisor->hasCapability(Capability::SupervisionReview)) {
            throw new DomainException('A linked supervisor must be a different supervision-capable assignment in the same session.');
        }

        if (($assignment->patient_id !== null || $assignment->encounter_id !== null)
            && ($supervisor->patient_id !== $assignment->patient_id
                || $supervisor->encounter_id !== $assignment->encounter_id)) {
            throw new DomainException('A linked supervisor must match the learner assignment patient and encounter scope.');
        }
    }

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

    /** @return HasMany<Assignment, $this> */
    public function supervisees(): HasMany
    {
        return $this->hasMany(self::class, 'supervisor_assignment_id');
    }

    /**
     * @return BelongsTo<SyntheticPatient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(SyntheticPatient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    /**
     * @return HasMany<WorkTask, $this>
     */
    public function workTasks(): HasMany
    {
        return $this->hasMany(WorkTask::class);
    }

    /** @return HasMany<ClinicalEntry, $this> */
    public function createdClinicalEntries(): HasMany
    {
        return $this->hasMany(ClinicalEntry::class, 'created_by_assignment_id');
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
