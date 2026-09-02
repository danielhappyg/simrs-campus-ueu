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
 * @property int $correction_case_id
 * @property int $sequence
 * @property string $event_type
 * @property string|null $previous_event_digest
 * @property int $actor_user_id
 * @property string $actor_name_snapshot
 * @property string|null $explanation
 * @property int|null $amount
 * @property string $original_settlement_content_digest
 * @property string|null $approval_event_digest
 * @property string $content_digest
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
final class FinanceSettlementCorrectionEvent extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    public const REVIEW_REJECTED = 'REVIEW_REJECTED';

    public const REFUND_APPROVED = 'REFUND_APPROVED';

    public const REFUND_COMPLETED = 'REFUND_COMPLETED';

    protected $fillable = [
        'correction_case_id', 'sequence', 'event_type', 'previous_event_digest',
        'actor_user_id', 'actor_name_snapshot', 'explanation', 'amount',
        'original_settlement_content_digest', 'approval_event_digest',
        'content_digest', 'occurred_at', 'created_at',
    ];

    /** @return BelongsTo<FinanceSettlementCorrectionCase, $this> */
    public function correctionCase(): BelongsTo
    {
        return $this->belongsTo(FinanceSettlementCorrectionCase::class, 'correction_case_id');
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer', 'amount' => 'integer',
            'occurred_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
