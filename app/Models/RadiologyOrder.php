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
 * @property int $encounter_id
 * @property int $master_id
 * @property int $ordered_by_user_id
 * @property int $master_version
 * @property string $master_version_public_id
 * @property string $master_content_digest
 * @property string $master_code
 * @property string $master_display_name
 * @property string|null $master_preparation_instruction
 * @property string $care_setting
 * @property string $encounter_status_snapshot
 * @property string $encounter_number_snapshot
 * @property string $care_location_label_snapshot
 * @property string $clinical_indication
 * @property string $status
 * @property int $version
 * @property Carbon $ordered_at
 * @property Carbon $created_at
 */
class RadiologyOrder extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ORDERED = 'ORDERED';

    public const PERFORMED = 'PERFORMED';

    public const REPORTED_VERIFIED = 'REPORTED_VERIFIED';

    public const CANCELLED = 'CANCELLED';

    protected $fillable = ['encounter_id', 'master_id', 'ordered_by_user_id', 'master_version', 'master_version_public_id', 'master_content_digest', 'master_code', 'master_display_name', 'master_preparation_instruction', 'care_setting', 'encounter_status_snapshot', 'encounter_number_snapshot', 'care_location_label_snapshot', 'clinical_indication', 'status', 'version', 'ordered_at'];

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function orderingPhysician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by_user_id');
    }

    /** @return BelongsTo<RadiologyExaminationMaster, $this> */
    public function master(): BelongsTo
    {
        return $this->belongsTo(RadiologyExaminationMaster::class, 'master_id');
    }

    /** @return HasOne<RadiologyPerformance, $this> */
    public function performance(): HasOne
    {
        return $this->hasOne(RadiologyPerformance::class);
    }

    /** @return HasOne<RadiologyOrderCancellation, $this> */
    public function cancellation(): HasOne
    {
        return $this->hasOne(RadiologyOrderCancellation::class);
    }

    /** @return HasMany<RadiologyReportVersion, $this> */
    public function reportVersions(): HasMany
    {
        return $this->hasMany(RadiologyReportVersion::class);
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'master_version' => 'integer', 'ordered_at' => 'datetime'];
    }
}
