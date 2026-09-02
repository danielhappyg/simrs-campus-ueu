<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property int $laboratory_order_id
 * @property int $actor_user_id
 * @property string $reason_code
 * @property string|null $note
 * @property Carbon $cancelled_at
 * @property Carbon $created_at
 */
class LaboratoryOrderCancellation extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['laboratory_order_id', 'actor_user_id', 'reason_code', 'note', 'cancelled_at', 'created_at'];

    protected function casts(): array
    {
        return ['cancelled_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
