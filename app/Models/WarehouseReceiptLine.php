<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class WarehouseReceiptLine extends WarehouseImmutableModel
{
    protected $fillable = [
        'receipt_id', 'purchase_order_line_id', 'purchase_order_version_id', 'supplier_id',
        'medicine_id', 'medicine_version_id',
        'line_number', 'medicine_code_snapshot', 'medicine_name_snapshot', 'base_unit_snapshot',
        'lot_code', 'expiry_date', 'ordered_quantity_snapshot', 'presented_quantity',
        'accepted_quantity', 'rejected_quantity', 'quarantined_quantity',
        'unit_acquisition_value', 'variance_reason_code', 'variance_note', 'content_digest', 'created_at',
    ];

    /** @return BelongsTo<WarehouseReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(WarehouseReceipt::class, 'receipt_id');
    }

    /** @return HasOne<WarehouseCustodyReceiptAllocation, $this> */
    public function custodyAllocation(): HasOne
    {
        return $this->hasOne(WarehouseCustodyReceiptAllocation::class, 'receipt_line_id');
    }

    protected function casts(): array
    {
        return [
            'line_number' => 'integer', 'expiry_date' => 'date', 'ordered_quantity_snapshot' => 'integer',
            'presented_quantity' => 'integer', 'accepted_quantity' => 'integer',
            'rejected_quantity' => 'integer', 'quarantined_quantity' => 'integer',
            'unit_acquisition_value' => 'integer', 'created_at' => 'datetime',
        ];
    }
}
