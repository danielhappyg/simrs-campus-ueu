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
 * @property int $return_id
 * @property int $handover_item_id
 * @property string $condition
 * @property int $quantity
 * @property string $content_digest
 * @property Carbon $created_at
 */
class PharmacyReturnItem extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    public const RETURN_TO_STOCK = 'RETURN_TO_STOCK';

    public const QUARANTINE = 'QUARANTINE';

    public const DESTROYED_OR_NOT_RETURNABLE = 'DESTROYED_OR_NOT_RETURNABLE';

    protected $fillable = ['return_id', 'handover_item_id', 'condition', 'quantity', 'content_digest', 'created_at'];

    /** @return BelongsTo<PharmacyHandoverItem, $this> */
    public function handoverItem(): BelongsTo
    {
        return $this->belongsTo(PharmacyHandoverItem::class, 'handover_item_id');
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'created_at' => 'datetime'];
    }
}
