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
 * @property int $encounter_id
 * @property int $author_user_id
 * @property int|null $finalized_by_user_id
 * @property string $document_type
 * @property string $document_state
 * @property string $definition_version
 * @property int $version
 * @property array<string, string> $fields
 * @property Carbon|null $finalized_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 * @property-read User|null $finalizedBy
 */
class OutpatientClinicalDocument extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const DEFINITION_VERSION = 'OUTPATIENT_DOCUMENTATION_V1';

    public const TYPE_NURSING_ASSESSMENT = 'NURSING_ASSESSMENT';

    public const TYPE_MEDICAL_ASSESSMENT = 'MEDICAL_ASSESSMENT';

    public const STATE_DRAFT = 'DRAFT';

    public const STATE_FINAL = 'FINAL';

    protected $fillable = [
        'encounter_id', 'author_user_id', 'finalized_by_user_id', 'document_type',
        'document_state', 'definition_version', 'version', 'fields', 'finalized_at',
    ];

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

    /** @return HasMany<OutpatientClinicalDocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(OutpatientClinicalDocumentVersion::class);
    }

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'version' => 'integer',
            'finalized_at' => 'datetime',
        ];
    }
}
