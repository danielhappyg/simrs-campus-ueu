<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehousePurchaseOrderLine extends WarehouseImmutableModel
{
    protected $fillable = [
        'purchase_order_version_id', 'medicine_id', 'medicine_version_id', 'line_number',
        'medicine_code_snapshot', 'medicine_name_snapshot', 'base_unit_snapshot',
        'ordered_quantity', 'unit_acquisition_value', 'content_digest', 'created_at',
    ];

    /** @return BelongsTo<WarehousePurchaseOrderVersion, $this> */
    public function purchaseOrderVersion(): BelongsTo
    {
        return $this->belongsTo(WarehousePurchaseOrderVersion::class, 'purchase_order_version_id');
    }

    /** @return BelongsTo<PharmacyMedicine, $this> */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(PharmacyMedicine::class, 'medicine_id');
    }

    protected function casts(): array
    {
        return [
            'line_number' => 'integer', 'ordered_quantity' => 'integer',
            'unit_acquisition_value' => 'integer', 'created_at' => 'datetime',
        ];
    }
}
