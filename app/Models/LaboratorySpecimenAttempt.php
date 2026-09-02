<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $laboratory_order_id
 * @property int $attempt_number
 * @property string $label_identifier
 * @property int $collector_user_id
 * @property Carbon $collected_at
 * @property string|null $collection_note
 * @property string $state
 * @property int $version
 * @property Carbon $created_at
 * @property-read LaboratoryOrder $order
 * @property-read User $collector
 * @property-read Collection<int, LaboratorySpecimenEvent> $events
 */
class LaboratorySpecimenAttempt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const COLLECTED = 'COLLECTED';

    public const RECEIVED = 'RECEIVED';

    public const ACCEPTED = 'ACCEPTED';

    public const REJECTED = 'REJECTED';

    public $timestamps = false;

    protected $fillable = ['laboratory_order_id', 'attempt_number', 'label_identifier', 'collector_user_id', 'collected_at', 'collection_note', 'state', 'version', 'created_at'];

    /** @return BelongsTo<LaboratoryOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrder::class, 'laboratory_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }

    /** @return HasMany<LaboratorySpecimenEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(LaboratorySpecimenEvent::class);
    }

    protected function casts(): array
    {
        return ['attempt_number' => 'integer', 'version' => 'integer', 'collected_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
