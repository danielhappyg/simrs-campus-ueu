export type LaboratoryCareSetting = 'OUTPATIENT' | 'EMERGENCY' | 'INPATIENT';
export type LaboratoryOrderState =
    'ORDERED' | 'SPECIMEN_ACCEPTED' | 'REPORTED_VERIFIED' | 'CANCELLED';
export type LaboratorySpecimenState =
    'COLLECTED' | 'RECEIVED' | 'ACCEPTED' | 'REJECTED';
export type LaboratoryResultState = 'DRAFT' | 'VERIFIED';
export type LaboratoryValueKind = 'TEXT' | 'NUMERIC' | 'QUALITATIVE';
export type LaboratoryInterpretation = 'NORMAL' | 'ABNORMAL' | 'CRITICAL';
export type LaboratoryOrderSource = 'GOVERNED' | 'LEGACY_READ_ONLY';

export type LaboratoryOption = { value: string; label: string };

export type LaboratoryComponentDefinition = {
    code: string;
    display_name: string;
    value_kind: LaboratoryValueKind;
    unit_text: string | null;
    reference_text: string | null;
    critical_allowed: boolean;
};

export type LaboratoryExaminationOption = {
    public_id: string;
    code: string;
    display_name: string;
    specimen_type: string;
    collection_instruction: string | null;
    components: LaboratoryComponentDefinition[];
};

export type LaboratoryComponentResult = {
    code: string;
    display_name: string;
    value_kind: LaboratoryValueKind;
    value: string;
    unit_text: string | null;
    reference_text: string | null;
    interpretation: LaboratoryInterpretation;
    note: string | null;
};

export type LaboratorySpecimenProjection = {
    public_id: string;
    attempt_number: number;
    label_identifier: string;
    state: LaboratorySpecimenState;
    collected_at: string;
    collector_name: string;
    collection_note: string | null;
    received_at: string | null;
    receiver_name: string | null;
    assessed_at: string | null;
    assessor_name: string | null;
    rejection_reason_label: string | null;
    rejection_note: string | null;
};

export type LaboratoryCriticalCommunication = {
    communicated_at: string;
    method_label: string;
    recipient_physician_name: string;
    outcome_label: string;
    note: string | null;
};

export type LaboratoryAmendmentProjection = {
    public_id: string;
    version: number;
    reason_label: string;
    signer_name: string;
    signed_at: string;
    components: LaboratoryComponentResult[];
    critical_communication?: LaboratoryCriticalCommunication | null;
};

export type LaboratoryAcknowledgementProjection = {
    public_id: string;
    physician_name: string;
    acknowledged_at: string;
    is_current: boolean;
};

export type LaboratoryResultProjection = {
    public_id: string;
    version: number;
    state: LaboratoryResultState;
    author_name: string;
    saved_at: string;
    verifier_name: string | null;
    verified_at: string | null;
    components: LaboratoryComponentResult[];
    critical_communication: LaboratoryCriticalCommunication | null;
    amendments: LaboratoryAmendmentProjection[];
    acknowledgement: LaboratoryAcknowledgementProjection | null;
};

export type LaboratoryOrderActions = {
    cancel_url: string | null;
    collect_url: string | null;
    receive_url: string | null;
    accept_url: string | null;
    reject_url: string | null;
    save_result_url: string | null;
    verify_result_url: string | null;
    amend_result_url: string | null;
    acknowledge_url: string | null;
};

export type LaboratoryOrderProjection = {
    source?: LaboratoryOrderSource;
    public_id: string;
    version: number;
    state: LaboratoryOrderState;
    priority: 'ROUTINE' | 'URGENT';
    ordered_at: string;
    ordering_physician_public_id: string | null;
    ordering_physician_name: string;
    care_setting: LaboratoryCareSetting;
    care_location_label: string;
    encounter_number: string;
    encounter_url: string;
    patient: {
        medical_record_number: string;
        display_name: string;
    };
    examination: LaboratoryExaminationOption;
    clinical_question: string;
    cancellation: { reason_label: string; note: string | null } | null;
    specimens: LaboratorySpecimenProjection[];
    accepted_specimen_public_id: string | null;
    result: LaboratoryResultProjection | null;
    actions: LaboratoryOrderActions;
};

export type LaboratoryEncounterProjection = {
    definition_version: 'CROSS_SETTING_LABORATORY_SPECIMEN_RESULT_V1';
    encounter: {
        public_id: string;
        care_setting: LaboratoryCareSetting;
        status: string;
    };
    examination_options: LaboratoryExaminationOption[];
    priority_options: LaboratoryOption[];
    cancellation_reason_options: LaboratoryOption[];
    rejection_reason_options: LaboratoryOption[];
    orders: LaboratoryOrderProjection[];
    permissions: {
        can_order: boolean;
        can_cancel_own_order: boolean;
        can_collect: boolean;
        can_acknowledge_own_order: boolean;
    };
    commands: { create_order_url: string | null };
};

export type LaboratoryWorklistProps = {
    generated_at: string;
    filters: {
        q: string;
        care_setting: '' | LaboratoryCareSetting;
        state: '' | LaboratoryOrderState;
        priority: '' | 'ROUTINE' | 'URGENT';
    };
    filter_options: {
        care_settings: LaboratoryOption[];
        states: LaboratoryOption[];
        priorities: LaboratoryOption[];
    };
    orders: LaboratoryOrderProjection[];
    permissions: {
        can_collect: boolean;
        can_process_specimen: boolean;
        can_save_result: boolean;
        can_verify_result: boolean;
    };
    rejection_reason_options: LaboratoryOption[];
    interpretation_options: LaboratoryOption[];
    communication_method_options: LaboratoryOption[];
    communication_outcome_options: LaboratoryOption[];
    critical_communication_recipient_options: LaboratoryOption[];
    amendment_reason_options: LaboratoryOption[];
    read_error?: string | null;
};

export type LaboratoryMasterProjection = {
    public_id: string;
    code: string;
    display_name: string;
    specimen_type: string;
    collection_instruction: string | null;
    state: 'ACTIVE' | 'RETIRED';
    version: number;
    components: LaboratoryComponentDefinition[];
    actions: { update_url: string | null; retire_url: string | null };
};

export type LaboratoryMasterProps = {
    examinations: LaboratoryMasterProjection[];
    value_kind_options: LaboratoryOption[];
    permissions: { can_manage: boolean };
    commands: { create_url: string | null };
    read_error?: string | null;
};
