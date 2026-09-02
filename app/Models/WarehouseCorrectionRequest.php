<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class WarehouseCorrectionRequest extends WarehouseImmutableModel
{
    protected $fillable = [
        'correction_number', 'requester_user_id', 'custody_lot_id', 'stock_lot_id',
        'source_type', 'source_public_id', 'available_delta', 'quarantined_delta',
        'transit_delta', 'reason_code', 'explanation', 'source_fingerprint',
        'content_digest', 'requested_at', 'created_at',
    ];

    /** @return BelongsTo<WarehouseCustodyLot, $this> */
    public function custodyLot(): BelongsTo
    {
        return $this->belongsTo(WarehouseCustodyLot::class, 'custody_lot_id');
    }

    /** @return BelongsTo<PharmacyStockLot, $this> */
    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(PharmacyStockLot::class, 'stock_lot_id');
    }

    /** @return HasOne<WarehouseCorrectionDecision, $this> */
    public function decision(): HasOne
    {
        return $this->hasOne(WarehouseCorrectionDecision::class, 'correction_request_id');
    }

    /** @return HasOne<WarehouseCorrectionCompensation, $this> */
    public function compensation(): HasOne
    {
        return $this->hasOne(WarehouseCorrectionCompensation::class, 'correction_request_id');
    }

    protected function casts(): array
    {
        return [
            'available_delta' => 'integer', 'quarantined_delta' => 'integer',
            'transit_delta' => 'integer', 'requested_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
