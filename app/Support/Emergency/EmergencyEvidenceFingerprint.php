<?php

namespace App\Support\Emergency;

use App\Models\EmergencyClinicalDocumentVersion;
use App\Models\EmergencyDisposition;
use App\Models\EmergencyDispositionCorrectionIntent;
use App\Models\EmergencyHandoffCompensation;
use App\Models\EmergencyInpatientHandoff;
use App\Models\EmergencyResultFollowUpAcceptance;
use App\Models\EmergencyResultFollowUpProposal;
use App\Models\EmergencyTriageAssessment;
use App\Models\EmergencyTriageVocabularyVersion;
use App\Models\Encounter;
use App\Models\EncounterCancellation;
use App\Models\InpatientLocationEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class EmergencyEvidenceFingerprint
{
    private const ASSESSMENT_FIELDS = [
        'encounter_id', 'vocabulary_version_id', 'assessor_user_id', 'prior_assessment_id',
        'assessment_number', 'assessment_type', 'category_code', 'category_label_snapshot',
        'category_rank_snapshot', 'category_cue_snapshot', 'category_colour_token_snapshot',
        'observed_at', 'recorded_at', 'late_entry_reason', 'presenting_concern', 'clinical_basis',
        'arrival_condition', 'abcde_observations', 'consciousness', 'respiratory_rate', 'pulse',
        'systolic_bp', 'diastolic_bp', 'oxygen_saturation', 'temperature_celsius', 'pain_score',
        'weight_kg', 'unobtainable_fields', 'unobtainable_reasons', 'trauma_flag', 'trauma_note',
        'isolation_precaution_flag', 'isolation_precaution_note', 'handoff_note',
        'reassessment_reason', 'prior_assessment_digest', 'created_at',
    ];

    /** @param array<int, array<string, mixed>> $categories */
    public function vocabulary(string $displayName, array $categories, string $state): string
    {
        return EmergencyCanonicalJson::digest([$displayName, $categories, $state]);
    }

    /** @param array<string, mixed> $attributes */
    public function assessmentPayload(array $attributes): string
    {
        unset($attributes['content_digest']);
        $attributes = $this->timestamps($attributes, ['observed_at', 'recorded_at', 'created_at']);

        return EmergencyCanonicalJson::digest($attributes);
    }

    public function assessment(EmergencyTriageAssessment $assessment): string
    {
        $digest = $this->assessmentPayload($assessment->only(self::ASSESSMENT_FIELDS));
        if (! hash_equals($digest, (string) $assessment->content_digest)) {
            throw new EmergencyDenied('evidence_fingerprint_invalid', 'Sidik bukti triase tidak valid.');
        }

        return $digest;
    }

    /**
     * @param  array<string, mixed>  $createdAttributes
     * @return list<string>
     */
    public function assessmentRoundTripDifferences(EmergencyTriageAssessment $assessment, array $createdAttributes): array
    {
        $createdAttributes = $this->timestamps($createdAttributes, ['observed_at', 'recorded_at', 'created_at']);
        $persistedAttributes = $this->timestamps($assessment->only(self::ASSESSMENT_FIELDS), ['observed_at', 'recorded_at', 'created_at']);
        $differences = [];
        foreach (self::ASSESSMENT_FIELDS as $field) {
            if (EmergencyCanonicalJson::digest([$createdAttributes[$field] ?? null]) !== EmergencyCanonicalJson::digest([$persistedAttributes[$field] ?? null])) {
                $differences[] = $field;
            }
        }

        return $differences;
    }

    /** @param array<string, mixed> $fields */
    public function document(string $state, array $fields, int $version, int $authorUserId, DateTimeInterface|string|null $finalizedAt): string
    {
        return EmergencyCanonicalJson::digest([$state, $fields, $version, $authorUserId, $this->timestamp($finalizedAt)]);
    }

    public function documentVersion(EmergencyClinicalDocumentVersion $version): string
    {
        $digest = $this->document($version->state, $version->fields, $version->version, $version->author_user_id, $version->finalized_at?->toJSON());
        if (! hash_equals($digest, (string) $version->content_digest)) {
            throw new EmergencyDenied('evidence_fingerprint_invalid', 'Sidik dokumen IGD tidak valid.');
        }

        return $digest;
    }

    public function followUpProposal(EmergencyResultFollowUpProposal $proposal): string
    {
        $digest = $this->followUpProposalPayload($proposal->only(['encounter_id', 'proposed_by_user_id', 'proposed_to_user_id', 'prior_proposal_id', 'order_type', 'order_public_id', 'result_fingerprint', 'assignment_reason', 'handoff_note', 'effective_at', 'prior_proposal_digest']));
        if (! hash_equals($digest, (string) $proposal->content_digest)) {
            throw new EmergencyDenied('evidence_fingerprint_invalid', 'Sidik penugasan tindak lanjut tidak valid.');
        }

        return $digest;
    }

    /** @param array<string, mixed> $payload */
    public function followUpProposalPayload(array $payload): string
    {
        return EmergencyCanonicalJson::digest([
            $payload['encounter_id'], $payload['proposed_by_user_id'], $payload['proposed_to_user_id'],
            $payload['prior_proposal_id'] ?? null, $payload['order_type'], $payload['order_public_id'],
            $payload['result_fingerprint'], $payload['assignment_reason'], $payload['handoff_note'],
            $this->timestamp($payload['effective_at']), $payload['prior_proposal_digest'] ?? null,
        ]);
    }

    public function followUpAcceptance(EmergencyResultFollowUpAcceptance $acceptance): string
    {
        $proposal = $acceptance->proposal()->firstOrFail();
        $proposalDigest = $this->followUpProposal($proposal);
        $digest = $this->followUpAcceptancePayload($acceptance->proposal_id, $acceptance->accepted_by_user_id, $proposalDigest, $acceptance->accepted_at);
        if (! hash_equals($proposalDigest, (string) $acceptance->proposal_fingerprint) || ! hash_equals($digest, (string) $acceptance->content_digest)) {
            throw new EmergencyDenied('evidence_fingerprint_invalid', 'Penerimaan penugasan tidak valid.');
        }

        return $digest;
    }

    public function followUpAcceptancePayload(int $proposalId, int $actorId, string $proposalDigest, DateTimeInterface|string $acceptedAt): string
    {
        return EmergencyCanonicalJson::digest([$proposalId, $actorId, $proposalDigest, $this->timestamp($acceptedAt)]);
    }

    /** @param array<string, mixed> $payload */
    public function dispositionPayload(int $encounterId, int $physicianId, ?int $priorId, int $version, string $type, array $payload, ?string $reason, ?string $priorDigest, DateTimeInterface|string $signedAt): string
    {
        return EmergencyCanonicalJson::digest([$encounterId, $physicianId, $priorId, $version, $type, $payload, $reason, $priorDigest, $this->timestamp($signedAt)]);
    }

    public function disposition(EmergencyDisposition $disposition): string
    {
        $digest = $this->dispositionPayload($disposition->encounter_id, $disposition->physician_user_id, $disposition->prior_disposition_id, $disposition->version, $disposition->disposition_type, $disposition->payload, $disposition->correction_reason, $disposition->prior_disposition_digest, $disposition->signed_at->toJSON());
        if (! hash_equals($digest, (string) $disposition->content_digest)) {
            throw new EmergencyDenied('evidence_fingerprint_invalid', 'Sidik disposisi IGD tidak valid.');
        }

        return $digest;
    }

    public function encounter(Encounter $encounter): string
    {
        return EmergencyCanonicalJson::digest([$encounter->public_id, $encounter->patient_id, $encounter->care_setting, $encounter->status, $this->timestamp($encounter->registered_at), $encounter->payer_type, $encounter->insurance_number, $this->timestamp($encounter->updated_at)]);
    }

    /** @param array<string, mixed> $payload */
    public function correctionIntentPayload(array $payload): string
    {
        unset($payload['content_digest']);
        $payload = $this->timestamps($payload, ['expires_at']);

        return EmergencyCanonicalJson::digest($payload);
    }

    public function correctionIntent(EmergencyDispositionCorrectionIntent $intent): string
    {
        $digest = $this->correctionIntentPayload($intent->only(['encounter_id', 'current_disposition_id', 'handoff_id', 'physician_user_id', 'replacement_type', 'replacement_payload', 'reason', 'source_encounter_fingerprint', 'disposition_fingerprint', 'handoff_fingerprint', 'target_encounter_fingerprint', 'expires_at']));
        if (! hash_equals($digest, (string) $intent->content_digest)) {
            throw new EmergencyDenied('evidence_fingerprint_invalid', 'Sidik maksud koreksi tidak valid.');
        }

        return $digest;
    }

    public function handoff(EmergencyInpatientHandoff $handoff): string
    {
        $digest = $this->handoffPayload($handoff->only(['source_encounter_id', 'disposition_id', 'target_encounter_id', 'inpatient_location_event_id', 'inpatient_bed_id', 'actor_user_id', 'inpatient_bed_version', 'bed_snapshot', 'source_encounter_fingerprint', 'disposition_fingerprint', 'target_encounter_fingerprint', 'location_event_fingerprint', 'handed_off_at']));
        if (! hash_equals($digest, (string) $handoff->content_digest)) {
            throw new EmergencyDenied('evidence_fingerprint_invalid', 'Sidik serah-terima IGD tidak valid.');
        }

        return $digest;
    }

    /** @param array<string, mixed> $payload */
    public function handoffPayload(array $payload): string
    {
        unset($payload['content_digest'], $payload['created_at']);
        $payload = $this->timestamps($payload, ['handed_off_at']);

        return EmergencyCanonicalJson::digest($payload);
    }

    public function location(InpatientLocationEvent $location): string
    {
        $payload = $location->only([
            'encounter_id', 'encounter_public_id', 'actor_user_id', 'event_type', 'sequence',
            'from_ward_public_id', 'from_ward_code', 'from_ward_display_name',
            'from_bed_public_id', 'from_bed_code', 'from_bed_display_name', 'from_room_label', 'from_service_class',
            'to_ward_public_id', 'to_ward_code', 'to_ward_display_name',
            'to_bed_public_id', 'to_bed_code', 'to_bed_display_name', 'to_room_label', 'to_service_class',
            'reason', 'request_correlation_id', 'payload_digest', 'occurred_at',
        ]);

        return EmergencyCanonicalJson::digest($this->timestamps($payload, ['occurred_at']));
    }

    public function cancellation(EncounterCancellation $cancellation): string
    {
        $payload = $cancellation->only([
            'encounter_id', 'cancelled_by_user_id', 'reason_code', 'note', 'idempotency_key',
            'payload_digest', 'request_correlation_id', 'cancelled_at',
        ]);

        return EmergencyCanonicalJson::digest($this->timestamps($payload, ['cancelled_at']));
    }

    /** @param array<string, mixed> $payload */
    public function compensationPayload(array $payload): string
    {
        unset($payload['content_digest'], $payload['created_at']);
        $payload = $this->timestamps($payload, ['compensated_at']);

        return EmergencyCanonicalJson::digest($payload);
    }

    public function compensation(EmergencyHandoffCompensation $compensation): string
    {
        $digest = $this->compensationPayload($compensation->only([
            'handoff_id', 'correction_intent_id', 'replacement_disposition_id', 'actor_user_id',
            'target_cancellation_fingerprint', 'bed_reconciliation_fingerprint',
            'location_reconciliation_fingerprint', 'compensated_at',
        ]));
        if (! hash_equals($digest, (string) $compensation->content_digest)) {
            throw new EmergencyDenied('evidence_fingerprint_invalid', 'Sidik kompensasi serah-terima IGD tidak valid.');
        }

        return $digest;
    }

    public function vocabularyVersion(EmergencyTriageVocabularyVersion $version): string
    {
        $digest = $this->vocabulary($version->display_name, $version->categories, $version->state);
        if (! hash_equals($digest, (string) $version->content_digest)) {
            throw new EmergencyDenied('evidence_fingerprint_invalid', 'Sidik versi kosakata triase tidak valid.');
        }

        return $digest;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function timestamps(array $payload, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $this->timestamp($payload[$field]);
            }
        }

        return $payload;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $time = $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            throw new EmergencyDenied('evidence_fingerprint_invalid', 'Waktu bukti IGD tidak valid.');
        }

        return $time->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
