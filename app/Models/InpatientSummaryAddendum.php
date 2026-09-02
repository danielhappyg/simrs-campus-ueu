<?php

namespace App\Models;

use App\Support\Inpatient\InpatientSummaryAddendumMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $correction_request_id
 * @property int $encounter_id
 * @property int $author_user_id
 * @property int|null $finalized_by_user_id
 * @property string $definition_version
 * @property string $addendum_state
 * @property int $version
 * @property string|null $admission_reason
 * @property string|null $significant_findings
 * @property string|null $care_and_treatment_summary
 * @property string|null $condition_at_discharge
 * @property string|null $follow_up_plan
 * @property string $current_content_digest
 * @property Carbon|null $finalized_at
 * @property Carbon|null $created_at
 */
class InpatientSummaryAddendum extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    protected $table = 'inpatient_summary_addenda';

    public const DEFINITION_VERSION = 'INPATIENT_POST_CLOSURE_SUMMARY_ADDENDUM_V1';

    public const STATE_DRAFT = 'DRAFT';

    public const STATE_FINAL = 'FINAL';

    public const FIELDS = ['admission_reason', 'significant_findings', 'care_and_treatment_summary', 'condition_at_discharge', 'follow_up_plan'];

    protected $fillable = ['correction_request_id', 'encounter_id', 'author_user_id', 'finalized_by_user_id', 'definition_version', 'addendum_state', 'version', 'admission_reason', 'significant_findings', 'care_and_treatment_summary', 'condition_at_discharge', 'follow_up_plan', 'current_content_digest', 'finalized_at'];

    protected static function booted(): void
    {
        static::creating(static function (self $addendum): void {
            InpatientSummaryAddendumMutationScope::assertActive();
            if ($addendum->version !== 1 || $addendum->addendum_state !== self::STATE_DRAFT) {
                throw new LogicException('New summary addenda start DRAFT version 1.');
            }
        });
        static::updating(static function (self $addendum): void {
            InpatientSummaryAddendumMutationScope::assertActive();
            if ($addendum->isDirty(['public_id', 'correction_request_id', 'encounter_id', 'author_user_id', 'definition_version'])) {
                throw new LogicException('Summary addendum identity is immutable.');
            }
            if ($addendum->getOriginal('addendum_state') === self::STATE_FINAL || $addendum->version !== (int) $addendum->getOriginal('version') + 1) {
                throw new LogicException('Invalid summary addendum transition.');
            }
        });
        static::deleting(static fn () => throw new LogicException('Summary addenda cannot be deleted ordinarily.'));
    }

    /** @return HasMany<InpatientSummaryAddendumVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(InpatientSummaryAddendumVersion::class, 'addendum_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'finalized_at' => 'datetime'];
    }
}
