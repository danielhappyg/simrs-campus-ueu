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
 * @property int $prescription_id
 * @property int $preparation_id
 * @property int $pharmacist_user_id
 * @property int $sequence
 * @property string $state
 * @property string|null $partial_reason
 * @property string $preparation_fingerprint
 * @property string $content_digest
 * @property Carbon $handed_over_at
 * @property Carbon $created_at
 */
class PharmacyHandover extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    public const FULL = 'FULL';

    public const PARTIAL = 'PARTIAL';

    protected $fillable = ['prescription_id', 'preparation_id', 'pharmacist_user_id', 'sequence', 'state', 'partial_reason', 'preparation_fingerprint', 'content_digest', 'handed_over_at', 'created_at'];

    /** @return BelongsTo<PharmacyPrescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(PharmacyPrescription::class, 'prescription_id');
    }

    /** @return BelongsTo<PharmacyPreparation, $this> */
    public function preparation(): BelongsTo
    {
        return $this->belongsTo(PharmacyPreparation::class, 'preparation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function pharmacist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pharmacist_user_id');
    }

    /** @return HasMany<PharmacyHandoverItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PharmacyHandoverItem::class, 'handover_id');
    }

    /** @return HasMany<PharmacyReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(PharmacyReturn::class, 'handover_id');
    }

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'handed_over_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
