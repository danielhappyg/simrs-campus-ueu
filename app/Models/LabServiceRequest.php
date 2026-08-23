<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Database\Factories\LabServiceRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $encounter_id
 * @property int $requested_by_user_id
 * @property string $test_code
 * @property string $test_label
 * @property string|null $clinical_question
 * @property string $status
 * @property Carbon $requested_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LabServiceRequest extends Model
{
    /** @use HasFactory<LabServiceRequestFactory> */
    use HasFactory, HasPublicUlid, UsesSchemaQualifiedTable;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_CANCELLED = 'CANCELLED';

    /**
     * @var list<string>
     */
    public const STATUS_VALUES = [
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'encounter_id',
        'requested_by_user_id',
        'test_code',
        'test_label',
        'clinical_question',
        'status',
        'requested_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
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
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return HasOne<LabDiagnosticResult, $this>
     */
    public function result(): HasOne
    {
        return $this->hasOne(LabDiagnosticResult::class);
    }

    /**
     * @param  Builder<LabServiceRequest>  $query
     * @return Builder<LabServiceRequest>
     */
    public function scopeSyntheticOnly(Builder $query): Builder
    {
        return $query->whereHas('encounter.patient', fn (Builder $patientQuery): Builder => $patientQuery->where('is_synthetic', true));
    }

    /**
     * Route-bound lab orders must belong to a synthetic patient encounter.
     *
     * @param  mixed  $query
     * @param  mixed  $value
     * @return mixed
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->whereHas('encounter.patient', fn (Builder $patientQuery): Builder => $patientQuery->where('is_synthetic', true));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
        ];
    }
}
