<?php

namespace App\Models;

use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $scope
 * @property int $last_number
 */
class MedicalRecordNumberCounter extends Model
{
    use UsesSchemaQualifiedTable;

    public const GLOBAL_SCOPE = 'global';

    protected $primaryKey = 'scope';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'scope',
        'last_number',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_number' => 'integer',
        ];
    }
}
