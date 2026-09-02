<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseUnitReturnItem extends WarehouseImmutableModel
{
    protected $fillable = [
        'unit_return_id', 'original_transfer_item_id', 'original_transfer_id',
        'custody_lot_id', 'source_stock_lot_id',
        'line_number', 'quantity', 'intact_custody', 'reason_code', 'pair_public_id',
        'source_fingerprint', 'content_digest', 'created_at',
    ];

    /** @return BelongsTo<WarehouseUnitReturn, $this> */
    public function unitReturn(): BelongsTo
    {
        return $this->belongsTo(WarehouseUnitReturn::class, 'unit_return_id');
    }

    /** @return BelongsTo<WarehouseTransferItem, $this> */
    public function originalTransferItem(): BelongsTo
    {
        return $this->belongsTo(WarehouseTransferItem::class, 'original_transfer_item_id');
    }

    protected function casts(): array
    {
        return [
            'line_number' => 'integer', 'quantity' => 'integer',
            'intact_custody' => 'boolean', 'created_at' => 'datetime',
        ];
    }
}
