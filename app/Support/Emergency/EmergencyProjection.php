<?php

namespace App\Support\Emergency;

use App\Models\ClinicalEntry;
use App\Models\EmergencyClinicalDocument;
use App\Models\EmergencyClinicalDocumentVersion;
use App\Models\EmergencyDisposition;
use App\Models\EmergencyDispositionCorrectionIntent;
use App\Models\EmergencyInpatientHandoff;
use App\Models\EmergencyResultFollowUpProposal;
use App\Models\EmergencyTriageAssessment;
use App\Models\EmergencyTriageVocabulary;
use App\Models\EmergencyTriageVocabularyVersion;
use App\Models\Encounter;
use App\Models\LaboratoryOrder;
use App\Models\RadiologyOrder;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Inpatient\InpatientWardReadModel;
use App\Support\Laboratory\LaboratoryProjection;
use App\Support\Radiology\RadiologyProjection;
use Illuminate\Support\Facades\Route;

final class EmergencyProjection
{
    public const DEFINITION_VERSION = 'STRUCTURED_EMERGENCY_TRIAGE_DISPOSITION_V1';

    public function __construct(
        private readonly EmergencyActorPolicy $policy,
        private readonly EmergencyEvidenceFingerprint $fingerprints,
        private readonly EmergencyDiagnosticFollowUpService $followUp,
        private readonly InpatientWardReadModel $wardReadModel,
        private readonly LaboratoryProjection $laboratoryProjection,
        private readonly RadiologyProjection $radiologyProjection,
    ) {}

    /** @return array<string, mixed> */
    public function encounter(Encounter $encounter, User $actor): array
    {
        if ($encounter->care_setting !== Encounter::CARE_SETTING_EMERGENCY) {
            throw new EmergencyDenied('wrong_care_setting', 'Proyeksi ini hanya untuk episode IGD.');
        }
        $roles = $actor->roleSlugs();
        $clinicalVisible = ! $actor->is_system_administrator && count($roles) === 1 && in_array($roles[0], [RoleCapabilityMatrix::ROLE_NURSE, RoleCapabilityMatrix::ROLE_PHYSICIAN, RoleCapabilityMatrix::ROLE_RMIK], true);

        return [
            'triage' => $this->triage($encounter, $actor, $clinicalVisible),
            'documentation' => $this->documentation($encounter, $actor, $clinicalVisible),
            'follow_up' => $this->followUp($encounter, $actor, $clinicalVisible),
            'disposition' => $this->disposition($encounter, $actor, $clinicalVisible),
            'legacy_entries' => $this->legacyEntries($encounter, $clinicalVisible),
        ];
    }

    /** @return array<string, mixed> */
    public function worklistEncounter(Encounter $encounter, User $actor): array
    {
        $encounter->loadMissing(['patient', 'emergencyTriageAssessments']);
        $triageVisible = ! $actor->is_system_administrator
            && count($actor->roleSlugs()) === 1
            && in_array($actor->roleSlugs()[0], [RoleCapabilityMatrix::ROLE_NURSE, RoleCapabilityMatrix::ROLE_PHYSICIAN, RoleCapabilityMatrix::ROLE_RMIK], true);
        $triage = $triageVisible ? $encounter->emergencyTriageAssessments->sortByDesc('assessment_number')->first() : null;

        return [
            'public_id' => $encounter->public_id,
            'status' => $encounter->status,
            'clinic_name' => $encounter->clinic_name,
            'doctor_name' => $encounter->doctor_name,
            'payer_type' => $encounter->payer_type,
            'case_type' => $encounter->case_type,
            'accident_type' => $encounter->accident_type,
            'queue_number' => $encounter->queue_number,
            'registered_at' => $encounter->registered_at->toIso8601String(),
            'visit_date' => $encounter->visit_date?->toDateString(),
            'chief_complaint' => $encounter->chief_complaint,
            'waiting_minutes' => $encounter->registered_at->diffInMinutes(now()),
            'patient' => [
                'public_id' => $encounter->patient?->public_id,
                'medical_record_number' => $encounter->patient?->medical_record_number,
                'full_name' => $encounter->patient?->full_name,
                'date_of_birth' => $encounter->patient?->date_of_birth?->toDateString(),
                'sex' => $encounter->patient?->sex,
            ],
            'triage' => $triage ? [
                'current_category' => $triage->category_code,
                'current_category_label' => $triage->category_label_snapshot,
                'current_category_text_cue' => $triage->category_cue_snapshot,
                'last_assessed_at' => $triage->observed_at->toIso8601String(),
                'reassessment_count' => max(0, $triage->assessment_number - 1),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function triage(Encounter $encounter, User $actor, bool $visible = true): array
    {
        $canWrite = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_NURSE, Capability::EMERGENCY_TRIAGE_WRITE);
        $active = EmergencyTriageVocabulary::query()->where('state', EmergencyTriageVocabulary::ACTIVE)->orderByDesc('version')->first();
        $activeVersion = $active ? EmergencyTriageVocabularyVersion::query()->where('emergency_triage_vocabulary_id', $active->id)->where('version', $active->version)->first() : null;
        $assessments = $visible ? EmergencyTriageAssessment::query()->where('encounter_id', $encounter->id)->with(['assessor', 'vocabularyVersion.vocabulary'])->orderBy('assessment_number')->get() : collect();
        $items = $assessments->map(fn (EmergencyTriageAssessment $assessment): array => $this->assessment($assessment))->all();

        return [
            'definition_version' => self::DEFINITION_VERSION,
            'vocabulary' => $activeVersion ? ['public_id' => $active->public_id, 'version' => $activeVersion->version, 'state' => $activeVersion->state, 'effective_at' => $activeVersion->created_at->toIso8601String(), 'categories' => $activeVersion->categories] : null,
            'assessments' => $items, 'current' => $items === [] ? null : end($items),
            'permission' => ['can_write' => $canWrite],
            'actions' => [
                'finalize_initial_url' => $canWrite && $encounter->status === Encounter::STATUS_REGISTERED && $items === [] ? $this->route('emergency.triage.initial', [$encounter]) : null,
                'reassess_url' => $canWrite && $encounter->status === Encounter::STATUS_IN_EXAMINATION && $items !== [] && ! EmergencyDisposition::query()->where('encounter_id', $encounter->id)->exists() ? $this->route('emergency.triage.reassess', [$encounter]) : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function documentation(Encounter $encounter, User $actor, bool $visible = true): array
    {
        $documents = $visible ? EmergencyClinicalDocument::query()->where('encounter_id', $encounter->id)->with(['versions.author'])->get()->keyBy('document_type') : collect();
        $nursing = $documents->get(EmergencyClinicalDocument::NURSING);
        $medical = $documents->get(EmergencyClinicalDocument::MEDICAL);
        $canNursing = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_NURSE, Capability::EMERGENCY_TRIAGE_WRITE);
        $canMedical = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::EMERGENCY_DISPOSITION_WRITE);
        $mutable = $encounter->status === Encounter::STATUS_IN_EXAMINATION && ! EmergencyDisposition::query()->where('encounter_id', $encounter->id)->exists();

        return [
            'definition_version' => self::DEFINITION_VERSION,
            'current' => ['nursing' => $nursing ? $this->currentDocument($nursing) : null, 'medical' => $medical ? $this->currentDocument($medical) : null],
            'versions' => $documents->flatMap(fn (EmergencyClinicalDocument $document) => $document->versions->map(fn (EmergencyClinicalDocumentVersion $version) => $this->documentVersion($document, $version)))->sortBy(['created_at', 'version'])->values()->all(),
            'permissions' => ['nursing' => ['can_save_draft' => $canNursing, 'can_finalize' => $canNursing], 'medical' => ['can_save_draft' => $canMedical, 'can_finalize' => $canMedical]],
            'actions' => [
                'nursing' => ['save_draft_url' => $mutable && $canNursing && $nursing?->state !== EmergencyClinicalDocument::FINAL ? $this->route('emergency.documents.draft', [$encounter, 'NURSING']) : null, 'finalize_url' => $mutable && $canNursing && $nursing?->state === EmergencyClinicalDocument::DRAFT ? $this->route('emergency.documents.finalize', [$encounter, 'NURSING']) : null],
                'medical' => ['save_draft_url' => $mutable && $canMedical && $medical?->state !== EmergencyClinicalDocument::FINAL ? $this->route('emergency.documents.draft', [$encounter, 'MEDICAL']) : null, 'finalize_url' => $mutable && $canMedical && $medical?->state === EmergencyClinicalDocument::DRAFT ? $this->route('emergency.documents.finalize', [$encounter, 'MEDICAL']) : null],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function followUp(Encounter $encounter, User $actor, bool $visible = true): array
    {
        $can = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::EMERGENCY_RESULT_FOLLOW_UP);
        $unresolved = $visible ? $this->followUp->unresolvedDiagnostics($encounter) : [];
        $proposalGroups = $visible
            ? EmergencyResultFollowUpProposal::query()->where('encounter_id', $encounter->id)->with(['proposer', 'assignee', 'acceptance.actor'])->orderBy('id')->get()->groupBy(fn (EmergencyResultFollowUpProposal $proposal): string => $proposal->order_type.':'.$proposal->order_public_id)
            : collect();
        $items = array_map(function (array $item) use ($encounter, $actor, $can, $proposalGroups): array {
            $proposals = $proposalGroups->get($item['order_type'].':'.$item['order_public_id'], collect());
            $latest = $proposals->last();
            $history = $proposals->values()->map(fn ($proposal, $index) => $this->assignment($proposal, $index + 1, $proposal->is($latest)))->all();

            return [
                ...$item, 'label' => $this->diagnosticLabel($item['order_type'], $item['order_public_id']),
                'current' => $history === [] ? null : end($history), 'history' => $history,
                'actions' => [
                    'propose_url' => $can ? $this->route('emergency.follow-up.propose', [$encounter, $item['order_type'], $item['order_public_id']]) : null,
                    'accept_url' => $can && $latest && ! $latest->acceptance && $latest->proposed_to_user_id === $actor->id ? $this->route('emergency.follow-up.accept', [$latest]) : null,
                ],
            ];
        }, $unresolved);
        $assignmentHistory = $proposalGroups->map(function ($proposals): array {
            $latest = $proposals->last();

            return [
                'order_type' => $latest->order_type,
                'order_public_id' => $latest->order_public_id,
                'label' => $this->diagnosticLabel($latest->order_type, $latest->order_public_id),
                'current' => $this->assignment($latest, $proposals->count(), true),
                'history' => $proposals->values()->map(fn ($proposal, $index) => $this->assignment($proposal, $index + 1, $proposal->is($latest)))->all(),
            ];
        })->values()->all();
        $options = $visible && $can
            ? User::query()->where('status', 'ACTIVE')->where('is_system_administrator', false)->orderBy('name')->limit(100)->get()->filter(fn (User $user) => $this->policy->can($user, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::EMERGENCY_RESULT_FOLLOW_UP))->map(fn (User $user) => ['value' => $user->public_id, 'label' => $user->name])->values()->all()
            : [];

        $allAccepted = collect($unresolved)->every(fn ($item) => $this->followUp->hasAcceptedCurrentAssignment($encounter, $item));

        return ['unresolved_diagnostics' => $items, 'diagnostic_assignment_history' => $assignmentHistory, 'physician_options' => $options, 'unresolved_diagnostic_count' => count($items), 'all_assignments_accepted' => $allAccepted, 'permission' => ['can_propose' => $can, 'can_accept' => $can]];
    }

    /** @return array<string, mixed> */
    public function disposition(Encounter $encounter, User $actor, bool $visible = true): array
    {
        $canHandoff = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_REGISTRAR, Capability::EMERGENCY_INPATIENT_HANDOFF);
        $canCompensate = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_REGISTRAR, Capability::EMERGENCY_DISPOSITION_COMPENSATE);
        // A registrar sees only the signed disposition/intent chain required
        // to execute the bounded administrative handoff. Triage and clinical
        // document contents remain redacted by encounter().
        $dispositionVisible = $visible || $canHandoff || $canCompensate;
        $versions = $dispositionVisible ? EmergencyDisposition::query()->where('encounter_id', $encounter->id)->with(['physician', 'priorDisposition'])->orderBy('version')->get() : collect();
        $history = $versions->map(fn (EmergencyDisposition $item) => $this->dispositionVersion($item))->all();
        $current = $versions->last();
        $handoff = EmergencyInpatientHandoff::query()->where('source_encounter_id', $encounter->id)->with(['targetEncounter', 'bed.ward', 'actor', 'compensation'])->first();
        $canSign = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::EMERGENCY_DISPOSITION_WRITE);
        $intents = $dispositionVisible ? EmergencyDispositionCorrectionIntent::query()->where('encounter_id', $encounter->id)->with(['physician', 'events'])->orderByDesc('created_at')->get()->map(fn ($intent) => $this->correctionIntent($intent, $actor, $canSign))->all() : [];
        $triage = EmergencyTriageAssessment::query()->where('encounter_id', $encounter->id)->where('assessment_type', EmergencyTriageAssessment::INITIAL)->exists();
        $nursingFinal = EmergencyClinicalDocument::query()->where('encounter_id', $encounter->id)->where('document_type', 'NURSING')->where('state', 'FINAL')->exists();
        $medicalFinal = EmergencyClinicalDocument::query()->where('encounter_id', $encounter->id)->where('document_type', 'MEDICAL')->where('state', 'FINAL')->exists();
        $diagnosticReady = collect($this->followUp->unresolvedDiagnostics($encounter))->every(fn ($item) => $this->followUp->hasAcceptedCurrentAssignment($encounter, $item));

        $pendingIntent = collect($intents)->firstWhere('state', 'PENDING');
        $handoffEligible = $canHandoff
            && $current?->disposition_type === 'RAWAT_INAP'
            && ! $handoff
            && $encounter->status === Encounter::STATUS_IN_EXAMINATION;
        $compensationEligible = $canCompensate
            && $handoff
            && ! $handoff->compensation
            && is_array($pendingIntent);
        $bedOptions = $handoffEligible ? $this->availableBedOptions() : [];

        return [
            'current' => $history === [] ? null : end($history), 'history' => $history,
            'handoff' => $handoff ? $this->handoff($handoff) : null, 'correction_intents' => $intents, 'bed_options' => $bedOptions,
            'permissions' => ['can_sign' => $canSign, 'can_correct' => $canSign, 'can_handoff' => $handoffEligible, 'can_compensate' => $compensationEligible],
            'requirements' => ['initial_triage_final' => $triage, 'nursing_final' => $nursingFinal, 'medical_final' => $medicalFinal, 'diagnostic_follow_up_resolved' => $diagnosticReady],
            'actions' => [
                'sign_url' => $canSign && ! $current && $encounter->status === Encounter::STATUS_IN_EXAMINATION ? $this->route('emergency.disposition.sign', [$encounter]) : null,
                'correct_url' => $canSign && $current && ! $handoff && ! in_array($encounter->status, Encounter::TERMINAL_STATUSES, true) ? $this->route('emergency.disposition.correct', [$encounter]) : null,
                'create_correction_intent_url' => $canSign && $handoff && ! $handoff->compensation ? $this->route('emergency.disposition.correction-intent', [$encounter]) : null,
                'handoff_url' => $handoffEligible ? $this->route('emergency.disposition.handoff', [$encounter]) : null,
                'compensate_url' => $compensationEligible ? $this->route('emergency.disposition.compensate', [$encounter]) : null,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function sourceForInpatient(Encounter $inpatient, User $actor): ?array
    {
        if ($inpatient->care_setting !== Encounter::CARE_SETTING_INPATIENT) {
            return null;
        }
        $handoff = EmergencyInpatientHandoff::query()->where('target_encounter_id', $inpatient->id)->with('sourceEncounter.patient')->first();
        if (! $handoff) {
            return null;
        }
        if ($handoff->sourceEncounter->patient_id !== $inpatient->patient_id) {
            throw new EmergencyDenied('source_target_patient_mismatch', 'Tautan sumber IGD tidak cocok dengan pasien rawat inap.');
        }
        $source = $handoff->sourceEncounter;
        $projection = $this->encounter($source, $actor);
        $laboratory = $this->readOnlyDiagnosticProjection($this->laboratoryProjection->encounter($source, $actor));
        $radiology = $this->readOnlyDiagnosticProjection($this->radiologyProjection->encounter($source, $actor));
        $followUp = $this->readOnlyFollowUpProjection($projection['follow_up']);

        return ['source_encounter' => $this->encounterSummary($source, $projection['triage']['current']), 'triage' => $projection['triage']['assessments'], 'final_nursing_document' => $this->finalDocumentVersion($source, 'NURSING'), 'final_medical_document' => $this->finalDocumentVersion($source, 'MEDICAL'), 'dispositions' => $projection['disposition']['history'], 'handoff' => $projection['disposition']['handoff'], 'follow_up' => $followUp, 'laboratory' => $laboratory, 'radiology' => $radiology];
    }

    /** @return array<string, mixed> */
    private function assessment(EmergencyTriageAssessment $a): array
    {
        $version = $a->vocabularyVersion;

        return ['public_id' => $a->public_id, 'version' => $a->assessment_number, 'kind' => $a->assessment_type, 'category' => ['code' => $a->category_code, 'rank' => $a->category_rank_snapshot, 'display_name' => $a->category_label_snapshot, 'text_cue' => $a->category_cue_snapshot, 'colour_token' => $a->category_colour_token_snapshot, 'guidance_text' => collect($version->categories)->firstWhere('code', $a->category_code)['guidance_text'] ?? null], 'vocabulary_public_id' => $version->vocabulary->public_id, 'vocabulary_version' => $version->version, 'observed_at' => $a->observed_at->toIso8601String(), 'recorded_at' => $a->recorded_at->toIso8601String(), 'assessor' => ['public_id' => $a->assessor->public_id, 'name' => $a->assessor->name], 'late_entry_reason' => $a->late_entry_reason, 'reassessment_reason' => $a->reassessment_reason, 'presenting_concern' => $a->presenting_concern, 'clinical_basis' => $a->clinical_basis, 'arrival_condition' => $a->arrival_condition, 'abcde' => $a->abcde_observations, 'consciousness' => $a->consciousness, 'vitals' => ['respiratory_rate' => $a->respiratory_rate, 'pulse' => $a->pulse, 'systolic_pressure' => $a->systolic_bp, 'diastolic_pressure' => $a->diastolic_bp, 'oxygen_saturation' => $a->oxygen_saturation, 'temperature' => $a->temperature_celsius === null ? null : (float) $a->temperature_celsius, 'pain_score' => $a->pain_score, 'weight' => $a->weight_kg === null ? null : (float) $a->weight_kg], 'unobtainable_fields' => $a->unobtainable_fields, 'unobtainable_reason' => implode('; ', array_values($a->unobtainable_reasons)) ?: null, 'trauma' => $a->trauma_flag, 'trauma_note' => $a->trauma_note, 'isolation_precaution' => $a->isolation_precaution_flag, 'isolation_note' => $a->isolation_precaution_note, 'handoff_note' => $a->handoff_note, 'content_digest' => $this->fingerprints->assessment($a)];
    }

    /** @return array<string, mixed> */
    private function currentDocument(EmergencyClinicalDocument $document): array
    {
        $version = $document->versions->firstWhere('version', $document->version);

        return ['public_id' => $document->public_id, 'document_type' => $document->document_type, 'state' => $document->state, 'version' => $document->version, 'fields' => $version->fields, 'author' => ['public_id' => $version->author->public_id, 'name' => $version->author->name], 'updated_at' => $version->created_at->toIso8601String(), 'finalized_at' => $version->finalized_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function documentVersion(EmergencyClinicalDocument $document, EmergencyClinicalDocumentVersion $version): array
    {
        return ['public_id' => $version->public_id, 'document_public_id' => $document->public_id, 'document_type' => $document->document_type, 'state' => $version->state, 'version' => $version->version, 'fields' => $version->fields, 'author' => ['public_id' => $version->author->public_id, 'name' => $version->author->name], 'recorded_at' => $version->created_at->toIso8601String(), 'finalized_at' => $version->finalized_at?->toIso8601String(), 'content_digest' => $this->fingerprints->documentVersion($version)];
    }

    /** @return array<string, mixed> */
    private function assignment(EmergencyResultFollowUpProposal $proposal, int $version, bool $latest): array
    {
        $accepted = $proposal->acceptance;

        return ['public_id' => $proposal->public_id, 'version' => $version, 'state' => $latest ? ($accepted ? 'ACCEPTED' : 'PROPOSED') : 'SUPERSEDED', 'assignee' => ['public_id' => $proposal->assignee->public_id, 'name' => $proposal->assignee->name], 'proposed_by' => ['public_id' => $proposal->proposer->public_id, 'name' => $proposal->proposer->name], 'reason' => $proposal->assignment_reason, 'effective_at' => $proposal->effective_at->toIso8601String(), 'handoff_note' => $proposal->handoff_note, 'proposed_at' => $proposal->created_at->toIso8601String(), 'accepted_at' => $accepted?->accepted_at->toIso8601String(), 'accepted_by_name' => $accepted?->actor->name, 'fingerprint' => $this->fingerprints->followUpProposal($proposal)];
    }

    /** @return array<string, mixed> */
    private function dispositionVersion(EmergencyDisposition $item): array
    {
        return ['public_id' => $item->public_id, 'version' => $item->version, 'code' => $item->disposition_type, 'label' => $this->label($item->disposition_type), 'details' => $item->payload, 'physician' => ['public_id' => $item->physician->public_id, 'name' => $item->physician->name], 'signed_at' => $item->signed_at->toIso8601String(), 'correction_reason' => $item->correction_reason, 'supersedes_public_id' => $item->priorDisposition?->public_id, 'content_digest' => $this->fingerprints->disposition($item)];
    }

    /** @return array<string, mixed> */
    private function handoff(EmergencyInpatientHandoff $handoff): array
    {
        $compensated = $handoff->compensation;

        return ['public_id' => $handoff->public_id, 'state' => $compensated ? 'COMPENSATED' : 'COMPLETED', 'target_encounter_public_id' => $handoff->targetEncounter->public_id, 'target_encounter_url' => $this->route('pemeriksaan.rawat-inap.show', [$handoff->targetEncounter]), 'bed_code' => $handoff->bed_snapshot['bed_code'] ?? null, 'ward_display_name' => $handoff->bed_snapshot['ward_display_name'] ?? null, 'registrar_name' => $handoff->actor->name, 'completed_at' => $handoff->handed_off_at->toIso8601String(), 'compensated_at' => $compensated?->compensated_at->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function correctionIntent(EmergencyDispositionCorrectionIntent $intent, User $actor, bool $canSign): array
    {
        $event = $intent->events->sortByDesc('occurred_at')->first();
        $state = $event ? $event->event_type : ($intent->expires_at->isPast() ? 'EXPIRED' : 'PENDING');

        return ['public_id' => $intent->public_id, 'state' => $state, 'replacement_code' => $intent->replacement_type, 'replacement_label' => $this->label($intent->replacement_type), 'reason' => $intent->reason, 'physician_name' => $intent->physician->name, 'expires_at' => $intent->expires_at->toIso8601String(), 'created_at' => $intent->created_at->toIso8601String(), 'fingerprint' => $this->fingerprints->correctionIntent($intent), 'actions' => ['revoke_url' => $state === 'PENDING' && $canSign && $intent->physician_user_id === $actor->id ? $this->route('emergency.disposition.correction-intent.revoke', [$intent]) : null]];
    }

    /** @return list<array<string, mixed>> */
    private function legacyEntries(Encounter $encounter, bool $visible): array
    {
        if (! $visible) {
            return [];
        }

        return array_values(ClinicalEntry::query()->where('encounter_id', $encounter->id)->with('author')->orderBy('created_at')->get()->map(fn ($entry) => ['public_id' => $entry->public_id, 'entry_type' => $entry->entry_type, 'body' => $entry->body, 'author_name' => $entry->author?->name, 'created_at' => $entry->created_at?->toIso8601String()])->all());
    }

    /** @return array<string, mixed>|null */
    private function finalDocumentVersion(Encounter $encounter, string $type): ?array
    {
        $document = EmergencyClinicalDocument::query()->where('encounter_id', $encounter->id)->where('document_type', $type)->where('state', 'FINAL')->first();
        if (! $document) {
            return null;
        }
        $version = EmergencyClinicalDocumentVersion::query()->where('emergency_clinical_document_id', $document->id)->where('version', $document->version)->with('author')->firstOrFail();

        return $this->documentVersion($document, $version);
    }

    /**
     * @param  array<string, mixed>|null  $currentTriage
     * @return array<string, mixed>
     */
    private function encounterSummary(Encounter $encounter, ?array $currentTriage = null): array
    {
        $encounter->loadMissing('patient');

        return [
            'public_id' => $encounter->public_id,
            'status' => $encounter->status,
            'registered_at' => $encounter->registered_at->toIso8601String(),
            'queue_number' => $encounter->queue_number,
            'payer_type' => $encounter->payer_type,
            'chief_complaint' => $encounter->chief_complaint,
            'patient' => [
                'public_id' => $encounter->patient?->public_id,
                'medical_record_number' => $encounter->patient?->medical_record_number,
                'full_name' => $encounter->patient?->full_name,
                'date_of_birth' => $encounter->patient?->date_of_birth?->toDateString(),
                'sex' => $encounter->patient?->sex,
            ],
            'triage' => $currentTriage ? [
                'current_category' => $currentTriage['category']['code'] ?? null,
                'current_category_label' => $currentTriage['category']['display_name'] ?? null,
                'current_category_text_cue' => $currentTriage['category']['text_cue'] ?? null,
                'last_assessed_at' => $currentTriage['observed_at'] ?? null,
                'reassessment_count' => max(0, ((int) ($currentTriage['version'] ?? 1)) - 1),
            ] : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function availableBedOptions(): array
    {
        return array_values(collect($this->wardReadModel->registrationCatalogue())
            ->flatMap(fn (array $ward) => collect($ward['beds'])->map(fn (array $bed): array => [
                ...$bed,
                'ward_public_id' => $ward['public_id'],
                'ward_code' => $ward['code'],
                'ward_display_name' => $ward['display_name'],
                'room_label' => $bed['room_label'],
            ]))
            ->values()
            ->all());
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function readOnlyDiagnosticProjection(array $projection): array
    {
        foreach (array_keys($projection['permissions'] ?? []) as $key) {
            $projection['permissions'][$key] = false;
        }
        foreach (array_keys($projection['commands'] ?? []) as $key) {
            $projection['commands'][$key] = null;
        }
        foreach ($projection['orders'] ?? [] as $index => $order) {
            foreach (array_keys($order['actions'] ?? []) as $key) {
                $projection['orders'][$index]['actions'][$key] = null;
            }
        }

        return $projection;
    }

    private function diagnosticLabel(string $type, string $publicId): string
    {
        $label = $type === 'LABORATORY'
            ? LaboratoryOrder::query()->where('public_id', $publicId)->value('master_display_name')
            : RadiologyOrder::query()->where('public_id', $publicId)->value('master_display_name');

        return is_string($label) && $label !== '' ? $label : $publicId;
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function readOnlyFollowUpProjection(array $projection): array
    {
        $projection['physician_options'] = [];
        foreach (array_keys($projection['permission'] ?? []) as $key) {
            $projection['permission'][$key] = false;
        }
        foreach ($projection['unresolved_diagnostics'] ?? [] as $index => $diagnostic) {
            foreach (array_keys($diagnostic['actions'] ?? []) as $key) {
                $projection['unresolved_diagnostics'][$index]['actions'][$key] = null;
            }
        }

        return $projection;
    }

    /** @param array<int|string, mixed> $parameters */
    private function route(string $name, array $parameters = []): ?string
    {
        return Route::has($name) ? route($name, $parameters) : null;
    }

    private function label(string $code): string
    {
        return match ($code) {
            'PULANG' => 'Pulang', 'DIRUJUK' => 'Dirujuk', 'RAWAT_INAP' => 'Rawat Inap', 'MENINGGAL_DI_IGD' => 'Meninggal di IGD', 'DOA' => 'Datang dalam keadaan meninggal', default => str($code)->replace('_', ' ')->title()->toString()
        };
    }
}
