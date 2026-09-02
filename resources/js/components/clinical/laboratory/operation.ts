export const laboratoryFieldClass =
    'mt-1 min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 outline-none focus-visible:border-[#1b75bc] focus-visible:ring-2 focus-visible:ring-[#1b75bc]/30 disabled:bg-slate-100';

export function newLaboratoryOperationKey(operation: string): string {
    const suffix =
        typeof crypto !== 'undefined' && 'randomUUID' in crypto
            ? crypto.randomUUID()
            : `${Date.now()}-${Math.random().toString(16).slice(2)}`;

    return `laboratory-${operation}-${suffix}`;
}

export function formatLaboratoryDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
