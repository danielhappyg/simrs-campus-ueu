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
 * @property int $correction_case_id
 * @property int|null $correction_event_id
 * @property string $operation
 * @property string $idempotency_key
 * @property string $payload_digest
 * @property string $result_type
 * @property string $result_public_id
 * @property string $case_content_digest
 * @property string|null $event_content_digest
 * @property string $result_digest
 * @property string|null $request_correlation_id
 * @property Carbon $completed_at
 */
final class FinanceSettlementCorrectionOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const RESULT_CASE = 'CASE';

    public const RESULT_EVENT = 'EVENT';

    protected $fillable = [
        'actor_user_id', 'correction_case_id', 'correction_event_id', 'operation',
        'idempotency_key', 'payload_digest', 'result_type', 'result_public_id',
        'case_content_digest', 'event_content_digest', 'result_digest',
        'request_correlation_id', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
