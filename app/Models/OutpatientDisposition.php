<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property array<string,string> $payload
 * @property Carbon $signed_at
 * @property int $version
 * @property int $physician_user_id
 * @property int $medical_document_version_id
 */
final class OutpatientDisposition extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    public const TYPES = ['KONTROL_ULANG', 'SEMBUH', 'RAWAT_INAP'];

    protected $fillable = ['encounter_id', 'physician_user_id', 'medical_document_version_id', 'prior_disposition_id', 'version', 'disposition_type', 'payload', 'correction_reason', 'prior_disposition_digest', 'content_digest', 'signed_at', 'created_at'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Signed outpatient dispositions are immutable.'));
        self::deleting(fn () => throw new LogicException('Signed outpatient dispositions cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'version' => 'integer', 'signed_at' => 'datetime'];
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function physician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'physician_user_id');
    }

    /** @return BelongsTo<OutpatientClinicalDocumentVersion, $this> */
    public function medicalDocumentVersion(): BelongsTo
    {
        return $this->belongsTo(OutpatientClinicalDocumentVersion::class);
    }

    /** @return HasOne<OutpatientInpatientHandoff, $this> */
    public function handoff(): HasOne
    {
        return $this->hasOne(OutpatientInpatientHandoff::class, 'disposition_id');
    }
}
