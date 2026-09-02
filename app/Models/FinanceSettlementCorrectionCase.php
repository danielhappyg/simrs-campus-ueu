<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $correction_number
 * @property int $settlement_id
 * @property int $bill_id
 * @property int $bill_version_id
 * @property int $requesting_cashier_user_id
 * @property string $requesting_cashier_name_snapshot
 * @property string $settlement_public_id_snapshot
 * @property string $receipt_number_snapshot
 * @property int $amount
 * @property string $settlement_content_digest
 * @property string $bill_public_id_snapshot
 * @property string $bill_version_public_id_snapshot
 * @property int $bill_version_snapshot
 * @property string $reason_code
 * @property string $explanation
 * @property string $content_digest
 * @property Carbon $requested_at
 * @property Carbon $created_at
 */
final class FinanceSettlementCorrectionCase extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    public const WRONG_BILL = 'WRONG_BILL';

    public const DUPLICATE_COLLECTION = 'DUPLICATE_COLLECTION';

    public const CASHIER_INPUT_CONTEXT_ERROR = 'CASHIER_INPUT_CONTEXT_ERROR';

    public const OTHER_SUPERVISOR_REVIEW = 'OTHER_SUPERVISOR_REVIEW';

    /** @var list<string> */
    public const REASON_CODES = [
        self::WRONG_BILL,
        self::DUPLICATE_COLLECTION,
        self::CASHIER_INPUT_CONTEXT_ERROR,
        self::OTHER_SUPERVISOR_REVIEW,
    ];

    protected $fillable = [
        'correction_number', 'settlement_id', 'bill_id', 'bill_version_id',
        'requesting_cashier_user_id', 'requesting_cashier_name_snapshot',
        'settlement_public_id_snapshot', 'receipt_number_snapshot', 'amount',
        'settlement_content_digest', 'bill_public_id_snapshot',
        'bill_version_public_id_snapshot', 'bill_version_snapshot', 'reason_code',
        'explanation', 'content_digest', 'requested_at', 'created_at',
    ];

    /** @return BelongsTo<FinanceCashSettlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(FinanceCashSettlement::class, 'settlement_id');
    }

    /** @return HasMany<FinanceSettlementCorrectionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(FinanceSettlementCorrectionEvent::class, 'correction_case_id')
            ->orderBy('sequence')->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer', 'bill_version_snapshot' => 'integer',
            'requested_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
