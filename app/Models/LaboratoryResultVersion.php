<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $laboratory_order_id
 * @property int $laboratory_specimen_attempt_id
 * @property int $author_user_id
 * @property int|null $base_verified_version_id
 * @property int $version
 * @property string $state
 * @property array<int, array<string, mixed>> $results
 * @property string|null $correction_reason
 * @property string|null $base_verified_digest
 * @property string|null $prior_amendment_digest
 * @property string $content_digest
 * @property Carbon|null $verified_at
 * @property Carbon $created_at
 * @property-read LaboratoryOrder $order
 * @property-read LaboratorySpecimenAttempt $specimenAttempt
 * @property-read User $author
 * @property-read LaboratoryResultAcknowledgement|null $acknowledgement
 * @property-read LaboratoryCriticalCommunication|null $criticalCommunication
 */
class LaboratoryResultVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const DRAFT = 'DRAFT';

    public const VERIFIED = 'VERIFIED';

    public const AMENDED_VERIFIED = 'AMENDED_VERIFIED';

    public $timestamps = false;

    protected $fillable = ['laboratory_order_id', 'laboratory_specimen_attempt_id', 'author_user_id', 'base_verified_version_id', 'version', 'state', 'results', 'correction_reason', 'base_verified_digest', 'prior_amendment_digest', 'content_digest', 'verified_at', 'created_at'];

    /** @return BelongsTo<LaboratoryOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrder::class, 'laboratory_order_id');
    }

    /** @return BelongsTo<LaboratorySpecimenAttempt, $this> */
    public function specimenAttempt(): BelongsTo
    {
        return $this->belongsTo(LaboratorySpecimenAttempt::class, 'laboratory_specimen_attempt_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return HasOne<LaboratoryResultAcknowledgement, $this> */
    public function acknowledgement(): HasOne
    {
        return $this->hasOne(LaboratoryResultAcknowledgement::class);
    }

    /** @return HasOne<LaboratoryCriticalCommunication, $this> */
    public function criticalCommunication(): HasOne
    {
        return $this->hasOne(LaboratoryCriticalCommunication::class);
    }

    protected function casts(): array
    {
        return ['results' => 'array', 'version' => 'integer', 'verified_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
