<?php

namespace App\Models;

use App\Support\Inpatient\InpatientMasterMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $bed_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $display_name
 * @property string $room_label
 * @property string $service_class
 * @property string $state
 * @property string $reason_code
 * @property string|null $before_digest
 * @property string $after_digest
 * @property string|null $request_correlation_id
 */
class InpatientBedVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'bed_id', 'actor_user_id', 'version', 'display_name', 'room_label', 'service_class',
        'state', 'reason_code', 'before_digest', 'after_digest', 'request_correlation_id',
    ];

    protected static function booted(): void
    {
        static::creating(static function (): void {
            InpatientMasterMutationScope::assertActive();
        });
        static::updating(static function (): never {
            throw new LogicException('Inpatient bed versions are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient bed versions cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<InpatientBed, $this> */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(InpatientBed::class, 'bed_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
