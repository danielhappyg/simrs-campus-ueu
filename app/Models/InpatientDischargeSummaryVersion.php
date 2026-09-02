<?php

namespace App\Models;

use App\Support\Inpatient\InpatientDischargeSummaryMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $inpatient_discharge_summary_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $summary_state
 * @property string $definition_version
 * @property string|null $admission_reason
 * @property string|null $significant_findings
 * @property string|null $care_and_treatment_summary
 * @property string|null $condition_at_discharge
 * @property string|null $follow_up_plan
 * @property string $encounter_public_id
 * @property string $care_setting
 * @property string $encounter_status
 * @property int $location_sequence
 * @property string|null $location_event_public_id
 * @property string|null $location_event_type
 * @property string|null $history_baseline
 * @property bool $history_complete
 * @property string $ward_public_id
 * @property string $ward_code
 * @property string $ward_display_name
 * @property string $bed_public_id
 * @property string $bed_code
 * @property string $bed_display_name
 * @property string $room_label
 * @property string $service_class
 * @property Carbon|null $finalized_at
 * @property Carbon|null $created_at
 */
class InpatientDischargeSummaryVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'inpatient_discharge_summary_id', 'actor_user_id', 'version', 'summary_state',
        'definition_version', 'admission_reason', 'significant_findings', 'care_and_treatment_summary',
        'condition_at_discharge', 'follow_up_plan', 'encounter_public_id', 'care_setting',
        'encounter_status', 'location_sequence', 'ward_public_id', 'ward_code', 'ward_display_name',
        'bed_public_id', 'bed_code', 'bed_display_name', 'room_label', 'service_class', 'finalized_at',
        'location_event_public_id', 'location_event_type', 'history_baseline', 'history_complete',
    ];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientDischargeSummaryMutationScope::assertActive());
        static::updating(static function (): never {
            throw new LogicException('Inpatient discharge summary versions are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient discharge summary versions cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<InpatientDischargeSummary, $this> */
    public function summary(): BelongsTo
    {
        return $this->belongsTo(InpatientDischargeSummary::class, 'inpatient_discharge_summary_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'location_sequence' => 'integer',
            'history_complete' => 'boolean',
            'finalized_at' => 'datetime',
        ];
    }
}
