<?php

namespace App\Models;

use App\Support\Emergency\EmergencyMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $state
 * @property int $version
 * @property int $id
 * @property int $encounter_id
 * @property string $public_id
 * @property string $document_type
 * @property string $current_content_digest
 * @property-read Collection<int, EmergencyClinicalDocumentVersion> $versions
 */
class EmergencyClinicalDocument extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const NURSING = 'NURSING';

    public const MEDICAL = 'MEDICAL';

    public const DRAFT = 'DRAFT';

    public const FINAL = 'FINAL';

    protected $fillable = ['encounter_id', 'document_type', 'state', 'version', 'current_content_digest'];

    protected static function booted(): void
    {
        static::creating(static function (self $model): void {
            EmergencyMutationScope::assertActive();
            if ($model->state !== self::DRAFT || $model->version !== 1) {
                throw new \LogicException('New emergency document must start at Draft version 1.');
            }
        });
        static::updating(static function (self $model): void {
            EmergencyMutationScope::assertActive();
            if ($model->isDirty(['public_id', 'encounter_id', 'document_type']) || $model->getOriginal('state') === self::FINAL || $model->version !== ((int) $model->getOriginal('version')) + 1) {
                throw new \LogicException('Invalid emergency document transition.');
            }
        });
        static::deleting(static function (): never {
            throw new \LogicException('Emergency document cannot be deleted.');
        });
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return HasMany<EmergencyClinicalDocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(EmergencyClinicalDocumentVersion::class);
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
