<?php

namespace App\Models;

use App\Support\Finance\FinanceRadiologyTariffMutationScope;
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
 * @property int $radiology_master_id
 * @property int $radiology_master_version_id
 * @property string $radiology_master_version_public_id
 * @property int $radiology_master_version
 * @property string $radiology_master_content_digest
 * @property string $radiology_master_code
 * @property string $care_setting
 * @property string $state
 * @property int $version
 * @property Carbon $latest_effective_from
 * @property string $current_content_digest
 * @property-read RadiologyExaminationMaster $radiologyMaster
 * @property-read RadiologyExaminationMasterVersion $radiologyMasterVersion
 */
class FinanceRadiologyTariffBinding extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    protected $fillable = [
        'radiology_master_id', 'radiology_master_version_id', 'radiology_master_version_public_id',
        'radiology_master_version', 'radiology_master_content_digest', 'radiology_master_code',
        'care_setting', 'state', 'version', 'latest_effective_from', 'current_content_digest',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $binding): void {
            FinanceRadiologyTariffMutationScope::assertActive();
            if ($binding->state !== self::ACTIVE || $binding->version !== 1) {
                throw new LogicException('New radiology tariff bindings must start ACTIVE at version 1.');
            }
        });
        static::updating(static function (self $binding): void {
            FinanceRadiologyTariffMutationScope::assertActive();
            if ($binding->isDirty([
                'public_id', 'radiology_master_id', 'radiology_master_version_id',
                'radiology_master_version_public_id', 'radiology_master_version',
                'radiology_master_content_digest', 'radiology_master_code', 'care_setting',
            ]) || $binding->getOriginal('state') === self::RETIRED
                || $binding->version !== (int) $binding->getOriginal('version') + 1) {
                throw new LogicException('Radiology tariff binding identity is immutable and retirement is terminal.');
            }
        });
        static::deleting(static fn () => throw new LogicException('Radiology tariff bindings cannot be deleted.'));
    }

    /** @return BelongsTo<RadiologyExaminationMaster, $this> */
    public function radiologyMaster(): BelongsTo
    {
        return $this->belongsTo(RadiologyExaminationMaster::class, 'radiology_master_id');
    }

    /** @return BelongsTo<RadiologyExaminationMasterVersion, $this> */
    public function radiologyMasterVersion(): BelongsTo
    {
        return $this->belongsTo(RadiologyExaminationMasterVersion::class, 'radiology_master_version_id');
    }

    /** @return HasMany<FinanceRadiologyTariffBindingVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FinanceRadiologyTariffBindingVersion::class, 'binding_id');
    }

    protected function casts(): array
    {
        return [
            'radiology_master_version' => 'integer', 'version' => 'integer',
            'latest_effective_from' => 'date:Y-m-d',
        ];
    }
}
