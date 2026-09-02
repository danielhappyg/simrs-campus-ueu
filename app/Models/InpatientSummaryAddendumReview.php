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
 * @property int $addendum_version_id
 * @property int $baseline_review_id
 * @property int $reviewed_by_user_id
 * @property int|null $signed_off_by_user_id
 * @property string $definition_version
 * @property int $version
 * @property string $source_fingerprint
 * @property string $addendum_content_digest
 * @property string $review_state
 * @property int $blocker_count
 * @property Carbon $reviewed_at
 * @property Carbon|null $signed_off_at
 */
class InpatientSummaryAddendumReview extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    public const DEFINITION_VERSION = 'INPATIENT_SUMMARY_ADDENDUM_REVIEW_V1';

    public const STATE_DRAFT = 'DRAFT';

    public const STATE_SIGNED_OFF = 'SIGNED_OFF';

    protected $fillable = ['correction_request_id', 'encounter_id', 'addendum_version_id', 'baseline_review_id', 'reviewed_by_user_id', 'signed_off_by_user_id', 'definition_version', 'version', 'source_fingerprint', 'addendum_content_digest', 'review_state', 'blocker_count', 'reviewed_at', 'signed_off_at'];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientSummaryAddendumMutationScope::assertActive());
        static::updating(static fn () => throw new LogicException('Summary addendum reviews are immutable.'));
        static::deleting(static fn () => throw new LogicException('Summary addendum reviews cannot be deleted ordinarily.'));
    }

    /** @return HasMany<InpatientSummaryAddendumReviewItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InpatientSummaryAddendumReviewItem::class, 'review_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'blocker_count' => 'integer', 'reviewed_at' => 'datetime', 'signed_off_at' => 'datetime'];
    }
}
