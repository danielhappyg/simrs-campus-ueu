<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $public_id
 * @property int $version
 * @property string $document_state
 * @property string $definition_version
 * @property array<string, mixed> $fields
 * @property Carbon|null $created_at
 * @property Carbon|null $finalized_at
 * @property-read User|null $actor
 */
class OutpatientClinicalDocumentVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'outpatient_clinical_document_id', 'actor_user_id', 'version',
        'document_state', 'definition_version', 'fields', 'finalized_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Outpatient clinical document versions are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Outpatient clinical document versions cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<OutpatientClinicalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(OutpatientClinicalDocument::class, 'outpatient_clinical_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
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
