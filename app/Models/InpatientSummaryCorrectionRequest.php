<?php

namespace App\Models;

use App\Support\Inpatient\InpatientSummaryAddendumMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Carbon\CarbonInterface;
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
 * @property int $inpatient_discharge_id
 * @property int $inpatient_discharge_summary_id
 * @property int $inpatient_discharge_summary_version_id
 * @property int $inpatient_discharge_coding_source_id
 * @property int $inpatient_discharge_coding_source_version_id
 * @property int $inpatient_rm_coding_id
 * @property int $inpatient_rm_coding_version_id
 * @property int $baseline_review_id
 * @property int $requested_by_user_id
 * @property int|null $decided_by_user_id
 * @property string $reason_code
 * @property string|null $note
 * @property string|null $active_slot
 * @property string $request_state
 * @property int $version
 * @property string $summary_content_digest
 * @property string $summary_provenance_digest
 * @property string $source_content_digest
 * @property string $source_provenance_digest
 * @property string $coding_content_digest
 * @property string $baseline_fingerprint
 * @property string|null $decision_note
 * @property CarbonInterface|null $decided_at
 * @property CarbonInterface|null $consumed_at
 * @property string|null $request_correlation_id
 * @property Carbon|null $created_at
 */
class InpatientSummaryCorrectionRequest extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const STATE_SUBMITTED = 'SUBMITTED';

    public const STATE_APPROVED = 'APPROVED';

    public const STATE_DENIED = 'DENIED';

    public const STATE_CONSUMED = 'CONSUMED';

    public const REASON_CLINICAL_CORRECTION = 'CLINICAL_CORRECTION';

    public const REASON_MISSING_INFORMATION = 'MISSING_INFORMATION';

    public const REASON_WRONG_ENTRY = 'WRONG_ENTRY';

    public const REASON_OTHER = 'OTHER';

    protected $fillable = [
        'encounter_id', 'inpatient_discharge_id', 'inpatient_discharge_summary_id',
        'inpatient_discharge_summary_version_id', 'inpatient_discharge_coding_source_id',
        'inpatient_discharge_coding_source_version_id', 'inpatient_rm_coding_id',
        'inpatient_rm_coding_version_id', 'baseline_review_id', 'requested_by_user_id',
        'decided_by_user_id', 'reason_code', 'note', 'request_state', 'active_slot', 'version',
        'summary_content_digest', 'summary_provenance_digest', 'source_content_digest',
        'source_provenance_digest', 'coding_content_digest', 'baseline_fingerprint',
        'decision_note', 'decided_at', 'consumed_at', 'request_correlation_id',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $request): void {
            InpatientSummaryAddendumMutationScope::assertActive();
            if ($request->version !== 1 || $request->request_state !== self::STATE_SUBMITTED || $request->active_slot !== 'ACTIVE') {
                throw new LogicException('New inpatient correction requests must start SUBMITTED version 1.');
            }
        });
        static::updating(static function (self $request): void {
            InpatientSummaryAddendumMutationScope::assertActive();
            if ($request->isDirty(['public_id', 'encounter_id', 'inpatient_discharge_id', 'inpatient_discharge_summary_id', 'inpatient_discharge_summary_version_id', 'inpatient_discharge_coding_source_id', 'inpatient_discharge_coding_source_version_id', 'inpatient_rm_coding_id', 'inpatient_rm_coding_version_id', 'baseline_review_id', 'requested_by_user_id', 'reason_code', 'note', 'summary_content_digest', 'summary_provenance_digest', 'source_content_digest', 'source_provenance_digest', 'coding_content_digest', 'baseline_fingerprint'])) {
                throw new LogicException('Inpatient correction request baseline is immutable.');
            }
            if ($request->version !== (int) $request->getOriginal('version') + 1) {
                throw new LogicException('Inpatient correction request transitions append exactly one version.');
            }
            $from = $request->getOriginal('request_state');
            $to = $request->request_state;
            if (($from === self::STATE_SUBMITTED && ! in_array($to, [self::STATE_APPROVED, self::STATE_DENIED], true))
                || ($from === self::STATE_APPROVED && $to !== self::STATE_CONSUMED)
                || in_array($from, [self::STATE_DENIED, self::STATE_CONSUMED], true)) {
                throw new LogicException('Invalid inpatient correction request transition.');
            }
        });
        static::deleting(static fn () => throw new LogicException('Inpatient correction requests cannot be deleted ordinarily.'));
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return HasMany<InpatientSummaryCorrectionRequestVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(InpatientSummaryCorrectionRequestVersion::class, 'correction_request_id');
    }

    /** @return HasOne<InpatientSummaryAddendum, $this> */
    public function addendum(): HasOne
    {
        return $this->hasOne(InpatientSummaryAddendum::class, 'correction_request_id');
    }

    /** @return HasMany<InpatientSummaryAddendumReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(InpatientSummaryAddendumReview::class, 'correction_request_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'decided_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
