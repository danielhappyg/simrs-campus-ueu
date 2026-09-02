<?php

namespace App\Models;

use App\Support\Emergency\EmergencyImmutableEvidence;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property int $emergency_clinical_document_id
 * @property int $author_user_id
 * @property int $version
 * @property string $state
 * @property array<string, mixed> $fields
 * @property string $content_digest
 * @property Carbon|null $finalized_at
 * @property Carbon $created_at
 * @property-read User $author
 * @property-read EmergencyClinicalDocument $document
 */
class EmergencyClinicalDocumentVersion extends Model
{
    use EmergencyImmutableEvidence, HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['emergency_clinical_document_id', 'author_user_id', 'version', 'state', 'fields', 'content_digest', 'finalized_at', 'created_at'];

    /** @return BelongsTo<EmergencyClinicalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(EmergencyClinicalDocument::class, 'emergency_clinical_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'fields' => 'array', 'finalized_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
