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
 * @property int $prescription_id
 * @property int $technician_user_id
 * @property int|null $replaces_preparation_id
 * @property int $sequence
 * @property string $state
 * @property string $prescription_fingerprint
 * @property string $stock_fingerprint
 * @property string|null $replacement_reason
 * @property string $content_digest
 * @property Carbon $prepared_at
 * @property Carbon $created_at
 */
class PharmacyPreparation extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    public const ACTIVE = 'ACTIVE';

    public const SUPERSEDED = 'SUPERSEDED';

    public const HANDED_OVER = 'HANDED_OVER';

    protected $fillable = ['prescription_id', 'technician_user_id', 'replaces_preparation_id', 'sequence', 'state', 'prescription_fingerprint', 'stock_fingerprint', 'replacement_reason', 'content_digest', 'prepared_at', 'created_at'];

    /** @return BelongsTo<PharmacyPrescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(PharmacyPrescription::class, 'prescription_id');
    }

    /** @return BelongsTo<User, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_user_id');
    }

    /** @return HasMany<PharmacyPreparationAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PharmacyPreparationAllocation::class, 'preparation_id')->orderBy('fefo_sequence');
    }

    /** @return HasOne<PharmacyHandover, $this> */
    public function handover(): HasOne
    {
        return $this->hasOne(PharmacyHandover::class, 'preparation_id');
    }

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'prepared_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
