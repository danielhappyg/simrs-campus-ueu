<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class WarehouseSupplierReturn extends WarehouseMutableModel
{
    public const REQUESTED = 'REQUESTED';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    protected $fillable = [
        'return_number', 'supplier_id', 'supplier_version_id', 'requester_user_id',
        'state', 'version', 'current_content_digest', 'requested_at', 'resolved_at',
    ];

    /** @return BelongsTo<WarehouseSupplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(WarehouseSupplier::class, 'supplier_id');
    }

    /** @return HasMany<WarehouseSupplierReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WarehouseSupplierReturnItem::class, 'supplier_return_id')->orderBy('line_number');
    }

    /** @return HasOne<WarehouseSupplierReturnDecision, $this> */
    public function decision(): HasOne
    {
        return $this->hasOne(WarehouseSupplierReturnDecision::class, 'supplier_return_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'requested_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
