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
 * @property int $inpatient_rm_coding_id
 * @property int $actor_user_id
 * @property int $inpatient_discharge_coding_source_version_id
 * @property int $version
 * @property string $coding_state
 * @property string $definition_version
 * @property string $profile
 * @property string $source_public_id
 * @property int $source_version
 * @property string $source_version_public_id
 * @property string $source_content_digest
 * @property string $source_provenance_digest
 * @property bool $is_complete
 * @property string $content_digest
 * @property Carbon|null $created_at
 */
class InpatientRmCodingVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'inpatient_rm_coding_id', 'actor_user_id', 'inpatient_discharge_coding_source_version_id',
        'version', 'coding_state', 'definition_version', 'profile', 'source_public_id', 'source_version',
        'source_version_public_id', 'source_content_digest', 'source_provenance_digest',
        'is_complete', 'content_digest',
    ];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientRmMutationScope::assertActive());
        static::updating(static function (): never {
            throw new LogicException('Inpatient RMIK coding versions are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient RMIK coding versions cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<InpatientRmCoding, $this> */
    public function coding(): BelongsTo
    {
        return $this->belongsTo(InpatientRmCoding::class, 'inpatient_rm_coding_id');
    }

    /** @return BelongsTo<InpatientDischargeCodingSourceVersion, $this> */
    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(InpatientDischargeCodingSourceVersion::class, 'inpatient_discharge_coding_source_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return HasMany<InpatientRmCodingAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(InpatientRmCodingAssignment::class)->orderBy('ordinal');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'source_version' => 'integer',
            'is_complete' => 'boolean',
        ];
    }
}
