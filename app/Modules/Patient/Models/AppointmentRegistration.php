<?php

namespace App\Modules\Patient\Models;

use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Models\ServiceLocation;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Enums\DuplicateDecision;
use App\Modules\Patient\Enums\VisitSource;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $session_id
 * @property string $public_id
 * @property string $request_key
 * @property string $appointment_code
 * @property AppointmentStatus $status
 * @property VisitSource $visit_source
 * @property DuplicateDecision|null $duplicate_decision
 * @property CarbonImmutable $scheduled_at
 * @property CarbonImmutable|null $checked_in_at
 * @property-read SimulationSession $session
 * @property-read SyntheticPatient $patient
 * @property-read ServiceLocation $location
 * @property-read Assignment $registeredByAssignment
 * @property-read Encounter|null $encounter
 */
class AppointmentRegistration extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'request_key',
        'session_id',
        'patient_id',
        'location_id',
        'registered_by_assignment_id',
        'appointment_code',
        'scheduled_at',
        'visit_reason',
        'visit_source',
        'coverage_status',
        'identity_verification_method',
        'consent_version',
        'consent_acknowledged_at',
        'duplicate_decision',
        'duplicate_reason',
        'status',
        'checked_in_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $appointment): void {
            $patient = SyntheticPatient::query()->find($appointment->patient_id);
            $assignment = Assignment::query()->find($appointment->registered_by_assignment_id);

            if (! $patient
                || ! $assignment
                || $patient->session_id !== $appointment->session_id
                || $assignment->session_id !== $appointment->session_id) {
                throw new DomainException('Appointment identity, author, and session context must match.');
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
     * @return BelongsTo<SyntheticPatient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(SyntheticPatient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<ServiceLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ServiceLocation::class, 'location_id');
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function registeredByAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'registered_by_assignment_id');
    }

    /**
     * @return HasOne<Encounter, $this>
     */
    public function encounter(): HasOne
    {
        return $this->hasOne(Encounter::class, 'appointment_registration_id');
    }

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'immutable_datetime',
            'visit_source' => VisitSource::class,
            'consent_acknowledged_at' => 'immutable_datetime',
            'duplicate_decision' => DuplicateDecision::class,
            'status' => AppointmentStatus::class,
            'checked_in_at' => 'immutable_datetime',
        ];
    }
}
