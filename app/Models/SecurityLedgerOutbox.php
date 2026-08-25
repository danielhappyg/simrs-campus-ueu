<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SecurityLedgerOutbox extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const STATE_PENDING = 'PENDING';

    public const STATE_DELIVERED = 'DELIVERED';

    public const STATE_FAILED = 'FAILED';

    protected $fillable = [
        'security_ledger_entry_id',
        'destination',
        'delivery_state',
        'attempts',
        'available_at',
        'last_attempted_at',
        'delivered_at',
        'last_error_digest',
    ];

    protected $hidden = [
        'last_error_digest',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $outbox): void {
            if (
                $outbox->delivery_state !== self::STATE_PENDING
                || (int) $outbox->attempts !== 0
                || $outbox->getAttribute('available_at') === null
                || $outbox->getAttribute('last_attempted_at') !== null
                || $outbox->getAttribute('delivered_at') !== null
                || $outbox->getAttribute('last_error_digest') !== null
            ) {
                throw new LogicException('Security ledger outbox must start as an untouched pending delivery.');
            }
        });

        static::updating(function (self $outbox): void {
            $mutable = [
                'delivery_state',
                'attempts',
                'last_attempted_at',
                'delivered_at',
                'last_error_digest',
                'updated_at',
            ];

            if (array_diff(array_keys($outbox->getDirty()), $mutable) !== []) {
                throw new LogicException('Security ledger outbox identity, link, and destination are immutable.');
            }

            $outbox->assertValidDeliveryTransition();
        });

        static::deleting(function (): never {
            throw new LogicException('Security ledger outbox rows cannot be deleted.');
        });
    }

    /** @return BelongsTo<SecurityLedgerEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(SecurityLedgerEntry::class, 'security_ledger_entry_id');
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    private function assertValidDeliveryTransition(): void
    {
        $oldState = (string) $this->getOriginal('delivery_state');
        $newState = (string) $this->delivery_state;
        $oldAttempts = (int) $this->getOriginal('attempts');
        $newAttempts = (int) $this->attempts;
        $oldAttemptedAt = $this->asCarbon($this->getRawOriginal('last_attempted_at'));
        $newAttemptedAt = $this->asCarbon($this->last_attempted_at);
        $oldDeliveredAt = $this->asCarbon($this->getRawOriginal('delivered_at'));
        $newDeliveredAt = $this->asCarbon($this->delivered_at);

        if (! in_array($newState, [self::STATE_PENDING, self::STATE_DELIVERED, self::STATE_FAILED], true)) {
            throw new LogicException('Security ledger outbox delivery state is invalid.');
        }

        if (
            in_array($oldState, [self::STATE_DELIVERED, self::STATE_FAILED], true)
            && array_intersect(array_keys($this->getDirty()), [
                'delivery_state',
                'attempts',
                'last_attempted_at',
                'delivered_at',
                'last_error_digest',
            ]) !== []
        ) {
            throw new LogicException('Security ledger outbox terminal delivery evidence is immutable.');
        }

        if ($newAttempts < $oldAttempts) {
            throw new LogicException('Security ledger outbox attempts cannot decrease.');
        }

        if ($newAttempts > $oldAttempts && $newAttemptedAt === null) {
            throw new LogicException('Security ledger outbox attempt progress requires an attempt time.');
        }

        if (
            $oldAttemptedAt !== null
            && ($newAttemptedAt === null || $newAttemptedAt->lessThan($oldAttemptedAt))
        ) {
            throw new LogicException('Security ledger outbox attempt time cannot clear or move backward.');
        }

        if (! $this->sameMoment($newAttemptedAt, $oldAttemptedAt) && $newAttempts <= $oldAttempts) {
            throw new LogicException('Security ledger outbox attempt time can advance only with attempts.');
        }

        if ($oldDeliveredAt !== null && ! $this->sameMoment($newDeliveredAt, $oldDeliveredAt)) {
            throw new LogicException('Security ledger outbox delivery time is immutable once recorded.');
        }

        if (
            $this->getAttribute('last_error_digest') !== $this->getOriginal('last_error_digest')
            && $newAttempts <= $oldAttempts
        ) {
            throw new LogicException('Security ledger outbox error evidence can change only with a new attempt.');
        }

        if ($newState === self::STATE_DELIVERED) {
            if ($newDeliveredAt === null || $newAttemptedAt === null || $newAttempts < 1) {
                throw new LogicException('Delivered security ledger outbox rows require attributable attempt and delivery times.');
            }
        } elseif ($newDeliveredAt !== null) {
            throw new LogicException('Undelivered security ledger outbox rows cannot carry a delivery time.');
        }

        $newErrorDigest = $this->getAttribute('last_error_digest');

        if (
            $newState === self::STATE_FAILED
            && (
                $newAttempts < 1
                || $newAttemptedAt === null
                || ! is_string($newErrorDigest)
                || preg_match('/\A[0-9a-f]{64}\z/i', $newErrorDigest) !== 1
            )
        ) {
            throw new LogicException('Failed security ledger outbox rows require attributable attempt time and error digest.');
        }
    }

    private function asCarbon(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value);
    }

    private function sameMoment(?CarbonInterface $first, ?CarbonInterface $second): bool
    {
        if ($first === null || $second === null) {
            return $first === $second;
        }

        return $first->equalTo($second);
    }
}
