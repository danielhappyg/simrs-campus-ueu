<?php

namespace App\Models;

use App\Support\Inpatient\InpatientDischargeCodingSourceMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $encounter_id
 * @property int $assigned_physician_user_id
 * @property int|null $finalized_by_user_id
 * @property string $source_state
 * @property string $definition_version
 * @property int $version
 * @property string|null $principal_diagnosis_statement
 * @property array<int, string> $secondary_diagnosis_statements
 * @property string $procedure_attestation
 * @property array<int, string> $performed_procedure_statements
 * @property Carbon|null $finalized_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InpatientDischargeCodingSource extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const DEFINITION_VERSION = 'INPATIENT_DISCHARGE_CODING_SOURCE_V1';

    public const STATE_DRAFT = 'DRAFT';

    public const STATE_FINAL = 'FINAL';

    public const ATTESTATION_NONE = 'NO_PROCEDURE_RECORDED';

    public const ATTESTATION_RECORDED = 'PROCEDURES_RECORDED';

    protected $fillable = ['encounter_id', 'assigned_physician_user_id', 'finalized_by_user_id', 'source_state', 'definition_version', 'version', 'principal_diagnosis_statement', 'secondary_diagnosis_statements', 'procedure_attestation', 'performed_procedure_statements', 'finalized_at'];

    protected static function booted(): void
    {
        static::creating(function (self $source): void {
            InpatientDischargeCodingSourceMutationScope::assertActive();
            if ($source->version !== 1 || $source->source_state !== self::STATE_DRAFT) {
                throw new LogicException('New inpatient discharge coding sources must start as Draft version 1.');
            }
        });
        static::updating(function (self $source): void {
            InpatientDischargeCodingSourceMutationScope::assertActive();
            if ($source->isDirty(['public_id', 'encounter_id', 'assigned_physician_user_id', 'definition_version'])) {
                throw new LogicException('Inpatient discharge coding source identity is immutable.');
            }
            if ($source->getOriginal('source_state') === self::STATE_FINAL) {
                throw new LogicException('Final inpatient discharge coding sources are immutable.');
            }
            if ($source->version !== (int) $source->getOriginal('version') + 1) {
                throw new LogicException('Inpatient discharge coding source updates must append exactly one version.');
            }
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient discharge coding sources cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedPhysician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_physician_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    /** @return HasMany<InpatientDischargeCodingSourceVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(InpatientDischargeCodingSourceVersion::class);
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'secondary_diagnosis_statements' => 'array', 'performed_procedure_statements' => 'array', 'finalized_at' => 'datetime'];
    }
}
