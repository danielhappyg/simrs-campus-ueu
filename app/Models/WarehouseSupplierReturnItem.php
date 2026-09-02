<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseSupplierReturnItem extends WarehouseImmutableModel
{
    protected $fillable = [
        'supplier_return_id', 'receipt_id', 'receipt_line_id', 'custody_lot_id', 'supplier_id',
        'source_stock_lot_id', 'line_number', 'quantity', 'reason_code',
        'source_fingerprint', 'content_digest', 'created_at',
    ];

    /** @return BelongsTo<WarehouseSupplierReturn, $this> */
    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(WarehouseSupplierReturn::class, 'supplier_return_id');
    }

    /** @return BelongsTo<WarehouseReceiptLine, $this> */
    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(WarehouseReceiptLine::class, 'receipt_line_id');
    }

    protected function casts(): array
    {
        return ['line_number' => 'integer', 'quantity' => 'integer', 'created_at' => 'datetime'];
    }
}
