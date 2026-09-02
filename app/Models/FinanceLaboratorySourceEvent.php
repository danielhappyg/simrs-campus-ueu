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
 * @property int $laboratory_result_version_id
 * @property int $laboratory_order_id
 * @property int $laboratory_specimen_attempt_id
 * @property int|null $laboratory_critical_communication_id
 * @property int $binding_version_id
 * @property int $tariff_item_version_id
 * @property int $encounter_id
 * @property int $patient_id
 * @property int $imported_by_user_id
 * @property string $result_public_id
 * @property int $result_version
 * @property string $result_content_digest
 * @property string $result_evidence_digest
 * @property Carbon $verified_at
 * @property string $order_public_id
 * @property string $order_snapshot_digest
 * @property string $specimen_public_id
 * @property int $specimen_attempt_number
 * @property string $specimen_label_identifier
 * @property string $specimen_content_digest
 * @property string|null $critical_communication_public_id
 * @property string|null $critical_communication_content_digest
 * @property string $completion_evidence_digest
 * @property string $encounter_public_id
 * @property string $patient_public_id
 * @property string $care_setting
 * @property string $laboratory_master_version_public_id
 * @property int $laboratory_master_version
 * @property string $laboratory_master_code
 * @property string $laboratory_master_content_digest
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
 * @property Carbon $service_date
 * @property Carbon $imported_at
 * @property Carbon $created_at
 * @property-read LaboratoryResultVersion $resultVersion
 * @property-read LaboratoryOrder $order
 * @property-read LaboratorySpecimenAttempt $specimenAttempt
 * @property-read LaboratoryCriticalCommunication|null $criticalCommunication
 * @property-read FinanceLaboratoryTariffBindingVersion $bindingVersion
 * @property-read FinanceTariffItemVersion $tariffItemVersion
 */
class FinanceLaboratorySourceEvent extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = [
        'laboratory_result_version_id', 'laboratory_order_id', 'laboratory_specimen_attempt_id',
        'laboratory_critical_communication_id', 'binding_version_id', 'tariff_item_version_id',
        'encounter_id', 'patient_id', 'imported_by_user_id', 'result_public_id', 'result_version',
        'result_content_digest', 'result_evidence_digest', 'verified_at', 'order_public_id',
        'order_snapshot_digest', 'specimen_public_id', 'specimen_attempt_number',
        'specimen_label_identifier', 'specimen_content_digest',
        'critical_communication_public_id', 'critical_communication_content_digest',
        'completion_evidence_digest', 'encounter_public_id', 'patient_public_id', 'care_setting',
        'laboratory_master_version_public_id', 'laboratory_master_version',
        'laboratory_master_code', 'laboratory_master_content_digest', 'binding_public_id',
        'binding_version_public_id', 'binding_version', 'binding_content_digest',
        'tariff_item_public_id', 'tariff_item_version_public_id', 'tariff_item_code',
        'tariff_content_digest', 'component_public_id', 'component_code',
        'component_content_digest', 'service_date', 'event_type', 'quantity', 'unit_amount',
        'signed_amount', 'description', 'content_digest', 'imported_at', 'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $source): void {
            if (! FinanceMutationScope::isActive()) {
                throw new LogicException('Laboratory finance source creation requires the governed finance mutation scope.');
            }
            if ($source->event_type !== 'CHARGE' || $source->quantity !== 1
                || $source->unit_amount < 1 || $source->signed_amount !== $source->unit_amount) {
                throw new LogicException('Laboratory finance sources are one positive charge per original Verified result.');
            }
            $complete = $source->laboratory_critical_communication_id !== null
                && $source->critical_communication_public_id !== null
                && $source->critical_communication_content_digest !== null;
            $empty = $source->laboratory_critical_communication_id === null
                && $source->critical_communication_public_id === null
                && $source->critical_communication_content_digest === null;
            if (! $complete && ! $empty) {
                throw new LogicException('Laboratory critical communication snapshot is incomplete.');
            }
        });
        static::updating(static fn () => throw new LogicException('Laboratory finance source events are immutable.'));
        static::deleting(static fn () => throw new LogicException('Laboratory finance source events cannot be deleted.'));
    }

    /** @return BelongsTo<LaboratoryResultVersion, $this> */
    public function resultVersion(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResultVersion::class, 'laboratory_result_version_id');
    }

    /** @return BelongsTo<LaboratoryOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrder::class, 'laboratory_order_id');
    }

    /** @return BelongsTo<LaboratorySpecimenAttempt, $this> */
    public function specimenAttempt(): BelongsTo
    {
        return $this->belongsTo(LaboratorySpecimenAttempt::class, 'laboratory_specimen_attempt_id');
    }

    /** @return BelongsTo<LaboratoryCriticalCommunication, $this> */
    public function criticalCommunication(): BelongsTo
    {
        return $this->belongsTo(LaboratoryCriticalCommunication::class, 'laboratory_critical_communication_id');
    }

    /** @return BelongsTo<FinanceLaboratoryTariffBindingVersion, $this> */
    public function bindingVersion(): BelongsTo
    {
        return $this->belongsTo(FinanceLaboratoryTariffBindingVersion::class, 'binding_version_id');
    }

    /** @return BelongsTo<FinanceTariffItemVersion, $this> */
    public function tariffItemVersion(): BelongsTo
    {
        return $this->belongsTo(FinanceTariffItemVersion::class, 'tariff_item_version_id');
    }

    protected function casts(): array
    {
        return [
            'result_version' => 'integer', 'specimen_attempt_number' => 'integer',
            'laboratory_master_version' => 'integer', 'binding_version' => 'integer',
            'quantity' => 'integer', 'unit_amount' => 'integer', 'signed_amount' => 'integer',
            'verified_at' => 'datetime', 'service_date' => 'date:Y-m-d',
            'imported_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
