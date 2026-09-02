import type { InpatientSummaryAddendumProjection } from '@/components/clinical/inpatient/types';

export type SelectOption = { value: string; label: string };

export type RmikReviewStatus =
    'NOT_REVIEWED' | 'INCOMPLETE' | 'COMPLETE' | 'SIGNED_OFF';

export type CodingAssignment = {
    public_id?: string;
    kind: 'PRINCIPAL_DIAGNOSIS' | 'SECONDARY_DIAGNOSIS' | 'PROCEDURE';
    source_statement_kind: string;
    source_statement_index: number;
    source_statement_text_hash: string;
    code: string;
    description: string;
    source_statement?: string | null;
};

export type CodingSourceStatement = {
    kind: CodingAssignment['kind'];
    index: number;
    text: string;
    text_hash: string;
};

export type CompletenessItem = {
    code: string;
    label: string;
    status: 'PASS' | 'FAIL' | 'NOT_APPLICABLE';
    reason?: string | null;
};

export type InpatientRmEncounterRow = {
    public_id: string;
    status: string;
    discharged_at: string | null;
    last_ward_name: string | null;
    payer_type: string | null;
    review_status: RmikReviewStatus;
    review_version: number;
    patient: {
        medical_record_number: string | null;
        full_name: string | null;
    };
};

export type InpatientRmIndex = {
    filters: {
        q: string;
        ward: string;
        payer: string;
        review_state: string;
        discharged_from: string;
        discharged_to: string;
    };
    filter_options: {
        wards: SelectOption[];
        payers: SelectOption[];
        review_states: SelectOption[];
    };
    encounters: InpatientRmEncounterRow[];
    actions: { show_url: string };
};

export type InpatientRmDetail = {
    encounter: {
        public_id: string;
        status: string;
        discharged_at: string | null;
        last_ward_name: string | null;
        payer_type: string | null;
    };
    patient: { medical_record_number: string | null; full_name: string | null };
    discharge: {
        public_id: string | null;
        discharged_at: string | null;
        disposition_label: string | null;
    } | null;
    discharge_summary: {
        state: string | null;
        version: number | null;
        fields?: Record<string, string | null>;
    } | null;
    coding_source: {
        state: string | null;
        version: number | null;
        principal_diagnosis_statement?: string | null;
        secondary_diagnosis_statements?: string[];
        performed_procedure_statements?: string[];
    } | null;
    coding: {
        version: number;
        content_digest: string;
        state: 'DRAFT' | 'FINAL' | null;
        source_statements: CodingSourceStatement[];
        assignments: CodingAssignment[];
        history: Array<{
            version: number;
            assignments: CodingAssignment[];
            actor_name: string | null;
            created_at: string | null;
        }>;
        can_save_draft: boolean;
    };
    completeness: {
        status: RmikReviewStatus;
        version: number;
        source_fingerprint: string;
        items: CompletenessItem[];
        blockers: Array<{ code: string; label: string; reason: string }>;
        reviewer_name: string | null;
        reviewed_at: string | null;
        signed_off_by_name: string | null;
        signed_off_at: string | null;
        can_save_review: boolean;
        can_signoff: boolean;
    };
    history: Array<{
        event: string;
        actor_name: string | null;
        occurred_at: string | null;
        detail?: string | null;
    }>;
    summary_addendum: InpatientSummaryAddendumProjection;
    actions: {
        save_coding_draft_url: string | null;
        save_review_url: string | null;
        signoff_url: string | null;
    };
};

let idempotencyCounter = 0;

export function newInpatientRmIdempotencyKey(operation: string) {
    const random =
        globalThis.crypto?.randomUUID?.() ??
        `${Date.now().toString(36)}-${(++idempotencyCounter).toString(36)}`;

    return `inpatient-rmik-${operation}-${random}`.toLowerCase();
}

export const reviewStatusLabel: Record<RmikReviewStatus, string> = {
    NOT_REVIEWED: 'Belum ditinjau',
    INCOMPLETE: 'Belum lengkap',
    COMPLETE: 'Lengkap, siap ditutup',
    SIGNED_OFF: 'Episode ditutup oleh RM',
};
