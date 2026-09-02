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
 * @property int $handover_id
 * @property int $prescription_item_id
 * @property int $stock_lot_id
 * @property int $quantity
 * @property int $sale_value_snapshot
 * @property string $content_digest
 * @property Carbon $created_at
 */
class PharmacyHandoverItem extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['handover_id', 'prescription_item_id', 'stock_lot_id', 'quantity', 'sale_value_snapshot', 'content_digest', 'created_at'];

    /** @return BelongsTo<PharmacyStockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(PharmacyStockLot::class, 'stock_lot_id');
    }

    /** @return BelongsTo<PharmacyPrescriptionItem, $this> */
    public function prescriptionItem(): BelongsTo
    {
        return $this->belongsTo(PharmacyPrescriptionItem::class, 'prescription_item_id');
    }

    /** @return HasMany<PharmacyReturnItem, $this> */
    public function returnItems(): HasMany
    {
        return $this->hasMany(PharmacyReturnItem::class, 'handover_item_id');
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'sale_value_snapshot' => 'integer', 'created_at' => 'datetime'];
    }
}
