<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $supplier_code
 * @property string $display_name
 * @property string|null $synthetic_contact_name
 * @property string|null $synthetic_email
 * @property string|null $synthetic_phone
 * @property string|null $synthetic_reference
 * @property string $state
 * @property int $version
 * @property string $current_content_digest
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, WarehouseSupplierVersion> $versions
 */
final class WarehouseSupplier extends WarehouseMutableModel
{
    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    protected $fillable = [
        'supplier_code', 'display_name', 'synthetic_contact_name', 'synthetic_email',
        'synthetic_phone', 'synthetic_reference', 'state', 'version', 'current_content_digest',
    ];

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @return HasMany<WarehouseSupplierVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(WarehouseSupplierVersion::class, 'supplier_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
