<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $radiology_order_id
 * @property int $performed_by_user_id
 * @property Carbon $performed_at
 * @property Carbon $created_at
 */
class RadiologyPerformance extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['radiology_order_id', 'performed_by_user_id', 'performed_at', 'created_at'];

    /** @return BelongsTo<User, $this> */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    protected function casts(): array
    {
        return ['performed_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
