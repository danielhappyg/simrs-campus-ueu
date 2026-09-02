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
 * @property int $stock_lot_id
 * @property int $actor_user_id
 * @property int|null $handover_item_id
 * @property string $movement_type
 * @property int $available_delta
 * @property int $quarantined_delta
 * @property int $transit_delta
 * @property int $available_balance_after
 * @property int $quarantined_balance_after
 * @property int $transit_balance_after
 * @property string|null $custody_chain_public_id
 * @property string|null $pair_public_id
 * @property string|null $pair_leg
 * @property string $reason_code
 * @property string $source_type
 * @property string $source_public_id
 * @property string $content_digest
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
class PharmacyStockMovement extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    public const OPENING = 'OPENING';

    public const CORRECTION = 'CORRECTION';

    public const HANDOVER = 'HANDOVER';

    public const RETURN = 'RETURN';

    public const QUARANTINE = 'QUARANTINE';

    public const SUPPLIER_RECEIPT_AVAILABLE = 'SUPPLIER_RECEIPT_AVAILABLE';

    public const SUPPLIER_RECEIPT_QUARANTINED = 'SUPPLIER_RECEIPT_QUARANTINED';

    public const WAREHOUSE_DISPATCH_OUT = 'WAREHOUSE_DISPATCH_OUT';

    public const WAREHOUSE_TRANSIT_IN = 'WAREHOUSE_TRANSIT_IN';

    public const DEPOT_ACCEPT_TRANSIT_OUT = 'DEPOT_ACCEPT_TRANSIT_OUT';

    public const DEPOT_ACCEPT_AVAILABLE = 'DEPOT_ACCEPT_AVAILABLE';

    public const DEPOT_REJECT_TRANSIT_OUT = 'DEPOT_REJECT_TRANSIT_OUT';

    public const DEPOT_REJECT_SOURCE_AVAILABLE = 'DEPOT_REJECT_SOURCE_AVAILABLE';

    public const DEPOT_REJECT_SOURCE_QUARANTINED = 'DEPOT_REJECT_SOURCE_QUARANTINED';

    public const SUPPLIER_RETURN_OUT = 'SUPPLIER_RETURN_OUT';

    public const UNIT_RETURN_SOURCE_OUT = 'UNIT_RETURN_SOURCE_OUT';

    public const UNIT_RETURN_TRANSIT_IN = 'UNIT_RETURN_TRANSIT_IN';

    public const UNIT_RETURN_TRANSIT_OUT = 'UNIT_RETURN_TRANSIT_OUT';

    public const UNIT_RETURN_DEST_AVAILABLE = 'UNIT_RETURN_DEST_AVAILABLE';

    public const UNIT_RETURN_DEST_QUARANTINED = 'UNIT_RETURN_DEST_QUARANTINED';

    public const CORRECTION_COMPENSATION = 'CORRECTION_COMPENSATION';

    public const PAIR_LEG_SOURCE_OUT = 'SOURCE_OUT';

    public const PAIR_LEG_TRANSIT_IN = 'TRANSIT_IN';

    public const PAIR_LEG_TRANSIT_OUT = 'TRANSIT_OUT';

    public const PAIR_LEG_DESTINATION_IN = 'DESTINATION_IN';

    protected $fillable = ['stock_lot_id', 'actor_user_id', 'handover_item_id', 'movement_type', 'available_delta', 'quarantined_delta', 'transit_delta', 'available_balance_after', 'quarantined_balance_after', 'transit_balance_after', 'custody_chain_public_id', 'pair_public_id', 'pair_leg', 'reason_code', 'source_type', 'source_public_id', 'content_digest', 'occurred_at', 'created_at'];

    /** @return BelongsTo<PharmacyStockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(PharmacyStockLot::class, 'stock_lot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<PharmacyHandoverItem, $this> */
    public function handoverItem(): BelongsTo
    {
        return $this->belongsTo(PharmacyHandoverItem::class, 'handover_item_id');
    }

    protected function casts(): array
    {
        return ['available_delta' => 'integer', 'quarantined_delta' => 'integer', 'transit_delta' => 'integer', 'available_balance_after' => 'integer', 'quarantined_balance_after' => 'integer', 'transit_balance_after' => 'integer', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
