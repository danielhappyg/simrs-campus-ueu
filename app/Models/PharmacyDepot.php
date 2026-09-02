<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $depot_code
 * @property string $display_name
 * @property list<string> $eligible_care_settings
 * @property string $location_kind
 * @property string $state
 * @property int $version
 * @property string $current_content_digest
 */
class PharmacyDepot extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    /** A non-dispensing location that owns stock before it is replenished to a depot. */
    public const CENTRAL_WAREHOUSE = 'CENTRAL_WAREHOUSE';

    /** A dispensing location that may be selected for a prescription. */
    public const DISPENSING_DEPOT = 'DISPENSING_DEPOT';

    protected $fillable = ['depot_code', 'display_name', 'eligible_care_settings', 'location_kind', 'state', 'version', 'current_content_digest'];

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @return HasMany<PharmacyDepotVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PharmacyDepotVersion::class, 'depot_id');
    }

    /** @return HasMany<PharmacyStockLot, $this> */
    public function stockLots(): HasMany
    {
        return $this->hasMany(PharmacyStockLot::class, 'depot_id');
    }

    /**
     * Restrict destination selectors to active dispensing depots. Central warehouses
     * hold custody stock but are never prescription destinations.
     *
     * @param  Builder<PharmacyDepot>  $query
     * @return Builder<PharmacyDepot>
     */
    public function scopePrescriptionDestinations(Builder $query): Builder
    {
        return $query
            ->where('state', self::ACTIVE)
            ->where('location_kind', self::DISPENSING_DEPOT);
    }

    public function acceptsPrescriptionFor(string $careSetting): bool
    {
        return $this->state === self::ACTIVE
            && $this->location_kind === self::DISPENSING_DEPOT
            && in_array($careSetting, $this->eligible_care_settings, true);
    }

    protected function casts(): array
    {
        return ['eligible_care_settings' => 'array', 'version' => 'integer'];
    }
}
