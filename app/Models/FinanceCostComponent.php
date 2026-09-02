<?php

namespace App\Models;

use App\Support\Finance\FinanceTariffMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $group_id
 * @property string $component_code
 * @property string $display_name
 * @property string|null $description
 * @property string|null $terminology_label
 * @property string $state
 * @property int $version
 * @property string $current_content_digest
 * @property int $tariff_items_count
 * @property-read FinanceCostComponentGroup $group
 */
class FinanceCostComponent extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    protected $fillable = ['group_id', 'component_code', 'display_name', 'description', 'terminology_label', 'state', 'version', 'current_content_digest'];

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    protected static function booted(): void
    {
        static::creating(static function (self $record): void {
            FinanceTariffMutationScope::assertActive();
            if ($record->state !== self::ACTIVE || $record->version !== 1 || $record->component_code !== self::normalizeCode($record->component_code)) {
                throw new LogicException('New cost components must use a normalized code and start ACTIVE at version 1.');
            }
        });
        static::updating(static function (self $record): void {
            FinanceTariffMutationScope::assertActive();
            if ($record->isDirty(['public_id', 'component_code', 'group_id']) || $record->getOriginal('state') === self::RETIRED || $record->version !== (int) $record->getOriginal('version') + 1) {
                throw new LogicException('Cost-component identity and group binding are immutable and retirement is terminal.');
            }
        });
        static::deleting(static fn () => throw new LogicException('Cost components cannot be deleted.'));
    }

    /** @return BelongsTo<FinanceCostComponentGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(FinanceCostComponentGroup::class, 'group_id');
    }

    /** @return HasMany<FinanceCostComponentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FinanceCostComponentVersion::class, 'component_id');
    }

    /** @return HasMany<FinanceTariffItem, $this> */
    public function tariffItems(): HasMany
    {
        return $this->hasMany(FinanceTariffItem::class, 'component_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
