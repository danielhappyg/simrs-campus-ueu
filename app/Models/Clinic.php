<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use App\Support\Registration\ClinicBookingSurface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string $booking_surface
 * @property bool $is_active
 */
class Clinic extends Model
{
    use HasPublicUlid;
    use UsesSchemaQualifiedTable;

    protected $fillable = [
        'code',
        'name',
        'booking_surface',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
        'booking_surface' => ClinicBookingSurface::OUTPATIENT,
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
