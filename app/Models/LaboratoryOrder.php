<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Collection;
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
 * @property string $specimen_type_snapshot
 * @property string|null $collection_instruction_snapshot
 * @property array<int, array<string, mixed>> $components_snapshot
 * @property string $care_setting
 * @property string $encounter_status_snapshot
 * @property string $encounter_number_snapshot
 * @property string $care_location_label_snapshot
 * @property string $priority
 * @property string $clinical_question
 * @property string $status
 * @property int $version
 * @property Carbon $ordered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Encounter $encounter
 * @property-read LaboratoryExaminationMaster $master
 * @property-read User $orderingPhysician
 * @property-read LaboratoryOrderCancellation|null $cancellation
 * @property-read Collection<int, LaboratorySpecimenAttempt> $specimenAttempts
 * @property-read Collection<int, LaboratoryResultVersion> $resultVersions
 */
class LaboratoryOrder extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ORDERED = 'ORDERED';

    public const SPECIMEN_ACCEPTED = 'SPECIMEN_ACCEPTED';

    public const REPORTED_VERIFIED = 'REPORTED_VERIFIED';

    public const CANCELLED = 'CANCELLED';

    protected $fillable = [
        'encounter_id', 'master_id', 'ordered_by_user_id', 'master_version',
        'master_version_public_id', 'master_content_digest', 'master_code',
        'master_display_name', 'specimen_type_snapshot', 'collection_instruction_snapshot',
        'components_snapshot', 'care_setting', 'encounter_status_snapshot',
        'encounter_number_snapshot', 'care_location_label_snapshot', 'priority',
        'clinical_question', 'status', 'version', 'ordered_at',
    ];

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<LaboratoryExaminationMaster, $this> */
    public function master(): BelongsTo
    {
        return $this->belongsTo(LaboratoryExaminationMaster::class, 'master_id');
    }

    /** @return BelongsTo<User, $this> */
    public function orderingPhysician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by_user_id');
    }

    /** @return HasOne<LaboratoryOrderCancellation, $this> */
    public function cancellation(): HasOne
    {
        return $this->hasOne(LaboratoryOrderCancellation::class);
    }

    /** @return HasMany<LaboratorySpecimenAttempt, $this> */
    public function specimenAttempts(): HasMany
    {
        return $this->hasMany(LaboratorySpecimenAttempt::class);
    }

    /** @return HasMany<LaboratoryResultVersion, $this> */
    public function resultVersions(): HasMany
    {
        return $this->hasMany(LaboratoryResultVersion::class);
    }

    protected function casts(): array
    {
        return ['components_snapshot' => 'array', 'master_version' => 'integer', 'version' => 'integer', 'ordered_at' => 'datetime'];
    }
}
