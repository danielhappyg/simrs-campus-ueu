<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehouseCustodyMovementPair extends WarehouseImmutableModel
{
    protected $fillable = [
        'movement_set_id', 'custody_lot_id', 'movement_set_type_snapshot',
        'pair_type', 'quantity', 'content_digest', 'created_at',
    ];

    /** @return BelongsTo<WarehouseCustodyMovementSet, $this> */
    public function movementSet(): BelongsTo
    {
        return $this->belongsTo(WarehouseCustodyMovementSet::class, 'movement_set_id');
    }

    /** @return BelongsTo<WarehouseCustodyLot, $this> */
    public function custodyLot(): BelongsTo
    {
        return $this->belongsTo(WarehouseCustodyLot::class, 'custody_lot_id');
    }

    /** @return HasMany<PharmacyStockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(PharmacyStockMovement::class, 'warehouse_movement_pair_id');
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'created_at' => 'datetime'];
    }
}
