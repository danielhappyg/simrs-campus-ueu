<?php

namespace App\Models;

use App\Support\Inpatient\InpatientMasterMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $actor_user_id
 * @property string $operation
 * @property string $idempotency_key
 * @property string $payload_digest
 * @property string $result_type
 * @property string $result_public_id
 * @property string|null $request_correlation_id
 */
class InpatientMasterOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const RESULT_WARD = 'WARD';

    public const RESULT_BED = 'BED';

    protected $fillable = [
        'actor_user_id', 'operation', 'idempotency_key', 'payload_digest', 'result_type',
        'result_public_id', 'request_correlation_id', 'completed_at',
    ];

    protected static function booted(): void
    {
        static::creating(static function (): void {
            InpatientMasterMutationScope::assertActive();
        });
        static::updating(static function (): never {
            throw new LogicException('Inpatient master operation receipts are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient master operation receipts cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
