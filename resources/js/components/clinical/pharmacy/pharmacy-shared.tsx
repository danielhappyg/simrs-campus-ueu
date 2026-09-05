import { Link } from '@inertiajs/react';
import { Clock3, PackageCheck } from 'lucide-react';
import { cn } from '@/lib/utils';
import { formatPharmacyDate } from './operation';
import type { PharmacyCareSetting, PharmacyPrescriptionState } from './types';

export const pharmacyStatePresentation: Record<
    PharmacyPrescriptionState,
    { label: string; chip: string; rail: string }
> = {
    DRAFT: {
        label: 'Draft',
        chip: 'border-slate-300 bg-slate-100 text-slate-800',
        rail: 'bg-slate-500',
    },
    ORDERED: {
        label: 'Awaiting verification',
        chip: 'border-blue-300 bg-blue-50 text-blue-900',
        rail: 'bg-[#1b75bc]',
    },
    VERIFIED: {
        label: 'Verified',
        chip: 'border-cyan-300 bg-cyan-50 text-cyan-900',
        rail: 'bg-cyan-600',
    },
    REFUSED: {
        label: 'Refused by pharmacist',
        chip: 'border-red-300 bg-red-50 text-red-900',
        rail: 'bg-red-600',
    },
    PREPARED: {
        label: 'Ready to hand over',
        chip: 'border-violet-300 bg-violet-50 text-violet-900',
        rail: 'bg-violet-600',
    },
    PARTIALLY_HANDED_OVER: {
        label: 'Partially handed over',
        chip: 'border-amber-300 bg-amber-50 text-amber-950',
        rail: 'bg-amber-500',
    },
    HANDED_OVER: {
        label: 'Handed over',
        chip: 'border-emerald-300 bg-emerald-50 text-emerald-900',
        rail: 'bg-emerald-600',
    },
    UNFILLED_CLOSED: {
        label: 'Unfilled remainder closed',
        chip: 'border-slate-300 bg-slate-100 text-slate-800',
        rail: 'bg-slate-600',
    },
    CANCELLED: {
        label: 'Cancelled',
        chip: 'border-slate-300 bg-slate-100 text-slate-700',
        rail: 'bg-slate-500',
    },
};

export const pharmacyCareSettingLabel: Record<PharmacyCareSetting, string> = {
    OUTPATIENT: 'Outpatient',
    EMERGENCY: 'Emergency',
    INPATIENT: 'Inpatient',
};

export function PharmacyStatusChip({
    state,
}: {
    state: PharmacyPrescriptionState;
}) {
    const presentation = pharmacyStatePresentation[state];

    return (
        <span
            className={cn(
                'inline-flex min-h-7 items-center gap-2 rounded-full border px-2.5 text-xs font-semibold',
                presentation.chip,
            )}
        >
            <span
                aria-hidden="true"
                className={cn('size-2 rounded-full', presentation.rail)}
            />
            {presentation.label}
        </span>
    );
}

export function PharmacyEvidenceTime({ value }: { value: string | null }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
            <Clock3 aria-hidden="true" className="size-3.5" />
            {formatPharmacyDate(value)}
        </span>
    );
}

export function PharmacyEmptyState({
    title,
    body,
}: {
    title: string;
    body: string;
}) {
    return (
        <div className="rounded-lg border border-dashed border-border bg-muted/25 p-5 text-sm">
            <PackageCheck aria-hidden="true" className="size-5 text-primary" />
            <p className="mt-2 font-semibold text-foreground">{title}</p>
            <p className="mt-1 text-muted-foreground">{body}</p>
        </div>
    );
}

export function PharmacySubnav({
    current,
    canManage = false,
}: {
    current: 'queue' | 'history' | 'stock' | 'master';
    canManage?: boolean;
}) {
    const items = [
        { id: 'queue', label: 'Prescription queue', href: '/apotek/resep' },
        {
            id: 'history',
            label: 'Prescription history',
            href: '/apotek/riwayat',
        },
        { id: 'stock', label: 'Stock card', href: '/apotek/kartu-stok' },
        ...(canManage
            ? [
                  {
                      id: 'master',
                      label: 'Medicine & depot master data',
                      href: '/manajemen-data/apotek',
                  },
              ]
            : []),
    ] as const;

    return (
        <nav
            aria-label="Pharmacy navigation"
            className="flex flex-wrap gap-1 rounded-lg border border-border bg-card p-1"
        >
            {items.map((item) => (
                <Link
                    key={item.id}
                    href={item.href}
                    aria-current={current === item.id ? 'page' : undefined}
                    className={cn(
                        'inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold',
                        current === item.id
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                    )}
                >
                    {item.label}
                </Link>
            ))}
        </nav>
    );
}
