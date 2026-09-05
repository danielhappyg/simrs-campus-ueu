import { Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, CircleAlert, Clock3 } from 'lucide-react';
import type {
    FinanceBillState,
    FinanceCareSetting,
    FinanceReadinessState,
    FinanceSourceDomain,
} from './types';

export const financeFieldClass =
    'min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm outline-none transition focus:border-[#1b75bc] focus:ring-2 focus:ring-[#1b75bc]/25';

export const financeStatePresentation: Record<
    FinanceBillState,
    { label: string; className: string; icon: typeof Clock3 }
> = {
    OPEN_NO_VERSION: {
        label: 'Not issued',
        className: 'border-amber-300 bg-amber-50 text-amber-950',
        icon: Clock3,
    },
    ISSUED_CURRENT: {
        label: 'Current version',
        className: 'border-emerald-300 bg-emerald-50 text-emerald-950',
        icon: CheckCircle2,
    },
    NEW_SOURCE_PENDING: {
        label: 'New charges pending',
        className: 'border-orange-300 bg-orange-50 text-orange-950',
        icon: CircleAlert,
    },
};

export const careSettingLabels: Record<FinanceCareSetting, string> = {
    OUTPATIENT: 'Outpatient',
    EMERGENCY: 'Emergency',
    INPATIENT: 'Inpatient',
};

export const financeSourceDomainLabels: Record<FinanceSourceDomain, string> = {
    PHARMACY: 'Pharmacy',
    RADIOLOGY: 'Radiology',
    LABORATORY: 'Laboratory',
    ACCOMMODATION: 'Accommodation',
};

export const financeReadinessLabels: Record<FinanceReadinessState, string> = {
    SIAP_DISINKRONKAN: 'Ready to synchronize',
    TERSINKRONISASI: 'Synchronized',
    TARIF_BELUM_DIPETAKAN: 'Tariff not mapped',
    TARIF_TIDAK_EFEKTIF: 'Tariff not in effect',
    KONTEKS_TIDAK_COCOK: 'Context mismatch',
    BUKTI_TIDAK_KONSISTEN: 'Inconsistent evidence',
    INTERVAL_MASIH_TERBUKA: 'Interval still open',
    RIWAYAT_LOKASI_TIDAK_LENGKAP: 'Incomplete location history',
};

export function formatRupiah(value: number): string {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(value);
}

export function formatFinanceDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

export function formatPrintedRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(value);
}

export function formatPrintedFinanceDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

export function FinanceStateBadge({ state }: { state: FinanceBillState }) {
    const presentation = financeStatePresentation[state];
    const Icon = presentation.icon;

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold ${presentation.className}`}
        >
            <Icon aria-hidden="true" className="size-3.5" />
            {presentation.label}
        </span>
    );
}

export function FinanceCoverageRail({
    label,
    excludedLabel,
    domains,
}: {
    label: string;
    excludedLabel: string;
    domains: Array<{ domain: FinanceSourceDomain; label: string }>;
}) {
    return (
        <aside
            aria-label="Charge coverage"
            className="overflow-hidden rounded-xl border border-[#7fbcb6] bg-white shadow-sm"
        >
            <div className="grid sm:grid-cols-[0.42fr_1fr]">
                <div className="bg-[#0f5b62] px-5 py-4 text-white">
                    <p className="font-['IBM_Plex_Mono'] text-[0.6875rem] font-semibold tracking-[0.12em] uppercase">
                        Current bill coverage
                    </p>
                    <p className="mt-2 text-sm font-semibold">
                        {domains.map((item) => item.label).join(' + ') || '—'}
                    </p>
                </div>
                <div className="grid gap-3 px-5 py-4 text-sm md:grid-cols-2">
                    <div>
                        <p className="font-semibold text-emerald-900">
                            Included
                        </p>
                        <p className="mt-1 text-slate-700">{label}</p>
                    </div>
                    <div>
                        <p className="font-semibold text-slate-900">
                            Not included
                        </p>
                        <p className="mt-1 text-slate-600">{excludedLabel}</p>
                    </div>
                </div>
            </div>
        </aside>
    );
}

export function FinanceBackLink() {
    return (
        <Link
            href="/kasir/tagihan"
            className="inline-flex min-h-11 items-center gap-2 rounded-md px-3 text-sm font-semibold text-[#0d5275] outline-none hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc]"
        >
            <ArrowLeft aria-hidden="true" className="size-4" />
            Bill List
        </Link>
    );
}
