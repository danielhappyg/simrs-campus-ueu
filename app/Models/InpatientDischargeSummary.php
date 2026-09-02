<?php

namespace App\Models;

use App\Support\Inpatient\InpatientDischargeSummaryMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $encounter_id
 * @property int $assigned_physician_user_id
 * @property int|null $finalized_by_user_id
 * @property string $summary_state
 * @property string $definition_version
 * @property int $version
 * @property string|null $admission_reason
 * @property string|null $significant_findings
 * @property string|null $care_and_treatment_summary
 * @property string|null $condition_at_discharge
 * @property string|null $follow_up_plan
 * @property Carbon|null $finalized_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InpatientDischargeSummary extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const DEFINITION_VERSION = 'ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1';

    public const HISTORY_BASELINE_LEGACY_CURRENT_PLACEMENT = 'LEGACY_CURRENT_PLACEMENT';

    public const STATE_DRAFT = 'DRAFT';

    public const STATE_FINAL = 'FINAL';

    /** @var list<string> */
    public const NARRATIVE_FIELDS = [
        'admission_reason',
        'significant_findings',
        'care_and_treatment_summary',
        'condition_at_discharge',
        'follow_up_plan',
    ];

    protected $fillable = [
        'encounter_id', 'assigned_physician_user_id', 'finalized_by_user_id', 'summary_state',
        'definition_version', 'version', 'admission_reason', 'significant_findings',
        'care_and_treatment_summary', 'condition_at_discharge', 'follow_up_plan', 'finalized_at',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $summary): void {
            InpatientDischargeSummaryMutationScope::assertActive();
            if ($summary->version !== 1 || $summary->summary_state !== self::STATE_DRAFT) {
                throw new LogicException('New inpatient discharge summaries must start as Draft version 1.');
            }
        });
        static::updating(static function (self $summary): void {
            InpatientDischargeSummaryMutationScope::assertActive();
            if ($summary->isDirty(['public_id', 'encounter_id', 'assigned_physician_user_id', 'definition_version'])) {
                throw new LogicException('Inpatient discharge summary identity is immutable.');
            }
            if ($summary->getOriginal('summary_state') === self::STATE_FINAL) {
                throw new LogicException('Final inpatient discharge summaries are immutable.');
            }
            if ($summary->version !== (int) $summary->getOriginal('version') + 1) {
                throw new LogicException('Inpatient discharge summary updates must append exactly one version.');
            }
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient discharge summaries cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedPhysician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_physician_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    /** @return HasMany<InpatientDischargeSummaryVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(InpatientDischargeSummaryVersion::class, 'inpatient_discharge_summary_id');
    }

    /** @return HasOne<InpatientDischarge, $this> */
    public function discharge(): HasOne
    {
        return $this->hasOne(InpatientDischarge::class, 'inpatient_discharge_summary_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'finalized_at' => 'datetime'];
    }
}
