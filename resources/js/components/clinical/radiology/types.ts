export type RadiologyCareSetting = 'OUTPATIENT' | 'EMERGENCY' | 'INPATIENT';
export type RadiologyOrderState =
    'ORDERED' | 'PERFORMED' | 'REPORTED_VERIFIED' | 'CANCELLED';
export type RadiologyReportState = 'DRAFT' | 'VERIFIED';

export type RadiologyOption = { value: string; label: string };

export type RadiologyExaminationOption = {
    public_id: string;
    code: string;
    display_name: string;
    preparation_instruction: string | null;
};

export type RadiologyAmendmentProjection = {
    public_id: string;
    version: number;
    reason: string;
    amended_statement: string;
    radiologist_name: string;
    signed_at: string;
};

export type RadiologyAcknowledgementProjection = {
    public_id: string;
    physician_name: string;
    acknowledged_at: string;
    is_current: boolean;
};

export type RadiologyReportProjection = {
    public_id: string;
    version: number;
    state: RadiologyReportState;
    examination: string;
    findings: string;
    impression: string;
    recommendation: string | null;
    radiologist_name: string;
    verified_at: string | null;
    amendments: RadiologyAmendmentProjection[];
    acknowledgement: RadiologyAcknowledgementProjection | null;
};

export type RadiologyOrderActions = {
    cancel_url: string | null;
    perform_url: string | null;
    save_report_url: string | null;
    verify_report_url: string | null;
    amend_report_url: string | null;
    acknowledge_url: string | null;
};

export type RadiologyOrderProjection = {
    public_id: string;
    version: number;
    state: RadiologyOrderState;
    ordered_at: string;
    performed_at: string | null;
    ordering_physician_name: string;
    performer_name: string | null;
    care_setting: RadiologyCareSetting;
    care_location_label: string;
    encounter_number: string;
    encounter_url: string;
    patient: {
        medical_record_number: string;
        display_name: string;
    };
    examination: RadiologyExaminationOption;
    clinical_question: string;
    cancellation: { reason_label: string; note: string | null } | null;
    report: RadiologyReportProjection | null;
    actions: RadiologyOrderActions;
};

export type RadiologyEncounterProjection = {
    definition_version: 'CROSS_SETTING_RADIOLOGY_ORDER_REPORT_V1';
    encounter: {
        public_id: string;
        care_setting: RadiologyCareSetting;
        status: string;
    };
    examination_options: RadiologyExaminationOption[];
    cancellation_reason_options: RadiologyOption[];
    orders: RadiologyOrderProjection[];
    permissions: {
        can_order: boolean;
        can_cancel_own_order: boolean;
        can_acknowledge_own_order: boolean;
    };
    commands: { create_order_url: string | null };
};

export type RadiologyWorklistProps = {
    generated_at: string;
    filters: {
        q: string;
        care_setting: '' | RadiologyCareSetting;
        state: '' | RadiologyOrderState;
    };
    filter_options: {
        care_settings: RadiologyOption[];
        states: RadiologyOption[];
    };
    orders: RadiologyOrderProjection[];
    permissions: { can_perform: boolean; can_report: boolean };
    amendment_reason_options: RadiologyOption[];
    read_error?: string | null;
};

export type RadiologyMasterProjection = {
    public_id: string;
    code: string;
    display_name: string;
    preparation_instruction: string | null;
    state: 'ACTIVE' | 'RETIRED';
    version: number;
    actions: { update_url: string | null; retire_url: string | null };
};

export type RadiologyMasterProps = {
    examinations: RadiologyMasterProjection[];
    permissions: { can_manage: boolean };
    commands: { create_url: string | null };
    read_error?: string | null;
};
