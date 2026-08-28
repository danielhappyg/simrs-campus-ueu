<?php

namespace App\Models;

use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $bed_code
 */
class InpatientBedClaimMutex extends Model
{
    use UsesSchemaQualifiedTable;

    protected $primaryKey = 'bed_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'bed_code',
    ];
}
