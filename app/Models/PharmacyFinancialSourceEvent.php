<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $prescription_id
 * @property int $prescription_item_id
 * @property int $actor_user_id
 * @property string $event_type
 * @property int $quantity
 * @property int $amount
 * @property string $source_type
 * @property string $source_public_id
 * @property string $content_digest
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
class PharmacyFinancialSourceEvent extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    public const CHARGE = 'CHARGE';

    public const REVERSAL = 'REVERSAL';

    protected $fillable = ['prescription_id', 'prescription_item_id', 'actor_user_id', 'event_type', 'quantity', 'amount', 'source_type', 'source_public_id', 'content_digest', 'occurred_at', 'created_at'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'amount' => 'integer', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
