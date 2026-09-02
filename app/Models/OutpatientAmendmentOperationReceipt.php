<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $payload_digest
 * @property string $result_type
 * @property string $result_public_id
 */
class OutpatientAmendmentOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const RESULT_REQUEST = 'AMENDMENT_REQUEST';

    public const RESULT_ADDENDUM = 'ADDENDUM';

    public const RESULT_REVIEW = 'RENEWED_REVIEW';

    protected $fillable = [
        'encounter_id', 'actor_user_id', 'operation', 'idempotency_key',
        'payload_digest', 'result_type', 'result_public_id', 'request_correlation_id',
        'completed_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Outpatient amendment operation receipts are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Outpatient amendment operation receipts cannot be deleted by ordinary workflow.');
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
        return ['completed_at' => 'datetime'];
    }
}
