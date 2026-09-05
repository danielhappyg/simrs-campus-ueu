let fallback = 0;

export function newRadiologyOperationKey(operation: string): string {
    const suffix =
        globalThis.crypto?.randomUUID?.() ??
        `${Date.now().toString(36)}-${(++fallback).toString(36)}`;

    return `radiology-${operation}-${suffix}`.toLowerCase();
}

export function formatRadiologyDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Time unavailable';
    }

    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

export const radiologyFieldClass =
    'min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-xs outline-none transition focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/25 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500';
