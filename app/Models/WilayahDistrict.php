<?php

namespace App\Models;

use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $code
 * @property string $regency_code
 * @property string $name
 */
class WilayahDistrict extends Model
{
    use UsesSchemaQualifiedTable;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'regency_code',
        'name',
    ];

    /**
     * @return BelongsTo<WilayahRegency, $this>
     */
    public function regency(): BelongsTo
    {
        return $this->belongsTo(WilayahRegency::class, 'regency_code', 'code');
    }

    /**
     * @return HasMany<WilayahVillage, $this>
     */
    public function villages(): HasMany
    {
        return $this->hasMany(WilayahVillage::class, 'district_code', 'code');
    }
}
