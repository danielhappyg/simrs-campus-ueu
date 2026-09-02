<?php

namespace App\Models;

use App\Support\Inpatient\InpatientDischargeCodingSourceMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $encounter_id
 * @property string $operation
 * @property string $payload_digest
 * @property string $result_source_public_id
 * @property int $result_version
 */
class InpatientDischargeCodingSourceOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const OPERATION_DRAFT_SAVE = 'DISCHARGE_CODING_SOURCE_DRAFT_SAVE';

    public const OPERATION_FINALIZE = 'DISCHARGE_CODING_SOURCE_FINALIZE';

    protected $fillable = ['encounter_id', 'actor_user_id', 'operation', 'idempotency_key', 'payload_digest', 'result_source_public_id', 'result_version', 'request_correlation_id', 'completed_at'];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientDischargeCodingSourceMutationScope::assertActive());
        static::updating(static function (): never {
            throw new LogicException('Inpatient discharge coding source receipts are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient discharge coding source receipts cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['result_version' => 'integer', 'completed_at' => 'datetime'];
    }
}
