<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Database\Factories\EncounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $patient_id
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
 * @property Carbon $registered_at
 * @property int $registered_by_user_id
 * @property string|null $chief_complaint
 * @property string|null $case_type
 * @property string|null $accident_type
 * @property string|null $ward_name
 * @property string|null $ward_class
 * @property string|null $bed_code
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

    public const STATUS_REGISTERED = 'REGISTERED';

    public const STATUS_IN_EXAMINATION = 'IN_EXAMINATION';

    public const STATUS_READY_FOR_RM = 'READY_FOR_RM';

    public const STATUS_CLOSED = 'CLOSED';

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
    ];

    protected $fillable = [
        'patient_id',
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
        'registered_at',
        'registered_by_user_id',
        'chief_complaint',
        'case_type',
        'accident_type',
        'ward_name',
        'ward_class',
        'bed_code',
        'continue_from',
    ];

    protected $attributes = [
        'care_setting' => self::CARE_SETTING_OUTPATIENT,
        'status' => self::STATUS_REGISTERED,
    ];

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
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

    /**
     * @return HasMany<LabServiceRequest, $this>
     */
    public function labServiceRequests(): HasMany
    {
        return $this->hasMany(LabServiceRequest::class);
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
        ];
    }
}
