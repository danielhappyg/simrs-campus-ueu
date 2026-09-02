<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $supplier_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $supplier_code_snapshot
 * @property string $display_name
 * @property string|null $synthetic_contact_name
 * @property string|null $synthetic_email
 * @property string|null $synthetic_phone
 * @property string|null $synthetic_reference
 * @property string $state
 * @property string $reason_code
 * @property int|null $previous_version_id
 * @property int|null $previous_version_number
 * @property string|null $previous_content_digest
 * @property string $content_digest
 * @property string|null $request_correlation_id
 * @property Carbon $created_at
 * @property-read WarehouseSupplier $supplier
 * @property-read User $actor
 * @property-read WarehouseSupplierVersion|null $predecessor
 */
final class WarehouseSupplierVersion extends WarehouseImmutableModel
{
    protected $fillable = [
        'supplier_id', 'actor_user_id', 'version', 'supplier_code_snapshot', 'display_name',
        'synthetic_contact_name', 'synthetic_email', 'synthetic_phone', 'synthetic_reference',
        'state', 'reason_code', 'previous_version_id', 'previous_version_number',
        'previous_content_digest', 'content_digest',
        'request_correlation_id', 'created_at',
    ];

    /** @return BelongsTo<WarehouseSupplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(WarehouseSupplier::class, 'supplier_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<WarehouseSupplierVersion, $this> */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'previous_version_number' => 'integer', 'created_at' => 'datetime'];
    }
}
