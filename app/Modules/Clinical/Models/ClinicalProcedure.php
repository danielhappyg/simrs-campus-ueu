<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalProcedureStatus;
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

/**
 * A clinician-authored record of a procedure that was actually performed.
 * It deliberately contains no ICD assignment; coding remains an RMIK action.
 *
 * @property int $id
 * @property string $public_id
 * @property int $session_id
 * @property int $patient_id
 * @property int $encounter_id
 * @property int $encounter_closure_id
 * @property int|null $reason_condition_id
 * @property int|null $based_on_service_request_id
 * @property int $recorder_user_id
 * @property int $recorder_assignment_id
 * @property int $sequence_number
 * @property ClinicalProcedureStatus $status
 * @property string $authored_text
 * @property CarbonImmutable $performed_start_at
 * @property CarbonImmutable|null $performed_end_at
 * @property string $performer_text
 * @property string|null $body_site_text
 * @property string|null $outcome_text
 * @property string|null $note
 * @property string $content_hash
 * @property CarbonImmutable $recorded_at
 */
class ClinicalProcedure extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'public_id',
        'session_id',
        'patient_id',
        'encounter_id',
        'encounter_closure_id',
        'reason_condition_id',
        'based_on_service_request_id',
        'recorder_user_id',
        'recorder_assignment_id',
        'sequence_number',
        'status',
        'authored_text',
        'performed_start_at',
        'performed_end_at',
        'performer_text',
        'body_site_text',
        'outcome_text',
        'note',
        'content_hash',
        'recorded_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $procedure): void {
            $closure = EncounterClosure::query()->find($procedure->encounter_closure_id);
            $condition = $procedure->reason_condition_id === null
                ? null
                : ClinicalCondition::query()->with('sourceEntryVersion')->find($procedure->reason_condition_id);
            $serviceRequest = $procedure->based_on_service_request_id === null
                ? null
                : ServiceRequest::query()->find($procedure->based_on_service_request_id);
            $documented = null;
            $documentedProcedures = data_get($closure?->content, 'procedureDocumentation.procedures', []);

            if (is_array($documentedProcedures)) {
                foreach ($documentedProcedures as $candidate) {
                    if (is_array($candidate) && ($candidate['publicId'] ?? null) === $procedure->public_id) {
                        $documented = $candidate;
                        break;
                    }
                }
            }

            if (! $closure
                || $procedure->session_id !== $closure->session_id
                || $procedure->patient_id !== $closure->patient_id
                || $procedure->encounter_id !== $closure->encounter_id
                || $procedure->recorder_user_id !== $closure->author_user_id
                || $procedure->recorder_assignment_id !== $closure->author_assignment_id
                || $procedure->sequence_number < 1
                || $procedure->performed_end_at?->isBefore($procedure->performed_start_at)
                || ($procedure->reason_condition_id !== null && ! $condition)
                || ($condition && ($condition->encounter_id !== $closure->encounter_id
                    || $condition->patient_id !== $closure->patient_id
                    || $condition->sourceEntryVersion->public_id !== data_get($closure->content, 'sourceSnapshot.medical.publicId')))
                || ($procedure->based_on_service_request_id !== null && ! $serviceRequest)
                || ($serviceRequest && ($serviceRequest->encounter_id !== $closure->encounter_id
                    || $serviceRequest->patient_id !== $closure->patient_id))
                || ! is_array($documented)
                || ! hash_equals(hash('sha256', CanonicalJson::encode($procedure->integrityPayload())), $procedure->content_hash)
                || ! hash_equals((string) ($documented['contentHash'] ?? ''), $procedure->content_hash)
                || CanonicalJson::encode($procedure->integrityPayload()) !== CanonicalJson::encode(
                    $procedure->documentedIntegrityPayload($documented),
                )) {
                throw new DomainException('A performed procedure must preserve its exact closure, clinical source, performer, timing, and integrity hash.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Performed procedure records are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Performed procedure records are append-only.');
        });
    }

    /** @return array<string, mixed> */
    public function integrityPayload(): array
    {
        return [
            'publicId' => $this->public_id,
            'sequenceNumber' => $this->sequence_number,
            'status' => $this->status->value,
            'authoredText' => $this->authored_text,
            'performedStartAt' => $this->performed_start_at->toIso8601String(),
            'performedEndAt' => $this->performed_end_at?->toIso8601String(),
            'performerText' => $this->performer_text,
            'bodySiteText' => $this->body_site_text,
            'outcomeText' => $this->outcome_text,
            'note' => $this->note,
            'reasonConditionPublicId' => $this->reasonCondition?->public_id,
            'basedOnServiceRequestPublicId' => $this->basedOnServiceRequest?->public_id,
        ];
    }

    /**
     * @param  array<mixed>  $documented
     * @return array<string, mixed>
     */
    private function documentedIntegrityPayload(array $documented): array
    {
        return [
            'publicId' => $documented['publicId'] ?? null,
            'sequenceNumber' => $documented['sequenceNumber'] ?? null,
            'status' => $documented['status'] ?? null,
            'authoredText' => $documented['authoredText'] ?? null,
            'performedStartAt' => $documented['performedStartAt'] ?? null,
            'performedEndAt' => $documented['performedEndAt'] ?? null,
            'performerText' => $documented['performerText'] ?? null,
            'bodySiteText' => $documented['bodySiteText'] ?? null,
            'outcomeText' => $documented['outcomeText'] ?? null,
            'note' => $documented['note'] ?? null,
            'reasonConditionPublicId' => $documented['reasonConditionPublicId'] ?? null,
            'basedOnServiceRequestPublicId' => $documented['basedOnServiceRequestPublicId'] ?? null,
        ];
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

    /** @return BelongsTo<EncounterClosure, $this> */
    public function closure(): BelongsTo
    {
        return $this->belongsTo(EncounterClosure::class, 'encounter_closure_id');
    }

    /** @return BelongsTo<ClinicalCondition, $this> */
    public function reasonCondition(): BelongsTo
    {
        return $this->belongsTo(ClinicalCondition::class, 'reason_condition_id');
    }

    /** @return BelongsTo<ServiceRequest, $this> */
    public function basedOnServiceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'based_on_service_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorder_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function recorderAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'recorder_assignment_id');
    }

    protected function casts(): array
    {
        return [
            'status' => ClinicalProcedureStatus::class,
            'performed_start_at' => 'immutable_datetime',
            'performed_end_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
