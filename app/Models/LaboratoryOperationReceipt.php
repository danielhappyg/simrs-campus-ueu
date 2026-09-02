<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $operation
 * @property string $idempotency_key
 * @property string $payload_digest
 * @property string $result_type
 * @property string $result_public_id
 * @property int $result_version
 * @property string $result_state
 * @property string $result_digest
 */
class LaboratoryOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const RESULT_MASTER = 'MASTER';

    public const RESULT_ORDER = 'ORDER';

    public const RESULT_CANCELLATION = 'CANCELLATION';

    public const RESULT_SPECIMEN_ATTEMPT = 'SPECIMEN_ATTEMPT';

    public const RESULT_SPECIMEN_EVENT = 'SPECIMEN_EVENT';

    public const RESULT_RESULT_VERSION = 'RESULT_VERSION';

    public const RESULT_ACKNOWLEDGEMENT = 'ACKNOWLEDGEMENT';

    protected $fillable = ['actor_user_id', 'operation', 'idempotency_key', 'payload_digest', 'result_type', 'result_public_id', 'result_version', 'result_state', 'result_digest', 'request_correlation_id', 'completed_at'];

    protected function casts(): array
    {
        return ['result_version' => 'integer', 'completed_at' => 'datetime'];
    }
}
