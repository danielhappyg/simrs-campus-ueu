<?php

namespace App\Models;

use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $queue_date
 * @property int $last_number
 */
class DailyQueueCounter extends Model
{
    use UsesSchemaQualifiedTable;

    protected $primaryKey = 'queue_date';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'queue_date',
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
