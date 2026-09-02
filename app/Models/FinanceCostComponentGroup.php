<?php

namespace App\Models;

use App\Support\Finance\FinanceTariffMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property string $group_code
 * @property string $display_name
 * @property string $state
 * @property int $version
 * @property string $current_content_digest
 * @property int $components_count
 */
class FinanceCostComponentGroup extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    protected $fillable = ['group_code', 'display_name', 'state', 'version', 'current_content_digest'];

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    protected static function booted(): void
    {
        static::creating(static function (self $record): void {
            FinanceTariffMutationScope::assertActive();
            if ($record->state !== self::ACTIVE || $record->version !== 1 || $record->group_code !== self::normalizeCode($record->group_code)) {
                throw new LogicException('New cost-component groups must use a normalized code and start ACTIVE at version 1.');
            }
        });
        static::updating(static function (self $record): void {
            FinanceTariffMutationScope::assertActive();
            if ($record->isDirty(['public_id', 'group_code']) || $record->getOriginal('state') === self::RETIRED || $record->version !== (int) $record->getOriginal('version') + 1) {
                throw new LogicException('Cost-component group identity is immutable and retirement is terminal.');
            }
        });
        static::deleting(static fn () => throw new LogicException('Cost-component groups cannot be deleted.'));
    }

    /** @return HasMany<FinanceCostComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(FinanceCostComponent::class, 'group_id');
    }

    /** @return HasMany<FinanceCostComponentGroupVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FinanceCostComponentGroupVersion::class, 'group_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
