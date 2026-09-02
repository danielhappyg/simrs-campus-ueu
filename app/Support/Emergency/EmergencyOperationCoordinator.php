<?php

namespace App\Support\Emergency;

use App\Models\EmergencyClinicalDocumentVersion;
use App\Models\EmergencyDisposition;
use App\Models\EmergencyDispositionCorrectionIntent;
use App\Models\EmergencyDispositionCorrectionIntentEvent;
use App\Models\EmergencyHandoffCompensation;
use App\Models\EmergencyInpatientHandoff;
use App\Models\EmergencyOperationReceipt;
use App\Models\EmergencyResultFollowUpAcceptance;
use App\Models\EmergencyResultFollowUpProposal;
use App\Models\EmergencyTriageAssessment;
use App\Models\EmergencyTriageVocabulary;
use App\Models\EmergencyTriageVocabularyVersion;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EmergencyOperationCoordinator
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(): void  $authorize
     * @param  callable(): Model  $mutation
     */
    public function perform(User $actor, string $operation, ?string $resource, string $key, array $payload, callable $authorize, callable $mutation): EmergencyMutationResult
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key) > 255) {
            throw new EmergencyDenied('invalid_idempotency_key', 'Kunci idempotensi tidak valid.');
        }
        $payloadDigest = EmergencyCanonicalJson::digest($payload);

        try {
            return DB::transaction(function () use ($actor, $operation, $resource, $key, $payloadDigest, $authorize, $mutation): EmergencyMutationResult {
                $authorize();
                $receipt = EmergencyOperationReceipt::query()->where('actor_user_id', $actor->id)->where('operation', $operation)->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($receipt) {
                    if (! hash_equals((string) $receipt->payload_digest, $payloadDigest)) {
                        throw new EmergencyDenied('idempotency_conflict', 'Kunci idempotensi telah digunakan untuk permintaan berbeda.');
                    }

                    return new EmergencyMutationResult($this->resolve($receipt), true);
                }

                $record = EmergencyMutationScope::run($mutation);
                if (! $record instanceof Model) {
                    throw new \LogicException('Emergency mutation must return a model.');
                }
                $recordPublicId = $record->getAttribute('public_id');
                if (! is_string($recordPublicId)) {
                    throw new \LogicException('Emergency mutation result must expose a public ID.');
                }
                [$type, $version, $state, $digest] = $this->describe($record);
                EmergencyMutationScope::run(fn () => EmergencyOperationReceipt::query()->create([
                    'actor_user_id' => $actor->id, 'operation' => $operation, 'idempotency_key' => $key,
                    'payload_digest' => $payloadDigest, 'result_type' => $type, 'result_public_id' => $recordPublicId,
                    'result_version' => $version, 'result_state' => $state, 'result_digest' => $digest,
                    'request_correlation_id' => request()->attributes->get('request_id'), 'completed_at' => now(),
                ]));
                $audit = $this->audit->record('emergency.workflow.mutate', 'emergency_record', $this->safeResource($resource), $actor, 'SUCCESS', null, ['operation' => $operation]);
                if ($audit === null) {
                    throw new EmergencyAuditUnavailable('Audit operasi IGD gagal.');
                }

                return new EmergencyMutationResult($record, false);
            }, 3);
        } catch (AuthorizationException $exception) {
            $this->deny($actor, $operation, $resource, 'role_not_permitted');
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            $this->deny($actor, $operation, $resource, 'resource_not_found');
            throw $exception;
        } catch (EmergencyDenied $exception) {
            $this->deny($actor, $operation, $resource, $exception->reason);
            throw $exception;
        }
    }

    /** @return array{string,int,string,string} */
    private function describe(Model $record): array
    {
        return match (true) {
            $record instanceof EmergencyTriageVocabulary => ['TRIAGE_VOCABULARY', $record->version, $record->state, $record->current_content_digest],
            $record instanceof EmergencyTriageVocabularyVersion => ['TRIAGE_VOCABULARY_VERSION', $record->version, $record->state, $record->content_digest],
            $record instanceof EmergencyTriageAssessment => ['TRIAGE_ASSESSMENT', $record->assessment_number, 'FINAL', $record->content_digest],
            $record instanceof EmergencyClinicalDocumentVersion => ['CLINICAL_DOCUMENT_VERSION', $record->version, $record->state, $record->content_digest],
            $record instanceof EmergencyResultFollowUpProposal => ['FOLLOW_UP_PROPOSAL', 1, $record->acceptance()->exists() ? 'ACCEPTED' : 'PROPOSED', $record->content_digest],
            $record instanceof EmergencyResultFollowUpAcceptance => ['FOLLOW_UP_ACCEPTANCE', 1, 'ACCEPTED', $record->content_digest],
            $record instanceof EmergencyDisposition => ['DISPOSITION', $record->version, 'SIGNED', $record->content_digest],
            $record instanceof EmergencyDispositionCorrectionIntent => ['CORRECTION_INTENT', 1, 'PENDING', $record->content_digest],
            $record instanceof EmergencyDispositionCorrectionIntentEvent => ['CORRECTION_INTENT_EVENT', 1, $record->event_type, $record->content_digest],
            $record instanceof EmergencyInpatientHandoff => ['INPATIENT_HANDOFF', 1, 'COMPLETED', $record->content_digest],
            $record instanceof EmergencyHandoffCompensation => ['HANDOFF_COMPENSATION', 1, 'COMPENSATED', $record->content_digest],
            default => throw new \LogicException('Unsupported emergency receipt result.'),
        };
    }

    private function resolve(EmergencyOperationReceipt $receipt): Model
    {
        $map = [
            'TRIAGE_VOCABULARY' => EmergencyTriageVocabulary::class,
            'TRIAGE_VOCABULARY_VERSION' => EmergencyTriageVocabularyVersion::class,
            'TRIAGE_ASSESSMENT' => EmergencyTriageAssessment::class,
            'CLINICAL_DOCUMENT_VERSION' => EmergencyClinicalDocumentVersion::class,
            'FOLLOW_UP_PROPOSAL' => EmergencyResultFollowUpProposal::class,
            'FOLLOW_UP_ACCEPTANCE' => EmergencyResultFollowUpAcceptance::class,
            'DISPOSITION' => EmergencyDisposition::class,
            'CORRECTION_INTENT' => EmergencyDispositionCorrectionIntent::class,
            'CORRECTION_INTENT_EVENT' => EmergencyDispositionCorrectionIntentEvent::class,
            'INPATIENT_HANDOFF' => EmergencyInpatientHandoff::class,
            'HANDOFF_COMPENSATION' => EmergencyHandoffCompensation::class,
        ];
        $class = $map[$receipt->result_type] ?? throw new EmergencyDenied('receipt_corrupt', 'Jenis bukti operasi IGD tidak dikenal.');
        $record = $class::query()->where('public_id', $receipt->result_public_id)->firstOrFail();
        [, $version, $state, $digest] = $this->describe($record);
        if ($version !== $receipt->result_version || $state !== $receipt->result_state || ! hash_equals($digest, (string) $receipt->result_digest)) {
            throw new EmergencyDenied('receipt_corrupt', 'Bukti operasi IGD tidak lagi cocok.');
        }

        return $record;
    }

    private function deny(User $actor, string $operation, ?string $resource, string $reason): void
    {
        if ($this->audit->record('emergency.workflow.mutate', 'emergency_record', $this->safeResource($resource), $actor, 'DENIED', $reason, ['operation' => $operation]) === null) {
            throw new EmergencyAuditUnavailable('Audit penolakan IGD gagal.');
        }
    }

    private function safeResource(?string $resource): ?string
    {
        return is_string($resource) && Str::isUlid($resource) ? $resource : null;
    }
}
