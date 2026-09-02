<?php

namespace App\Models;

use App\Support\Finance\FinanceTariffMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $group_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $display_name
 * @property string $state
 * @property string $reason
 * @property string|null $previous_content_digest
 * @property string $content_digest
 * @property string|null $request_correlation_id
 * @property Carbon $created_at
 * @property-read User $actor
 */
class FinanceCostComponentGroupVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['group_id', 'actor_user_id', 'version', 'display_name', 'state', 'reason', 'previous_content_digest', 'content_digest', 'request_correlation_id', 'created_at'];

    protected static function booted(): void
    {
        static::creating(static fn () => FinanceTariffMutationScope::assertActive());
        static::updating(static fn () => throw new LogicException('Cost-component group versions are immutable.'));
        static::deleting(static fn () => throw new LogicException('Cost-component group versions cannot be deleted.'));
    }

    /** @return BelongsTo<FinanceCostComponentGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(FinanceCostComponentGroup::class, 'group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'datetime'];
    }
}
