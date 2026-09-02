export type FinanceCashierCollectionState =
    | 'OPEN'
    | 'RECOUNT_REQUIRED'
    | 'AWAITING_SUPERVISOR'
    | 'VERIFIED'
    | 'HANDED_OFF';

export type FinanceCashierCollectionEventType =
    'CLOSE_REQUESTED' | 'RECOUNT_SUBMITTED' | 'CLOSE_VERIFIED';

export type FinanceCashierCollectionIntegrity = {
    status: 'OK' | 'FAILED';
    message: string | null;
};

export type FinanceCashierCollectionAction = {
    allowed: boolean;
    url: string | null;
    denial_reason: string | null;
};

export type FinanceCashierCollectionMember = {
    public_id: string;
    settlement_public_id: string;
    receipt_number: string;
    amount: number;
    collected_at: string;
    settlement_content_digest: string;
    content_digest: string;
};

export type FinanceCashierCollectionEvent = {
    public_id: string;
    sequence: number;
    event_type: FinanceCashierCollectionEventType;
    actor_name: string;
    membership_count: number;
    gross_amount: number;
    completed_refund_amount: number;
    expected_net_amount: number;
    counted_amount: number;
    variance_amount: number;
    explanation: string | null;
    occurred_at: string;
    membership_digest: string;
    content_digest: string;
};

export type FinanceCashDepositHandoffSummary = {
    public_id: string;
    handoff_number: string;
    handed_off_at: string;
    supervisor_name: string;
    receipt_url: string;
    content_digest: string;
};

export type FinanceCashierCollectionBatch = {
    public_id: string;
    batch_number: string;
    cashier_name: string;
    opened_at: string;
    frozen_at: string | null;
    verified_at: string | null;
    handed_off_at: string | null;
    state: FinanceCashierCollectionState;
    membership_count: number;
    gross_amount: number;
    completed_refund_amount: number;
    expected_net_amount: number;
    counted_amount: number | null;
    variance_amount: number | null;
    content_digest: string;
    state_fingerprint: string;
    integrity: FinanceCashierCollectionIntegrity;
    members: FinanceCashierCollectionMember[];
    events: FinanceCashierCollectionEvent[];
    handoff: FinanceCashDepositHandoffSummary | null;
    show_url: string;
};

export type FinanceCashierCollectionWorklistProps = {
    definition_version: 'APPEND_ONLY_CASHIER_COLLECTION_BATCH_V1';
    generated_at: string;
    actor_role: 'cashier' | 'cashier_supervisor';
    batches: FinanceCashierCollectionBatch[];
    open_batch: FinanceCashierCollectionAction;
    read_error?: string | null;
};

export type FinanceCashierCollectionDetailProps = {
    definition_version: 'APPEND_ONLY_CASHIER_COLLECTION_BATCH_V1';
    generated_at: string;
    actor_role: 'cashier' | 'cashier_supervisor';
    batch: FinanceCashierCollectionBatch;
    actions: {
        request_close: FinanceCashierCollectionAction;
        recount: FinanceCashierCollectionAction;
        verify: FinanceCashierCollectionAction;
        create_handoff: FinanceCashierCollectionAction;
    };
    back_url: string;
    read_error?: string | null;
};

export type FinanceCashDepositHandoffReceipt = {
    public_id: string;
    handoff_number: string;
    batch_public_id: string;
    batch_number: string;
    cashier_name: string;
    supervisor_name: string;
    membership_count: number;
    gross_amount: number;
    completed_refund_amount: number;
    expected_net_amount: number;
    counted_amount: number;
    variance_amount: 0;
    opened_at: string;
    frozen_at: string;
    verified_at: string;
    handed_off_at: string;
    batch_content_digest: string;
    verified_event_digest: string;
    content_digest: string;
};

export type FinanceCashDepositHandoffReceiptProps = {
    definition_version: 'APPEND_ONLY_CASHIER_COLLECTION_BATCH_V1';
    generated_at: string;
    receipt: FinanceCashDepositHandoffReceipt;
    back_url: string;
};
