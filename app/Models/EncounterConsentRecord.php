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
 * @property int $encounter_id
 * @property string $explainer_signature_png
 * @property string $patient_signature_png
 * @property string|null $explainer_name
 * @property string|null $patient_or_guardian_name
 * @property Carbon $signed_at
 * @property int|null $signed_by_user_id
 */
class EncounterConsentRecord extends Model
{
    use HasPublicUlid;
    use UsesSchemaQualifiedTable;

    protected $table = 'encounter_consents';

    protected $fillable = [
        'encounter_id',
        'explainer_signature_png',
        'patient_signature_png',
        'explainer_name',
        'patient_or_guardian_name',
        'signed_at',
        'signed_by_user_id',
    ];

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }
}
