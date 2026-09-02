<?php

namespace App\Models;

use App\Support\Inpatient\InpatientMasterMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $ward_id
 * @property string $code
 * @property string $display_name
 * @property string $room_label
 * @property string $service_class
 * @property string $state
 * @property int $version
 * @property-read InpatientWard $ward
 */
class InpatientBed extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const STATE_ACTIVE = 'ACTIVE';

    public const STATE_RETIRED = 'RETIRED';

    protected $fillable = ['ward_id', 'code', 'display_name', 'room_label', 'service_class', 'state', 'version'];

    protected static function booted(): void
    {
        static::creating(static function (self $bed): void {
            InpatientMasterMutationScope::assertActive();
            if ($bed->state !== self::STATE_ACTIVE || $bed->version !== 1 || $bed->code !== InpatientWard::normalizeCode($bed->code)) {
                throw new LogicException('New inpatient beds must use a normalized immutable code and start ACTIVE at version 1.');
            }
        });
        static::updating(static function (self $bed): void {
            InpatientMasterMutationScope::assertActive();
            if ($bed->isDirty(['public_id', 'ward_id', 'code'])) {
                throw new LogicException('Inpatient bed identity, ward, and code are immutable.');
            }
            if ($bed->getOriginal('state') === self::STATE_RETIRED) {
                throw new LogicException('Retired inpatient beds are terminal and immutable.');
            }
            if ($bed->version !== (int) $bed->getOriginal('version') + 1
                || ! in_array($bed->state, [self::STATE_ACTIVE, self::STATE_RETIRED], true)) {
                throw new LogicException('Inpatient bed updates must append exactly one valid version.');
            }
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient beds cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<InpatientWard, $this> */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(InpatientWard::class, 'ward_id');
    }

    /** @return HasMany<InpatientBedVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(InpatientBedVersion::class, 'bed_id');
    }

    /** @return HasMany<Encounter, $this> */
    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class, 'inpatient_bed_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
