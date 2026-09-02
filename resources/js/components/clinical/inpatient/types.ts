import type { SourceEmergencyProjection } from '../emergency/types';
import type { LaboratoryEncounterProjection } from '../laboratory/types';
import type { PharmacyEncounterProjection } from '../pharmacy/types';
import type { RadiologyEncounterProjection } from '../radiology/types';

export type InpatientDailyDocumentType = 'NURSING_DAILY' | 'MEDICAL_DAILY';

export type InpatientDailyDocumentState = 'DRAFT' | 'FINAL';

export type InpatientPlacementSnapshot = {
    ward_public_id: string;
    ward_code: string;
    ward_display_name: string;
    bed_public_id: string;
    bed_code: string;
    bed_display_name: string;
    room_label: string;
    service_class: string;
};

export type InpatientLocationEventType = 'ADMISSION_LOCATION' | 'BED_TRANSFER';

export type InpatientLocationEvent = {
    public_id: string;
    event_type: InpatientLocationEventType;
    sequence: number;
    from_placement: InpatientPlacementSnapshot | null;
    to_placement: InpatientPlacementSnapshot;
    actor: {
        public_id: string | null;
        name: string | null;
    };
    reason: string | null;
    request_correlation_id: string | null;
    occurred_at: string | null;
};

export type InpatientBedTransferAction = {
    allowed: boolean;
    url: string | null;
    expected_location_sequence: number;
    expected_source_bed_public_id: string | null;
    target_beds: InpatientPlacementSnapshot[];
};

export type InpatientLocationHistory = {
    history_baseline: 'LEGACY_CURRENT_PLACEMENT' | null;
    history_complete: boolean;
    current_placement: InpatientPlacementSnapshot | null;
    current_sequence: number;
    events: InpatientLocationEvent[];
    transfer: InpatientBedTransferAction;
};

export type InpatientDailyDocument = {
    public_id: string;
    document_type: InpatientDailyDocumentType;
    state: InpatientDailyDocumentState;
    definition_version: string;
    service_date: string;
    version: number;
    fields: Record<string, string | null>;
    author: {
        public_id?: string | null;
        name: string | null;
    };
    is_current_actor_document: boolean;
    placement_snapshot: InpatientPlacementSnapshot | null;
    encounter_status_snapshot: string | null;
    created_at: string | null;
    updated_at: string | null;
    finalized_at: string | null;
};

export type InpatientDailyDocumentVersion = {
    public_id: string;
    document_public_id: string;
    document_type: InpatientDailyDocumentType;
    state: InpatientDailyDocumentState;
    encounter_public_id: string;
    care_setting: string;
    definition_version: string;
    service_date: string;
    version: number;
    fields: Record<string, string | null>;
    actor_name: string | null;
    author_name: string | null;
    placement_snapshot: InpatientPlacementSnapshot;
    encounter_status_snapshot: string;
    created_at: string | null;
    finalized_at: string | null;
};

export type InpatientClinicalEntry = {
    public_id: string;
    entry_type: string;
    body: string;
    created_at: string | null;
    author_name: string | null;
};

export type InpatientDocumentPermission = {
    can_save_draft: boolean;
    can_finalize: boolean;
    editable_document_public_id: string | null;
};

export type InpatientDocumentActions = {
    save_draft_url: string | null;
    finalize_url: string | null;
};

export type InpatientDischargeSummaryState = 'DRAFT' | 'FINAL';

export type InpatientDischargeSummaryFields = {
    admission_reason: string;
    significant_findings: string;
    care_and_treatment_summary: string;
    condition_at_discharge: string;
    follow_up_plan: string;
};

export type InpatientDischargeSummaryVersion = {
    public_id: string;
    state: InpatientDischargeSummaryState;
    version: number;
    definition_version: string;
    fields: InpatientDischargeSummaryFields;
    actor_name: string | null;
    created_at: string | null;
    finalized_at: string | null;
};

export type InpatientDischargeSummary = {
    public_id: string;
    state: InpatientDischargeSummaryState;
    version: number;
    definition_version: string;
    fields: InpatientDischargeSummaryFields;
    assigned_physician: {
        public_id: string | null;
        name: string | null;
    };
    created_at: string | null;
    updated_at: string | null;
    finalized_at: string | null;
};

export type InpatientDischargeSummaryProjection = {
    definition_version: string;
    summary: InpatientDischargeSummary | null;
    versions: InpatientDischargeSummaryVersion[];
    permission: {
        can_save_draft: boolean;
        can_finalize: boolean;
    };
    actions: InpatientDocumentActions;
};

export type InpatientDischargeCodingProcedureAttestation =
    'NO_PROCEDURE_RECORDED' | 'PROCEDURES_RECORDED';

export type InpatientDischargeCodingSourceFields = {
    principal_diagnosis_statement: string;
    secondary_diagnosis_statements: string[];
    procedure_attestation: InpatientDischargeCodingProcedureAttestation;
    performed_procedure_statements: string[];
};

export type InpatientDischargeCodingSourceState = 'DRAFT' | 'FINAL';

export type InpatientDischargeCodingSource = {
    public_id: string;
    state: InpatientDischargeCodingSourceState;
    version: number;
    assigned_physician: {
        public_id: string | null;
        name: string | null;
    };
    fields: InpatientDischargeCodingSourceFields;
    finalized_at: string | null;
};

export type InpatientDischargeCodingSourceVersion = {
    public_id: string;
    state: InpatientDischargeCodingSourceState;
    version: number;
    fields: InpatientDischargeCodingSourceFields;
    actor_name: string | null;
    created_at: string | null;
};

export type InpatientDischargeCodingSourceProjection = {
    definition_version: 'INPATIENT_DISCHARGE_CODING_SOURCE_V1';
    source: InpatientDischargeCodingSource | null;
    versions: InpatientDischargeCodingSourceVersion[];
    permission: {
        can_save_draft: boolean;
        can_finalize: boolean;
    };
    actions: {
        save_draft_url: string | null;
        finalize_url: string | null;
    };
};

export type InpatientRoutineDischargeRecord = {
    public_id: string;
    disposition_code: 'PULANG_ATAS_IZIN_DOKTER';
    disposition_label: 'Pulang atas izin dokter';
    discharge_summary_public_id: string;
    discharge_summary_version: number;
    location_sequence: number;
    source_bed_public_id: string;
    source_bed_code: string;
    encounter_status_before: string;
    encounter_status_after: 'READY_FOR_RM';
    discharged_at: string | null;
};

export type InpatientRoutineDischargeProjection = {
    disposition: {
        code: 'PULANG_ATAS_IZIN_DOKTER';
        label: 'Pulang atas izin dokter';
    };
    record: InpatientRoutineDischargeRecord | null;
    permission: {
        can_execute: boolean;
    };
    requirements: {
        expected_summary_version: number | null;
        current_location_sequence: number;
        source_bed_public_id: string | null;
    };
    actions: {
        execute_url: string | null;
    };
};

export type InpatientSummaryAddendumReasonOption = {
    value: string;
    label: string;
    requires_note: boolean;
};

export type InpatientSummaryAddendumFields = InpatientDischargeSummaryFields;

export type InpatientSummaryAddendumReviewItem = {
    item_code: string;
    label: string;
    is_blocking: boolean;
    is_complete: boolean;
    source_reference: string | null;
};

export type InpatientSummaryCorrectionRequest = {
    public_id: string;
    state: 'SUBMITTED' | 'APPROVED' | 'DENIED' | 'CONSUMED';
    version: number;
    reason_code: string;
    reason_label: string;
    note: string | null;
    requested_at: string | null;
    requester_name: string | null;
    decided_at: string | null;
    decider_name: string | null;
    decision_note: string | null;
    baseline: {
        discharge_summary_version: number;
        coding_source_version: number;
        coding_version: number;
        review_version: number;
        fingerprint: string;
    };
    addendum: {
        public_id: string;
        state: 'DRAFT' | 'FINAL';
        version: number;
        definition_version: string;
        fields: InpatientSummaryAddendumFields;
        author_name: string | null;
        finalized_by_name: string | null;
        created_at: string | null;
        finalized_at: string | null;
    } | null;
    renewed_review: {
        public_id: string;
        state: 'DRAFT' | 'SIGNED_OFF';
        version: number;
        definition_version: string;
        source_fingerprint: string;
        reviewed_at: string | null;
        reviewer_name: string | null;
        signed_off_at: string | null;
        signed_off_by_name: string | null;
        items: InpatientSummaryAddendumReviewItem[];
    } | null;
    current_review_source_fingerprint: string | null;
    current_review_version: number;
    permissions: {
        can_decide: boolean;
        can_write_addendum: boolean;
        can_finalize_addendum: boolean;
        can_save_renewed_review: boolean;
        can_signoff_renewed_review: boolean;
    };
    actions: {
        decision_url: string | null;
        save_addendum_url: string | null;
        finalize_addendum_url: string | null;
        save_renewed_review_url: string | null;
        signoff_renewed_review_url: string | null;
    };
};

export type InpatientSummaryAddendumProjection = {
    definition_version: string;
    available: boolean;
    reason_options: InpatientSummaryAddendumReasonOption[];
    can_request: boolean;
    store_url: string | null;
    requests: InpatientSummaryCorrectionRequest[];
};

export type InpatientDocumentationShowProps = {
    variant: 'rawat-inap';
    encounter: {
        public_id: string;
        status: string;
        care_setting: string;
        registered_at: string | null;
        patient: {
            public_id: string | null;
            medical_record_number: string | null;
            full_name: string | null;
            date_of_birth: string | null;
            sex: string | null;
        };
        placement: {
            ward_public_id: string;
            ward_code: string;
            ward_display_name: string;
            bed_public_id: string;
            bed_code: string;
            bed_display_name: string;
            room_label: string;
            service_class: string;
        } | null;
    };
    legacyEntries: InpatientClinicalEntry[];
    documentation: {
        available: boolean;
        unavailable_reason: string | null;
        definition_version: string;
        documents: InpatientDailyDocument[];
        versions: InpatientDailyDocumentVersion[];
    };
    permissions: {
        nursing: InpatientDocumentPermission;
        medical: InpatientDocumentPermission;
    };
    actions: {
        nursing: InpatientDocumentActions;
        medical: InpatientDocumentActions;
    };
    discharge_summary: InpatientDischargeSummaryProjection;
    inpatient_discharge_coding_source: InpatientDischargeCodingSourceProjection;
    inpatient_discharge: InpatientRoutineDischargeProjection;
    inpatient_summary_addendum: InpatientSummaryAddendumProjection;
    location_history: InpatientLocationHistory;
    laboratory?: LaboratoryEncounterProjection;
    radiology?: RadiologyEncounterProjection;
    pharmacy?: PharmacyEncounterProjection;
    source_emergency?: SourceEmergencyProjection | null;
};
