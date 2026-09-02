<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseSupplierReturnDecision extends WarehouseImmutableModel
{
    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    protected $fillable = [
        'supplier_return_id', 'requester_user_id_snapshot', 'approver_user_id', 'decision', 'return_fingerprint',
        'reason_code', 'note', 'content_digest', 'decided_at', 'created_at',
    ];

    /** @return BelongsTo<WarehouseSupplierReturn, $this> */
    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(WarehouseSupplierReturn::class, 'supplier_return_id');
    }

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
