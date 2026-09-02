<?php

namespace App\Models;

use App\Support\Finance\FinanceLaboratoryTariffMutationScope;
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
 * @property int $laboratory_master_id
 * @property int $laboratory_master_version_id
 * @property string $laboratory_master_version_public_id
 * @property int $laboratory_master_version
 * @property string $laboratory_master_content_digest
 * @property string $laboratory_master_code
 * @property string $care_setting
 * @property string $state
 * @property int $version
 * @property Carbon $latest_effective_from
 * @property string $current_content_digest
 * @property-read LaboratoryExaminationMaster $laboratoryMaster
 * @property-read LaboratoryExaminationMasterVersion $laboratoryMasterVersion
 */
class FinanceLaboratoryTariffBinding extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    protected $fillable = [
        'laboratory_master_id', 'laboratory_master_version_id', 'laboratory_master_version_public_id',
        'laboratory_master_version', 'laboratory_master_content_digest', 'laboratory_master_code',
        'care_setting', 'state', 'version', 'latest_effective_from', 'current_content_digest',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $binding): void {
            FinanceLaboratoryTariffMutationScope::assertActive();
            if ($binding->state !== self::ACTIVE || $binding->version !== 1) {
                throw new LogicException('New laboratory tariff bindings must start ACTIVE at version 1.');
            }
        });
        static::updating(static function (self $binding): void {
            FinanceLaboratoryTariffMutationScope::assertActive();
            if ($binding->isDirty([
                'public_id', 'laboratory_master_id', 'laboratory_master_version_id',
                'laboratory_master_version_public_id', 'laboratory_master_version',
                'laboratory_master_content_digest', 'laboratory_master_code', 'care_setting',
            ]) || $binding->getOriginal('state') === self::RETIRED
                || $binding->version !== (int) $binding->getOriginal('version') + 1) {
                throw new LogicException('Laboratory tariff binding identity is immutable and retirement is terminal.');
            }
        });
        static::deleting(static fn () => throw new LogicException('Laboratory tariff bindings cannot be deleted.'));
    }

    /** @return BelongsTo<LaboratoryExaminationMaster, $this> */
    public function laboratoryMaster(): BelongsTo
    {
        return $this->belongsTo(LaboratoryExaminationMaster::class, 'laboratory_master_id');
    }

    /** @return BelongsTo<LaboratoryExaminationMasterVersion, $this> */
    public function laboratoryMasterVersion(): BelongsTo
    {
        return $this->belongsTo(LaboratoryExaminationMasterVersion::class, 'laboratory_master_version_id');
    }

    /** @return HasMany<FinanceLaboratoryTariffBindingVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FinanceLaboratoryTariffBindingVersion::class, 'binding_id');
    }

    protected function casts(): array
    {
        return [
            'laboratory_master_version' => 'integer', 'version' => 'integer',
            'latest_effective_from' => 'date:Y-m-d',
        ];
    }
}
