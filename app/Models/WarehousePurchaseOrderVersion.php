<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehousePurchaseOrderVersion extends WarehouseImmutableModel
{
    protected $fillable = [
        'purchase_order_id', 'supplier_id', 'supplier_version_id', 'actor_user_id', 'version',
        'state', 'supplier_code_snapshot', 'supplier_name_snapshot', 'previous_version_id',
        'previous_version_number', 'previous_content_digest',
        'content_digest', 'request_correlation_id', 'submitted_at', 'created_at',
    ];

    /** @return BelongsTo<WarehousePurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(WarehousePurchaseOrder::class, 'purchase_order_id');
    }

    /** @return HasMany<WarehousePurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(WarehousePurchaseOrderLine::class, 'purchase_order_version_id')->orderBy('line_number');
    }

    /** @return BelongsTo<WarehousePurchaseOrderVersion, $this> */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'previous_version_number' => 'integer',
            'submitted_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
