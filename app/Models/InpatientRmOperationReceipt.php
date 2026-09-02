<?php

namespace App\Models;

use App\Support\Inpatient\InpatientRmMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $encounter_id
 * @property int $actor_user_id
 * @property int $inpatient_discharge_coding_source_version_id
 * @property int $inpatient_rm_coding_version_id
 * @property int|null $inpatient_rm_completeness_review_id
 * @property string $operation
 * @property string $payload_digest
 * @property string $source_version_public_id
 * @property string $source_content_digest
 * @property string $source_provenance_digest
 * @property string $coding_public_id
 * @property int $coding_version
 * @property string $coding_digest
 * @property string $result_type
 * @property string $result_public_id
 * @property int $result_version
 */
class InpatientRmOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const OPERATION_CODING_SAVE = 'INPATIENT_RM_CODING_DRAFT_SAVE';

    public const OPERATION_REVIEW_SAVE = 'INPATIENT_RM_COMPLETENESS_REVIEW_SAVE';

    public const OPERATION_SIGNOFF = 'INPATIENT_RM_EPISODE_SIGNOFF';

    protected $fillable = [
        'encounter_id', 'actor_user_id', 'inpatient_discharge_coding_source_version_id',
        'inpatient_rm_coding_version_id', 'inpatient_rm_completeness_review_id',
        'operation', 'idempotency_key', 'payload_digest', 'source_version_public_id',
        'source_content_digest', 'source_provenance_digest', 'coding_public_id',
        'coding_version', 'coding_digest', 'result_type', 'result_public_id',
        'result_version', 'request_correlation_id', 'completed_at',
    ];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientRmMutationScope::assertActive());
        static::updating(static function (): never {
            throw new LogicException('Inpatient RMIK operation receipts are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient RMIK operation receipts cannot be deleted by ordinary workflow.');
        });
    }

    protected function casts(): array
    {
        return ['coding_version' => 'integer', 'result_version' => 'integer', 'completed_at' => 'datetime'];
    }
}
