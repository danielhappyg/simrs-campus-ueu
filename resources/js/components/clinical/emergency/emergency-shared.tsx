import { AlertTriangle, Clock3 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { formatEmergencyDate } from './operation';
import type { EmergencyObservationState, EmergencyTriageCode } from './types';

export const triagePresentation: Record<
    EmergencyTriageCode,
    { label: string; cue: string; rail: string; chip: string }
> = {
    MERAH: {
        label: 'MERAH',
        cue: 'Immediate priority',
        rail: 'bg-[#c62828]',
        chip: 'border-[#c62828]/30 bg-[#c62828]/10 text-[#9f1e1e]',
    },
    KUNING: {
        label: 'KUNING',
        cue: 'Urgent priority',
        rail: 'bg-[#d89b00]',
        chip: 'border-[#d89b00]/40 bg-[#ffefb3] text-[#664900]',
    },
    HIJAU: {
        label: 'HIJAU',
        cue: 'Low priority',
        rail: 'bg-[#18864b]',
        chip: 'border-[#18864b]/30 bg-[#18864b]/10 text-[#0d6a38]',
    },
    HITAM: {
        label: 'HITAM',
        cue: 'Black category',
        rail: 'bg-[#1e293b]',
        chip: 'border-[#1e293b]/30 bg-[#1e293b] text-white',
    },
};

export const observationLabel: Record<EmergencyObservationState, string> = {
    ASSESSED_NO_CONCERN: 'Assessed · no concern',
    ASSESSED_CONCERN: 'Assessed · concern identified',
    NOT_ASSESSED: 'Not assessed',
};

export function TriageChip({
    code,
    cue,
}: {
    code: EmergencyTriageCode | null;
    cue?: string | null;
}) {
    if (!code) {
        return (
            <span className="inline-flex min-h-7 items-center rounded-full border border-dashed border-warning/50 bg-warning/10 px-2.5 text-xs font-semibold text-warning">
                Not triaged
            </span>
        );
    }

    const style = triagePresentation[code];

    return (
        <span
            className={cn(
                'inline-flex min-h-7 items-center gap-2 rounded-full border px-2.5 text-xs font-bold tracking-wide',
                style.chip,
            )}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'size-2 rounded-full ring-2 ring-white',
                    style.rail,
                )}
            />
            {style.label} · {cue || style.cue}
        </span>
    );
}

export function EmergencyEmptyState({
    title,
    body,
}: {
    title: string;
    body: string;
}) {
    return (
        <div className="rounded-lg border border-dashed border-border bg-muted/35 p-4 text-sm">
            <p className="font-semibold text-foreground">{title}</p>
            <p className="mt-1 text-muted-foreground">{body}</p>
        </div>
    );
}

export function PendingRequirement({
    complete,
    children,
}: {
    complete: boolean;
    children: React.ReactNode;
}) {
    return (
        <li className="flex items-start gap-2 text-sm">
            {complete ? (
                <span
                    aria-hidden="true"
                    className="mt-1 size-2.5 rounded-full bg-success"
                />
            ) : (
                <AlertTriangle
                    aria-hidden="true"
                    className="mt-0.5 size-4 shrink-0 text-warning"
                />
            )}
            <span className={complete ? 'text-foreground' : 'text-warning'}>
                {children}
            </span>
        </li>
    );
}

export function EvidenceTime({ value }: { value: string | null }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
            <Clock3 aria-hidden="true" className="size-3.5" />
            {formatEmergencyDate(value)}
        </span>
    );
}
