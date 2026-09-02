<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseTransferItem extends WarehouseImmutableModel
{
    protected $fillable = [
        'transfer_id', 'custody_lot_id', 'source_stock_lot_id', 'source_depot_id', 'line_number',
        'medicine_code_snapshot', 'base_unit_snapshot', 'lot_code_snapshot',
        'expiry_date_snapshot', 'quantity', 'pair_public_id', 'source_lot_fingerprint',
        'content_digest', 'created_at',
    ];

    /** @return BelongsTo<WarehouseTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(WarehouseTransfer::class, 'transfer_id');
    }

    /** @return BelongsTo<WarehouseCustodyLot, $this> */
    public function custodyLot(): BelongsTo
    {
        return $this->belongsTo(WarehouseCustodyLot::class, 'custody_lot_id');
    }

    protected function casts(): array
    {
        return [
            'line_number' => 'integer', 'expiry_date_snapshot' => 'date',
            'quantity' => 'integer', 'created_at' => 'datetime',
        ];
    }
}
