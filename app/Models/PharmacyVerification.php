<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $prescription_id
 * @property int $pharmacist_user_id
 * @property string $decision
 * @property string $manual_allergy_review
 * @property array<string, bool> $checklist
 * @property list<array<string, mixed>> $item_decisions
 * @property string|null $reason_code
 * @property string|null $note
 * @property string $prescription_fingerprint
 * @property string $content_digest
 * @property Carbon $verified_at
 * @property Carbon $created_at
 */
class PharmacyVerification extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    public const VERIFIED = 'VERIFIED';

    public const REFUSED = 'REFUSED';

    protected $fillable = ['prescription_id', 'pharmacist_user_id', 'decision', 'manual_allergy_review', 'checklist', 'item_decisions', 'reason_code', 'note', 'prescription_fingerprint', 'content_digest', 'verified_at', 'created_at'];

    /** @return BelongsTo<PharmacyPrescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(PharmacyPrescription::class, 'prescription_id');
    }

    /** @return BelongsTo<User, $this> */
    public function pharmacist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pharmacist_user_id');
    }

    protected function casts(): array
    {
        return ['checklist' => 'array', 'item_decisions' => 'array', 'verified_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
