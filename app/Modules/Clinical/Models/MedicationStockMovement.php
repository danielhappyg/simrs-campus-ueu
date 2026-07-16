<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Teaching\Models\Assignment;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $session_id
 * @property int $medication_stock_id
 * @property int $medication_dispense_id
 * @property string $direction
 * @property string $quantity
 * @property string $balance_before
 * @property string $balance_after
 * @property int $actor_assignment_id
 * @property CarbonImmutable $occurred_at
 */
class MedicationStockMovement extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'session_id',
        'medication_stock_id',
        'medication_dispense_id',
        'direction',
        'quantity',
        'balance_before',
        'balance_after',
        'actor_user_id',
        'actor_assignment_id',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            $stock = MedicationStock::query()->find($movement->medication_stock_id);
            $dispense = MedicationDispense::query()->find($movement->medication_dispense_id);
            $actor = Assignment::query()->active()->find($movement->actor_assignment_id);
            $expectedAfter = (int) round(((float) $movement->balance_before) * 1000)
                - (int) round(((float) $movement->quantity) * 1000);

            if (! $stock
                || ! $dispense
                || ! $actor
                || $movement->direction !== 'OUT'
                || $movement->quantity <= 0
                || $movement->session_id !== $stock->session_id
                || $dispense->medication_stock_id !== $stock->getKey()
                || $dispense->preparer_assignment_id !== $actor->getKey()
                || $movement->actor_user_id !== $actor->user_id
                || $expectedAfter !== (int) round(((float) $movement->balance_after) * 1000)
                || (float) $movement->balance_after < 0) {
                throw new DomainException('A stock movement must exactly reconcile an authorized synthetic dispense without a negative balance.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Medication stock movements are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Medication stock movements are append-only.');
        });
    }

    /** @return BelongsTo<MedicationStock, $this> */
    public function medicationStock(): BelongsTo
    {
        return $this->belongsTo(MedicationStock::class);
    }

    /** @return BelongsTo<MedicationDispense, $this> */
    public function medicationDispense(): BelongsTo
    {
        return $this->belongsTo(MedicationDispense::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'balance_before' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
