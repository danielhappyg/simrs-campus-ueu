<?php

namespace App\Modules\Encounter\Models;

use App\Support\Models\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property bool $is_active
 */
class ServiceLocation extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'code',
        'name',
        'type',
        'is_active',
    ];

    /**
     * @return HasMany<Encounter, $this>
     */
    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class, 'location_id');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
