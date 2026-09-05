export const pharmacyFieldClass =
    'mt-1 min-h-11 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-xs outline-none focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:bg-muted disabled:text-muted-foreground';

let fallback = 0;

export function newPharmacyOperationKey(operation: string): string {
    const suffix =
        globalThis.crypto?.randomUUID?.() ??
        `${Date.now().toString(36)}-${(++fallback).toString(36)}`;

    return `pharmacy-${operation}-${suffix}`.toLowerCase();
}

export function formatPharmacyDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

export function formatRupiah(value: number): string {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(value);
}
