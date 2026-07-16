<?php

namespace App\Modules\Audit\Models;

use App\Models\User;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * @property string $id
 * @property string $action
 * @property string $resource_type
 * @property string|null $resource_id
 * @property string $outcome
 * @property CarbonImmutable $recorded_at
 * @property array<string, mixed>|null $metadata
 * @property-read User|null $actor
 * @property-read Assignment|null $assignment
 */
class AuditEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'recorded_at',
        'actor_user_id',
        'assignment_id',
        'session_id',
        'patient_id',
        'encounter_id',
        'action',
        'resource_type',
        'resource_id',
        'resource_version',
        'outcome',
        'reason',
        'request_correlation_id',
        'ip_hash',
        'user_agent',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->id ??= (string) Str::ulid();
            $event->recorded_at ??= now();
        });

        static::updating(function (): never {
            throw new LogicException('Audit events are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit events are append-only and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return BelongsTo<SimulationSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SimulationSession::class);
    }

    /**
     * @return BelongsTo<SyntheticPatient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(SyntheticPatient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
