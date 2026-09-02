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
 * @property string $reservation_type
 * @property string $normalized_code
 * @property Carbon $created_at
 */
class FinanceTariffCodeReservation extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const TYPE_GROUP = 'GROUP';

    public const TYPE_COMPONENT = 'COMPONENT';

    public const TYPE_CATALOGUE = 'CATALOGUE';

    public const TYPE_TARIFF_ITEM = 'TARIFF_ITEM';

    public const TYPES = [
        self::TYPE_GROUP,
        self::TYPE_COMPONENT,
        self::TYPE_CATALOGUE,
        self::TYPE_TARIFF_ITEM,
    ];

    public $timestamps = false;

    protected $fillable = ['actor_user_id', 'reservation_type', 'normalized_code', 'created_at'];

    protected static function booted(): void
    {
        static::creating(static function (self $reservation): void {
            FinanceTariffMutationScope::assertActive();
            if (! in_array($reservation->reservation_type, self::TYPES, true)
                || $reservation->normalized_code !== mb_strtoupper(trim($reservation->normalized_code))) {
                throw new LogicException('Finance tariff code reservations require a valid type and normalized code.');
            }
        });
        static::updating(static fn () => throw new LogicException('Finance tariff code reservations are immutable.'));
        static::deleting(static fn () => throw new LogicException('Finance tariff code reservations cannot be deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
