<?php

namespace App\Models;

use App\Support\Inpatient\InpatientDocumentationMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $inpatient_clinical_document_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $document_type
 * @property string $document_state
 * @property string $definition_version
 * @property array<string, string> $fields
 * @property string $encounter_public_id
 * @property string $care_setting
 * @property Carbon $service_date
 * @property string $ward_public_id
 * @property string $ward_code
 * @property string $ward_display_name
 * @property string $bed_public_id
 * @property string $bed_code
 * @property string $bed_display_name
 * @property string $room_label
 * @property string $service_class
 * @property string $encounter_status
 * @property Carbon|null $finalized_at
 * @property Carbon|null $created_at
 * @property-read User $actor
 */
class InpatientClinicalDocumentVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'inpatient_clinical_document_id', 'actor_user_id', 'version', 'document_type', 'document_state',
        'definition_version', 'fields', 'encounter_public_id', 'care_setting', 'service_date',
        'ward_public_id', 'ward_code', 'ward_display_name', 'bed_public_id', 'bed_code',
        'bed_display_name', 'room_label', 'service_class', 'encounter_status', 'finalized_at',
    ];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientDocumentationMutationScope::assertActive());
        static::updating(static function (): never {
            throw new LogicException('Inpatient clinical document versions are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient clinical document versions cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<InpatientClinicalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(InpatientClinicalDocument::class, 'inpatient_clinical_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'fields' => 'array',
            'service_date' => 'date',
            'finalized_at' => 'datetime',
        ];
    }
}
