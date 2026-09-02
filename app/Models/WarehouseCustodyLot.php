<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehouseCustodyLot extends WarehouseImmutableModel
{
    protected $fillable = [
        'supplier_id', 'medicine_id', 'medicine_code_snapshot', 'base_unit_snapshot',
        'lot_code', 'expiry_date', 'first_received_at', 'content_digest', 'created_at',
    ];

    /** @return BelongsTo<WarehouseSupplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(WarehouseSupplier::class, 'supplier_id');
    }

    /** @return BelongsTo<PharmacyMedicine, $this> */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(PharmacyMedicine::class, 'medicine_id');
    }

    /** @return HasMany<WarehouseCustodyReceiptAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(WarehouseCustodyReceiptAllocation::class, 'custody_lot_id');
    }

    /** @return HasMany<WarehouseCustodyMovementSet, $this> */
    public function movementSets(): HasMany
    {
        return $this->hasMany(WarehouseCustodyMovementSet::class, 'custody_lot_id');
    }

    /** @return HasMany<PharmacyStockLot, $this> */
    public function stockLots(): HasMany
    {
        return $this->hasMany(PharmacyStockLot::class, 'warehouse_custody_lot_id');
    }

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date', 'first_received_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
