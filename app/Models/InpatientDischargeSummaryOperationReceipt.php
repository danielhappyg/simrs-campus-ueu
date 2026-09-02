<?php

namespace App\Models;

use App\Support\Inpatient\InpatientDischargeSummaryMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $encounter_id
 * @property string $operation
 * @property string $payload_digest
 * @property string $result_summary_public_id
 * @property int $result_version
 */
class InpatientDischargeSummaryOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const OPERATION_DRAFT_SAVE = 'DISCHARGE_SUMMARY_DRAFT_SAVE';

    public const OPERATION_FINALIZE = 'DISCHARGE_SUMMARY_FINALIZE';

    protected $fillable = [
        'encounter_id', 'actor_user_id', 'operation', 'idempotency_key', 'payload_digest',
        'result_summary_public_id', 'result_version', 'request_correlation_id', 'completed_at',
    ];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientDischargeSummaryMutationScope::assertActive());
        static::updating(static function (): never {
            throw new LogicException('Inpatient discharge summary operation receipts are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient discharge summary operation receipts cannot be deleted by ordinary workflow.');
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
