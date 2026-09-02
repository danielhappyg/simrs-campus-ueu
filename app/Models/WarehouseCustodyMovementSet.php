<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehouseCustodyMovementSet extends WarehouseImmutableModel
{
    protected $fillable = [
        'custody_lot_id', 'actor_user_id', 'movement_set_type', 'source_type',
        'source_public_id', 'content_digest', 'occurred_at', 'created_at',
    ];

    /** @return BelongsTo<WarehouseCustodyLot, $this> */
    public function custodyLot(): BelongsTo
    {
        return $this->belongsTo(WarehouseCustodyLot::class, 'custody_lot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return HasMany<PharmacyStockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(PharmacyStockMovement::class, 'warehouse_movement_set_id');
    }

    /** @return HasMany<WarehouseCustodyMovementPair, $this> */
    public function pairs(): HasMany
    {
        return $this->hasMany(WarehouseCustodyMovementPair::class, 'movement_set_id');
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
