export const emergencyFieldClass =
    'mt-1 min-h-11 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-xs outline-none focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:bg-muted disabled:text-muted-foreground';

let fallback = 0;

export function newEmergencyOperationKey(operation: string): string {
    const suffix =
        globalThis.crypto?.randomUUID?.() ??
        `${Date.now().toString(36)}-${(++fallback).toString(36)}`;

    return `emergency-${operation}-${suffix}`.toLowerCase();
}

export function formatEmergencyDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

export function toLocalDateTimeInput(value?: string | null): string {
    const date = value ? new Date(value) : new Date();
    const offset = date.getTimezoneOffset();

    return new Date(date.getTime() - offset * 60_000)
        .toISOString()
        .slice(0, 16);
}
