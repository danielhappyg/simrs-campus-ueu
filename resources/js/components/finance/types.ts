export type FinanceCareSetting = 'OUTPATIENT' | 'EMERGENCY' | 'INPATIENT';
export type FinanceSourceDomain =
    'PHARMACY' | 'RADIOLOGY' | 'LABORATORY' | 'ACCOMMODATION';

export type FinanceReadinessState =
    | 'SIAP_DISINKRONKAN'
    | 'TERSINKRONISASI'
    | 'TARIF_BELUM_DIPETAKAN'
    | 'TARIF_TIDAK_EFEKTIF'
    | 'KONTEKS_TIDAK_COCOK'
    | 'BUKTI_TIDAK_KONSISTEN'
    | 'INTERVAL_MASIH_TERBUKA'
    | 'RIWAYAT_LOKASI_TIDAK_LENGKAP';

export type FinanceBillState =
    'OPEN_NO_VERSION' | 'ISSUED_CURRENT' | 'NEW_SOURCE_PENDING';

export type FinanceCashSettlementCorrectionReasonCode =
    | 'WRONG_BILL'
    | 'DUPLICATE_COLLECTION'
    | 'CASHIER_INPUT_CONTEXT_ERROR'
    | 'OTHER_SUPERVISOR_REVIEW';

export type FinanceCashSettlementCorrectionState =
    | 'ACTIVE'
    | 'CORRECTION_REQUESTED'
    | 'REVIEW_REJECTED'
    | 'REFUND_APPROVED'
    | 'REFUND_COMPLETED';

export type FinanceCashSettlementCorrectionEventType =
    'REVIEW_REJECTED' | 'REFUND_APPROVED' | 'REFUND_COMPLETED';

export type FinancePatient = {
    public_id: string;
    full_name: string;
    medical_record_number: string;
};

export type FinanceEncounter = {
    public_id: string;
    encounter_number: string;
    care_setting: FinanceCareSetting;
    service_location: string;
    patient: FinancePatient;
};

export type FinanceTotals = {
    gross_amount: number;
    reversal_amount: number;
    net_amount: number;
};

export type FinanceSourceReadinessItem = {
    public_id: string;
    source_domain: 'RADIOLOGY' | 'LABORATORY' | 'ACCOMMODATION';
    source_reference: string;
    description: string;
    service_at: string;
    state: FinanceReadinessState;
    state_label: string;
    detail: string;
};

export type FinanceSourceReadiness = {
    resolved_count: number;
    unresolved_count: number;
    issue_blocked: boolean;
    issue_blocker: string | null;
    items: FinanceSourceReadinessItem[];
};

export type FinanceBillSummary = {
    public_id: string;
    bill_number: string;
    state: FinanceBillState;
    current_version: number;
    current_source_event_count: number;
    pending_source_count: number;
    synchronization_available: boolean;
    latest_source_at: string | null;
    fingerprint: string;
    encounter: FinanceEncounter;
    totals: FinanceTotals;
    source_readiness: FinanceSourceReadiness;
    actions: {
        show_url: string;
        synchronize_url: string | null;
    };
};

export type FinanceSynchronizationCandidate = {
    encounter_public_id: string;
    care_setting: FinanceCareSetting;
    encounter_status: string;
    location_label: string;
    patient: FinancePatient;
    source_event_count: number;
    gross_amount: number;
    reversal_amount: number;
    net_amount: number;
    latest_source_at: string | null;
    source_readiness: FinanceSourceReadiness;
    synchronize_url: string;
};

export type FinanceRadiologyTariffProvenance = {
    binding_public_id: string;
    binding_version_public_id: string;
    binding_version: number;
    binding_content_digest: string;
    radiology_master_version_public_id: string;
    radiology_master_version: number;
    radiology_master_code: string;
    radiology_master_content_digest: string;
    tariff_item_public_id: string;
    tariff_item_version_public_id: string;
    tariff_item_version: number;
    tariff_code: string;
    tariff_content_digest: string;
    component_public_id: string;
    component_code: string;
    component_content_digest: string;
    service_date: string;
    effective_from: string;
};

export type FinanceLaboratoryTariffProvenance = {
    binding_public_id: string;
    binding_version_public_id: string;
    binding_version: number;
    binding_content_digest: string;
    laboratory_result_public_id: string;
    laboratory_result_version: number;
    laboratory_result_content_digest: string;
    laboratory_verified_at: string;
    laboratory_specimen_public_id: string;
    laboratory_master_version_public_id: string;
    laboratory_master_version: number;
    laboratory_master_code: string;
    laboratory_master_content_digest: string;
    tariff_item_public_id: string;
    tariff_item_version_public_id: string;
    tariff_item_version: number;
    tariff_code: string;
    tariff_content_digest: string;
    component_public_id: string;
    component_code: string;
    component_content_digest: string;
    service_date: string;
    effective_from: string;
};

export type FinanceAccommodationTariffProvenance = {
    binding_public_id: string;
    binding_version_public_id: string;
    binding_version: number;
    binding_content_digest: string;
    opening_location_event_public_id: string;
    opening_location_event_digest: string;
    closing_type: 'BED_TRANSFER' | 'ROUTINE_DISCHARGE';
    closing_public_id: string;
    closing_content_digest: string;
    bed_public_id: string;
    bed_code: string;
    inpatient_bed_version_public_id: string;
    inpatient_bed_version: number;
    inpatient_bed_content_digest: string;
    ward_code: string;
    room_label: string;
    service_class: string;
    pricing_unit: 'OCCUPANCY_DAY';
    occupancy_anchor_at: string;
    interval_start_at: string;
    interval_end_at: string;
    tariff_item_public_id: string;
    tariff_item_version_public_id: string;
    tariff_item_code: string;
    tariff_content_digest: string;
    component_public_id: string;
    component_code: string;
    component_content_digest: string;
    service_date: string;
    effective_from: string;
};

type FinanceSourceLineBase = {
    public_id: string;
    source_reference: string;
    event_type: 'CHARGE' | 'REVERSAL';
    description: string;
    quantity: number;
    unit_amount: number;
    signed_amount: number;
    occurred_at: string | null;
};

export type FinanceSourceLine = FinanceSourceLineBase &
    (
        | { source_domain: 'PHARMACY'; tariff_provenance: null }
        | {
              source_domain: 'RADIOLOGY';
              tariff_provenance: FinanceRadiologyTariffProvenance;
          }
        | {
              source_domain: 'LABORATORY';
              tariff_provenance: FinanceLaboratoryTariffProvenance;
          }
        | {
              source_domain: 'ACCOMMODATION';
              tariff_provenance: FinanceAccommodationTariffProvenance;
          }
    );

export type FinanceBillVersion = FinanceTotals & {
    public_id: string;
    version: number;
    source_event_count: number;
    issue_reason: string;
    issued_by_user_id: number;
    issued_at: string | null;
    coverage_profile: string;
    coverage_label: string;
    lines: FinanceSourceLine[];
};

export type FinanceCashSettlementSummary = {
    public_id: string;
    receipt_number: string;
    amount: number;
    payment_method: 'CASH';
    state: 'SETTLED';
    settled_at: string;
    content_digest: string;
    correction_state: FinanceCashSettlementCorrectionState;
    correction_public_id: string | null;
    correction_request_available: boolean;
    correction_url: string | null;
    receipt_url: string;
};

export type FinanceCashSettlementContext = {
    bill_public_id: string;
    bill_number: string;
    bill_state: FinanceBillState;
    bill_fingerprint: string;
    bill_version_public_id: string | null;
    bill_version: number | null;
    bill_version_content_digest: string | null;
    amount: number | null;
    payment_method: 'CASH';
    settlement_available: boolean;
    settlement: FinanceCashSettlementSummary | null;
};

export type FinanceCashReceipt = {
    public_id: string;
    receipt_number: string;
    bill_number: string;
    bill_version: number;
    encounter_public_id: string;
    patient_name: string;
    medical_record_number: string;
    care_setting: FinanceCareSetting;
    payment_method: 'CASH';
    state: 'SETTLED';
    amount: number;
    settled_at: string;
    cashier_name: string;
    coverage_profile: string;
    coverage_label: string;
    coverage_exclusion: string;
    source_domains: FinanceSourceDomain[];
    content_digest: string;
    correction_state: FinanceCashSettlementCorrectionState;
    correction_public_id: string | null;
    correction_url: string | null;
};

export type FinanceCashSettlementCorrectionEvent = {
    public_id: string;
    sequence: number;
    event_type: FinanceCashSettlementCorrectionEventType;
    actor_name: string;
    explanation: string | null;
    amount: number | null;
    occurred_at: string;
    content_digest: string;
};

export type FinanceCashSettlementCorrectionCase = {
    public_id: string;
    correction_number: string;
    settlement_public_id: string;
    receipt_number: string;
    amount: number;
    reason_code: FinanceCashSettlementCorrectionReasonCode;
    explanation: string;
    requesting_cashier_name: string;
    requester_is_actor: boolean;
    requested_at: string;
    state: Exclude<FinanceCashSettlementCorrectionState, 'ACTIVE'>;
    fingerprint: string;
    events: FinanceCashSettlementCorrectionEvent[];
    show_url: string;
    refund_receipt_url: string | null;
};

export type FinanceCashSettlementCorrectionWorklistProps = {
    definition_version: 'APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1';
    generated_at: string;
    cases: FinanceCashSettlementCorrectionCase[];
};

export type FinanceCashSettlementCorrectionDetailProps = {
    definition_version: 'APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1';
    generated_at: string;
    case: FinanceCashSettlementCorrectionCase;
    permissions: {
        can_review: boolean;
        can_complete_refund: boolean;
    };
    commands: {
        review_url: string;
        complete_refund_url: string;
    };
    back_url: string;
};

export type FinanceCashSettlementRefundReceipt = {
    correction_public_id: string;
    correction_number: string;
    original_settlement_public_id: string;
    original_receipt_number: string;
    amount: number;
    reason_code: FinanceCashSettlementCorrectionReasonCode;
    explanation: string;
    requesting_cashier_name: string;
    approving_supervisor_name: string;
    completion_actor_name: string;
    requested_at: string;
    approved_at: string;
    completed_at: string;
    state: 'REFUND_COMPLETED';
    content_digest: string;
};

export type FinanceCashSettlementRefundReceiptProps = {
    definition_version: 'APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1';
    generated_at: string;
    receipt: FinanceCashSettlementRefundReceipt;
    back_url: string;
};

export type FinanceWorklistProps = {
    definition_version: 'CROSS_SETTING_VERSIONED_ENCOUNTER_BILL_V1';
    generated_at: string;
    bills: FinanceBillSummary[];
    synchronization_candidates: FinanceSynchronizationCandidate[];
    coverage: {
        profile: string;
        label: string;
        excluded_label: string;
        domains: Array<{
            domain: FinanceSourceDomain;
            label: string;
        }>;
    };
    read_error?: string | null;
};

export type FinanceBillDetailProps = {
    definition_version: 'CROSS_SETTING_VERSIONED_ENCOUNTER_BILL_V1';
    generated_at: string;
    bill: FinanceBillSummary & {
        current_sources: FinanceSourceLine[];
        versions: FinanceBillVersion[];
    };
    coverage: FinanceWorklistProps['coverage'];
    settlement: FinanceCashSettlementContext;
    permissions: {
        can_issue: boolean;
        can_settle: boolean;
        can_request_correction: boolean;
    };
    commands: {
        issue_url: string | null;
        settlement_url: string | null;
        correction_request_url: string | null;
    };
    read_error?: string | null;
};

export type FinanceCashReceiptProps = {
    definition_version: 'EXACT_CASH_SETTLEMENT_AND_RECEIPT_V1';
    generated_at: string;
    receipt: FinanceCashReceipt;
    back_url: string;
};
