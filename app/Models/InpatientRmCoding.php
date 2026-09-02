<?php

namespace App\Models;

use App\Support\Inpatient\InpatientRmMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $encounter_id
 * @property int $created_by_user_id
 * @property string $definition_version
 * @property string $profile
 * @property int $version
 * @property string $coding_state
 * @property bool $current_is_complete
 * @property string $current_content_digest
 */
class InpatientRmCoding extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const DEFINITION_VERSION = 'INPATIENT_RM_MANUAL_CODING_V1';

    public const PROFILE = 'LOCAL_TEACHING_MANUAL_V1';

    public const DIAGNOSIS_CODE_SYSTEM = 'ICD-10';

    public const PROCEDURE_CODE_SYSTEM = 'ICD-9-CM';

    public const STATE_DRAFT = 'DRAFT';

    public const STATE_FINAL = 'FINAL';

    protected $fillable = [
        'encounter_id', 'created_by_user_id', 'definition_version', 'profile',
        'version', 'coding_state', 'current_is_complete', 'current_content_digest',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $coding): void {
            InpatientRmMutationScope::assertActive();
            if ($coding->version !== 1) {
                throw new LogicException('New inpatient RMIK coding must start at version 1.');
            }
            if ($coding->coding_state !== self::STATE_DRAFT) {
                throw new LogicException('New inpatient RMIK coding must start in Draft.');
            }
        });
        static::updating(function (self $coding): void {
            InpatientRmMutationScope::assertActive();
            if ($coding->isDirty(['public_id', 'encounter_id', 'created_by_user_id', 'definition_version', 'profile'])) {
                throw new LogicException('Inpatient RMIK coding identity is immutable.');
            }
            if ($coding->version !== (int) $coding->getOriginal('version') + 1) {
                throw new LogicException('Inpatient RMIK coding updates must append exactly one version.');
            }
            if ($coding->getOriginal('coding_state') === self::STATE_FINAL) {
                throw new LogicException('Final inpatient RMIK coding is immutable.');
            }
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient RMIK coding cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return HasMany<InpatientRmCodingVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(InpatientRmCodingVersion::class);
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'current_is_complete' => 'boolean'];
    }
}
