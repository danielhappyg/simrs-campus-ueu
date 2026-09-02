<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $actor_user_id
 * @property int $collection_batch_id
 * @property int|null $collection_event_id
 * @property int|null $deposit_handoff_id
 * @property string $operation
 * @property string $idempotency_key
 * @property string $payload_digest
 * @property string $result_type
 * @property string $result_public_id
 * @property string $batch_content_digest
 * @property string|null $event_content_digest
 * @property string|null $handoff_content_digest
 * @property string $result_digest
 * @property string|null $request_correlation_id
 * @property Carbon $completed_at
 */
final class FinanceCashierCollectionOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const RESULT_BATCH = 'BATCH';

    public const RESULT_EVENT = 'EVENT';

    public const RESULT_HANDOFF = 'HANDOFF';

    protected $fillable = ['actor_user_id', 'collection_batch_id', 'collection_event_id', 'deposit_handoff_id', 'operation', 'idempotency_key', 'payload_digest', 'result_type', 'result_public_id', 'batch_content_digest', 'event_content_digest', 'handoff_content_digest', 'result_digest', 'request_correlation_id', 'completed_at'];

    protected function casts(): array
    {
        return ['actor_user_id' => 'integer', 'collection_batch_id' => 'integer', 'collection_event_id' => 'integer', 'deposit_handoff_id' => 'integer', 'completed_at' => 'datetime'];
    }
}
