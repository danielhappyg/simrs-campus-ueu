import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { CareSettingSubnav } from '@/components/care-setting-subnav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type ClinicOption = { value: string; label: string };

type EncounterRow = {
    public_id: string;
    status: string;
    clinic_name: string;
    doctor_name: string | null;
    payer_type: string;
    admission_mode: string | null;
    queue_number: number | null;
    registered_at: string | null;
    visit_date: string | null;
    entry_count: number;
    active_lab_order_count: number;
    completeness_status?:
        'NOT_REVIEWED' | 'INCOMPLETE' | 'COMPLETE' | 'SIGNED_OFF';
    blocker_count?: number;
    patient: {
        public_id: string | null;
        medical_record_number: string | null;
        full_name: string | null;
        date_of_birth: string | null;
        sex: string | null;
    };
};

type Filters = {
    q: string;
    clinic: string;
    payer: string;
    date_from: string;
    date_to: string;
};

type Props = {
    encounters: EncounterRow[];
    clinics: ClinicOption[];
    payerOptions: ClinicOption[];
    filters: Filters;
    canComplete?: boolean;
};

const payerLabel: Record<string, string> = {
    UMUM: 'Self-pay',
    BPJS: 'BPJS',
    LAINNYA: 'Other',
};

const admissionLabel: Record<string, string> = {
    DATANG_SENDIRI: 'Datang sendiri',
    RUJUKAN: 'Referral',
    IGD: 'From Emergency Department',
};

const fieldClass =
    'border-input h-8 rounded-md border bg-white px-2.5 text-sm shadow-xs outline-none focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/30';

export default function RmRawatJalan({
    encounters,
    clinics,
    payerOptions,
    filters,
}: Props) {
    const [q, setQ] = useState(filters.q);
    const [clinic, setClinic] = useState(filters.clinic);
    const [payer, setPayer] = useState(filters.payer);
    const [dateFrom, setDateFrom] = useState(filters.date_from);
    const [dateTo, setDateTo] = useState(filters.date_to);
    const [showBatal, setShowBatal] = useState(false);

    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            '/rm/rawat-jalan',
            {
                q: q || undefined,
                clinic: clinic || undefined,
                payer: payer || undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Outpatient Medical Records" />

            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-3 px-3 py-4 md:px-5 md:py-5">
                <CareSettingSubnav
                    items={[
                        {
                            href: '/rm/rawat-jalan',
                            label: 'Outpatient Care',
                            active: true,
                        },
                        { href: '/rm/rawat-inap', label: 'Inpatient Care' },
                    ]}
                />
                <header>
                    <h1 className="text-xl font-semibold tracking-tight text-[#0f172a] md:text-2xl">
                        Medical Records · Outpatient Care
                    </h1>
                    <p className="mt-0.5 text-xs text-[#64748b]">
                        Encounter queue ready for review and completion by
                        petugas RMIK.
                    </p>
                </header>

                <form
                    onSubmit={applyFilters}
                    className="rounded-lg border border-[#d7e6f3] bg-[#f5f9fc] p-3"
                >
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid min-w-[12rem] flex-1 gap-1">
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                Medical record no. / Name
                            </label>
                            <Input
                                className={cn(fieldClass, 'bg-white')}
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Medical record no. / Name"
                            />
                        </div>
                        <div className="grid min-w-[9rem] gap-1">
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                Clinic
                            </label>
                            <select
                                className={fieldClass}
                                value={clinic}
                                onChange={(e) => setClinic(e.target.value)}
                            >
                                <option value="">All clinics</option>
                                {clinics.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid min-w-[9rem] gap-1">
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                Payment method
                            </label>
                            <select
                                className={fieldClass}
                                value={payer}
                                onChange={(e) => setPayer(e.target.value)}
                            >
                                <option value="">Semua cara bayar</option>
                                {payerOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid min-w-[9rem] gap-1">
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                From
                            </label>
                            <Input
                                type="date"
                                className={fieldClass}
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                            />
                        </div>
                        <div className="grid min-w-[9rem] gap-1">
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                Sampai
                            </label>
                            <Input
                                type="date"
                                className={fieldClass}
                                value={dateTo}
                                onChange={(e) => setDateTo(e.target.value)}
                            />
                        </div>
                        <Button
                            type="submit"
                            size="sm"
                            className="h-8 bg-[#1b75bc] hover:bg-[#1665a3]"
                        >
                            Tampilkan
                        </Button>
                    </div>
                    <div className="mt-2 flex flex-wrap gap-4 text-xs text-[#64748b]">
                        <label className="inline-flex items-center gap-1.5 opacity-70">
                            <input
                                type="checkbox"
                                disabled
                                className="accent-[#1b75bc]"
                            />
                            Semua cara masuk
                        </label>
                        <label className="inline-flex items-center gap-1.5 opacity-70">
                            <input
                                type="checkbox"
                                disabled
                                className="accent-[#1b75bc]"
                            />
                            Semua status klaim
                        </label>
                        <label className="inline-flex items-center gap-1.5">
                            <input
                                type="checkbox"
                                checked={showBatal}
                                onChange={(e) => setShowBatal(e.target.checked)}
                                className="accent-[#1b75bc]"
                            />
                            Show cancelled patients
                        </label>
                    </div>
                </form>

                <section className="rounded-lg border border-[#e2e8f0] bg-white p-3">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[52rem] text-left text-sm">
                            <thead className="border-b border-[#e2e8f0] text-[0.7rem] tracking-wide text-[#64748b] uppercase">
                                <tr>
                                    <th className="px-2 py-1.5 font-medium">
                                        Queue
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        MRN
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Name
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Clinic
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Physician
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Admission route
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Payer
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Completeness
                                    </th>
                                    <th className="px-2 py-1.5 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {encounters.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={9}
                                            className="px-2 py-6 text-[#64748b]"
                                        >
                                            No encounters are ready for medical
                                            records under filter ini.
                                        </td>
                                    </tr>
                                ) : (
                                    encounters.map((encounter) => (
                                        <tr
                                            key={encounter.public_id}
                                            className="border-b border-[#f1f5f9]"
                                        >
                                            <td className="px-2 py-1.5 font-mono text-xs">
                                                {encounter.queue_number ?? '—'}
                                            </td>
                                            <td className="px-2 py-1.5 font-mono text-xs">
                                                {
                                                    encounter.patient
                                                        .medical_record_number
                                                }
                                            </td>
                                            <td className="px-2 py-1.5 font-medium">
                                                {encounter.patient.full_name}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                {encounter.clinic_name}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                {encounter.doctor_name ?? '—'}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                {encounter.admission_mode
                                                    ? (admissionLabel[
                                                          encounter
                                                              .admission_mode
                                                      ] ??
                                                      encounter.admission_mode)
                                                    : '—'}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                {payerLabel[
                                                    encounter.payer_type
                                                ] ?? encounter.payer_type}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                <p className="text-xs font-semibold text-secondary-foreground">
                                                    {encounter.status ===
                                                    'CLOSED'
                                                        ? 'Signed off'
                                                        : encounter.completeness_status ===
                                                            'COMPLETE'
                                                          ? 'Lengkap'
                                                          : encounter.completeness_status ===
                                                              'INCOMPLETE'
                                                            ? 'Incomplete'
                                                            : 'Not reviewed'}
                                                </p>
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {encounter.entry_count}{' '}
                                                    clinical sources
                                                </p>
                                                {(encounter.blocker_count ??
                                                    0) > 0 ? (
                                                    <p className="mt-0.5 text-xs font-medium text-warning">
                                                        {
                                                            encounter.blocker_count
                                                        }{' '}
                                                        blocker
                                                    </p>
                                                ) : null}
                                                {encounter.active_lab_order_count >
                                                0 ? (
                                                    <p className="mt-0.5 text-xs font-medium text-warning">
                                                        {
                                                            encounter.active_lab_order_count
                                                        }{' '}
                                                        order lab aktif
                                                    </p>
                                                ) : null}
                                            </td>
                                            <td className="max-w-[17rem] px-2 py-1.5 text-right">
                                                <Link
                                                    href={`/rm/rawat-jalan/${encounter.public_id}`}
                                                    className="inline-flex min-h-11 items-center rounded-md border border-primary/30 bg-primary/5 px-3 text-xs font-semibold text-primary hover:bg-primary/10"
                                                >
                                                    {encounter.status ===
                                                    'CLOSED'
                                                        ? 'View medical record'
                                                        : 'Review medical record'}
                                                </Link>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </>
    );
}

RmRawatJalan.layout = {
    breadcrumbs: [
        { title: 'Home', href: '/' },
        { title: 'Medical Records', href: '/rm/rawat-jalan' },
        { title: 'Outpatient Care', href: '/rm/rawat-jalan' },
    ] satisfies BreadcrumbItem[],
};
