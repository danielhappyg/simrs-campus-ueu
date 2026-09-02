import type {
    InpatientDailyDocumentState,
    InpatientDailyDocumentType,
} from './types';

export const inpatientDocumentTypeLabel: Record<
    InpatientDailyDocumentType,
    string
> = {
    NURSING_DAILY: 'Catatan harian keperawatan',
    MEDICAL_DAILY: 'Catatan harian medis',
};

export const inpatientDocumentStateLabel: Record<
    InpatientDailyDocumentState,
    string
> = {
    DRAFT: 'Draf',
    FINAL: 'Final',
};

export const inpatientStatusLabel: Record<string, string> = {
    REGISTERED: 'Terdaftar',
    IN_EXAMINATION: 'Dalam pemeriksaan',
    READY_FOR_RM: 'Siap RM',
    CANCELLED: 'Dibatalkan',
    CLOSED: 'Ditutup',
};

export function formatClinicalDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: value.includes('T') ? 'short' : undefined,
        timeZone: 'Asia/Jakarta',
    }).format(date);
}
