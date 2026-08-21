<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
class Clinic extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return HasMany<Doctor, $this>
     */
    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class);
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
