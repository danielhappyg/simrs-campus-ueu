<?php

namespace App\Models;

use App\Support\Inpatient\InpatientRmMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $encounter_id
 * @property int $inpatient_rm_coding_version_id
 * @property int $reviewed_by_user_id
 * @property int|null $signed_off_by_user_id
 * @property string $definition_version
 * @property int $version
 * @property string $source_fingerprint
 * @property int $coding_version
 * @property string $coding_digest
 * @property string $source_version_public_id
 * @property string $source_content_digest
 * @property string $source_provenance_digest
 * @property string $review_state
 * @property int $blocker_count
 * @property Carbon $reviewed_at
 * @property Carbon|null $signed_off_at
 */
class InpatientRmCompletenessReview extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    public const DEFINITION_VERSION = 'INPATIENT_RM_COMPLETENESS_V1';

    public const STATE_DRAFT = 'DRAFT';

    public const STATE_SIGNED_OFF = 'SIGNED_OFF';

    protected $fillable = [
        'encounter_id', 'inpatient_rm_coding_version_id', 'reviewed_by_user_id',
        'signed_off_by_user_id', 'definition_version', 'version', 'source_fingerprint',
        'coding_version', 'coding_digest', 'source_version_public_id',
        'source_content_digest', 'source_provenance_digest',
        'review_state', 'blocker_count', 'reviewed_at', 'signed_off_at',
    ];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientRmMutationScope::assertActive());
        static::updating(static function (): never {
            throw new LogicException('Inpatient RMIK completeness reviews are immutable snapshots.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient RMIK completeness reviews cannot be deleted by ordinary workflow.');
        });
    }

    /** @return HasMany<InpatientRmCompletenessItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InpatientRmCompletenessItem::class);
    }

    /** @return BelongsTo<InpatientRmCodingVersion, $this> */
    public function codingVersion(): BelongsTo
    {
        return $this->belongsTo(InpatientRmCodingVersion::class, 'inpatient_rm_coding_version_id');
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function signedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_off_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'coding_version' => 'integer', 'blocker_count' => 'integer',
            'reviewed_at' => 'datetime', 'signed_off_at' => 'datetime',
        ];
    }
}
