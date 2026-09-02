<?php

namespace App\Models;

use App\Support\Emergency\EmergencyImmutableEvidence;
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
class EmergencyOperationReceipt extends Model
{
    use EmergencyImmutableEvidence, HasPublicUlid, UsesSchemaQualifiedTable;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['result_version' => 'integer', 'completed_at' => 'datetime'];
    }
}
