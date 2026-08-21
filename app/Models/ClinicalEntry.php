<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use Database\Factories\ClinicalEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $encounter_id
 * @property int $author_user_id
 * @property string $entry_type
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ClinicalEntry extends Model
{
    /** @use HasFactory<ClinicalEntryFactory> */
    use HasFactory, HasPublicUlid;

    public const TYPE_NURSING_INTAKE = 'NURSING_INTAKE';

    public const TYPE_MEDICAL_ASSESSMENT = 'MEDICAL_ASSESSMENT';

    /**
     * @var list<string>
     */
    public const TYPE_VALUES = [
        self::TYPE_NURSING_INTAKE,
        self::TYPE_MEDICAL_ASSESSMENT,
    ];

    protected $fillable = [
        'encounter_id',
        'author_user_id',
        'entry_type',
        'body',
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
