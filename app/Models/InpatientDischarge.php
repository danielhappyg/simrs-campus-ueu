<?php

namespace App\Models;

use App\Support\Inpatient\InpatientDischargeMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $encounter_id
 * @property int $inpatient_discharge_summary_id
 * @property int $inpatient_discharge_summary_version_id
 * @property int $actor_user_id
 * @property string $disposition_code
 * @property string $disposition_label
 * @property string $discharge_summary_public_id
 * @property int $discharge_summary_version
 * @property string $discharge_summary_version_public_id
 * @property string $discharge_summary_content_digest
 * @property string $discharge_summary_provenance_digest
 * @property int $inpatient_discharge_coding_source_version_id
 * @property string $discharge_coding_source_public_id
 * @property int $discharge_coding_source_version
 * @property string $discharge_coding_source_version_public_id
 * @property string $discharge_coding_source_content_digest
 * @property string $discharge_coding_source_provenance_digest
 * @property int $location_sequence
 * @property string $source_ward_public_id
 * @property string $source_ward_code
 * @property string $source_bed_public_id
 * @property string $source_bed_code
 * @property string $encounter_status_before
 * @property string $encounter_status_after
 * @property string $payload_digest
 * @property string|null $request_correlation_id
 * @property Carbon $discharged_at
 */
class InpatientDischarge extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    public const DISPOSITION_ROUTINE_HOME = 'PULANG_ATAS_IZIN_DOKTER';

    public const DISPOSITION_ROUTINE_HOME_LABEL = 'Pulang atas izin dokter';

    protected $fillable = [
        'encounter_id', 'inpatient_discharge_summary_id', 'inpatient_discharge_summary_version_id', 'actor_user_id',
        'disposition_code', 'disposition_label', 'discharge_summary_public_id',
        'discharge_summary_version', 'discharge_summary_version_public_id',
        'discharge_summary_content_digest', 'discharge_summary_provenance_digest',
        'inpatient_discharge_coding_source_version_id', 'discharge_coding_source_public_id',
        'discharge_coding_source_version', 'discharge_coding_source_version_public_id',
        'discharge_coding_source_content_digest', 'discharge_coding_source_provenance_digest',
        'location_sequence', 'source_ward_public_id',
        'source_ward_code', 'source_bed_public_id', 'source_bed_code',
        'encounter_status_before', 'encounter_status_after', 'payload_digest',
        'request_correlation_id', 'discharged_at',
    ];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientDischargeMutationScope::assertActive());
        static::updating(static function (): never {
            throw new LogicException('Inpatient discharge records are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient discharge records cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
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
        return [
            'discharge_summary_version' => 'integer',
            'discharge_coding_source_version' => 'integer',
            'location_sequence' => 'integer',
            'discharged_at' => 'datetime',
        ];
    }
}
