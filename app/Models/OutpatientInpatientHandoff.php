<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class OutpatientInpatientHandoff extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    protected $fillable = ['source_encounter_id', 'disposition_id', 'target_encounter_id', 'registrar_user_id', 'inpatient_location_event_id', 'bed_snapshot', 'content_digest', 'completed_at', 'created_at'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Outpatient inpatient handoffs are immutable.'));
        self::deleting(fn () => throw new LogicException('Outpatient inpatient handoffs cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['bed_snapshot' => 'array', 'completed_at' => 'datetime'];
    }

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

    /** @return BelongsTo<OutpatientDisposition, $this> */
    public function disposition(): BelongsTo
    {
        return $this->belongsTo(OutpatientDisposition::class);
    }
}
