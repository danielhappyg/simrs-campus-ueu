<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $receipt_number
 * @property int $bill_id
 * @property int $bill_version_id
 * @property int $encounter_id
 * @property int $patient_id
 * @property int $cashier_user_id
 * @property string $cashier_name_snapshot
 * @property string $bill_public_id_snapshot
 * @property string $bill_number_snapshot
 * @property string $bill_version_public_id_snapshot
 * @property int $bill_version_snapshot
 * @property string $encounter_public_id_snapshot
 * @property string $patient_public_id_snapshot
 * @property string $patient_name_snapshot
 * @property string $medical_record_number_snapshot
 * @property string $care_setting
 * @property string $coverage_profile_snapshot
 * @property string $coverage_label_snapshot
 * @property string $coverage_exclusion_snapshot
 * @property string $payment_method
 * @property string $state
 * @property int $amount
 * @property int|null $prior_net_collected_amount_snapshot
 * @property bool $collection_binding_required
 * @property string $source_set_digest
 * @property string $bill_version_content_digest
 * @property string $content_digest
 * @property Carbon $settled_at
 * @property Carbon $created_at
 * @property-read FinanceBill|null $bill
 * @property-read FinanceBillVersion|null $billVersion
 */
final class FinanceCashSettlement extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    public const PAYMENT_CASH = 'CASH';

    public const SETTLED = 'SETTLED';

    protected $fillable = [
        'receipt_number', 'bill_id', 'bill_version_id', 'encounter_id', 'patient_id',
        'cashier_user_id', 'cashier_name_snapshot', 'bill_public_id_snapshot', 'bill_number_snapshot',
        'bill_version_public_id_snapshot', 'bill_version_snapshot', 'encounter_public_id_snapshot',
        'patient_public_id_snapshot', 'patient_name_snapshot', 'medical_record_number_snapshot',
        'care_setting', 'coverage_profile_snapshot', 'coverage_label_snapshot',
        'coverage_exclusion_snapshot', 'payment_method', 'state', 'amount', 'source_set_digest',
        'prior_net_collected_amount_snapshot',
        'collection_binding_required',
        'bill_version_content_digest', 'content_digest', 'settled_at', 'created_at',
    ];

    /** @return BelongsTo<FinanceBill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(FinanceBill::class, 'bill_id');
    }

    /** @return BelongsTo<FinanceBillVersion, $this> */
    public function billVersion(): BelongsTo
    {
        return $this->belongsTo(FinanceBillVersion::class, 'bill_version_id');
    }

    /** @return HasOne<FinanceSettlementCorrectionCase, $this> */
    public function correctionCase(): HasOne
    {
        return $this->hasOne(FinanceSettlementCorrectionCase::class, 'settlement_id');
    }

    protected function casts(): array
    {
        return [
            'bill_version_snapshot' => 'integer', 'amount' => 'integer',
            'prior_net_collected_amount_snapshot' => 'integer',
            'collection_binding_required' => 'boolean',
            'settled_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
