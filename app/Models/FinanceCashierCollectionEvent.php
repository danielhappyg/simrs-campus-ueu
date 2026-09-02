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
 * @property int $collection_batch_id
 * @property int $sequence
 * @property string $event_type
 * @property string|null $previous_event_digest
 * @property int $actor_user_id
 * @property string $actor_name_snapshot
 * @property int $membership_count
 * @property int $gross_amount
 * @property int $completed_refund_amount
 * @property int $expected_net_amount
 * @property int $counted_amount
 * @property int $variance_amount
 * @property string $membership_digest
 * @property string|null $explanation
 * @property string $content_digest
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
final class FinanceCashierCollectionEvent extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const CLOSE_REQUESTED = 'CLOSE_REQUESTED';

    public const RECOUNT_SUBMITTED = 'RECOUNT_SUBMITTED';

    public const CLOSE_VERIFIED = 'CLOSE_VERIFIED';

    public $timestamps = false;

    protected $fillable = ['collection_batch_id', 'sequence', 'event_type', 'previous_event_digest', 'actor_user_id', 'actor_name_snapshot', 'membership_count', 'gross_amount', 'completed_refund_amount', 'expected_net_amount', 'counted_amount', 'variance_amount', 'membership_digest', 'explanation', 'content_digest', 'occurred_at', 'created_at'];

    /** @return BelongsTo<FinanceCashierCollectionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(FinanceCashierCollectionBatch::class, 'collection_batch_id');
    }

    protected function casts(): array
    {
        return ['collection_batch_id' => 'integer', 'sequence' => 'integer', 'actor_user_id' => 'integer', 'membership_count' => 'integer', 'gross_amount' => 'integer', 'completed_refund_amount' => 'integer', 'expected_net_amount' => 'integer', 'counted_amount' => 'integer', 'variance_amount' => 'integer', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
