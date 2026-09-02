<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $pharmacy_financial_source_event_id
 * @property int|null $finance_radiology_source_event_id
 * @property int|null $finance_laboratory_source_event_id
 * @property int|null $finance_accommodation_source_event_id
 * @property int $encounter_id
 * @property int $patient_id
 * @property int $imported_by_user_id
 * @property string $source_domain
 * @property string $source_table
 * @property string $source_public_id
 * @property string $source_content_digest
 * @property string $event_type
 * @property string $care_setting
 * @property int $quantity
 * @property int $unit_amount
 * @property int $signed_amount
 * @property string $description
 * @property string $content_digest
 * @property Carbon $occurred_at
 * @property Carbon $imported_at
 * @property Carbon $created_at
 */
class FinanceChargeEvent extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    public const SOURCE_PHARMACY = 'PHARMACY';

    public const SOURCE_TABLE_PHARMACY = 'pharmacy_financial_source_events';

    public const SOURCE_RADIOLOGY = 'RADIOLOGY';

    public const SOURCE_TABLE_RADIOLOGY = 'finance_radiology_source_events';

    public const SOURCE_LABORATORY = 'LABORATORY';

    public const SOURCE_TABLE_LABORATORY = 'finance_laboratory_source_events';

    public const SOURCE_ACCOMMODATION = 'ACCOMMODATION';

    public const SOURCE_TABLE_ACCOMMODATION = 'finance_accommodation_source_events';

    public const CHARGE = 'CHARGE';

    public const REVERSAL = 'REVERSAL';

    protected $fillable = [
        'pharmacy_financial_source_event_id', 'finance_radiology_source_event_id', 'finance_laboratory_source_event_id',
        'finance_accommodation_source_event_id',
        'encounter_id', 'patient_id', 'imported_by_user_id',
        'source_domain', 'source_table', 'source_public_id', 'source_content_digest', 'event_type',
        'care_setting', 'quantity', 'unit_amount', 'signed_amount', 'description', 'content_digest',
        'occurred_at', 'imported_at', 'created_at',
    ];

    /** @return BelongsTo<PharmacyFinancialSourceEvent, $this> */
    public function pharmacySource(): BelongsTo
    {
        return $this->belongsTo(PharmacyFinancialSourceEvent::class, 'pharmacy_financial_source_event_id');
    }

    /** @return BelongsTo<FinanceRadiologySourceEvent, $this> */
    public function radiologySource(): BelongsTo
    {
        return $this->belongsTo(FinanceRadiologySourceEvent::class, 'finance_radiology_source_event_id');
    }

    /** @return BelongsTo<FinanceLaboratorySourceEvent, $this> */
    public function laboratorySource(): BelongsTo
    {
        return $this->belongsTo(FinanceLaboratorySourceEvent::class, 'finance_laboratory_source_event_id');
    }

    /** @return BelongsTo<FinanceAccommodationSourceEvent, $this> */
    public function accommodationSource(): BelongsTo
    {
        return $this->belongsTo(FinanceAccommodationSourceEvent::class, 'finance_accommodation_source_event_id');
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
        return [
            'quantity' => 'integer', 'unit_amount' => 'integer', 'signed_amount' => 'integer',
            'occurred_at' => 'datetime', 'imported_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
