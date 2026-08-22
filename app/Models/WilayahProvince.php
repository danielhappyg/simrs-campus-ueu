<?php

namespace App\Models;

use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $code
 * @property string $name
 */
class WilayahProvince extends Model
{
    use UsesSchemaQualifiedTable;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
    ];

    /**
     * @return HasMany<WilayahRegency, $this>
     */
    public function regencies(): HasMany
    {
        return $this->hasMany(WilayahRegency::class, 'province_code', 'code');
    }
}
