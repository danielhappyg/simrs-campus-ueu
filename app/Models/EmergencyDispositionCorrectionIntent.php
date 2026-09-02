<?php

namespace App\Models;

use App\Support\Emergency\EmergencyImmutableEvidence;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $encounter_id
 * @property int $physician_user_id
 * @property int $current_disposition_id
 * @property int $handoff_id
 * @property string $public_id
 * @property string $replacement_type
 * @property array<string, mixed> $replacement_payload
 * @property string $reason
 * @property string $source_encounter_fingerprint
 * @property string $disposition_fingerprint
 * @property string $handoff_fingerprint
 * @property string $target_encounter_fingerprint
 * @property string $content_digest
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property-read User $physician
 * @property-read Encounter $encounter
 * @property-read EmergencyDisposition $disposition
 * @property-read EmergencyInpatientHandoff $handoff
 * @property-read Collection<int, EmergencyDispositionCorrectionIntentEvent> $events
 */
class EmergencyDispositionCorrectionIntent extends Model
{
    use EmergencyImmutableEvidence, HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $guarded = ['id', 'public_id'];

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<EmergencyDisposition, $this> */
    public function disposition(): BelongsTo
    {
        return $this->belongsTo(EmergencyDisposition::class, 'current_disposition_id');
    }

    /** @return BelongsTo<EmergencyInpatientHandoff, $this> */
    public function handoff(): BelongsTo
    {
        return $this->belongsTo(EmergencyInpatientHandoff::class, 'handoff_id');
    }

    /** @return BelongsTo<User, $this> */
    public function physician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'physician_user_id');
    }

    /** @return HasMany<EmergencyDispositionCorrectionIntentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(EmergencyDispositionCorrectionIntentEvent::class, 'correction_intent_id');
    }

    protected function casts(): array
    {
        return ['replacement_payload' => 'array', 'expires_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
