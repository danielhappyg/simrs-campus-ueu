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
 * @property string $catalogue_code
 * @property string $display_name
 * @property string $state
 * @property int $version
 * @property string $current_content_digest
 * @property int $tariff_items_count
 */
class FinanceTariffCatalogue extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    protected $fillable = ['catalogue_code', 'display_name', 'state', 'version', 'current_content_digest'];

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    protected static function booted(): void
    {
        static::creating(static function (self $record): void {
            FinanceTariffMutationScope::assertActive();
            if ($record->state !== self::ACTIVE || $record->version !== 1 || $record->catalogue_code !== self::normalizeCode($record->catalogue_code)) {
                throw new LogicException('New tariff catalogues must use a normalized code and start ACTIVE at version 1.');
            }
        });
        static::updating(static function (self $record): void {
            FinanceTariffMutationScope::assertActive();
            if ($record->isDirty(['public_id', 'catalogue_code']) || $record->getOriginal('state') === self::RETIRED || $record->version !== (int) $record->getOriginal('version') + 1) {
                throw new LogicException('Tariff catalogue identity is immutable and retirement is terminal.');
            }
        });
        static::deleting(static fn () => throw new LogicException('Tariff catalogues cannot be deleted.'));
    }

    /** @return HasMany<FinanceTariffCatalogueVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FinanceTariffCatalogueVersion::class, 'catalogue_id');
    }

    /** @return HasMany<FinanceTariffItem, $this> */
    public function tariffItems(): HasMany
    {
        return $this->hasMany(FinanceTariffItem::class, 'catalogue_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
