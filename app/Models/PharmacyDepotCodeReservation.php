<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $actor_user_id
 * @property string $normalized_code
 * @property Carbon $created_at
 */
class PharmacyDepotCodeReservation extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['actor_user_id', 'normalized_code', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
