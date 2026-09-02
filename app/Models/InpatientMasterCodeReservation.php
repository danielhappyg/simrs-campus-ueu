<?php

namespace App\Models;

use App\Support\Inpatient\InpatientMasterMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Durable code tombstone. Synthetic reset intentionally retains these rows.
 *
 * @property int $id
 * @property string $public_id
 * @property int $actor_user_id
 * @property string $master_type
 * @property string $normalized_code
 */
class InpatientMasterCodeReservation extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const TYPE_WARD = 'WARD';

    public const TYPE_BED = 'BED';

    public const UPDATED_AT = null;

    protected $fillable = ['actor_user_id', 'master_type', 'normalized_code'];

    protected static function booted(): void
    {
        static::creating(static function (): void {
            InpatientMasterMutationScope::assertActive();
        });
        static::updating(static function (): never {
            throw new LogicException('Inpatient master code reservations are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient master code reservations cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
