<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehousePurchaseOrder extends WarehouseMutableModel
{
    public const DRAFT = 'DRAFT';

    public const SUBMITTED = 'SUBMITTED';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const PARTIALLY_RECEIVED = 'PARTIALLY_RECEIVED';

    public const FULLY_RECEIVED = 'FULLY_RECEIVED';

    public const CLOSED = 'CLOSED';

    public const CANCELLED = 'CANCELLED';

    protected $fillable = [
        'purchase_order_number', 'supplier_id', 'supplier_version_id', 'creator_user_id',
        'state', 'version', 'current_content_digest', 'submitted_at',
    ];

    /** @return BelongsTo<WarehouseSupplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(WarehouseSupplier::class, 'supplier_id');
    }

    /** @return BelongsTo<WarehouseSupplierVersion, $this> */
    public function supplierVersion(): BelongsTo
    {
        return $this->belongsTo(WarehouseSupplierVersion::class, 'supplier_version_id');
    }

    /** @return HasMany<WarehousePurchaseOrderVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(WarehousePurchaseOrderVersion::class, 'purchase_order_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'submitted_at' => 'datetime'];
    }
}
