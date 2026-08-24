export type ClinicalDocumentType = 'NURSING_ASSESSMENT' | 'MEDICAL_ASSESSMENT';

export type ClinicalDocument = {
    public_id: string;
    document_type: ClinicalDocumentType;
    document_state: 'DRAFT' | 'FINAL';
    definition_version: string;
    version: number;
    fields: Record<string, string | null>;
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
    fields: Record<string, string | null>;
    actor_name: string | null;
    created_at: string | null;
    finalized_at: string | null;
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
    };
    permissions: {
        nursing: DocumentPermission;
        medical: DocumentPermission;
        can_create_lab_order: boolean;
    };
    actions: {
        nursing: DocumentActions;
        medical: DocumentActions;
        store_lab_order_url: string;
    };
    labTestOptions: Array<{ code: string; label: string }>;
};
