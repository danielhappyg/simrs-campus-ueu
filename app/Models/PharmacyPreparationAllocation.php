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
 * @property int $preparation_id
 * @property int $prescription_item_id
 * @property int $stock_lot_id
 * @property int $quantity
 * @property int $fefo_sequence
 * @property string $lot_fingerprint
 * @property string $content_digest
 * @property Carbon $created_at
 */
class PharmacyPreparationAllocation extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['preparation_id', 'prescription_item_id', 'stock_lot_id', 'quantity', 'fefo_sequence', 'lot_fingerprint', 'content_digest', 'created_at'];

    /** @return BelongsTo<PharmacyStockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(PharmacyStockLot::class, 'stock_lot_id');
    }

    /** @return BelongsTo<PharmacyPrescriptionItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PharmacyPrescriptionItem::class, 'prescription_item_id');
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'fefo_sequence' => 'integer', 'created_at' => 'datetime'];
    }
}
