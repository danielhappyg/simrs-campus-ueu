<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseTransferDecision extends WarehouseImmutableModel
{
    public const ACCEPTED = 'ACCEPTED';

    public const REJECTED = 'REJECTED';

    protected $fillable = [
        'transfer_id', 'dispatcher_user_id_snapshot', 'acceptor_user_id', 'decision', 'rejection_disposition', 'reason_code',
        'note', 'transfer_fingerprint', 'content_digest', 'decided_at', 'created_at',
    ];

    /** @return BelongsTo<WarehouseTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(WarehouseTransfer::class, 'transfer_id');
    }

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
