<?php

namespace App\Models;

use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $code
 * @property string $province_code
 * @property string $name
 */
class WilayahRegency extends Model
{
    use UsesSchemaQualifiedTable;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'province_code',
        'name',
    ];

    /**
     * @return BelongsTo<WilayahProvince, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(WilayahProvince::class, 'province_code', 'code');
    }

    /**
     * @return HasMany<WilayahDistrict, $this>
     */
    public function districts(): HasMany
    {
        return $this->hasMany(WilayahDistrict::class, 'regency_code', 'code');
    }
}
