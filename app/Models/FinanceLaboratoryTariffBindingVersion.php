<?php

namespace App\Models;

use App\Support\Finance\FinanceLaboratoryTariffMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $binding_id
 * @property int $tariff_item_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $tariff_item_public_id
 * @property string $tariff_item_code
 * @property string $state
 * @property Carbon $effective_from
 * @property string $reason
 * @property string|null $previous_content_digest
 * @property string $content_digest
 * @property string|null $request_correlation_id
 * @property Carbon $created_at
 */
class FinanceLaboratoryTariffBindingVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = [
        'binding_id', 'tariff_item_id', 'actor_user_id', 'version', 'tariff_item_public_id',
        'tariff_item_code', 'state', 'effective_from', 'reason', 'previous_content_digest',
        'content_digest', 'request_correlation_id', 'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $version): void {
            FinanceLaboratoryTariffMutationScope::assertActive();
            if ($version->version < 1 || ! in_array($version->state, [FinanceLaboratoryTariffBinding::ACTIVE, FinanceLaboratoryTariffBinding::RETIRED], true)) {
                throw new LogicException('Laboratory tariff binding version evidence is invalid.');
            }
        });
        static::updating(static fn () => throw new LogicException('Laboratory tariff binding versions are immutable.'));
        static::deleting(static fn () => throw new LogicException('Laboratory tariff binding versions cannot be deleted.'));
    }

    /** @return BelongsTo<FinanceLaboratoryTariffBinding, $this> */
    public function binding(): BelongsTo
    {
        return $this->belongsTo(FinanceLaboratoryTariffBinding::class, 'binding_id');
    }

    /** @return BelongsTo<FinanceTariffItem, $this> */
    public function tariffItem(): BelongsTo
    {
        return $this->belongsTo(FinanceTariffItem::class, 'tariff_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'effective_from' => 'date:Y-m-d', 'created_at' => 'datetime'];
    }
}
