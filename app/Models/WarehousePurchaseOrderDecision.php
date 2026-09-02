<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehousePurchaseOrderDecision extends WarehouseImmutableModel
{
    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    protected $fillable = [
        'purchase_order_id', 'purchase_order_version_id', 'supplier_id', 'supplier_version_id',
        'creator_user_id_snapshot', 'reviewer_user_id', 'decision',
        'purchase_order_fingerprint', 'reason_code', 'note', 'content_digest', 'decided_at', 'created_at',
    ];

    /** @return BelongsTo<WarehousePurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(WarehousePurchaseOrder::class, 'purchase_order_id');
    }

    /** @return BelongsTo<WarehousePurchaseOrderVersion, $this> */
    public function purchaseOrderVersion(): BelongsTo
    {
        return $this->belongsTo(WarehousePurchaseOrderVersion::class, 'purchase_order_version_id');
    }

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
