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
 * @property int $handoff_id
 * @property int $correction_intent_id
 * @property int $replacement_disposition_id
 * @property int $actor_user_id
 * @property string $target_cancellation_fingerprint
 * @property string $bed_reconciliation_fingerprint
 * @property string $location_reconciliation_fingerprint
 * @property string $content_digest
 * @property Carbon $compensated_at
 * @property Carbon $created_at
 * @property-read User $actor
 * @property-read EmergencyInpatientHandoff $handoff
 * @property-read EmergencyDispositionCorrectionIntent $correctionIntent
 * @property-read EmergencyDisposition $replacementDisposition
 */
class EmergencyHandoffCompensation extends Model
{
    use EmergencyImmutableEvidence, HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $table = 'emergency_handoff_compensations';

    protected $guarded = ['id', 'public_id'];

    /** @return BelongsTo<EmergencyInpatientHandoff, $this> */
    public function handoff(): BelongsTo
    {
        return $this->belongsTo(EmergencyInpatientHandoff::class);
    }

    /** @return BelongsTo<EmergencyDispositionCorrectionIntent, $this> */
    public function correctionIntent(): BelongsTo
    {
        return $this->belongsTo(EmergencyDispositionCorrectionIntent::class, 'correction_intent_id');
    }

    /** @return BelongsTo<EmergencyDisposition, $this> */
    public function replacementDisposition(): BelongsTo
    {
        return $this->belongsTo(EmergencyDisposition::class, 'replacement_disposition_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['compensated_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
