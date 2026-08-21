<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $clinic_id
 * @property string $name
 * @property string|null $specialty
 * @property bool $is_active
 */
class Doctor extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'clinic_id',
        'name',
        'specialty',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * @return HasMany<ClinicSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(ClinicSchedule::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
