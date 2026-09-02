<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $handover_id
 * @property int $pharmacist_user_id
 * @property string $reason_code
 * @property string|null $note
 * @property string $handover_fingerprint
 * @property string $content_digest
 * @property Carbon $returned_at
 * @property Carbon $created_at
 */
class PharmacyReturn extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['handover_id', 'pharmacist_user_id', 'reason_code', 'note', 'handover_fingerprint', 'content_digest', 'returned_at', 'created_at'];

    /** @return BelongsTo<PharmacyHandover, $this> */
    public function handover(): BelongsTo
    {
        return $this->belongsTo(PharmacyHandover::class, 'handover_id');
    }

    /** @return BelongsTo<User, $this> */
    public function pharmacist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pharmacist_user_id');
    }

    /** @return HasMany<PharmacyReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PharmacyReturnItem::class, 'return_id');
    }

    protected function casts(): array
    {
        return ['returned_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
