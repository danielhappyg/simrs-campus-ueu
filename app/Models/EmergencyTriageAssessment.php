<?php

namespace App\Models;

use App\Support\Emergency\EmergencyImmutableEvidence;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $encounter_id
 * @property int $vocabulary_version_id
 * @property int $assessor_user_id
 * @property int|null $prior_assessment_id
 * @property int $assessment_number
 * @property string $assessment_type
 * @property string $category_code
 * @property string $category_label_snapshot
 * @property int $category_rank_snapshot
 * @property string $category_cue_snapshot
 * @property string $category_colour_token_snapshot
 * @property string $presenting_concern
 * @property string $clinical_basis
 * @property string $arrival_condition
 * @property string|null $late_entry_reason
 * @property string|null $reassessment_reason
 * @property array<string, string> $abcde_observations
 * @property string $consciousness
 * @property int|null $respiratory_rate
 * @property int|null $pulse
 * @property int|null $systolic_bp
 * @property int|null $diastolic_bp
 * @property int|null $oxygen_saturation
 * @property numeric-string|null $temperature_celsius
 * @property int|null $pain_score
 * @property numeric-string|null $weight_kg
 * @property array<int, string> $unobtainable_fields
 * @property array<string, string> $unobtainable_reasons
 * @property bool $trauma_flag
 * @property string|null $trauma_note
 * @property bool $isolation_precaution_flag
 * @property string|null $isolation_precaution_note
 * @property string|null $handoff_note
 * @property string|null $prior_assessment_digest
 * @property string $content_digest
 * @property Carbon $observed_at
 * @property Carbon $recorded_at
 * @property Carbon $created_at
 * @property-read User $assessor
 * @property-read EmergencyTriageVocabularyVersion $vocabularyVersion
 * @property-read Encounter $encounter
 * @property-read EmergencyTriageAssessment|null $priorAssessment
 */
class EmergencyTriageAssessment extends Model
{
    use EmergencyImmutableEvidence, HasPublicUlid, UsesSchemaQualifiedTable;

    public const INITIAL = 'INITIAL';

    public const REASSESSMENT = 'REASSESSMENT';

    public const CATEGORIES = ['MERAH', 'KUNING', 'HIJAU', 'HITAM'];

    public const ABCDE_STATES = ['ASSESSED_NO_CONCERN', 'ASSESSED_CONCERN', 'NOT_ASSESSED'];

    public const CONSCIOUSNESS = ['ALERT', 'VOICE', 'PAIN', 'UNRESPONSIVE'];

    public $timestamps = false;

    protected $guarded = ['id', 'public_id'];

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<EmergencyTriageVocabularyVersion, $this> */
    public function vocabularyVersion(): BelongsTo
    {
        return $this->belongsTo(EmergencyTriageVocabularyVersion::class, 'vocabulary_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_user_id');
    }

    /** @return BelongsTo<EmergencyTriageAssessment, $this> */
    public function priorAssessment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prior_assessment_id');
    }

    protected function casts(): array
    {
        return [
            'assessment_number' => 'integer', 'category_rank_snapshot' => 'integer',
            'observed_at' => 'datetime', 'recorded_at' => 'datetime', 'created_at' => 'datetime',
            'abcde_observations' => 'array', 'unobtainable_fields' => 'array', 'unobtainable_reasons' => 'array',
            'respiratory_rate' => 'integer', 'pulse' => 'integer', 'systolic_bp' => 'integer', 'diastolic_bp' => 'integer',
            'oxygen_saturation' => 'integer', 'temperature_celsius' => 'decimal:1', 'pain_score' => 'integer', 'weight_kg' => 'decimal:1',
            'trauma_flag' => 'boolean', 'isolation_precaution_flag' => 'boolean',
        ];
    }
}
