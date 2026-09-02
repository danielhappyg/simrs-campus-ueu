<?php

namespace App\Models;

use App\Support\Audit\AuditEvent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $actor_user_id
 * @property string $audit_event_id
 * @property string $audit_action_snapshot
 * @property string $audit_resource_type_snapshot
 * @property string $operation
 * @property string $idempotency_key
 * @property string $payload_digest
 * @property string $result_type
 * @property string $result_public_id
 * @property int $result_version
 * @property string $result_state
 * @property string $result_digest
 * @property int $control_total
 * @property string|null $request_correlation_id
 * @property Carbon $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $actor
 * @property-read AuditEvent $auditEvent
 */
final class WarehouseOperationReceipt extends WarehouseImmutableModel
{
    public const RESULT_SUPPLIER = 'SUPPLIER';

    public const RESULT_PURCHASE_ORDER = 'PURCHASE_ORDER';

    public const RESULT_PURCHASE_ORDER_DECISION = 'PURCHASE_ORDER_DECISION';

    public const RESULT_RECEIPT = 'RECEIPT';

    public const RESULT_TRANSFER = 'TRANSFER';

    public const RESULT_TRANSFER_DECISION = 'TRANSFER_DECISION';

    public const RESULT_SUPPLIER_RETURN = 'SUPPLIER_RETURN';

    public const RESULT_SUPPLIER_RETURN_DECISION = 'SUPPLIER_RETURN_DECISION';

    public const RESULT_UNIT_RETURN = 'UNIT_RETURN';

    public const RESULT_UNIT_RETURN_DECISION = 'UNIT_RETURN_DECISION';

    public const RESULT_CORRECTION_REQUEST = 'CORRECTION_REQUEST';

    public const RESULT_CORRECTION_DECISION = 'CORRECTION_DECISION';

    public const RESULT_CORRECTION_COMPENSATION = 'CORRECTION_COMPENSATION';

    public $timestamps = true;

    protected $fillable = [
        'actor_user_id', 'audit_event_id', 'audit_action_snapshot', 'audit_resource_type_snapshot',
        'operation', 'idempotency_key', 'payload_digest', 'result_type',
        'result_public_id', 'result_version', 'result_state', 'result_digest',
        'control_total', 'request_correlation_id', 'completed_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<AuditEvent, $this> */
    public function auditEvent(): BelongsTo
    {
        return $this->belongsTo(AuditEvent::class, 'audit_event_id');
    }

    protected function casts(): array
    {
        return ['result_version' => 'integer', 'control_total' => 'integer', 'completed_at' => 'datetime'];
    }
}
