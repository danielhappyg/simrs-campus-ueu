<?php

namespace App\Models;

use App\Support\Inpatient\InpatientLocationMutationScope;
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
 * @property int $actor_user_id
 * @property string $event_type
 * @property int $sequence
 * @property string|null $from_ward_public_id
 * @property string|null $from_ward_code
 * @property string|null $from_ward_display_name
 * @property string|null $from_bed_public_id
 * @property string|null $from_bed_code
 * @property string|null $from_bed_display_name
 * @property string|null $from_room_label
 * @property string|null $from_service_class
 * @property int|null $from_inpatient_bed_version_id
 * @property string|null $from_inpatient_bed_version_public_id
 * @property int|null $from_inpatient_bed_version
 * @property string|null $from_inpatient_bed_after_digest
 * @property string $to_ward_public_id
 * @property string $to_ward_code
 * @property string $to_ward_display_name
 * @property string $to_bed_public_id
 * @property string $to_bed_code
 * @property string $to_bed_display_name
 * @property string $to_room_label
 * @property string $to_service_class
 * @property int|null $to_inpatient_bed_version_id
 * @property string|null $to_inpatient_bed_version_public_id
 * @property int|null $to_inpatient_bed_version
 * @property string|null $to_inpatient_bed_after_digest
 * @property string|null $reason
 * @property string|null $request_correlation_id
 * @property string $payload_digest
 * @property Carbon $occurred_at
 */
class InpatientLocationEvent extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    public const TYPE_ADMISSION = 'ADMISSION_LOCATION';

    public const TYPE_TRANSFER = 'BED_TRANSFER';

    protected $fillable = [
        'encounter_id', 'encounter_public_id', 'actor_user_id', 'event_type', 'sequence',
        'from_ward_public_id', 'from_ward_code', 'from_ward_display_name',
        'from_bed_public_id', 'from_bed_code', 'from_bed_display_name', 'from_room_label', 'from_service_class',
        'from_inpatient_bed_version_id', 'from_inpatient_bed_version_public_id',
        'from_inpatient_bed_version', 'from_inpatient_bed_after_digest',
        'to_ward_public_id', 'to_ward_code', 'to_ward_display_name',
        'to_bed_public_id', 'to_bed_code', 'to_bed_display_name', 'to_room_label', 'to_service_class',
        'to_inpatient_bed_version_id', 'to_inpatient_bed_version_public_id',
        'to_inpatient_bed_version', 'to_inpatient_bed_after_digest',
        'reason', 'request_correlation_id', 'payload_digest', 'occurred_at',
    ];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientLocationMutationScope::assertActive());
        static::updating(static function (): never {
            throw new LogicException('Inpatient location events are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient location events cannot be deleted by ordinary workflow.');
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

    /** @return BelongsTo<InpatientBedVersion, $this> */
    public function fromBedVersion(): BelongsTo
    {
        return $this->belongsTo(InpatientBedVersion::class, 'from_inpatient_bed_version_id');
    }

    /** @return BelongsTo<InpatientBedVersion, $this> */
    public function toBedVersion(): BelongsTo
    {
        return $this->belongsTo(InpatientBedVersion::class, 'to_inpatient_bed_version_id');
    }

    /**
     * Finance may consume only prospectively complete provenance. Legacy rows
     * remain nullable and must never be inferred from mutable bed heads.
     */
    public function hasRequiredBedVersionProvenance(): bool
    {
        $toComplete = $this->hasBedVersionTuple('to');

        return $toComplete && ($this->event_type === self::TYPE_ADMISSION || $this->hasBedVersionTuple('from'));
    }

    private function hasBedVersionTuple(string $prefix): bool
    {
        $publicId = $this->getAttribute($prefix.'_inpatient_bed_version_public_id');

        return $this->getAttribute($prefix.'_inpatient_bed_version_id') !== null
            && is_string($publicId)
            && strlen($publicId) === 26
            && (int) $this->getAttribute($prefix.'_inpatient_bed_version') >= 1
            && is_string($this->getAttribute($prefix.'_inpatient_bed_after_digest'))
            && strlen((string) $this->getAttribute($prefix.'_inpatient_bed_after_digest')) === 64;
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'from_inpatient_bed_version_id' => 'integer',
            'from_inpatient_bed_version' => 'integer',
            'to_inpatient_bed_version_id' => 'integer',
            'to_inpatient_bed_version' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
