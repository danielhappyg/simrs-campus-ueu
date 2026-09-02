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
 * @property int $ward_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $display_name
 * @property string $state
 * @property string $reason_code
 * @property string|null $before_digest
 * @property string $after_digest
 * @property string|null $request_correlation_id
 */
class InpatientWardVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'ward_id', 'actor_user_id', 'version', 'display_name', 'state', 'reason_code',
        'before_digest', 'after_digest', 'request_correlation_id',
    ];

    protected static function booted(): void
    {
        static::creating(static function (): void {
            InpatientMasterMutationScope::assertActive();
        });
        static::updating(static function (): never {
            throw new LogicException('Inpatient ward versions are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient ward versions cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<InpatientWard, $this> */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(InpatientWard::class, 'ward_id');
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
