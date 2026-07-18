<?php

namespace App\Modules\Encounter\Models;

use App\Modules\Clinical\Models\ClinicalEntry;
use App\Modules\Clinical\Models\OutpatientEarlyDeparture;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $session_id
 * @property int $patient_id
 * @property string $public_id
 * @property string $encounter_number
 * @property EncounterStatus $status
 * @property EnvironmentMode $environment_mode
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property CarbonImmutable|null $closure_requested_at
 * @property CarbonImmutable|null $clinically_closed_at
 * @property CarbonImmutable|null $finalized_at
 * @property-read SimulationSession $session
 * @property-read SyntheticPatient $patient
 * @property-read AppointmentRegistration $appointment
 * @property-read ServiceLocation $location
 */
class Encounter extends Model
{
    use HasPublicUlid;

    private bool $statusTransitionInProgress = false;

    protected $fillable = [
        'session_id',
        'patient_id',
        'appointment_registration_id',
        'location_id',
        'encounter_number',
        'class',
        'service_type_code',
        'service_type_display',
        'status',
        'period_start',
        'period_end',
        'disposition',
        'closure_requested_at',
        'clinically_closed_at',
        'finalized_at',
        'environment_mode',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $encounter): void {
            if ($encounter->environment_mode !== EnvironmentMode::Simulation) {
                throw new DomainException('Outpatient encounters are restricted to simulation mode in the reference MVP.');
            }

            if (! $encounter->exists && $encounter->status !== EncounterStatus::Planned) {
                throw new DomainException('A new outpatient encounter must begin in the planned state.');
            }

            if ($encounter->exists && $encounter->isDirty('status') && ! $encounter->statusTransitionInProgress) {
                throw new DomainException('Encounter status changes must use the transition service.');
            }

            $patient = SyntheticPatient::query()->find($encounter->patient_id);
            $appointment = AppointmentRegistration::query()->find($encounter->appointment_registration_id);

            if (! $patient
                || ! $appointment
                || $patient->session_id !== $encounter->session_id
                || $appointment->session_id !== $encounter->session_id
                || $appointment->patient_id !== $encounter->patient_id
                || $appointment->location_id !== $encounter->location_id) {
                throw new DomainException('Encounter identity, appointment, location, and session context must match.');
            }
        });
    }

    /** @internal State transitions are persisted by EncounterTransitionService. */
    public function persistTransitionState(EncounterStatus $target): void
    {
        if (! $this->exists || ! $this->status->canTransitionTo($target)) {
            throw new DomainException('The requested encounter transition is not allowed.');
        }

        $this->statusTransitionInProgress = true;

        try {
            $this->status = $target;
            $this->save();
        } finally {
            $this->statusTransitionInProgress = false;
        }
    }

    /**
     * @return BelongsTo<SimulationSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SimulationSession::class);
    }

    /**
     * @return BelongsTo<SyntheticPatient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(SyntheticPatient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<AppointmentRegistration, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(AppointmentRegistration::class, 'appointment_registration_id');
    }

    /**
     * @return BelongsTo<ServiceLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ServiceLocation::class, 'location_id');
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'encounter_id');
    }

    /**
     * @return HasMany<WorkTask, $this>
     */
    public function workTasks(): HasMany
    {
        return $this->hasMany(WorkTask::class, 'encounter_id');
    }

    /**
     * @return HasMany<QueueEvent, $this>
     */
    public function queueEvents(): HasMany
    {
        return $this->hasMany(QueueEvent::class, 'encounter_id');
    }

    /**
     * @return HasMany<EncounterTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(EncounterTransition::class, 'encounter_id');
    }

    /** @return HasMany<ClinicalEntry, $this> */
    public function clinicalEntries(): HasMany
    {
        return $this->hasMany(ClinicalEntry::class, 'encounter_id');
    }

    /** @return HasOne<OutpatientEarlyDeparture, $this> */
    public function earlyDeparture(): HasOne
    {
        return $this->hasOne(OutpatientEarlyDeparture::class, 'encounter_id');
    }

    protected function casts(): array
    {
        return [
            'status' => EncounterStatus::class,
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'closure_requested_at' => 'immutable_datetime',
            'clinically_closed_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'environment_mode' => EnvironmentMode::class,
        ];
    }
}
