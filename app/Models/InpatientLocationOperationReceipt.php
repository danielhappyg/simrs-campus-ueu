<?php

namespace App\Models;

use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $encounter_id
 * @property string $payload_digest
 * @property string $result_event_public_id
 * @property int $result_sequence
 */
class InpatientLocationOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const OPERATION_TRANSFER = 'INPATIENT_BED_TRANSFER';

    protected $fillable = [
        'encounter_id', 'actor_user_id', 'operation', 'idempotency_key', 'payload_digest',
        'result_event_public_id', 'result_sequence', 'request_correlation_id', 'completed_at',
    ];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientLocationMutationScope::assertActive());
        static::updating(static function (): never {
            throw new LogicException('Inpatient location receipts are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient location receipts cannot be deleted by ordinary workflow.');
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
        return ['result_sequence' => 'integer', 'completed_at' => 'datetime'];
    }
}
