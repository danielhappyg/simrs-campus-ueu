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
 * @property string $operation
 * @property string $idempotency_key
 * @property string $payload_digest
 * @property string $result_type
 * @property string $result_public_id
 * @property int $result_version
 * @property string $result_state
 * @property int $result_source_event_count
 * @property string $result_source_set_digest
 * @property string $result_digest
 * @property string|null $request_correlation_id
 * @property Carbon $completed_at
 */
class FinanceOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const RESULT_BILL = 'BILL';

    public const RESULT_BILL_VERSION = 'BILL_VERSION';

    protected $fillable = [
        'actor_user_id', 'operation', 'idempotency_key', 'payload_digest', 'result_type',
        'result_public_id', 'result_version', 'result_state', 'result_source_event_count',
        'result_source_set_digest', 'result_digest', 'request_correlation_id', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'result_version' => 'integer', 'result_source_event_count' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
