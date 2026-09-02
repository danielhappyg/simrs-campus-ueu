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
 * @property int $inpatient_location_event_id
 * @property int $inpatient_bed_version_id
 * @property int|null $closing_location_event_id
 * @property int|null $inpatient_discharge_id
 * @property int $binding_version_id
 * @property int $tariff_item_version_id
 * @property int $encounter_id
 * @property int $patient_id
 * @property int $imported_by_user_id
 * @property string $opening_location_event_public_id
 * @property string $opening_location_event_digest
 * @property string $closing_type
 * @property string $closing_public_id
 * @property string $closing_content_digest
 * @property Carbon $interval_start_at
 * @property Carbon $interval_end_at
 * @property Carbon $occupancy_anchor_at
 * @property string $encounter_public_id
 * @property string $patient_public_id
 * @property string $care_setting
 * @property string $ward_public_id
 * @property string $ward_code
 * @property string $bed_public_id
 * @property string $bed_code
 * @property string $bed_display_name
 * @property string $room_label
 * @property string $service_class
 * @property string $inpatient_bed_version_public_id
 * @property int $inpatient_bed_version
 * @property string $inpatient_bed_content_digest
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
 * @property Carbon $service_date
 * @property string $pricing_unit
 * @property string $event_type
 * @property int $quantity
 * @property int $unit_amount
 * @property int $signed_amount
 * @property string $description
 * @property string $content_digest
 * @property Carbon $imported_at
 * @property Carbon $created_at
 */
class FinanceAccommodationSourceEvent extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    public const BED_TRANSFER = 'BED_TRANSFER';

    public const ROUTINE_DISCHARGE = 'ROUTINE_DISCHARGE';

    protected $guarded = ['id', 'public_id'];

    protected static function booted(): void
    {
        static::creating(static function (self $source): void {
            if (! FinanceMutationScope::isActive()) {
                throw new LogicException('Accommodation finance source creation requires the governed finance mutation scope.');
            }
            if ($source->event_type !== 'CHARGE' || $source->care_setting !== 'INPATIENT'
                || $source->pricing_unit !== 'OCCUPANCY_DAY' || $source->quantity !== 1
                || $source->unit_amount < 1 || $source->signed_amount !== $source->unit_amount) {
                throw new LogicException('Accommodation finance sources are one positive occupancy-day charge.');
            }
        });
        static::updating(static fn () => throw new LogicException('Accommodation finance sources are immutable.'));
        static::deleting(static fn () => throw new LogicException('Accommodation finance sources cannot be deleted.'));
    }

    /** @return BelongsTo<InpatientLocationEvent, $this> */
    public function openingLocationEvent(): BelongsTo
    {
        return $this->belongsTo(InpatientLocationEvent::class, 'inpatient_location_event_id');
    }

    /** @return BelongsTo<InpatientBedVersion, $this> */
    public function bedVersion(): BelongsTo
    {
        return $this->belongsTo(InpatientBedVersion::class, 'inpatient_bed_version_id');
    }

    /** @return BelongsTo<InpatientLocationEvent, $this> */
    public function closingLocationEvent(): BelongsTo
    {
        return $this->belongsTo(InpatientLocationEvent::class, 'closing_location_event_id');
    }

    /** @return BelongsTo<InpatientDischarge, $this> */
    public function discharge(): BelongsTo
    {
        return $this->belongsTo(InpatientDischarge::class, 'inpatient_discharge_id');
    }

    /** @return BelongsTo<FinanceAccommodationTariffBindingVersion, $this> */
    public function bindingVersion(): BelongsTo
    {
        return $this->belongsTo(FinanceAccommodationTariffBindingVersion::class, 'binding_version_id');
    }

    /** @return BelongsTo<FinanceTariffItemVersion, $this> */
    public function tariffItemVersion(): BelongsTo
    {
        return $this->belongsTo(FinanceTariffItemVersion::class, 'tariff_item_version_id');
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    protected function casts(): array
    {
        return ['inpatient_bed_version' => 'integer', 'binding_version' => 'integer', 'quantity' => 'integer', 'unit_amount' => 'integer', 'signed_amount' => 'integer', 'service_date' => 'date:Y-m-d', 'interval_start_at' => 'datetime', 'interval_end_at' => 'datetime', 'occupancy_anchor_at' => 'datetime', 'imported_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
