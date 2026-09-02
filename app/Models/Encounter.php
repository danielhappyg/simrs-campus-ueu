<?php

namespace App\Models;

use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Database\Factories\EncounterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $patient_id
 * @property int|null $active_inpatient_patient_id
 * @property string $care_setting
 * @property string $status
 * @property string $clinic_name
 * @property int|null $clinic_id
 * @property int|null $doctor_id
 * @property int|null $clinic_schedule_id
 * @property string|null $doctor_name
 * @property string|null $schedule_label
 * @property Carbon|null $visit_date
 * @property string|null $admission_mode
 * @property string $payer_type
 * @property string|null $insurance_number
 * @property string|null $booking_code
 * @property int|null $queue_number
 * @property string $queue_date
 * @property Carbon $registered_at
 * @property int $registered_by_user_id
 * @property string|null $chief_complaint
 * @property string|null $case_type
 * @property string|null $accident_type
 * @property string|null $ward_name
 * @property string|null $ward_class
 * @property string|null $bed_code
 * @property int|null $inpatient_bed_id
 * @property string|null $continue_from
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Encounter extends Model
{
    /** @use HasFactory<EncounterFactory> */
    use HasFactory, HasPublicUlid, UsesSchemaQualifiedTable;

    public const CARE_SETTING_OUTPATIENT = 'OUTPATIENT';

    public const CARE_SETTING_EMERGENCY = 'EMERGENCY';

    public const CARE_SETTING_INPATIENT = 'INPATIENT';

    /** @var list<string> */
    public const CARE_SETTINGS = [
        self::CARE_SETTING_OUTPATIENT,
        self::CARE_SETTING_EMERGENCY,
        self::CARE_SETTING_INPATIENT,
    ];

    public const STATUS_REGISTERED = 'REGISTERED';

    public const STATUS_IN_EXAMINATION = 'IN_EXAMINATION';

    public const STATUS_READY_FOR_RM = 'READY_FOR_RM';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const PAYER_UMUM = 'UMUM';

    public const PAYER_BPJS = 'BPJS';

    public const PAYER_LAINNYA = 'LAINNYA';

    public const ADMISSION_DATANG_SENDIRI = 'DATANG_SENDIRI';

    public const ADMISSION_RUJUKAN = 'RUJUKAN';

    public const ADMISSION_IGD = 'IGD';

    public const CASE_NON_BEDAH = 'NON_BEDAH';

    public const CASE_BEDAH = 'BEDAH';

    public const ACCIDENT_NONE = 'BUKAN_KECELAKAAN';

    public const ACCIDENT_YES = 'KECELAKAAN';

    public const CONTINUE_LANGSUNG = 'LANGSUNG';

    public const CONTINUE_DARI_IGD = 'DARI_IGD';

    public const CONTINUE_DARI_RJ = 'DARI_RJ';

    /**
     * @var list<string>
     */
    public const CONTINUE_FROM_VALUES = [
        self::CONTINUE_LANGSUNG,
        self::CONTINUE_DARI_IGD,
        self::CONTINUE_DARI_RJ,
    ];

    /**
     * @var list<string>
     */
    public const PAYER_VALUES = [
        self::PAYER_UMUM,
        self::PAYER_BPJS,
        self::PAYER_LAINNYA,
    ];

    /**
     * @var list<string>
     */
    public const ADMISSION_VALUES = [
        self::ADMISSION_DATANG_SENDIRI,
        self::ADMISSION_RUJUKAN,
        self::ADMISSION_IGD,
    ];

    /**
     * @var list<string>
     */
    public const EMERGENCY_ADMISSION_VALUES = [
        self::ADMISSION_DATANG_SENDIRI,
        self::ADMISSION_RUJUKAN,
    ];

    /**
     * @var list<string>
     */
    public const CASE_TYPE_VALUES = [
        self::CASE_NON_BEDAH,
        self::CASE_BEDAH,
    ];

    /**
     * @var list<string>
     */
    public const ACCIDENT_TYPE_VALUES = [
        self::ACCIDENT_NONE,
        self::ACCIDENT_YES,
    ];

    /**
     * @var list<string>
     */
    public const EXAMINATION_STATUSES = [
        self::STATUS_REGISTERED,
        self::STATUS_IN_EXAMINATION,
        self::STATUS_READY_FOR_RM,
    ];

    /** @var list<string> */
    public const ACTIVE_STATUSES = self::EXAMINATION_STATUSES;

    /** @var list<string> */
    public const TERMINAL_STATUSES = [
        self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
    ];

    /** @var list<string> */
    public const BED_OCCUPYING_STATUSES = [
        self::STATUS_REGISTERED,
        self::STATUS_IN_EXAMINATION,
    ];

    protected $fillable = [
        'patient_id',
        'active_inpatient_patient_id',
        'care_setting',
        'status',
        'clinic_name',
        'clinic_id',
        'doctor_id',
        'clinic_schedule_id',
        'doctor_name',
        'schedule_label',
        'visit_date',
        'admission_mode',
        'payer_type',
        'insurance_number',
        'booking_code',
        'queue_number',
        'queue_date',
        'registered_at',
        'registered_by_user_id',
        'chief_complaint',
        'case_type',
        'accident_type',
        'ward_name',
        'ward_class',
        'bed_code',
        'inpatient_bed_id',
        'continue_from',
    ];

    protected $attributes = [
        'care_setting' => self::CARE_SETTING_OUTPATIENT,
        'status' => self::STATUS_REGISTERED,
    ];

    protected static function booted(): void
    {
        static::saving(static function (Encounter $encounter): void {
            $encounter->active_inpatient_patient_id = $encounter->care_setting === self::CARE_SETTING_INPATIENT
                && in_array($encounter->status, self::BED_OCCUPYING_STATUSES, true)
                ? $encounter->patient_id
                : null;
        });

        static::updating(static function (Encounter $encounter): void {
            $isInpatientPlacement = $encounter->care_setting === self::CARE_SETTING_INPATIENT
                || $encounter->getOriginal('care_setting') === self::CARE_SETTING_INPATIENT
                || $encounter->isDirty(['inpatient_bed_id', 'bed_code', 'ward_name', 'ward_class']);
            if ($isInpatientPlacement
                && $encounter->isDirty(['inpatient_bed_id', 'bed_code', 'ward_name', 'ward_class', 'clinic_name'])
                && ! InpatientLocationMutationScope::isActive()) {
                throw new \LogicException('Direct inpatient placement mutation is prohibited.');
            }
        });
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<InpatientBed, $this> */
    public function inpatientBed(): BelongsTo
    {
        return $this->belongsTo(InpatientBed::class, 'inpatient_bed_id');
    }

    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * @return BelongsTo<ClinicSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ClinicSchedule::class, 'clinic_schedule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    /**
     * @return HasMany<ClinicalEntry, $this>
     */
    public function clinicalEntries(): HasMany
    {
        return $this->hasMany(ClinicalEntry::class);
    }

    /** @return HasMany<EmergencyTriageAssessment, $this> */
    public function emergencyTriageAssessments(): HasMany
    {
        return $this->hasMany(EmergencyTriageAssessment::class)
            ->orderBy('assessment_number');
    }

    /** @return HasMany<EmergencyClinicalDocument, $this> */
    public function emergencyClinicalDocuments(): HasMany
    {
        return $this->hasMany(EmergencyClinicalDocument::class);
    }

    /** @return HasMany<EmergencyResultFollowUpProposal, $this> */
    public function emergencyResultFollowUpProposals(): HasMany
    {
        return $this->hasMany(EmergencyResultFollowUpProposal::class)
            ->orderBy('created_at');
    }

    /** @return HasMany<EmergencyDisposition, $this> */
    public function emergencyDispositions(): HasMany
    {
        return $this->hasMany(EmergencyDisposition::class)
            ->orderBy('version');
    }

    /** @return HasMany<EmergencyDispositionCorrectionIntent, $this> */
    public function emergencyDispositionCorrectionIntents(): HasMany
    {
        return $this->hasMany(EmergencyDispositionCorrectionIntent::class)
            ->orderBy('created_at');
    }

    /** @return HasOne<EmergencyInpatientHandoff, $this> */
    public function emergencyInpatientHandoff(): HasOne
    {
        return $this->hasOne(EmergencyInpatientHandoff::class, 'source_encounter_id');
    }

    /** @return HasOne<EmergencyInpatientHandoff, $this> */
    public function sourceEmergencyHandoff(): HasOne
    {
        return $this->hasOne(EmergencyInpatientHandoff::class, 'target_encounter_id');
    }

    /** @return HasMany<InpatientClinicalDocument, $this> */
    public function inpatientClinicalDocuments(): HasMany
    {
        return $this->hasMany(InpatientClinicalDocument::class);
    }

    /** @return HasOne<InpatientDischargeSummary, $this> */
    public function inpatientDischargeSummary(): HasOne
    {
        return $this->hasOne(InpatientDischargeSummary::class);
    }

    /** @return HasOne<InpatientDischargeCodingSource, $this> */
    public function inpatientDischargeCodingSource(): HasOne
    {
        return $this->hasOne(InpatientDischargeCodingSource::class);
    }

    /** @return HasOne<InpatientRmCoding, $this> */
    public function inpatientRmCoding(): HasOne
    {
        return $this->hasOne(InpatientRmCoding::class);
    }

    /** @return HasMany<InpatientRmCompletenessReview, $this> */
    public function inpatientRmCompletenessReviews(): HasMany
    {
        return $this->hasMany(InpatientRmCompletenessReview::class);
    }

    /** @return HasOne<InpatientDischarge, $this> */
    public function inpatientDischarge(): HasOne
    {
        return $this->hasOne(InpatientDischarge::class);
    }

    /** @return HasMany<InpatientLocationEvent, $this> */
    public function inpatientLocationEvents(): HasMany
    {
        return $this->hasMany(InpatientLocationEvent::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<LabServiceRequest, $this>
     */
    public function labServiceRequests(): HasMany
    {
        return $this->hasMany(LabServiceRequest::class);
    }

    /** @return HasMany<RadiologyOrder, $this> */
    public function radiologyOrders(): HasMany
    {
        return $this->hasMany(RadiologyOrder::class);
    }

    /** @return HasMany<LaboratoryOrder, $this> */
    public function laboratoryOrders(): HasMany
    {
        return $this->hasMany(LaboratoryOrder::class);
    }

    /** @return HasMany<PharmacyPrescription, $this> */
    public function pharmacyPrescriptions(): HasMany
    {
        return $this->hasMany(PharmacyPrescription::class)->orderBy('created_at');
    }

    /** @return HasMany<OutpatientClinicalDocument, $this> */
    public function outpatientClinicalDocuments(): HasMany
    {
        return $this->hasMany(OutpatientClinicalDocument::class);
    }

    /** @return HasMany<OutpatientRmCompletenessReview, $this> */
    public function outpatientRmCompletenessReviews(): HasMany
    {
        return $this->hasMany(OutpatientRmCompletenessReview::class);
    }

    /** @return HasMany<OutpatientPostClosureAmendmentRequest, $this> */
    public function outpatientPostClosureAmendmentRequests(): HasMany
    {
        return $this->hasMany(OutpatientPostClosureAmendmentRequest::class);
    }

    /** @return HasOne<EncounterCancellation, $this> */
    public function cancellation(): HasOne
    {
        return $this->hasOne(EncounterCancellation::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function occupiesInpatientBed(): bool
    {
        return $this->care_setting === self::CARE_SETTING_INPATIENT
            && in_array($this->status, self::BED_OCCUPYING_STATUSES, true);
    }

    /**
     * @param  Builder<Encounter>  $query
     * @return Builder<Encounter>
     */
    public function scopeSyntheticOnly(Builder $query): Builder
    {
        return $query->whereHas('patient', fn (Builder $patientQuery): Builder => $patientQuery->where('is_synthetic', true));
    }

    /**
     * Route-bound encounters must never resolve outside the synthetic graph.
     *
     * @param  mixed  $query
     * @param  mixed  $value
     * @return mixed
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->whereHas('patient', fn (Builder $patientQuery): Builder => $patientQuery->where('is_synthetic', true));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'visit_date' => 'date',
            'queue_number' => 'integer',
            'active_inpatient_patient_id' => 'integer',
        ];
    }
}
