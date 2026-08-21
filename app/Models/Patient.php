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
 * @property string $full_name
 * @property Carbon $date_of_birth
 * @property string $sex
 * @property string|null $phone
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

    protected $fillable = [
        'medical_record_number',
        'full_name',
        'date_of_birth',
        'sex',
        'phone',
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
