<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $medical_record_number
 * @property string|null $nik
 * @property string $full_name
 * @property string|null $place_of_birth
 * @property Carbon $date_of_birth
 * @property string $sex
 * @property string|null $religion
 * @property string|null $marital_status
 * @property string|null $education
 * @property string|null $occupation
 * @property string|null $province_code
 * @property string|null $province
 * @property string|null $city_code
 * @property string|null $city
 * @property string|null $district_code
 * @property string|null $district
 * @property string|null $village_code
 * @property string|null $village
 * @property string|null $address_line
 * @property string|null $domicile
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $ethnicity
 * @property string|null $language
 * @property string|null $notes
 * @property string|null $responsible_party_name
 * @property bool $is_synthetic
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory, HasPublicUlid, UsesSchemaQualifiedTable;

    /** SATUSEHAT / HL7 AdministrativeGender: male. */
    public const SEX_LAKI_LAKI = 'male';

    /** SATUSEHAT / HL7 AdministrativeGender: female. */
    public const SEX_PEREMPUAN = 'female';

    /** SATUSEHAT / HL7 AdministrativeGender: other. */
    public const SEX_LAINNYA = 'other';

    /** SATUSEHAT / HL7 AdministrativeGender: unknown. */
    public const SEX_TIDAK_DIKETAHUI = 'unknown';

    /**
     * @var list<string>
     */
    public const SEX_VALUES = [
        self::SEX_LAKI_LAKI,
        self::SEX_PEREMPUAN,
        self::SEX_LAINNYA,
        self::SEX_TIDAK_DIKETAHUI,
    ];

    public const MARITAL_BELUM_KAWIN = 'BELUM_KAWIN';

    public const MARITAL_KAWIN = 'KAWIN';

    public const MARITAL_CERAI_HIDUP = 'CERAI_HIDUP';

    public const MARITAL_CERAI_MATI = 'CERAI_MATI';

    /**
     * @var list<string>
     */
    public const MARITAL_VALUES = [
        self::MARITAL_BELUM_KAWIN,
        self::MARITAL_KAWIN,
        self::MARITAL_CERAI_HIDUP,
        self::MARITAL_CERAI_MATI,
    ];

    /**
     * @var list<string>
     */
    public const RELIGION_VALUES = [
        'ISLAM',
        'KRISTEN',
        'KATOLIK',
        'HINDU',
        'BUDDHA',
        'KONGHUCU',
        'LAINNYA',
    ];

    /**
     * @var list<string>
     */
    public const EDUCATION_VALUES = [
        'TIDAK_SEKOLAH',
        'SD',
        'SMP',
        'SMA',
        'D3',
        'S1',
        'S2',
        'S3',
        'LAINNYA',
    ];

    /**
     * @var list<string>
     */
    public const OCCUPATION_VALUES = [
        'PELAJAR',
        'MAHASISWA',
        'PNS',
        'SWASTA',
        'WIRASWASTA',
        'IRT',
        'PENSIUNAN',
        'LAINNYA',
    ];

    /**
     * @var list<string>
     */
    public const ETHNICITY_VALUES = [
        'JAWA',
        'SUNDA',
        'BETAWI',
        'BATAK',
        'MINANG',
        'BUGIS',
        'LAINNYA',
    ];

    /**
     * @var list<string>
     */
    public const LANGUAGE_VALUES = [
        'INDONESIA',
        'JAWA',
        'SUNDA',
        'INGGRIS',
        'LAINNYA',
    ];

    protected $fillable = [
        'medical_record_number',
        'nik',
        'full_name',
        'place_of_birth',
        'date_of_birth',
        'sex',
        'religion',
        'marital_status',
        'education',
        'occupation',
        'province_code',
        'province',
        'city_code',
        'city',
        'district_code',
        'district',
        'village_code',
        'village',
        'address_line',
        'domicile',
        'phone',
        'email',
        'ethnicity',
        'language',
        'notes',
        'responsible_party_name',
        'is_synthetic',
        'created_by_user_id',
    ];

    protected $attributes = [
        'is_synthetic' => true,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<Encounter, $this>
     */
    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    /**
     * Keep teaching workflows inside the synthetic patient boundary.
     *
     * @param  Builder<Patient>  $query
     * @return Builder<Patient>
     */
    public function scopeSyntheticOnly(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_synthetic'), true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_synthetic' => 'boolean',
        ];
    }
}
