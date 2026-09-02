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
 * @property string $handoff_number
 * @property int $collection_batch_id
 * @property int $verified_event_id
 * @property int $cashier_user_id
 * @property int $supervisor_user_id
 * @property string $cashier_name_snapshot
 * @property string $supervisor_name_snapshot
 * @property string $batch_public_id_snapshot
 * @property string $batch_number_snapshot
 * @property int $membership_count
 * @property int $gross_amount
 * @property int $completed_refund_amount
 * @property int $expected_net_amount
 * @property int $counted_amount
 * @property string $batch_content_digest
 * @property string $verified_event_digest
 * @property string $content_digest
 * @property Carbon $handed_off_at
 * @property Carbon $created_at
 * @property-read FinanceCashierCollectionBatch|null $batch
 * @property-read FinanceCashierCollectionEvent|null $verifiedEvent
 */
final class FinanceCashDepositHandoff extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['handoff_number', 'collection_batch_id', 'verified_event_id', 'cashier_user_id', 'supervisor_user_id', 'cashier_name_snapshot', 'supervisor_name_snapshot', 'batch_public_id_snapshot', 'batch_number_snapshot', 'membership_count', 'gross_amount', 'completed_refund_amount', 'expected_net_amount', 'counted_amount', 'batch_content_digest', 'verified_event_digest', 'content_digest', 'handed_off_at', 'created_at'];

    /** @return BelongsTo<FinanceCashierCollectionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(FinanceCashierCollectionBatch::class, 'collection_batch_id');
    }

    /** @return BelongsTo<FinanceCashierCollectionEvent, $this> */
    public function verifiedEvent(): BelongsTo
    {
        return $this->belongsTo(FinanceCashierCollectionEvent::class, 'verified_event_id');
    }

    protected function casts(): array
    {
        return ['collection_batch_id' => 'integer', 'verified_event_id' => 'integer', 'cashier_user_id' => 'integer', 'supervisor_user_id' => 'integer', 'membership_count' => 'integer', 'gross_amount' => 'integer', 'completed_refund_amount' => 'integer', 'expected_net_amount' => 'integer', 'counted_amount' => 'integer', 'handed_off_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
