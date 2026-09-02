<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseUnitReturnDecision extends WarehouseImmutableModel
{
    public const ACCEPTED = 'ACCEPTED';

    public const REJECTED = 'REJECTED';

    protected $fillable = [
        'unit_return_id', 'requester_user_id_snapshot', 'acceptor_user_id', 'decision', 'destination_disposition',
        'return_fingerprint', 'reason_code', 'note', 'content_digest', 'decided_at', 'created_at',
    ];

    /** @return BelongsTo<WarehouseUnitReturn, $this> */
    public function unitReturn(): BelongsTo
    {
        return $this->belongsTo(WarehouseUnitReturn::class, 'unit_return_id');
    }

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
