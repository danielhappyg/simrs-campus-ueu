import type {
    InpatientDailyDocumentState,
    InpatientDailyDocumentType,
} from './types';

export const inpatientDocumentTypeLabel: Record<
    InpatientDailyDocumentType,
    string
> = {
    NURSING_DAILY: 'Daily nursing note',
    MEDICAL_DAILY: 'Daily medical note',
};

export const inpatientDocumentStateLabel: Record<
    InpatientDailyDocumentState,
    string
> = {
    DRAFT: 'Draft',
    FINAL: 'Final',
};

export const inpatientStatusLabel: Record<string, string> = {
    REGISTERED: 'Registered',
    IN_EXAMINATION: 'In examination',
    READY_FOR_RM: 'Ready for medical records',
    CANCELLED: 'Cancelled',
    CLOSED: 'Closed',
};

export function formatClinicalDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: value.includes('T') ? 'short' : undefined,
        timeZone: 'Asia/Jakarta',
    }).format(date);
}
