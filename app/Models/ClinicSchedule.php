<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $clinic_id
 * @property int $doctor_id
 * @property string $label
 * @property string|null $day_label
 * @property string|null $starts_at
 * @property string|null $ends_at
 * @property bool $is_active
 */
class ClinicSchedule extends Model
{
    use HasPublicUlid;
    use UsesSchemaQualifiedTable;

    protected $fillable = [
        'clinic_id',
        'doctor_id',
        'label',
        'day_label',
        'starts_at',
        'ends_at',
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
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
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
