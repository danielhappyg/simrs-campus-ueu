<?php

namespace App\Models;

use App\Support\Emergency\EmergencyImmutableEvidence;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $encounter_id
 * @property int $physician_user_id
 * @property int|null $prior_disposition_id
 * @property string $public_id
 * @property int $version
 * @property string $disposition_type
 * @property array<string, mixed> $payload
 * @property string|null $correction_reason
 * @property string|null $prior_disposition_digest
 * @property string $content_digest
 * @property Carbon $signed_at
 * @property Carbon $created_at
 * @property-read User $physician
 * @property-read Encounter $encounter
 * @property-read EmergencyDisposition|null $priorDisposition
 * @property-read EmergencyInpatientHandoff|null $handoff
 */
class EmergencyDisposition extends Model
{
    use EmergencyImmutableEvidence, HasPublicUlid, UsesSchemaQualifiedTable;

    public const TYPES = ['PULANG', 'DIRUJUK', 'RAWAT_INAP', 'MENINGGAL_DI_IGD', 'DOA'];

    public $timestamps = false;

    protected $guarded = ['id', 'public_id'];

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

    /** @return BelongsTo<EmergencyDisposition, $this> */
    public function priorDisposition(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prior_disposition_id');
    }

    /** @return HasOne<EmergencyInpatientHandoff, $this> */
    public function handoff(): HasOne
    {
        return $this->hasOne(EmergencyInpatientHandoff::class, 'disposition_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'payload' => 'array', 'signed_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
