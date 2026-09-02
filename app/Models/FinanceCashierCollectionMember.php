<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $collection_batch_id
 * @property int $settlement_id
 * @property int $cashier_user_id
 * @property string $cashier_name_snapshot
 * @property string $settlement_public_id_snapshot
 * @property string $receipt_number_snapshot
 * @property int $amount
 * @property string $settlement_content_digest
 * @property string $content_digest
 * @property Carbon $collected_at
 * @property Carbon $created_at
 * @property-read FinanceCashSettlement|null $settlement
 */
final class FinanceCashierCollectionMember extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['collection_batch_id', 'settlement_id', 'cashier_user_id', 'cashier_name_snapshot', 'settlement_public_id_snapshot', 'receipt_number_snapshot', 'amount', 'settlement_content_digest', 'content_digest', 'collected_at', 'created_at'];

    /** @return BelongsTo<FinanceCashierCollectionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(FinanceCashierCollectionBatch::class, 'collection_batch_id');
    }

    /** @return BelongsTo<FinanceCashSettlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(FinanceCashSettlement::class, 'settlement_id');
    }

    protected function casts(): array
    {
        return ['collection_batch_id' => 'integer', 'settlement_id' => 'integer', 'cashier_user_id' => 'integer', 'amount' => 'integer', 'collected_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
