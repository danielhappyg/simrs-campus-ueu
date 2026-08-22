<?php

namespace App\Models;

use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $code
 * @property string $district_code
 * @property string $name
 */
class WilayahVillage extends Model
{
    use UsesSchemaQualifiedTable;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'district_code',
        'name',
    ];

    /**
     * @return BelongsTo<WilayahDistrict, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(WilayahDistrict::class, 'district_code', 'code');
    }
}
