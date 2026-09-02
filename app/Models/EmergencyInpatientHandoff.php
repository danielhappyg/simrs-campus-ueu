<?php

namespace App\Models;

use App\Support\Emergency\EmergencyImmutableEvidence;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $source_encounter_id
 * @property int $target_encounter_id
 * @property int $disposition_id
 * @property int $inpatient_location_event_id
 * @property int $inpatient_bed_id
 * @property int $actor_user_id
 * @property int $inpatient_bed_version
 * @property array<string, mixed> $bed_snapshot
 * @property string $source_encounter_fingerprint
 * @property string $disposition_fingerprint
 * @property string $target_encounter_fingerprint
 * @property string $location_event_fingerprint
 * @property string $content_digest
 * @property Carbon $handed_off_at
 * @property Carbon $created_at
 * @property-read Encounter $sourceEncounter
 * @property-read Encounter $targetEncounter
 * @property-read EmergencyDisposition $disposition
 * @property-read InpatientLocationEvent $locationEvent
 * @property-read InpatientBed $bed
 * @property-read User $actor
 * @property-read EmergencyHandoffCompensation|null $compensation
 */
class EmergencyInpatientHandoff extends Model
{
    use EmergencyImmutableEvidence, HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $guarded = ['id', 'public_id'];

    /** @return BelongsTo<Encounter, $this> */
    public function sourceEncounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'source_encounter_id');
    }

    /** @return BelongsTo<Encounter, $this> */
    public function targetEncounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'target_encounter_id');
    }

    /** @return BelongsTo<EmergencyDisposition, $this> */
    public function disposition(): BelongsTo
    {
        return $this->belongsTo(EmergencyDisposition::class);
    }

    /** @return BelongsTo<InpatientLocationEvent, $this> */
    public function locationEvent(): BelongsTo
    {
        return $this->belongsTo(InpatientLocationEvent::class, 'inpatient_location_event_id');
    }

    /** @return BelongsTo<InpatientBed, $this> */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(InpatientBed::class, 'inpatient_bed_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return HasOne<EmergencyHandoffCompensation, $this> */
    public function compensation(): HasOne
    {
        return $this->hasOne(EmergencyHandoffCompensation::class, 'handoff_id');
    }

    protected function casts(): array
    {
        return ['inpatient_bed_version' => 'integer', 'bed_snapshot' => 'array', 'handed_off_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
