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
 * @property int $bill_version_id
 * @property int $charge_event_id
 * @property int $line_number
 * @property string $source_domain
 * @property string $source_public_id
 * @property string $source_content_digest
 * @property string $event_type
 * @property int $quantity
 * @property int $unit_amount
 * @property int $signed_amount
 * @property string $description
 * @property string $charge_event_content_digest
 * @property string $content_digest
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
class FinanceBillLine extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = [
        'bill_version_id', 'charge_event_id', 'line_number', 'source_domain', 'source_public_id',
        'source_content_digest', 'event_type', 'quantity', 'unit_amount', 'signed_amount',
        'description', 'charge_event_content_digest', 'content_digest', 'occurred_at', 'created_at',
    ];

    /** @return BelongsTo<FinanceBillVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FinanceBillVersion::class, 'bill_version_id');
    }

    /** @return BelongsTo<FinanceChargeEvent, $this> */
    public function chargeEvent(): BelongsTo
    {
        return $this->belongsTo(FinanceChargeEvent::class, 'charge_event_id');
    }

    protected function casts(): array
    {
        return [
            'line_number' => 'integer', 'quantity' => 'integer', 'unit_amount' => 'integer',
            'signed_amount' => 'integer', 'occurred_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
