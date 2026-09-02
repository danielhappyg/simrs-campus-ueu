<?php

namespace App\Models;

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
 * @property int|null $reviewed_by_user_id
 * @property int|null $signed_off_by_user_id
 * @property string $definition_version
 * @property int $version
 * @property string $source_fingerprint
 * @property string $review_state
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $signed_off_at
 * @property-read User|null $reviewedBy
 * @property-read User|null $signedOffBy
 */
class OutpatientRmCompletenessReview extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const DEFINITION_VERSION = 'OUTPATIENT_RM_COMPLETENESS_V1';

    public const STATE_DRAFT = 'DRAFT';

    public const STATE_SIGNED_OFF = 'SIGNED_OFF';

    protected $fillable = [
        'encounter_id', 'reviewed_by_user_id', 'signed_off_by_user_id',
        'definition_version', 'version', 'source_fingerprint', 'review_state',
        'reviewed_at', 'signed_off_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Outpatient RM completeness reviews are immutable snapshots.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Outpatient RM completeness reviews cannot be deleted by ordinary workflow.');
        });
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

    /** @return HasMany<OutpatientRmCompletenessItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OutpatientRmCompletenessItem::class);
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'reviewed_at' => 'datetime',
            'signed_off_at' => 'datetime',
        ];
    }
}
