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
 * @property string $result_digest
 * @property string|null $request_correlation_id
 * @property Carbon $completed_at
 */
class PharmacyOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const RESULT_MEDICINE = 'MEDICINE';

    public const RESULT_DEPOT = 'DEPOT';

    public const RESULT_LOT = 'LOT';

    public const RESULT_PRESCRIPTION = 'PRESCRIPTION';

    public const RESULT_VERIFICATION = 'VERIFICATION';

    public const RESULT_PREPARATION = 'PREPARATION';

    public const RESULT_HANDOVER = 'HANDOVER';

    public const RESULT_RETURN = 'RETURN';

    protected $fillable = ['actor_user_id', 'operation', 'idempotency_key', 'payload_digest', 'result_type', 'result_public_id', 'result_version', 'result_state', 'result_digest', 'request_correlation_id', 'completed_at'];

    protected function casts(): array
    {
        return ['result_version' => 'integer', 'completed_at' => 'datetime'];
    }
}
