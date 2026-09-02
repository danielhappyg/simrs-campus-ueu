<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseCorrectionDecision extends WarehouseImmutableModel
{
    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    protected $fillable = [
        'correction_request_id', 'custody_lot_id', 'stock_lot_id', 'requester_user_id_snapshot',
        'supervisor_user_id', 'requested_available_delta_snapshot',
        'requested_quarantined_delta_snapshot', 'requested_transit_delta_snapshot',
        'decision', 'request_fingerprint',
        'reason_code', 'note', 'content_digest', 'decided_at', 'created_at',
    ];

    /** @return BelongsTo<WarehouseCorrectionRequest, $this> */
    public function correctionRequest(): BelongsTo
    {
        return $this->belongsTo(WarehouseCorrectionRequest::class, 'correction_request_id');
    }

    protected function casts(): array
    {
        return [
            'requested_available_delta_snapshot' => 'integer',
            'requested_quarantined_delta_snapshot' => 'integer',
            'requested_transit_delta_snapshot' => 'integer',
            'decided_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
