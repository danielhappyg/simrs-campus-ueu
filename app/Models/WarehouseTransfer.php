<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class WarehouseTransfer extends WarehouseMutableModel
{
    public const DISPATCHED = 'DISPATCHED';

    public const ACCEPTED = 'ACCEPTED';

    public const REJECTED = 'REJECTED';

    protected $fillable = [
        'transfer_number', 'source_depot_id', 'source_depot_version_id', 'destination_depot_id',
        'destination_depot_version_id', 'dispatcher_user_id', 'state', 'version',
        'current_content_digest', 'dispatched_at', 'resolved_at',
    ];

    /** @return BelongsTo<PharmacyDepot, $this> */
    public function sourceDepot(): BelongsTo
    {
        return $this->belongsTo(PharmacyDepot::class, 'source_depot_id');
    }

    /** @return BelongsTo<PharmacyDepot, $this> */
    public function destinationDepot(): BelongsTo
    {
        return $this->belongsTo(PharmacyDepot::class, 'destination_depot_id');
    }

    /** @return HasMany<WarehouseTransferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WarehouseTransferItem::class, 'transfer_id')->orderBy('line_number');
    }

    /** @return HasOne<WarehouseTransferDecision, $this> */
    public function decision(): HasOne
    {
        return $this->hasOne(WarehouseTransferDecision::class, 'transfer_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'dispatched_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
