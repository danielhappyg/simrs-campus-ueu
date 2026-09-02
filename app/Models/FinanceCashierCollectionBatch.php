<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $batch_number
 * @property int $cashier_user_id
 * @property string $cashier_name_snapshot
 * @property string $content_digest
 * @property Carbon $opened_at
 * @property Carbon $created_at
 */
final class FinanceCashierCollectionBatch extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['batch_number', 'cashier_user_id', 'cashier_name_snapshot', 'content_digest', 'opened_at', 'created_at'];

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    /** @return HasOne<FinanceCashierCollectionActiveSlot, $this> */
    public function activeSlot(): HasOne
    {
        return $this->hasOne(FinanceCashierCollectionActiveSlot::class, 'collection_batch_id');
    }

    /** @return HasMany<FinanceCashierCollectionMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(FinanceCashierCollectionMember::class, 'collection_batch_id')->orderBy('id');
    }

    /** @return HasMany<FinanceCashierCollectionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(FinanceCashierCollectionEvent::class, 'collection_batch_id')->orderBy('sequence')->orderBy('id');
    }

    /** @return HasOne<FinanceCashDepositHandoff, $this> */
    public function handoff(): HasOne
    {
        return $this->hasOne(FinanceCashDepositHandoff::class, 'collection_batch_id');
    }

    protected function casts(): array
    {
        return ['cashier_user_id' => 'integer', 'opened_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
