<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehouseReceipt extends WarehouseImmutableModel
{
    protected $fillable = [
        'receipt_number', 'purchase_order_id', 'purchase_order_version_id',
        'purchase_order_decision_id', 'supplier_id', 'supplier_version_id', 'receiver_user_id',
        'approval_decision_snapshot', 'supplier_reference', 'presented_quantity', 'accepted_quantity', 'rejected_quantity',
        'quarantined_quantity', 'variance_reason_code', 'variance_note',
        'purchase_order_fingerprint', 'content_digest', 'received_at', 'created_at',
    ];

    /** @return BelongsTo<WarehousePurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(WarehousePurchaseOrder::class, 'purchase_order_id');
    }

    /** @return BelongsTo<WarehouseSupplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(WarehouseSupplier::class, 'supplier_id');
    }

    /** @return HasMany<WarehouseReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(WarehouseReceiptLine::class, 'receipt_id')->orderBy('line_number');
    }

    protected function casts(): array
    {
        return [
            'presented_quantity' => 'integer', 'accepted_quantity' => 'integer',
            'rejected_quantity' => 'integer', 'quarantined_quantity' => 'integer',
            'received_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
