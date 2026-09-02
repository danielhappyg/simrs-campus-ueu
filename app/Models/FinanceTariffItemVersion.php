<?php

namespace App\Models;

use App\Support\Finance\FinanceTariffMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $tariff_item_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $catalogue_public_id
 * @property string $catalogue_code
 * @property string $component_public_id
 * @property string $component_code
 * @property string $component_content_digest
 * @property string $display_name
 * @property string $care_setting
 * @property string $service_domain
 * @property string|null $reference_label
 * @property string|null $ward_class_label
 * @property int $amount_rupiah
 * @property string $state
 * @property Carbon $effective_from
 * @property string $reason
 * @property string|null $previous_content_digest
 * @property string $content_digest
 * @property string|null $request_correlation_id
 * @property Carbon $created_at
 * @property-read User $actor
 */
class FinanceTariffItemVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const CARE_SETTINGS = ['OUTPATIENT', 'EMERGENCY', 'INPATIENT'];

    public const SERVICE_DOMAINS = ['GENERAL_SERVICE', 'LABORATORY', 'RADIOLOGY', 'ACCOMMODATION'];

    public $timestamps = false;

    protected $fillable = ['tariff_item_id', 'actor_user_id', 'version', 'catalogue_public_id', 'catalogue_code', 'component_public_id', 'component_code', 'component_content_digest', 'display_name', 'care_setting', 'service_domain', 'reference_label', 'ward_class_label', 'amount_rupiah', 'state', 'effective_from', 'reason', 'previous_content_digest', 'content_digest', 'request_correlation_id', 'created_at'];

    protected static function booted(): void
    {
        static::creating(static function (self $record): void {
            FinanceTariffMutationScope::assertActive();
            if ($record->amount_rupiah < 1 || ! in_array($record->care_setting, self::CARE_SETTINGS, true) || ! in_array($record->service_domain, self::SERVICE_DOMAINS, true)) {
                throw new LogicException('Tariff item versions require positive integer rupiah and closed labels.');
            }
        });
        static::updating(static fn () => throw new LogicException('Tariff item versions are immutable.'));
        static::deleting(static fn () => throw new LogicException('Tariff item versions cannot be deleted.'));
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
        return ['version' => 'integer', 'amount_rupiah' => 'integer', 'effective_from' => 'date:Y-m-d', 'created_at' => 'datetime'];
    }
}
