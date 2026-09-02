export type PharmacyCareSetting = 'OUTPATIENT' | 'EMERGENCY' | 'INPATIENT';

export type PharmacyPrescriptionState =
    | 'DRAFT'
    | 'ORDERED'
    | 'VERIFIED'
    | 'REFUSED'
    | 'PREPARED'
    | 'PARTIALLY_HANDED_OVER'
    | 'HANDED_OVER'
    | 'UNFILLED_CLOSED'
    | 'CANCELLED';

export type PharmacyOption = { value: string; label: string };

export type PharmacyPatient = {
    public_id: string | null;
    medical_record_number: string | null;
    full_name: string | null;
    date_of_birth: string | null;
    sex: string | null;
};

export type PharmacyEncounterSummary = {
    public_id: string;
    care_setting: PharmacyCareSetting;
    status: string;
    location_label: string;
    registered_at: string | null;
    patient: PharmacyPatient;
};

export type PharmacyMedicineSummary = {
    public_id: string;
    code: string;
    generic_display_name: string;
    brand_display_name: string | null;
    strength_text: string;
    dosage_form: string;
    base_issue_unit: string;
    route_choices: string[];
    state: 'ACTIVE' | 'RETIRED';
    version: number;
};

export type PharmacyDepotSummary = {
    public_id: string;
    code: string;
    display_name: string;
    eligible_care_settings: PharmacyCareSetting[];
    state: 'ACTIVE' | 'RETIRED';
    version: number;
};

export type PharmacyPrescriptionItem = {
    public_id: string;
    medicine: PharmacyMedicineSummary;
    dose_text: string;
    route: string;
    frequency_text: string;
    duration_text: string;
    requested_quantity: number;
    verified_quantity: number | null;
    handed_over_quantity: number;
    returned_quantity: number;
    remaining_quantity: number;
    clinical_instruction: string;
};

export type PharmacyVerificationItem = {
    prescription_item_public_id: string;
    medicine_label: string;
    requested_quantity: number;
    verified_quantity: number;
    reduction_reason: string | null;
};

export type PharmacyVerification = {
    public_id: string;
    state: 'VERIFIED' | 'REFUSED';
    version: number;
    pharmacist_name: string | null;
    identity_context_confirmed: boolean;
    instruction_readability_confirmed: boolean;
    manual_allergy_review_status: string;
    note: string | null;
    refusal_reason: string | null;
    items: PharmacyVerificationItem[];
    recorded_at: string | null;
    fingerprint: string;
};

export type PharmacyLotAllocation = {
    public_id: string;
    prescription_item_public_id: string;
    medicine_label: string;
    lot_public_id: string;
    lot_code: string;
    expiry_date: string | null;
    no_expiry_reason: string | null;
    quantity: number;
};

export type PharmacyPreparation = {
    public_id: string;
    version: number;
    technician_name: string | null;
    note: string | null;
    allocations: PharmacyLotAllocation[];
    prepared_at: string | null;
    fingerprint: string;
};

export type PharmacyHandover = {
    public_id: string;
    sequence: number;
    pharmacist_name: string | null;
    unfilled_quantity: number;
    partial_reason: string | null;
    items: Array<{
        public_id: string;
        prescription_item_public_id: string;
        medicine_label: string;
        lot_code: string;
        quantity: number;
        returnable_quantity: number;
    }>;
    handed_over_at: string | null;
    fingerprint: string;
};

export type PharmacyReturnCondition =
    'RETURN_TO_STOCK' | 'QUARANTINE' | 'DESTROYED_OR_NOT_RETURNABLE';

export type PharmacyReturn = {
    public_id: string;
    sequence: number;
    prescription_item_public_id: string;
    medicine_label: string;
    lot_code: string;
    quantity: number;
    condition: PharmacyReturnCondition;
    reason: string;
    pharmacist_name: string | null;
    returned_at: string | null;
    fingerprint: string;
};

export type PharmacyPrescriptionEvent = {
    public_id: string;
    state: PharmacyPrescriptionState;
    version: number;
    actor_name: string | null;
    reason: string | null;
    occurred_at: string | null;
    fingerprint: string | null;
};

export type PharmacyControlTotals = {
    ordered_quantity: number;
    verified_quantity: number;
    handed_over_quantity: number;
    returned_to_stock_quantity: number;
    quarantined_return_quantity: number;
    non_returnable_quantity: number;
    gross_charge_source_rupiah: number;
    reversed_charge_source_rupiah: number;
    net_charge_source_rupiah: number;
};

export type PharmacyPrescriptionActions = {
    save_draft_url: string | null;
    order_url: string | null;
    replace_url: string | null;
    cancel_url: string | null;
    verify_url: string | null;
    refuse_url: string | null;
    prepare_url: string | null;
    handover_url: string | null;
    close_unfilled_url: string | null;
    return_url: string | null;
};

export type PharmacyPrescriptionProjection = {
    public_id: string;
    version: number;
    state: PharmacyPrescriptionState;
    fingerprint: string;
    encounter: PharmacyEncounterSummary;
    depot: PharmacyDepotSummary;
    ordering_physician: { public_id: string | null; name: string | null };
    clinical_note: string;
    items: PharmacyPrescriptionItem[];
    verification: PharmacyVerification | null;
    preparation: PharmacyPreparation | null;
    handovers: PharmacyHandover[];
    returns: PharmacyReturn[];
    history: PharmacyPrescriptionEvent[];
    control_totals: PharmacyControlTotals;
    actions: PharmacyPrescriptionActions;
};

export type PharmacyEncounterProjection = {
    definition_version: 'CROSS_SETTING_MEDICATION_DISPENSING_STOCK_LEDGER_V1';
    encounter: PharmacyEncounterSummary;
    medicine_options: PharmacyMedicineSummary[];
    depot_options: PharmacyDepotSummary[];
    prescriptions: PharmacyPrescriptionProjection[];
    permissions: {
        can_prescribe: boolean;
        can_view: boolean;
    };
    commands: { create_draft_url: string | null };
};

export type PharmacyWorklistProps = {
    generated_at: string;
    prescriptions: PharmacyPrescriptionProjection[];
    filters: {
        q: string;
        care_setting: '' | PharmacyCareSetting;
        state: '' | PharmacyPrescriptionState;
        depot: string;
    };
    filter_options: {
        care_settings: PharmacyOption[];
        states: PharmacyOption[];
        depots: PharmacyOption[];
    };
    permissions: {
        can_verify: boolean;
        can_prepare: boolean;
        can_handover: boolean;
        can_return: boolean;
    };
    read_error?: string | null;
};

export type PharmacyPrescriptionPageProps = {
    prescription: PharmacyPrescriptionProjection;
    permissions: PharmacyWorklistProps['permissions'];
    read_error?: string | null;
};

export type PharmacyMedicineMaster = PharmacyMedicineSummary & {
    standard_acquisition_value_rupiah: number;
    teaching_sale_value_rupiah: number;
    actions: { update_url: string | null; retire_url: string | null };
};

export type PharmacyDepotMaster = PharmacyDepotSummary & {
    actions: { update_url: string | null; retire_url: string | null };
};

export type PharmacyLotProjection = {
    public_id: string;
    state: 'ACTIVE' | 'QUARANTINED' | 'RETIRED';
    medicine: PharmacyMedicineSummary;
    depot: PharmacyDepotSummary;
    lot_code: string;
    opened_at: string | null;
    expiry_date: string | null;
    no_expiry_reason: string | null;
    available_quantity: number;
    quarantined_quantity: number;
    acquisition_value_rupiah: number;
    source_reference: string;
    fingerprint: string;
    actions: {
        quarantine_url: string | null;
        release_url: string | null;
        correct_url: string | null;
    };
};

export type PharmacyMasterProps = {
    medicines: PharmacyMedicineMaster[];
    depots: PharmacyDepotMaster[];
    lots: PharmacyLotProjection[];
    route_options: PharmacyOption[];
    dosage_form_options: PharmacyOption[];
    care_setting_options: PharmacyOption[];
    permissions: {
        can_manage_medicines: boolean;
        can_manage_depots: boolean;
        can_manage_inventory: boolean;
    };
    commands: {
        create_medicine_url: string | null;
        create_depot_url: string | null;
        open_lot_url: string | null;
    };
    read_error?: string | null;
};

export type PharmacyStockMovement = {
    public_id: string;
    sequence: number;
    movement_type: string;
    available_delta: number;
    quarantined_delta: number;
    available_balance: number;
    quarantined_balance: number;
    reason: string | null;
    source_reference: string | null;
    actor_name: string | null;
    occurred_at: string | null;
    fingerprint: string;
};

export type PharmacyStockCardLot = PharmacyLotProjection & {
    movements: PharmacyStockMovement[];
    reconciled: boolean;
};

export type PharmacyStockCardProps = {
    generated_at: string;
    lots: PharmacyStockCardLot[];
    filters: { q: string; medicine: string; depot: string; state: string };
    filter_options: {
        medicines: PharmacyOption[];
        depots: PharmacyOption[];
        states: PharmacyOption[];
    };
    read_error?: string | null;
};
