<?php

namespace App\Models;

use App\Support\Finance\FinanceAccommodationTariffMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $inpatient_bed_id
 * @property int $inpatient_bed_version_id
 * @property string $inpatient_bed_version_public_id
 * @property int $inpatient_bed_version
 * @property string $inpatient_bed_content_digest
 * @property string $ward_public_id
 * @property string $ward_code
 * @property string $bed_public_id
 * @property string $bed_code
 * @property string $service_class
 * @property string $care_setting
 * @property string $pricing_unit
 * @property string $state
 * @property int $version
 * @property Carbon $latest_effective_from
 * @property string $current_content_digest
 * @property-read InpatientBed $bed
 * @property-read InpatientBedVersion $bedVersion
 */
class FinanceAccommodationTariffBinding extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    protected $fillable = [
        'inpatient_bed_id', 'inpatient_bed_version_id', 'inpatient_bed_version_public_id',
        'inpatient_bed_version', 'inpatient_bed_content_digest', 'ward_public_id', 'ward_code',
        'bed_public_id', 'bed_code', 'service_class', 'care_setting', 'pricing_unit', 'state',
        'version', 'latest_effective_from', 'current_content_digest',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $binding): void {
            FinanceAccommodationTariffMutationScope::assertActive();
            if ($binding->state !== self::ACTIVE || $binding->version !== 1 || $binding->care_setting !== 'INPATIENT' || $binding->pricing_unit !== 'OCCUPANCY_DAY') {
                throw new LogicException('New accommodation tariff bindings must start ACTIVE at version 1.');
            }
        });
        static::updating(static function (self $binding): void {
            FinanceAccommodationTariffMutationScope::assertActive();
            if ($binding->isDirty([
                'public_id', 'inpatient_bed_id', 'inpatient_bed_version_id', 'inpatient_bed_version_public_id',
                'inpatient_bed_version', 'inpatient_bed_content_digest', 'ward_public_id', 'ward_code',
                'bed_public_id', 'bed_code', 'service_class', 'care_setting', 'pricing_unit',
            ]) || $binding->getOriginal('state') === self::RETIRED
                || $binding->version !== (int) $binding->getOriginal('version') + 1) {
                throw new LogicException('Accommodation tariff binding identity is immutable and retirement is terminal.');
            }
        });
        static::deleting(static fn () => throw new LogicException('Accommodation tariff bindings cannot be deleted.'));
    }

    /** @return BelongsTo<InpatientBed, $this> */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(InpatientBed::class, 'inpatient_bed_id');
    }

    /** @return BelongsTo<InpatientBedVersion, $this> */
    public function bedVersion(): BelongsTo
    {
        return $this->belongsTo(InpatientBedVersion::class, 'inpatient_bed_version_id');
    }

    /** @return HasMany<FinanceAccommodationTariffBindingVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FinanceAccommodationTariffBindingVersion::class, 'binding_id');
    }

    protected function casts(): array
    {
        return ['inpatient_bed_version' => 'integer', 'version' => 'integer', 'latest_effective_from' => 'date:Y-m-d'];
    }
}
