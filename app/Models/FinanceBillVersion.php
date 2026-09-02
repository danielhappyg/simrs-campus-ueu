<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $bill_id
 * @property int|null $previous_version_id
 * @property int $issued_by_user_id
 * @property int $encounter_id
 * @property int $patient_id
 * @property int $version
 * @property string $encounter_public_id_snapshot
 * @property string $encounter_number_snapshot
 * @property string $patient_public_id_snapshot
 * @property string $patient_name_snapshot
 * @property string $medical_record_number_snapshot
 * @property string $care_setting
 * @property string $payer_snapshot
 * @property string $service_location_snapshot
 * @property string $coverage_profile
 * @property string $source_set_digest
 * @property int $source_event_count
 * @property Carbon $source_cutoff_at
 * @property int $gross_amount
 * @property int $reversal_amount
 * @property int $net_amount
 * @property string $issue_reason
 * @property string $content_digest
 * @property Carbon $issued_at
 * @property Carbon $created_at
 * @property-read Collection<int, FinanceBillLine> $lines
 */
class FinanceBillVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    public const COVERAGE_PHARMACY_V1 = 'PHARMACY_HANDOVER_RETURN_ONLY_V1';

    public const COVERAGE_PHARMACY_RADIOLOGY_V1 = 'PHARMACY_AND_RADIOLOGY_TARIFF_SOURCE_V1';

    public const COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_V1 = 'PHARMACY_RADIOLOGY_AND_LABORATORY_TARIFF_SOURCE_V1';

    public const COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1 = 'PHARMACY_RADIOLOGY_LABORATORY_AND_ACCOMMODATION_TARIFF_SOURCE_V1';

    protected $fillable = [
        'bill_id', 'previous_version_id', 'issued_by_user_id', 'encounter_id', 'patient_id', 'version',
        'encounter_public_id_snapshot', 'encounter_number_snapshot', 'patient_public_id_snapshot',
        'patient_name_snapshot', 'medical_record_number_snapshot', 'care_setting', 'payer_snapshot',
        'service_location_snapshot', 'coverage_profile', 'source_set_digest', 'source_event_count',
        'source_cutoff_at', 'gross_amount', 'reversal_amount', 'net_amount', 'issue_reason',
        'content_digest', 'issued_at', 'created_at',
    ];

    /** @return BelongsTo<FinanceBill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(FinanceBill::class, 'bill_id');
    }

    /** @return BelongsTo<FinanceBillVersion, $this> */
    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    /** @return HasMany<FinanceBillLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(FinanceBillLine::class, 'bill_version_id')->orderBy('line_number');
    }

    /** @return HasMany<FinanceCashSettlement, $this> */
    public function cashSettlements(): HasMany
    {
        return $this->hasMany(FinanceCashSettlement::class, 'bill_version_id')->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'source_event_count' => 'integer', 'gross_amount' => 'integer',
            'reversal_amount' => 'integer', 'net_amount' => 'integer', 'source_cutoff_at' => 'datetime',
            'issued_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
