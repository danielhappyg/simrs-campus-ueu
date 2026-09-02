import type { LaboratoryEncounterProjection } from '../laboratory/types';
import type { PharmacyEncounterProjection } from '../pharmacy/types';
import type { RadiologyEncounterProjection } from '../radiology/types';

export type EmergencyTriageCode = 'MERAH' | 'KUNING' | 'HIJAU' | 'HITAM';
export type EmergencyObservationState =
    'ASSESSED_NO_CONCERN' | 'ASSESSED_CONCERN' | 'NOT_ASSESSED';
export type EmergencyConsciousness =
    'ALERT' | 'VOICE' | 'PAIN' | 'UNRESPONSIVE';
export type EmergencyDocumentType = 'NURSING' | 'MEDICAL';
export type EmergencyDocumentState = 'DRAFT' | 'FINAL';
export type EmergencyDispositionCode =
    'PULANG' | 'DIRUJUK' | 'RAWAT_INAP' | 'MENINGGAL_DI_IGD' | 'DOA';

export type EmergencyOption = { value: string; label: string };

export type EmergencyPatient = {
    public_id: string | null;
    medical_record_number: string | null;
    full_name: string | null;
    date_of_birth: string | null;
    sex: string | null;
};

export type EmergencyEncounterSummary = {
    public_id: string;
    status: string;
    registered_at: string | null;
    visit_date?: string | null;
    queue_number: number | null;
    clinic_name?: string | null;
    doctor_name?: string | null;
    payer_type: string;
    case_type?: string | null;
    accident_type?: string | null;
    chief_complaint: string | null;
    waiting_minutes?: number | null;
    patient: EmergencyPatient;
    triage?: EmergencyTriageSummary | null;
};

export type EmergencyTriageCategory = {
    code: EmergencyTriageCode;
    rank: number;
    display_name: string;
    text_cue: string;
    colour_token: string;
    guidance_text: string | null;
};

export type EmergencyTriageVocabulary = {
    public_id: string;
    version: number;
    state: 'ACTIVE' | 'RETIRED';
    effective_at: string | null;
    categories: EmergencyTriageCategory[];
};

export type EmergencyTriageVocabularyVersion = {
    public_id: string;
    version: number;
    display_name: string;
    state: 'ACTIVE' | 'RETIRED';
    categories: EmergencyTriageCategory[];
    actor_name: string | null;
    created_at: string | null;
    content_digest: string | null;
};

export type EmergencyTriageVocabularyMasterRecord = {
    public_id: string;
    vocabulary_code: string;
    display_name: string;
    state: 'ACTIVE' | 'RETIRED';
    version: number;
    categories: EmergencyTriageCategory[];
    versions: EmergencyTriageVocabularyVersion[];
    actions: { revise_url: string | null };
};

export type EmergencyTriageVocabularyMasterProps = {
    vocabularies: EmergencyTriageVocabularyMasterRecord[];
    permissions: { can_manage: boolean };
    commands: { create_url: string | null };
    read_error?: string | null;
};

export type EmergencyTriageSummary = {
    current_category: EmergencyTriageCode | null;
    current_category_label: string | null;
    current_category_text_cue?: string | null;
    last_assessed_at: string | null;
    reassessment_count: number;
};

export type EmergencyAbcdeObservation = {
    state: EmergencyObservationState;
    note: string | null;
};

export type EmergencyVitals = {
    respiratory_rate: number | null;
    pulse: number | null;
    systolic_pressure: number | null;
    diastolic_pressure: number | null;
    oxygen_saturation: number | null;
    temperature: number | null;
    pain_score: number | null;
    weight: number | null;
};

export type EmergencyTriageAssessment = {
    public_id: string;
    version: number;
    kind: 'INITIAL' | 'REASSESSMENT';
    category: EmergencyTriageCategory;
    vocabulary_public_id: string;
    vocabulary_version: number;
    observed_at: string | null;
    recorded_at: string | null;
    assessor: { public_id: string | null; name: string | null };
    late_entry_reason: string | null;
    reassessment_reason: string | null;
    presenting_concern: string;
    clinical_basis: string;
    arrival_condition: string;
    abcde: Record<
        'airway' | 'breathing' | 'circulation' | 'disability' | 'exposure',
        EmergencyAbcdeObservation
    >;
    consciousness: EmergencyConsciousness;
    vitals: EmergencyVitals;
    unobtainable_fields: string[];
    unobtainable_reason: string | null;
    trauma: boolean;
    trauma_note: string | null;
    isolation_precaution: boolean;
    isolation_note: string | null;
    handoff_note: string | null;
    content_digest?: string | null;
};

export type EmergencyTriageProjection = {
    definition_version: 'STRUCTURED_EMERGENCY_TRIAGE_DISPOSITION_V1';
    vocabulary: EmergencyTriageVocabulary | null;
    assessments: EmergencyTriageAssessment[];
    current: EmergencyTriageAssessment | null;
    permission: { can_write: boolean };
    actions: {
        finalize_initial_url: string | null;
        reassess_url: string | null;
    };
};

export type EmergencyDocumentFields = Record<string, string | null>;

export type EmergencyDocumentVersion = {
    public_id: string;
    document_public_id: string;
    document_type: EmergencyDocumentType;
    state: EmergencyDocumentState;
    version: number;
    fields: EmergencyDocumentFields;
    author: { public_id: string | null; name: string | null };
    recorded_at: string | null;
    finalized_at: string | null;
    content_digest?: string | null;
};

export type EmergencyDocument = {
    public_id: string;
    document_type: EmergencyDocumentType;
    state: EmergencyDocumentState;
    version: number;
    fields: EmergencyDocumentFields;
    author: { public_id: string | null; name: string | null };
    updated_at: string | null;
    finalized_at: string | null;
};

export type EmergencyDocumentProjection = {
    definition_version: string;
    current: {
        nursing: EmergencyDocument | null;
        medical: EmergencyDocument | null;
    };
    versions: EmergencyDocumentVersion[];
    permissions: {
        nursing: { can_save_draft: boolean; can_finalize: boolean };
        medical: { can_save_draft: boolean; can_finalize: boolean };
    };
    actions: {
        nursing: {
            save_draft_url: string | null;
            finalize_url: string | null;
        };
        medical: {
            save_draft_url: string | null;
            finalize_url: string | null;
        };
    };
};

export type EmergencyFollowUpAssignment = {
    public_id: string;
    version: number;
    state: 'PROPOSED' | 'ACCEPTED' | 'REVOKED' | 'SUPERSEDED';
    assignee: { public_id: string | null; name: string | null };
    proposed_by: { public_id: string | null; name: string | null };
    reason: string;
    effective_at: string | null;
    handoff_note: string;
    proposed_at: string | null;
    accepted_at: string | null;
    accepted_by_name: string | null;
    fingerprint?: string | null;
};

export type EmergencyDiagnosticFollowUpItem = {
    order_type: 'LABORATORY' | 'RADIOLOGY';
    order_public_id: string;
    label: string;
    fingerprint: string;
    current: EmergencyFollowUpAssignment | null;
    history: EmergencyFollowUpAssignment[];
    actions: {
        propose_url: string | null;
        accept_url: string | null;
    };
};

export type EmergencyDiagnosticAssignmentHistoryItem = {
    order_type: 'LABORATORY' | 'RADIOLOGY';
    order_public_id: string;
    label: string;
    current: EmergencyFollowUpAssignment;
    history: EmergencyFollowUpAssignment[];
};

export type EmergencyFollowUpProjection = {
    unresolved_diagnostics: EmergencyDiagnosticFollowUpItem[];
    diagnostic_assignment_history: EmergencyDiagnosticAssignmentHistoryItem[];
    physician_options: EmergencyOption[];
    unresolved_diagnostic_count: number;
    permission: { can_propose: boolean; can_accept: boolean };
    all_assignments_accepted: boolean;
};

export type EmergencyDispositionDetails = Record<string, string | null>;

export type EmergencyDispositionVersion = {
    public_id: string;
    version: number;
    code: EmergencyDispositionCode;
    label: string;
    details: EmergencyDispositionDetails;
    physician: { public_id: string | null; name: string | null };
    signed_at: string | null;
    correction_reason: string | null;
    supersedes_public_id: string | null;
    content_digest?: string | null;
};

export type EmergencyHandoff = {
    public_id: string;
    state: 'PENDING' | 'COMPLETED' | 'COMPENSATED';
    target_encounter_public_id: string | null;
    target_encounter_url: string | null;
    bed_code: string | null;
    ward_display_name: string | null;
    registrar_name: string | null;
    completed_at: string | null;
    compensated_at: string | null;
};

export type EmergencyCorrectionIntent = {
    public_id: string;
    state: 'PENDING' | 'EXECUTED' | 'EXPIRED' | 'REVOKED';
    replacement_code: EmergencyDispositionCode;
    replacement_label: string;
    reason: string;
    physician_name: string | null;
    expires_at: string;
    created_at: string;
    fingerprint: string;
    actions: { revoke_url: string | null };
};

export type EmergencyBedOption = {
    public_id: string;
    code: string;
    display_name: string;
    ward_public_id: string;
    ward_code: string;
    ward_display_name: string;
    room_label: string;
    service_class: string;
};

export type EmergencyDispositionProjection = {
    current: EmergencyDispositionVersion | null;
    history: EmergencyDispositionVersion[];
    handoff: EmergencyHandoff | null;
    correction_intents: EmergencyCorrectionIntent[];
    bed_options: EmergencyBedOption[];
    permissions: {
        can_sign: boolean;
        can_correct: boolean;
        can_handoff: boolean;
        can_compensate: boolean;
    };
    requirements: {
        initial_triage_final: boolean;
        nursing_final: boolean;
        medical_final: boolean;
        diagnostic_follow_up_resolved: boolean;
    };
    actions: {
        sign_url: string | null;
        correct_url: string | null;
        create_correction_intent_url: string | null;
        handoff_url: string | null;
        compensate_url: string | null;
    };
};

export type EmergencyLegacyEntry = {
    public_id: string;
    entry_type: string;
    body: string;
    author_name: string | null;
    created_at: string | null;
};

export type EmergencyShowProps = {
    encounter: EmergencyEncounterSummary;
    triage: EmergencyTriageProjection;
    documentation: EmergencyDocumentProjection;
    follow_up: EmergencyFollowUpProjection;
    disposition: EmergencyDispositionProjection;
    laboratory: LaboratoryEncounterProjection;
    radiology: RadiologyEncounterProjection;
    pharmacy?: PharmacyEncounterProjection;
    legacy_entries: EmergencyLegacyEntry[];
};

export type EmergencyWorklistProps = {
    encounters: EmergencyEncounterSummary[];
    filters: { q: string; date_from: string; date_to: string; payer?: string };
    indexPath?: string;
    showPathPrefix?: string;
    canOpen: boolean;
    payerOptions?: EmergencyOption[];
};

export type SourceEmergencyProjection = {
    source_encounter: EmergencyEncounterSummary;
    triage: EmergencyTriageAssessment[];
    final_nursing_document: EmergencyDocumentVersion | null;
    final_medical_document: EmergencyDocumentVersion | null;
    dispositions: EmergencyDispositionVersion[];
    handoff: EmergencyHandoff | null;
    follow_up: EmergencyFollowUpProjection;
    laboratory: LaboratoryEncounterProjection;
    radiology: RadiologyEncounterProjection;
};
