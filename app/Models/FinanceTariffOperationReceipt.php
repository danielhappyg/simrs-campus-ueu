<?php

namespace App\Models;

use App\Support\Finance\FinanceTariffMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
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
 * @property int $result_version
 * @property string $result_state
 * @property string $result_digest
 * @property string|null $request_correlation_id
 * @property Carbon $completed_at
 */
class FinanceTariffOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const RESULT_GROUP = 'GROUP';

    public const RESULT_COMPONENT = 'COMPONENT';

    public const RESULT_CATALOGUE = 'CATALOGUE';

    public const RESULT_TARIFF_ITEM = 'TARIFF_ITEM';

    public const RESULT_TYPES = [
        self::RESULT_GROUP,
        self::RESULT_COMPONENT,
        self::RESULT_CATALOGUE,
        self::RESULT_TARIFF_ITEM,
    ];

    protected $fillable = [
        'actor_user_id', 'operation', 'idempotency_key', 'payload_digest', 'result_type',
        'result_public_id', 'result_version', 'result_state', 'result_digest',
        'request_correlation_id', 'completed_at',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $receipt): void {
            FinanceTariffMutationScope::assertActive();
            if (! in_array($receipt->result_type, self::RESULT_TYPES, true)
                || $receipt->result_version < 1
                || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $receipt->payload_digest)
                || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $receipt->result_digest)) {
                throw new LogicException('Finance tariff receipt evidence is incomplete.');
            }
        });
        static::updating(static fn () => throw new LogicException('Finance tariff operation receipts are immutable.'));
        static::deleting(static fn () => throw new LogicException('Finance tariff operation receipts cannot be deleted.'));
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
