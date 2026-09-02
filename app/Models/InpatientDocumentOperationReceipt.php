<?php

namespace App\Models;

use App\Support\Inpatient\InpatientDocumentationMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $encounter_id
 * @property int $actor_user_id
 * @property string $operation
 * @property string $idempotency_key
 * @property string $payload_digest
 * @property string $result_public_id
 * @property int $result_version
 * @property string|null $request_correlation_id
 */
class InpatientDocumentOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    protected $fillable = [
        'encounter_id', 'actor_user_id', 'operation', 'idempotency_key', 'payload_digest',
        'result_public_id', 'result_version', 'request_correlation_id', 'completed_at',
    ];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientDocumentationMutationScope::assertActive());
        static::updating(static function (): never {
            throw new LogicException('Inpatient document operation receipts are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient document operation receipts cannot be deleted by ordinary workflow.');
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
