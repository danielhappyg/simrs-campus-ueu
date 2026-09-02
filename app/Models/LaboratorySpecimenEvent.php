<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property int $laboratory_specimen_attempt_id
 * @property int $actor_user_id
 * @property string $event_type
 * @property string|null $reason_code
 * @property string|null $note
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property-read LaboratorySpecimenAttempt $attempt
 * @property-read User $actor
 */
class LaboratorySpecimenEvent extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const RECEIVED = 'RECEIVED';

    public const ACCEPTED = 'ACCEPTED';

    public const REJECTED = 'REJECTED';

    public $timestamps = false;

    protected $fillable = ['laboratory_specimen_attempt_id', 'actor_user_id', 'event_type', 'reason_code', 'note', 'occurred_at', 'created_at'];

    /** @return BelongsTo<LaboratorySpecimenAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(LaboratorySpecimenAttempt::class, 'laboratory_specimen_attempt_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
