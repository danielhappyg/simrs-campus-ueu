<?php

namespace App\Models;

use App\Support\Inpatient\InpatientDischargeMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $encounter_id
 * @property int $actor_user_id
 * @property int $inpatient_discharge_summary_version_id
 * @property int $inpatient_discharge_coding_source_version_id
 * @property string $payload_digest
 * @property string $result_discharge_public_id
 * @property string $discharge_summary_version_public_id
 * @property string $discharge_summary_content_digest
 * @property string $discharge_summary_provenance_digest
 * @property string $discharge_coding_source_public_id
 * @property int $discharge_coding_source_version
 * @property string $discharge_coding_source_version_public_id
 * @property string $discharge_coding_source_content_digest
 * @property string $discharge_coding_source_provenance_digest
 */
class InpatientDischargeOperationReceipt extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const OPERATION_EXECUTE = 'INPATIENT_DISCHARGE_EXECUTE';

    protected $fillable = [
        'encounter_id', 'actor_user_id', 'inpatient_discharge_summary_version_id',
        'operation', 'idempotency_key', 'payload_digest', 'result_discharge_public_id',
        'discharge_summary_version_public_id', 'discharge_summary_content_digest',
        'discharge_summary_provenance_digest', 'request_correlation_id', 'completed_at',
        'inpatient_discharge_coding_source_version_id', 'discharge_coding_source_public_id',
        'discharge_coding_source_version', 'discharge_coding_source_version_public_id',
        'discharge_coding_source_content_digest', 'discharge_coding_source_provenance_digest',
    ];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientDischargeMutationScope::assertActive());
        static::updating(static function (): never {
            throw new LogicException('Inpatient discharge operation receipts are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient discharge operation receipts cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<InpatientDischargeSummaryVersion, $this> */
    public function summaryVersion(): BelongsTo
    {
        return $this->belongsTo(InpatientDischargeSummaryVersion::class, 'inpatient_discharge_summary_version_id');
    }

    /** @return BelongsTo<InpatientDischargeCodingSourceVersion, $this> */
    public function codingSourceVersion(): BelongsTo
    {
        return $this->belongsTo(InpatientDischargeCodingSourceVersion::class, 'inpatient_discharge_coding_source_version_id');
    }

    protected function casts(): array
    {
        return ['discharge_coding_source_version' => 'integer', 'completed_at' => 'datetime'];
    }
}
