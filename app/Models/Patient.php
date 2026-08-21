<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use Database\Factories\PatientFactory;
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
 * @property string|null $education
 * @property string|null $occupation
 * @property string|null $province
 * @property string|null $city
 * @property string|null $district
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
    use HasFactory, HasPublicUlid;

    public const SEX_LAKI_LAKI = 'LAKI_LAKI';

    public const SEX_PEREMPUAN = 'PEREMPUAN';

    public const SEX_TIDAK_DIKETAHUI = 'TIDAK_DIKETAHUI';

    /**
     * @var list<string>
     */
    public const SEX_VALUES = [
        self::SEX_LAKI_LAKI,
        self::SEX_PEREMPUAN,
        self::SEX_TIDAK_DIKETAHUI,
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

    protected $fillable = [
        'medical_record_number',
        'nik',
        'full_name',
        'place_of_birth',
        'date_of_birth',
        'sex',
        'religion',
        'education',
        'occupation',
        'province',
        'city',
        'district',
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
