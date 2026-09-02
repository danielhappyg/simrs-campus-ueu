<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseCorrectionCompensation extends WarehouseImmutableModel
{
    protected $fillable = [
        'correction_request_id', 'correction_decision_id', 'custody_lot_id', 'actor_user_id',
        'stock_lot_id', 'supervisor_user_id_snapshot', 'movement_set_id', 'approval_decision_snapshot',
        'available_delta', 'quarantined_delta', 'transit_delta', 'available_balance_after',
        'quarantined_balance_after', 'transit_balance_after', 'movement_set_digest',
        'content_digest', 'occurred_at', 'created_at',
    ];

    /** @return BelongsTo<WarehouseCorrectionRequest, $this> */
    public function correctionRequest(): BelongsTo
    {
        return $this->belongsTo(WarehouseCorrectionRequest::class, 'correction_request_id');
    }

    /** @return BelongsTo<WarehouseCorrectionDecision, $this> */
    public function correctionDecision(): BelongsTo
    {
        return $this->belongsTo(WarehouseCorrectionDecision::class, 'correction_decision_id');
    }

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

    /** @return BelongsTo<WarehouseCustodyMovementSet, $this> */
    public function movementSet(): BelongsTo
    {
        return $this->belongsTo(WarehouseCustodyMovementSet::class, 'movement_set_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id_snapshot');
    }

    protected function casts(): array
    {
        return [
            'available_delta' => 'integer', 'quarantined_delta' => 'integer', 'transit_delta' => 'integer',
            'available_balance_after' => 'integer', 'quarantined_balance_after' => 'integer',
            'transit_balance_after' => 'integer', 'occurred_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
