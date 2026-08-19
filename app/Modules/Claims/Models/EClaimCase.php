<?php

namespace App\Modules\Claims\Models;

use App\Modules\Claims\Enums\EClaimCaseStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Support\CanonicalJson;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $session_id
 * @property int $patient_id
 * @property int $encounter_id
 * @property int $created_by_assignment_id
 * @property EClaimCaseStatus $status
 * @property string $compatibility_profile
 * @property string $synthetic_sep
 * @property array<string, mixed> $source_snapshot
 * @property string $source_snapshot_hash
 * @property string|null $grouper_code
 * @property string|null $grouper_description
 * @property int|null $simulated_tariff
 * @property CarbonImmutable|null $data_staged_at
 * @property CarbonImmutable|null $grouped_at
 * @property CarbonImmutable|null $finalized_at
 * @property CarbonImmutable|null $submission_simulated_at
 */
class EClaimCase extends Model
{
    use HasPublicUlid;

    private bool $transitionInProgress = false;

    protected $fillable = [
        'session_id',
        'patient_id',
        'encounter_id',
        'created_by_user_id',
        'created_by_assignment_id',
        'status',
        'compatibility_profile',
        'synthetic_sep',
        'source_snapshot',
        'source_snapshot_hash',
        'grouper_code',
        'grouper_description',
        'simulated_tariff',
        'data_staged_at',
        'grouped_at',
        'finalized_at',
        'submission_simulated_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $case): void {
            $encounter = Encounter::query()->with('patient')->find($case->encounter_id);
            $assignment = Assignment::query()->active()->find($case->created_by_assignment_id);

            if (! $encounter
                || ! $assignment
                || ! $encounter->patient->synthetic_flag
                || $case->session_id !== $encounter->session_id
                || $case->patient_id !== $encounter->patient_id
                || $case->created_by_user_id !== $assignment->user_id
                || $assignment->session_id !== $encounter->session_id
                || $assignment->patient_id !== $encounter->patient_id
                || $assignment->encounter_id !== $encounter->getKey()
                || $case->status !== EClaimCaseStatus::ClaimCreated
                || $case->compatibility_profile !== config('eclaim.compatibility_profile')
                || ! str_starts_with($case->synthetic_sep, 'SIM-SEP-')
                || data_get($case->source_snapshot, 'boundary.transportState') !== 'NOT_SENT'
                || data_get($case->source_snapshot, 'boundary.externalEndpoint') !== null
                || ! hash_equals(
                    hash('sha256', CanonicalJson::encode($case->source_snapshot)),
                    $case->source_snapshot_hash,
                )) {
                throw new DomainException('An E-Klaim simulation case must preserve its synthetic source and exact assignment context.');
            }
        });

        static::updating(function (self $case): void {
            if (! $case->transitionInProgress) {
                throw new DomainException('E-Klaim simulation cases change only through the claim workflow service.');
            }

            $allowed = [
                'status',
                'grouper_code',
                'grouper_description',
                'simulated_tariff',
                'data_staged_at',
                'grouped_at',
                'finalized_at',
                'submission_simulated_at',
                'updated_at',
            ];
            $dirtyOutsideTransition = collect(array_keys($case->getDirty()))
                ->reject(fn (string $field): bool => in_array($field, $allowed, true))
                ->isNotEmpty();

            if ($dirtyOutsideTransition) {
                throw new DomainException('E-Klaim source snapshots and identity fields are immutable.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('E-Klaim simulation cases are append-only evidence.');
        });
    }

    /** @param array<string, mixed> $details */
    public function persistTransition(EClaimCaseStatus $target, CarbonImmutable $at, array $details = []): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new DomainException("E-Klaim case cannot transition from {$this->status->value} to {$target->value}.");
        }

        $this->transitionInProgress = true;

        try {
            $attributes = ['status' => $target];

            if ($target === EClaimCaseStatus::DataStaged) {
                $attributes['data_staged_at'] = $at;
            }

            if ($target === EClaimCaseStatus::Grouped) {
                $attributes['grouped_at'] = $at;
                $attributes['grouper_code'] = $details['grouper_code'] ?? null;
                $attributes['grouper_description'] = $details['grouper_description'] ?? null;
                $attributes['simulated_tariff'] = $details['simulated_tariff'] ?? null;
            }

            if ($target === EClaimCaseStatus::Finalized) {
                $attributes['finalized_at'] = $at;
            }

            if ($target === EClaimCaseStatus::SubmissionSimulated) {
                $attributes['submission_simulated_at'] = $at;
            }

            $this->forceFill($attributes)->save();
        } finally {
            $this->transitionInProgress = false;
        }
    }

    /** @return BelongsTo<SimulationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SimulationSession::class);
    }

    /** @return BelongsTo<SyntheticPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(SyntheticPatient::class, 'patient_id');
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<Assignment, $this> */
    public function createdByAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'created_by_assignment_id');
    }

    /** @return HasMany<EClaimEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(EClaimEvent::class)->orderBy('sequence_number');
    }

    protected function casts(): array
    {
        return [
            'status' => EClaimCaseStatus::class,
            'source_snapshot' => 'array',
            'simulated_tariff' => 'integer',
            'data_staged_at' => 'immutable_datetime',
            'grouped_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'submission_simulated_at' => 'immutable_datetime',
        ];
    }
}
