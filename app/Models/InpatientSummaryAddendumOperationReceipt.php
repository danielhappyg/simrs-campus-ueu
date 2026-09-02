<?php

namespace App\Models;

use App\Support\Inpatient\InpatientSummaryAddendumMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $encounter_id
 * @property int $actor_user_id
 * @property int $correction_request_id
 * @property int $request_version_id
 * @property int|null $addendum_id
 * @property int|null $addendum_version_id
 * @property int|null $review_id
 * @property int $baseline_review_id
 * @property int $baseline_summary_version_id
 * @property int $baseline_source_version_id
 * @property int $baseline_coding_version_id
 * @property string $operation
 * @property string $payload_digest
 * @property string $baseline_fingerprint
 * @property string|null $addendum_content_digest
 * @property string $result_type
 * @property string $result_public_id
 * @property int $result_version
 */
class InpatientSummaryAddendumOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    public const SUBMIT = 'REQUEST_SUBMIT';

    public const DECIDE = 'REQUEST_DECIDE';

    public const SAVE = 'ADDENDUM_DRAFT_SAVE';

    public const FINALIZE = 'ADDENDUM_FINALIZE';

    public const REVIEW = 'REVIEW_SAVE';

    public const SIGNOFF = 'REVIEW_SIGNOFF';

    protected $fillable = ['encounter_id', 'actor_user_id', 'correction_request_id', 'request_version_id', 'addendum_id', 'addendum_version_id', 'review_id', 'baseline_review_id', 'baseline_summary_version_id', 'baseline_source_version_id', 'baseline_coding_version_id', 'operation', 'idempotency_key', 'payload_digest', 'baseline_fingerprint', 'addendum_content_digest', 'result_type', 'result_public_id', 'result_version', 'request_correlation_id', 'completed_at'];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientSummaryAddendumMutationScope::assertActive());
        static::updating(static fn () => throw new LogicException('Summary addendum receipts are immutable.'));
        static::deleting(static fn () => throw new LogicException('Summary addendum receipts cannot be deleted ordinarily.'));
    }

    protected function casts(): array
    {
        return ['result_version' => 'integer', 'completed_at' => 'datetime'];
    }
}
