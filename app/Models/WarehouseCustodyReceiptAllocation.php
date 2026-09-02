<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseCustodyReceiptAllocation extends WarehouseImmutableModel
{
    protected $fillable = [
        'receipt_id', 'receipt_line_id', 'custody_lot_id', 'supplier_id', 'medicine_id',
        'medicine_version_id', 'lot_code_snapshot', 'expiry_date_snapshot',
        'available_quantity', 'quarantined_quantity', 'unit_acquisition_value_snapshot',
        'content_digest', 'created_at',
    ];

    /** @return BelongsTo<WarehouseReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(WarehouseReceipt::class, 'receipt_id');
    }

    /** @return BelongsTo<WarehouseReceiptLine, $this> */
    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(WarehouseReceiptLine::class, 'receipt_line_id');
    }

    /** @return BelongsTo<WarehouseCustodyLot, $this> */
    public function custodyLot(): BelongsTo
    {
        return $this->belongsTo(WarehouseCustodyLot::class, 'custody_lot_id');
    }

    /** @return BelongsTo<WarehouseSupplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(WarehouseSupplier::class, 'supplier_id');
    }

    /** @return BelongsTo<PharmacyMedicine, $this> */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(PharmacyMedicine::class, 'medicine_id');
    }

    /** @return BelongsTo<PharmacyMedicineVersion, $this> */
    public function medicineVersion(): BelongsTo
    {
        return $this->belongsTo(PharmacyMedicineVersion::class, 'medicine_version_id');
    }

    protected function casts(): array
    {
        return [
            'expiry_date_snapshot' => 'date', 'available_quantity' => 'integer',
            'quarantined_quantity' => 'integer', 'unit_acquisition_value_snapshot' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
