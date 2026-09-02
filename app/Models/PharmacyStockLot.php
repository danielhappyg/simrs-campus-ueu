<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $medicine_id
 * @property int $depot_id
 * @property int $opened_by_user_id
 * @property int $medicine_version
 * @property int $depot_version
 * @property string $medicine_code_snapshot
 * @property string $depot_code_snapshot
 * @property string $lot_code
 * @property Carbon $received_at
 * @property Carbon|null $expiry_date
 * @property string|null $no_expiry_reason
 * @property int $available_quantity
 * @property int $quarantined_quantity
 * @property int|null $warehouse_custody_lot_id
 * @property string|null $warehouse_source_type
 * @property string|null $warehouse_source_public_id
 * @property int $transit_quantity
 * @property int $acquisition_value
 * @property string $source_reference
 * @property string $state
 * @property int $version
 * @property string $content_digest
 */
class PharmacyStockLot extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ACTIVE = 'ACTIVE';

    public const QUARANTINED = 'QUARANTINED';

    public const RETIRED = 'RETIRED';

    public const WAREHOUSE_SOURCE_RECEIPT_LINE = 'RECEIPT_LINE';

    public const WAREHOUSE_SOURCE_TRANSFER_ITEM = 'TRANSFER_ITEM';

    public const WAREHOUSE_SOURCE_UNIT_RETURN_ITEM = 'UNIT_RETURN_ITEM';

    public const WAREHOUSE_SOURCE_CORRECTION = 'CORRECTION';

    protected $fillable = ['medicine_id', 'depot_id', 'opened_by_user_id', 'medicine_version', 'depot_version', 'medicine_code_snapshot', 'depot_code_snapshot', 'lot_code', 'received_at', 'expiry_date', 'no_expiry_reason', 'available_quantity', 'quarantined_quantity', 'warehouse_custody_lot_id', 'warehouse_source_type', 'warehouse_source_public_id', 'transit_quantity', 'acquisition_value', 'source_reference', 'state', 'version', 'content_digest'];

    /** @return BelongsTo<PharmacyMedicine, $this> */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(PharmacyMedicine::class, 'medicine_id');
    }

    /** @return BelongsTo<PharmacyDepot, $this> */
    public function depot(): BelongsTo
    {
        return $this->belongsTo(PharmacyDepot::class, 'depot_id');
    }

    /** @return BelongsTo<WarehouseCustodyLot, $this> */
    public function warehouseCustodyLot(): BelongsTo
    {
        return $this->belongsTo(WarehouseCustodyLot::class, 'warehouse_custody_lot_id');
    }

    /** @return HasMany<PharmacyStockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(PharmacyStockMovement::class, 'stock_lot_id');
    }

    protected function casts(): array
    {
        return ['medicine_version' => 'integer', 'depot_version' => 'integer', 'received_at' => 'datetime', 'expiry_date' => 'date', 'available_quantity' => 'integer', 'quarantined_quantity' => 'integer', 'warehouse_custody_lot_id' => 'integer', 'transit_quantity' => 'integer', 'acquisition_value' => 'integer', 'version' => 'integer'];
    }
}
