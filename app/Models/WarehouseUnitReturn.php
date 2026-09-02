<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class WarehouseUnitReturn extends WarehouseMutableModel
{
    public const DISPATCHED = 'DISPATCHED';

    public const ACCEPTED = 'ACCEPTED';

    public const REJECTED = 'REJECTED';

    protected $fillable = [
        'return_number', 'original_transfer_id', 'source_depot_id', 'source_depot_version_id',
        'destination_depot_id', 'destination_depot_version_id', 'requester_user_id',
        'state', 'version', 'current_content_digest', 'dispatched_at', 'resolved_at',
    ];

    /** @return BelongsTo<WarehouseTransfer, $this> */
    public function originalTransfer(): BelongsTo
    {
        return $this->belongsTo(WarehouseTransfer::class, 'original_transfer_id');
    }

    /** @return HasMany<WarehouseUnitReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WarehouseUnitReturnItem::class, 'unit_return_id')->orderBy('line_number');
    }

    /** @return HasOne<WarehouseUnitReturnDecision, $this> */
    public function decision(): HasOne
    {
        return $this->hasOne(WarehouseUnitReturnDecision::class, 'unit_return_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'dispatched_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
