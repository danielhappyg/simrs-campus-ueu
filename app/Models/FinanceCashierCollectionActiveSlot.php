<?php

namespace App\Models;

use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $cashier_user_id
 * @property int $collection_batch_id
 * @property Carbon $created_at
 */
final class FinanceCashierCollectionActiveSlot extends Model
{
    use UsesSchemaQualifiedTable;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'cashier_user_id';

    protected $fillable = ['cashier_user_id', 'collection_batch_id', 'created_at'];

    /** @return BelongsTo<FinanceCashierCollectionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(FinanceCashierCollectionBatch::class, 'collection_batch_id');
    }

    protected function casts(): array
    {
        return ['cashier_user_id' => 'integer', 'collection_batch_id' => 'integer', 'created_at' => 'datetime'];
    }
}
