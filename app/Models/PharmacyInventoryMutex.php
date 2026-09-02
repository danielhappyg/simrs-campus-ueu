<?php

namespace App\Models;

use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $depot_id
 * @property int $medicine_id
 * @property int $lock_version
 */
class PharmacyInventoryMutex extends Model
{
    use UsesSchemaQualifiedTable;

    protected $fillable = ['depot_id', 'medicine_id', 'lock_version'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer'];
    }
}
