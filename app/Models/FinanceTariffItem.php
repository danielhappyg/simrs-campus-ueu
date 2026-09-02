<?php

namespace App\Models;

use App\Support\Finance\FinanceTariffMutationScope;
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
 * @property int $catalogue_id
 * @property int $component_id
 * @property string $tariff_code
 * @property string $state
 * @property int $version
 * @property Carbon $latest_effective_from
 * @property string $current_content_digest
 * @property-read FinanceTariffCatalogue $catalogue
 * @property-read FinanceCostComponent $component
 */
class FinanceTariffItem extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    protected $fillable = ['catalogue_id', 'component_id', 'tariff_code', 'state', 'version', 'latest_effective_from', 'current_content_digest'];

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    protected static function booted(): void
    {
        static::creating(static function (self $record): void {
            FinanceTariffMutationScope::assertActive();
            if ($record->state !== self::ACTIVE || $record->version !== 1 || $record->tariff_code !== self::normalizeCode($record->tariff_code)) {
                throw new LogicException('New tariff items must use a normalized code and start ACTIVE at version 1.');
            }
        });
        static::updating(static function (self $record): void {
            FinanceTariffMutationScope::assertActive();
            if ($record->isDirty(['public_id', 'tariff_code', 'catalogue_id', 'component_id']) || $record->getOriginal('state') === self::RETIRED || $record->version !== (int) $record->getOriginal('version') + 1) {
                throw new LogicException('Tariff item identity and bindings are immutable and retirement is terminal.');
            }
        });
        static::deleting(static fn () => throw new LogicException('Tariff items cannot be deleted.'));
    }

    /** @return BelongsTo<FinanceTariffCatalogue, $this> */
    public function catalogue(): BelongsTo
    {
        return $this->belongsTo(FinanceTariffCatalogue::class, 'catalogue_id');
    }

    /** @return BelongsTo<FinanceCostComponent, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(FinanceCostComponent::class, 'component_id');
    }

    /** @return HasMany<FinanceTariffItemVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FinanceTariffItemVersion::class, 'tariff_item_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'latest_effective_from' => 'date:Y-m-d'];
    }
}
