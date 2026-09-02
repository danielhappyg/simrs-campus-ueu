<?php

namespace App\Models;

use App\Support\Inpatient\InpatientDocumentationMutationScope;
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
 * @property int $author_user_id
 * @property int|null $finalized_by_user_id
 * @property string $document_type
 * @property Carbon $service_date
 * @property string $document_state
 * @property string $definition_version
 * @property int $version
 * @property array<string, string> $fields
 * @property Carbon|null $finalized_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $author
 */
class InpatientClinicalDocument extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const DEFINITION_VERSION = 'INPATIENT_LONGITUDINAL_DOCUMENTATION_V1';

    public const TYPE_NURSING_DAILY = 'NURSING_DAILY';

    public const TYPE_MEDICAL_DAILY = 'MEDICAL_DAILY';

    /** @var list<string> */
    public const TYPES = [self::TYPE_NURSING_DAILY, self::TYPE_MEDICAL_DAILY];

    public const STATE_DRAFT = 'DRAFT';

    public const STATE_FINAL = 'FINAL';

    protected $fillable = [
        'encounter_id', 'author_user_id', 'finalized_by_user_id', 'document_type',
        'service_date', 'document_state', 'definition_version', 'version', 'fields', 'finalized_at',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $document): void {
            InpatientDocumentationMutationScope::assertActive();
            if ($document->version !== 1 || $document->document_state !== self::STATE_DRAFT) {
                throw new LogicException('New inpatient documents must start as Draft version 1.');
            }
        });
        static::updating(static function (self $document): void {
            InpatientDocumentationMutationScope::assertActive();
            if ($document->isDirty(['public_id', 'encounter_id', 'author_user_id', 'document_type', 'service_date', 'definition_version'])) {
                throw new LogicException('Inpatient document identity is immutable.');
            }
            if ($document->getOriginal('document_state') === self::STATE_FINAL) {
                throw new LogicException('Final inpatient documents are immutable.');
            }
            if ($document->version !== (int) $document->getOriginal('version') + 1) {
                throw new LogicException('Inpatient document updates must append exactly one version.');
            }
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient documents cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    /** @return HasMany<InpatientClinicalDocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(InpatientClinicalDocumentVersion::class, 'inpatient_clinical_document_id');
    }

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'version' => 'integer',
            'fields' => 'array',
            'finalized_at' => 'datetime',
        ];
    }
}
