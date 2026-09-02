<?php

namespace App\Models;

use App\Support\Emergency\EmergencyImmutableEvidence;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property string $event_type
 * @property string|null $reason
 * @property string $intent_fingerprint
 * @property string $content_digest
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property-read User $actor
 */
class EmergencyDispositionCorrectionIntentEvent extends Model
{
    use EmergencyImmutableEvidence, HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['correction_intent_id', 'actor_user_id', 'event_type', 'reason', 'intent_fingerprint', 'content_digest', 'occurred_at', 'created_at'];

    /** @return BelongsTo<EmergencyDispositionCorrectionIntent, $this> */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(EmergencyDispositionCorrectionIntent::class, 'correction_intent_id');
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
