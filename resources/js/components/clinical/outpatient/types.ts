import type { LaboratoryEncounterProjection } from '../laboratory/types';
import type { PharmacyEncounterProjection } from '../pharmacy/types';
import type { RadiologyEncounterProjection } from '../radiology/types';

export type ClinicalDocumentType = 'NURSING_ASSESSMENT' | 'MEDICAL_ASSESSMENT';

export type TerminologySelection = {
    code: string;
    display: string;
};

export type ClinicalDocumentFieldValue =
    string | null | TerminologySelection | TerminologySelection[];

export type ClinicalDocument = {
    public_id: string;
    document_type: ClinicalDocumentType;
    document_state: 'DRAFT' | 'FINAL';
    definition_version: string;
    version: number;
    fields: Record<string, ClinicalDocumentFieldValue>;
    author_name: string | null;
    updated_at: string | null;
    finalized_at: string | null;
    finalized_by_name: string | null;
};

export type ClinicalDocumentVersion = {
    public_id: string;
    document_type: ClinicalDocumentType;
    version: number;
    state: 'DRAFT' | 'FINAL';
    fields: Record<string, ClinicalDocumentFieldValue>;
    actor_name: string | null;
    created_at: string | null;
    finalized_at: string | null;
};

export type AmendmentReasonOption = {
    value: string;
    label: string;
    requires_note: boolean;
};

export type OutpatientAmendmentReviewItem = {
    item_code: string;
    label: string;
    is_blocking: boolean;
    is_complete: boolean;
    source_reference: string | null;
};

export type OutpatientAmendment = {
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
    original_document: {
        public_id: string;
        document_type: ClinicalDocumentType;
        version: number;
    };
    addendum: {
        public_id: string;
        state: 'DRAFT' | 'FINAL';
        version: number;
        definition_version: string;
        fields: { addendum_text: string };
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
        items: OutpatientAmendmentReviewItem[];
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

export type ClinicalEntry = {
    public_id: string;
    entry_type: string;
    body: string;
    created_at: string | null;
    author_name: string | null;
};

export type LabOrder = {
    public_id: string;
    test_code: string;
    test_label: string;
    clinical_question: string | null;
    status: string;
    requested_at: string;
    requested_by_name: string | null;
    result: {
        public_id: string;
        status: string;
        result_text: string;
        issued_at: string;
        entered_by_name: string | null;
    } | null;
};

export type OutpatientEncounter = {
    public_id: string;
    status: string;
    clinic_name: string;
    doctor_name: string | null;
    schedule_label: string | null;
    payer_type: string;
    queue_number: number | null;
    registered_at: string | null;
    visit_date: string | null;
    chief_complaint: string | null;
    patient: {
        public_id: string | null;
        medical_record_number: string | null;
        full_name: string | null;
        date_of_birth: string | null;
        sex: string | null;
        nik: string | null;
    };
    lab_orders?: LabOrder[];
};

export type DocumentPermission = {
    can_save_draft: boolean;
    can_finalize: boolean;
};

export type DocumentActions = {
    save_draft_url: string;
    finalize_url: string;
};

export type OutpatientDispositionType =
    'KONTROL_ULANG' | 'SEMBUH' | 'RAWAT_INAP';

export type OutpatientDisposition = {
    current: {
        public_id: string;
        version: number;
        code: OutpatientDispositionType;
        payload: Record<string, string | null>;
        signed_at: string | null;
        physician_name: string | null;
        bound_medical_document_version: number;
    } | null;
    pending_handoff: boolean;
};

export type OutpatientShowProps = {
    variant: 'rawat-jalan';
    encounter: OutpatientEncounter;
    legacyEntries: ClinicalEntry[];
    documentation: {
        definition_version: string;
        source_fingerprint: string;
        documents: ClinicalDocument[];
        active_drafts: ClinicalDocument[];
        versions: ClinicalDocumentVersion[];
        terminology_lookup_url?: string;
    };
    permissions: {
        nursing: DocumentPermission;
        medical: DocumentPermission;
        can_create_lab_order: boolean;
        can_request_amendment?: boolean;
        can_sign_disposition?: boolean;
        can_correct_disposition?: boolean;
    };
    actions: {
        nursing: DocumentActions;
        medical: DocumentActions;
        store_lab_order_url: string;
        store_amendment_url?: string | null;
        sign_disposition_url?: string | null;
        correct_disposition_url?: string | null;
    };
    disposition?: OutpatientDisposition;
    labTestOptions: Array<{ code: string; label: string }>;
    amendmentReasonOptions?: AmendmentReasonOption[];
    amendments?: OutpatientAmendment[];
    laboratory?: LaboratoryEncounterProjection;
    radiology?: RadiologyEncounterProjection;
    pharmacy?: PharmacyEncounterProjection;
};
