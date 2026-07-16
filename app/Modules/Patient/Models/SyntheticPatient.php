<?php

namespace App\Modules\Patient\Models;

use App\Modules\Clinical\Models\ClinicalEntry;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\AdministrativeSex;
use App\Modules\Patient\Enums\PatientRecordStatus;
use App\Modules\Teaching\Models\SimulationSession;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $session_id
 * @property string $public_id
 * @property bool $synthetic_flag
 * @property string $fixture_source
 * @property string $full_name
 * @property CarbonImmutable $birth_date
 * @property AdministrativeSex $administrative_sex
 * @property PatientRecordStatus $record_status
 */
class SyntheticPatient extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'session_id',
        'synthetic_flag',
        'fixture_source',
        'full_name',
        'birth_date',
        'administrative_sex',
        'address',
        'phone',
        'email',
        'religion',
        'occupation',
        'education',
        'marital_status',
        'deceased_flag',
        'record_status',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $patient): void {
            if ($patient->synthetic_flag !== true) {
                throw new DomainException('The reference MVP accepts synthetic patients only.');
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
     * @return HasMany<PatientIdentifier, $this>
     */
    public function identifiers(): HasMany
    {
        return $this->hasMany(PatientIdentifier::class, 'patient_id');
    }

    /** @return HasMany<ClinicalEntry, $this> */
    public function clinicalEntries(): HasMany
    {
        return $this->hasMany(ClinicalEntry::class, 'patient_id');
    }

    /**
     * @return HasMany<AppointmentRegistration, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(AppointmentRegistration::class, 'patient_id');
    }

    /**
     * @return HasMany<Encounter, $this>
     */
    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class, 'patient_id');
    }

    protected function casts(): array
    {
        return [
            'synthetic_flag' => 'boolean',
            'birth_date' => 'immutable_date',
            'administrative_sex' => AdministrativeSex::class,
            'address' => 'array',
            'deceased_flag' => 'boolean',
            'record_status' => PatientRecordStatus::class,
        ];
    }
}
