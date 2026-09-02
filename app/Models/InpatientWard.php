<?php

namespace App\Models;

use App\Support\Inpatient\InpatientMasterMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $display_name
 * @property string $state
 * @property int $version
 * @property-read Collection<int, InpatientBed> $beds
 */
class InpatientWard extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const STATE_ACTIVE = 'ACTIVE';

    public const STATE_RETIRED = 'RETIRED';

    /** @var list<string> */
    public const STATES = [self::STATE_ACTIVE, self::STATE_RETIRED];

    protected $fillable = ['code', 'display_name', 'state', 'version'];

    protected static function booted(): void
    {
        static::creating(static function (self $ward): void {
            InpatientMasterMutationScope::assertActive();
            if ($ward->state !== self::STATE_ACTIVE || $ward->version !== 1 || $ward->code !== self::normalizeCode($ward->code)) {
                throw new LogicException('New inpatient wards must use a normalized immutable code and start ACTIVE at version 1.');
            }
        });
        static::updating(static function (self $ward): void {
            InpatientMasterMutationScope::assertActive();
            if ($ward->isDirty(['public_id', 'code'])) {
                throw new LogicException('Inpatient ward identity and code are immutable.');
            }
            if ($ward->getOriginal('state') === self::STATE_RETIRED) {
                throw new LogicException('Retired inpatient wards are terminal and immutable.');
            }
            if ($ward->version !== (int) $ward->getOriginal('version') + 1 || ! in_array($ward->state, self::STATES, true)) {
                throw new LogicException('Inpatient ward updates must append exactly one valid version.');
            }
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient wards cannot be deleted by ordinary workflow.');
        });
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @return HasMany<InpatientBed, $this> */
    public function beds(): HasMany
    {
        return $this->hasMany(InpatientBed::class, 'ward_id');
    }

    /** @return HasMany<InpatientWardVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(InpatientWardVersion::class, 'ward_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
