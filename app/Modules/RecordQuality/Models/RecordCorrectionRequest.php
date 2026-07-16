<?php

namespace App\Modules\RecordQuality\Models;

use App\Models\User;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\RecordQuality\Enums\RecordCorrectionStatus;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property string $request_key
 * @property int $record_quality_finding_id
 * @property int $session_id
 * @property int $patient_id
 * @property int $encounter_id
 * @property int $requested_by_user_id
 * @property int $requested_by_assignment_id
 * @property int $responsible_assignment_id
 * @property RecordCorrectionStatus $status
 * @property string $reason
 * @property string $requested_source_hash
 * @property CarbonImmutable $requested_at
 * @property int|null $response_closure_id
 * @property CarbonImmutable|null $responded_at
 * @property int|null $resolved_by_assignment_id
 * @property string|null $resolution_note
 * @property CarbonImmutable|null $resolved_at
 */
class RecordCorrectionRequest extends Model
{
    use HasPublicUlid;

    private bool $lifecycleTransitionInProgress = false;

    protected $fillable = [
        'request_key',
        'record_quality_finding_id',
        'session_id',
        'patient_id',
        'encounter_id',
        'requested_by_user_id',
        'requested_by_assignment_id',
        'responsible_assignment_id',
        'status',
        'reason',
        'requested_source_hash',
        'requested_at',
        'response_closure_id',
        'responded_at',
        'resolved_by_assignment_id',
        'resolution_note',
        'resolved_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            $finding = RecordQualityFinding::query()->with('review')->find($request->record_quality_finding_id);
            $requester = Assignment::query()->active()->find($request->requested_by_assignment_id);
            $responsible = Assignment::query()->active()->find($request->responsible_assignment_id);

            if (! $finding
                || ! $requester
                || ! $responsible
                || $request->requested_by_user_id !== $requester->user_id
                || $request->session_id !== $finding->review->session_id
                || $request->patient_id !== $finding->review->patient_id
                || $request->encounter_id !== $finding->review->encounter_id
                || $requester->session_id !== $request->session_id
                || $requester->patient_id !== $request->patient_id
                || $requester->encounter_id !== $request->encounter_id
                || ! $requester->hasCapability(Capability::RecordReview)
                || $responsible->getKey() !== $finding->responsible_assignment_id
                || ! hash_equals($finding->affected_content_hash, $request->requested_source_hash)) {
                throw new DomainException('A correction request must match its finding, RMIK requester, responsible author, and exact source hash.');
            }

            if (! $request->exists && $request->status !== RecordCorrectionStatus::Open) {
                throw new DomainException('A new correction request must begin open.');
            }

            if ($request->exists && $request->isDirty([
                'request_key',
                'record_quality_finding_id',
                'session_id',
                'patient_id',
                'encounter_id',
                'requested_by_user_id',
                'requested_by_assignment_id',
                'responsible_assignment_id',
                'reason',
                'requested_source_hash',
                'requested_at',
            ])) {
                throw new DomainException('Correction request content and provenance are immutable.');
            }

            if ($request->exists
                && $request->isDirty([
                    'status',
                    'response_closure_id',
                    'responded_at',
                    'resolved_by_assignment_id',
                    'resolution_note',
                    'resolved_at',
                ])
                && ! $request->lifecycleTransitionInProgress) {
                throw new DomainException('Correction request lifecycle changes must use the workflow service.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Correction requests are append-only.');
        });
    }

    /** @internal */
    public function persistResponse(EncounterClosure $closure): void
    {
        if (! in_array($this->status, [RecordCorrectionStatus::Open, RecordCorrectionStatus::ResponseSubmitted], true)
            || $closure->encounter_id !== $this->encounter_id
            || $closure->author_assignment_id !== $this->responsible_assignment_id
            || $closure->status->value !== 'SUBMITTED') {
            throw new DomainException('Only an exact submitted successor closure can answer this correction request.');
        }

        $this->lifecycleTransitionInProgress = true;

        try {
            $this->status = RecordCorrectionStatus::ResponseSubmitted;
            $this->response_closure_id = $closure->getKey();
            $this->responded_at = CarbonImmutable::now();
            $this->save();
        } finally {
            $this->lifecycleTransitionInProgress = false;
        }
    }

    /** @internal */
    public function persistPendingVerification(EncounterClosure $closure): void
    {
        if ($this->status !== RecordCorrectionStatus::ResponseSubmitted
            || $this->response_closure_id !== $closure->getKey()) {
            throw new DomainException('Only the linked correction response can proceed to RMIK verification.');
        }

        $this->lifecycleTransitionInProgress = true;

        try {
            $this->status = RecordCorrectionStatus::CorrectedPendingVerification;
            $this->save();
        } finally {
            $this->lifecycleTransitionInProgress = false;
        }
    }

    /** @internal */
    public function persistResolved(Assignment $resolver, string $resolutionNote): void
    {
        if ($this->status !== RecordCorrectionStatus::CorrectedPendingVerification
            || $resolver->getKey() !== $this->requested_by_assignment_id
            || ! $resolver->hasCapability(Capability::RecordReview)) {
            throw new DomainException('Only the requesting RMIK assignment can resolve a verified correction.');
        }

        $this->lifecycleTransitionInProgress = true;

        try {
            $this->status = RecordCorrectionStatus::Resolved;
            $this->resolved_by_assignment_id = $resolver->getKey();
            $this->resolution_note = $resolutionNote;
            $this->resolved_at = CarbonImmutable::now();
            $this->save();
        } finally {
            $this->lifecycleTransitionInProgress = false;
        }
    }

    /** @return BelongsTo<RecordQualityFinding, $this> */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(RecordQualityFinding::class, 'record_quality_finding_id');
    }

    /** @return BelongsTo<SimulationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SimulationSession::class);
    }

    /** @return BelongsTo<SyntheticPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(SyntheticPatient::class);
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function requestedByAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'requested_by_assignment_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function responsibleAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'responsible_assignment_id');
    }

    /** @return BelongsTo<EncounterClosure, $this> */
    public function responseClosure(): BelongsTo
    {
        return $this->belongsTo(EncounterClosure::class, 'response_closure_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function resolvedByAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'resolved_by_assignment_id');
    }

    protected function casts(): array
    {
        return [
            'status' => RecordCorrectionStatus::class,
            'requested_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
