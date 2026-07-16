<?php

namespace App\Modules\Clinical\Models;

use App\Modules\Teaching\Models\SimulationSession;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $session_id
 * @property string $authored_medication
 * @property string|null $form
 * @property string|null $strength
 * @property string $lot_number
 * @property CarbonImmutable $expires_on
 * @property string $quantity_on_hand
 * @property string $unit
 * @property bool $synthetic_flag
 */
class MedicationStock extends Model
{
    use HasPublicUlid;

    private bool $quantityTransitionInProgress = false;

    protected $fillable = [
        'session_id',
        'authored_medication',
        'form',
        'strength',
        'lot_number',
        'expires_on',
        'quantity_on_hand',
        'unit',
        'synthetic_flag',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $stock): void {
            if (! $stock->synthetic_flag || (float) $stock->quantity_on_hand < 0) {
                throw new DomainException('Medication stock in the reference workflow must remain synthetic and non-negative.');
            }

            if ($stock->exists && $stock->isDirty([
                'session_id',
                'authored_medication',
                'form',
                'strength',
                'lot_number',
                'expires_on',
                'unit',
                'synthetic_flag',
            ])) {
                throw new DomainException('Synthetic stock identity and lot provenance are immutable.');
            }

            if ($stock->exists && $stock->isDirty('quantity_on_hand') && ! $stock->quantityTransitionInProgress) {
                throw new DomainException('Synthetic stock quantity changes must use the dispensing workflow.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Synthetic stock lots cannot be hard-deleted.');
        });
    }

    /** @return array{before: string, after: string} */
    public function decrementForDispense(string $quantity): array
    {
        $beforeMilli = (int) round(((float) $this->quantity_on_hand) * 1000);
        $quantityMilli = (int) round(((float) $quantity) * 1000);

        if ($quantityMilli <= 0 || $beforeMilli < $quantityMilli) {
            throw new DomainException('Synthetic stock is insufficient for the requested dispense quantity.');
        }

        $afterMilli = $beforeMilli - $quantityMilli;
        $before = number_format($beforeMilli / 1000, 3, '.', '');
        $after = number_format($afterMilli / 1000, 3, '.', '');
        $this->quantityTransitionInProgress = true;

        try {
            $this->quantity_on_hand = $after;
            $this->save();
        } finally {
            $this->quantityTransitionInProgress = false;
        }

        return ['before' => $before, 'after' => $after];
    }

    /** @return BelongsTo<SimulationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SimulationSession::class);
    }

    /** @return HasMany<MedicationDispense, $this> */
    public function dispenses(): HasMany
    {
        return $this->hasMany(MedicationDispense::class);
    }

    protected function casts(): array
    {
        return [
            'expires_on' => 'immutable_date',
            'quantity_on_hand' => 'decimal:3',
            'synthetic_flag' => 'boolean',
        ];
    }
}
