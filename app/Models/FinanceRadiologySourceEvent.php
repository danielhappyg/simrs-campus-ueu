<?php

namespace App\Models;

use App\Support\Finance\FinanceMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $radiology_performance_id
 * @property int $radiology_order_id
 * @property int $binding_version_id
 * @property int $tariff_item_version_id
 * @property int $encounter_id
 * @property int $patient_id
 * @property int $imported_by_user_id
 * @property string $performance_public_id
 * @property string $order_public_id
 * @property string $encounter_public_id
 * @property string $patient_public_id
 * @property string $care_setting
 * @property string $radiology_master_version_public_id
 * @property int $radiology_master_version
 * @property string $radiology_master_code
 * @property string $radiology_master_content_digest
 * @property string $binding_public_id
 * @property string $binding_version_public_id
 * @property int $binding_version
 * @property string $binding_content_digest
 * @property string $tariff_item_public_id
 * @property string $tariff_item_version_public_id
 * @property string $tariff_item_code
 * @property string $tariff_content_digest
 * @property string $component_public_id
 * @property string $component_code
 * @property string $component_content_digest
 * @property string $event_type
 * @property int $quantity
 * @property int $unit_amount
 * @property int $signed_amount
 * @property string $description
 * @property string $content_digest
 * @property Carbon $performed_at
 * @property Carbon $service_date
 * @property-read FinanceRadiologyTariffBindingVersion $bindingVersion
 * @property-read FinanceTariffItemVersion $tariffItemVersion
 */
class FinanceRadiologySourceEvent extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = [
        'radiology_performance_id', 'radiology_order_id', 'binding_version_id',
        'tariff_item_version_id', 'encounter_id', 'patient_id', 'imported_by_user_id',
        'performance_public_id', 'performed_at', 'order_public_id', 'encounter_public_id',
        'patient_public_id', 'care_setting', 'radiology_master_version_public_id',
        'radiology_master_version', 'radiology_master_code', 'radiology_master_content_digest',
        'binding_public_id', 'binding_version_public_id', 'binding_version',
        'binding_content_digest', 'tariff_item_public_id', 'tariff_item_version_public_id',
        'tariff_item_code', 'tariff_content_digest', 'component_public_id', 'component_code',
        'component_content_digest', 'service_date', 'event_type', 'quantity', 'unit_amount',
        'signed_amount', 'description', 'content_digest', 'imported_at', 'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $source): void {
            if (! FinanceMutationScope::isActive()) {
                throw new LogicException('Radiology finance source creation requires the governed finance mutation scope.');
            }
            if ($source->event_type !== 'CHARGE' || $source->quantity !== 1
                || $source->unit_amount < 1 || $source->signed_amount !== $source->unit_amount) {
                throw new LogicException('Radiology finance sources are one positive charge per performance.');
            }
        });
        static::updating(static fn () => throw new LogicException('Radiology finance source events are immutable.'));
        static::deleting(static fn () => throw new LogicException('Radiology finance source events cannot be deleted.'));
    }

    /** @return BelongsTo<RadiologyPerformance, $this> */
    public function performance(): BelongsTo
    {
        return $this->belongsTo(RadiologyPerformance::class, 'radiology_performance_id');
    }

    /** @return BelongsTo<RadiologyOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(RadiologyOrder::class, 'radiology_order_id');
    }

    /** @return BelongsTo<FinanceRadiologyTariffBindingVersion, $this> */
    public function bindingVersion(): BelongsTo
    {
        return $this->belongsTo(FinanceRadiologyTariffBindingVersion::class, 'binding_version_id');
    }

    /** @return BelongsTo<FinanceTariffItemVersion, $this> */
    public function tariffItemVersion(): BelongsTo
    {
        return $this->belongsTo(FinanceTariffItemVersion::class, 'tariff_item_version_id');
    }

    protected function casts(): array
    {
        return [
            'radiology_master_version' => 'integer', 'binding_version' => 'integer',
            'quantity' => 'integer', 'unit_amount' => 'integer', 'signed_amount' => 'integer',
            'performed_at' => 'datetime', 'service_date' => 'date:Y-m-d',
            'imported_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
