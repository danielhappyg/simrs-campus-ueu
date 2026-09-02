<?php

namespace App\Models;

use App\Support\Emergency\EmergencyMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $public_id
 * @property string $vocabulary_code
 * @property string $display_name
 * @property string $state
 * @property int $version
 * @property string $current_content_digest
 * @property-read Collection<int, EmergencyTriageVocabularyVersion> $versions
 */
class EmergencyTriageVocabulary extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    protected $fillable = ['vocabulary_code', 'display_name', 'state', 'version', 'current_content_digest'];

    protected static function booted(): void
    {
        static::creating(static fn () => EmergencyMutationScope::assertActive());
        static::updating(static function (self $model): void {
            EmergencyMutationScope::assertActive();
            if ($model->isDirty(['public_id', 'vocabulary_code']) || $model->getOriginal('state') === self::RETIRED || $model->version !== ((int) $model->getOriginal('version')) + 1) {
                throw new \LogicException('Invalid emergency vocabulary transition.');
            }
        });
        static::deleting(static function (): never {
            throw new \LogicException('Emergency vocabulary cannot be deleted.');
        });
    }

    /** @return HasMany<EmergencyTriageVocabularyVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(EmergencyTriageVocabularyVersion::class);
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
