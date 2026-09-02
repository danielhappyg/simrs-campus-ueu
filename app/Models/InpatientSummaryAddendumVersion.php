<?php

namespace App\Models;

use App\Support\Inpatient\InpatientSummaryAddendumMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $addendum_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $addendum_state
 * @property string $definition_version
 * @property string|null $admission_reason
 * @property string|null $significant_findings
 * @property string|null $care_and_treatment_summary
 * @property string|null $condition_at_discharge
 * @property string|null $follow_up_plan
 * @property string $content_digest
 * @property Carbon|null $finalized_at
 */
class InpatientSummaryAddendumVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    protected $fillable = ['addendum_id', 'actor_user_id', 'version', 'addendum_state', 'definition_version', 'admission_reason', 'significant_findings', 'care_and_treatment_summary', 'condition_at_discharge', 'follow_up_plan', 'content_digest', 'finalized_at'];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientSummaryAddendumMutationScope::assertActive());
        static::updating(static fn () => throw new LogicException('Summary addendum versions are immutable.'));
        static::deleting(static fn () => throw new LogicException('Summary addendum versions cannot be deleted ordinarily.'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'finalized_at' => 'datetime'];
    }
}
