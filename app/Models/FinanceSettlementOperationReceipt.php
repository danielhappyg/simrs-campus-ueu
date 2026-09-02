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
 * @property string $settlement_public_id
 * @property string $bill_version_public_id
 * @property int $amount
 * @property string $source_set_digest
 * @property string $bill_version_content_digest
 * @property string $settlement_content_digest
 * @property string $result_digest
 * @property string|null $request_correlation_id
 * @property Carbon $completed_at
 */
final class FinanceSettlementOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    protected $fillable = [
        'actor_user_id', 'operation', 'idempotency_key', 'payload_digest',
        'settlement_public_id', 'bill_version_public_id', 'amount', 'source_set_digest',
        'bill_version_content_digest', 'settlement_content_digest', 'result_digest',
        'request_correlation_id', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'completed_at' => 'datetime'];
    }
}
