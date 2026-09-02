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
 * @property string $bill_number
 * @property int $encounter_id
 * @property int $patient_id
 * @property string $care_setting
 * @property string $state
 * @property int $current_version
 * @property int $current_source_event_count
 * @property string $current_source_set_digest
 * @property string|null $current_issued_source_set_digest
 * @property string $current_content_digest
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Encounter|null $encounter
 * @property-read Patient|null $patient
 */
class FinanceBill extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const OPEN_NO_VERSION = 'OPEN_NO_VERSION';

    public const ISSUED_CURRENT = 'ISSUED_CURRENT';

    public const NEW_SOURCE_PENDING = 'NEW_SOURCE_PENDING';

    protected $fillable = [
        'bill_number', 'encounter_id', 'patient_id', 'care_setting', 'state', 'current_version',
        'current_source_event_count', 'current_source_set_digest', 'current_issued_source_set_digest',
        'current_content_digest',
    ];

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return HasMany<FinanceBillVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FinanceBillVersion::class, 'bill_id')->orderBy('version');
    }

    /** @return HasMany<FinanceCashSettlement, $this> */
    public function cashSettlements(): HasMany
    {
        return $this->hasMany(FinanceCashSettlement::class, 'bill_id');
    }

    protected function casts(): array
    {
        return ['current_version' => 'integer', 'current_source_event_count' => 'integer'];
    }
}
