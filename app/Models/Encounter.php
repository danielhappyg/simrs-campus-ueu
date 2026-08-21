<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
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
 * @property string $payer_type
 * @property Carbon $registered_at
 * @property int $registered_by_user_id
 * @property string|null $chief_complaint
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Encounter extends Model
{
    /** @use HasFactory<EncounterFactory> */
    use HasFactory, HasPublicUlid;

    public const CARE_SETTING_OUTPATIENT = 'OUTPATIENT';

    public const STATUS_REGISTERED = 'REGISTERED';

    public const STATUS_IN_EXAMINATION = 'IN_EXAMINATION';

    public const STATUS_READY_FOR_RM = 'READY_FOR_RM';

    public const STATUS_CLOSED = 'CLOSED';

    public const PAYER_UMUM = 'UMUM';

    public const PAYER_BPJS = 'BPJS';

    public const PAYER_LAINNYA = 'LAINNYA';

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
    public const EXAMINATION_STATUSES = [
        self::STATUS_REGISTERED,
        self::STATUS_IN_EXAMINATION,
    ];

    protected $fillable = [
        'patient_id',
        'care_setting',
        'status',
        'clinic_name',
        'payer_type',
        'registered_at',
        'registered_by_user_id',
        'chief_complaint',
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
        ];
    }
}
