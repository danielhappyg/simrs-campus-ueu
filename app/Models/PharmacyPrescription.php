<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $public_id
 * @property int $encounter_id
 * @property int $patient_id
 * @property int $ordering_physician_user_id
 * @property int $depot_id
 * @property int|null $replaces_prescription_id
 * @property string $care_setting
 * @property string $encounter_number_snapshot
 * @property string $location_snapshot
 * @property string|null $location_fingerprint
 * @property int $depot_version
 * @property string $depot_code_snapshot
 * @property string|null $clinical_note
 * @property string $status
 * @property int $version
 * @property string $current_content_digest
 * @property CarbonInterface|null $ordered_at
 */
class PharmacyPrescription extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const DRAFT = 'DRAFT';

    public const ORDERED = 'ORDERED';

    public const VERIFIED = 'VERIFIED';

    public const PREPARED = 'PREPARED';

    public const PARTIALLY_HANDED_OVER = 'PARTIALLY_HANDED_OVER';

    public const HANDED_OVER = 'HANDED_OVER';

    public const UNFILLED_CLOSED = 'UNFILLED_CLOSED';

    public const CANCELLED = 'CANCELLED';

    public const REFUSED = 'REFUSED';

    public const ACTIVE_STATES = [self::DRAFT, self::ORDERED, self::VERIFIED, self::PREPARED, self::PARTIALLY_HANDED_OVER];

    protected $fillable = ['encounter_id', 'patient_id', 'ordering_physician_user_id', 'depot_id', 'replaces_prescription_id', 'care_setting', 'encounter_number_snapshot', 'location_snapshot', 'location_fingerprint', 'depot_version', 'depot_code_snapshot', 'clinical_note', 'status', 'version', 'current_content_digest', 'ordered_at'];

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

    /** @return BelongsTo<User, $this> */
    public function orderingPhysician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordering_physician_user_id');
    }

    /** @return BelongsTo<PharmacyDepot, $this> */
    public function depot(): BelongsTo
    {
        return $this->belongsTo(PharmacyDepot::class);
    }

    /** @return HasMany<PharmacyPrescriptionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PharmacyPrescriptionItem::class, 'prescription_id')->orderBy('line_number');
    }

    /** @return HasMany<PharmacyPrescriptionVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PharmacyPrescriptionVersion::class, 'prescription_id')->orderBy('version');
    }

    /** @return HasOne<PharmacyVerification, $this> */
    public function verification(): HasOne
    {
        return $this->hasOne(PharmacyVerification::class, 'prescription_id');
    }

    /** @return HasMany<PharmacyPreparation, $this> */
    public function preparations(): HasMany
    {
        return $this->hasMany(PharmacyPreparation::class, 'prescription_id')->orderBy('sequence');
    }

    /** @return HasMany<PharmacyHandover, $this> */
    public function handovers(): HasMany
    {
        return $this->hasMany(PharmacyHandover::class, 'prescription_id')->orderBy('sequence');
    }

    protected function casts(): array
    {
        return ['depot_version' => 'integer', 'version' => 'integer', 'ordered_at' => 'datetime'];
    }
}
