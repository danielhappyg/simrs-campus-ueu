import { Head, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { CareSettingSubnav } from '@/components/care-setting-subnav';
import { OperationalPagination } from '@/components/operational-pagination';
import type { OperationalPaginationMeta } from '@/components/operational-pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type Option = { value: string; label: string };

type RecapRow = {
    public_id: string;
    registered_at: string | null;
    visit_date: string | null;
    queue_number: number | null;
    care_setting: string;
    care_setting_label?: string;
    clinic_name: string;
    doctor_name: string | null;
    payer_type: string;
    payer_label?: string;
    booking_code: string | null;
    origin: 'ONLINE' | 'WALK_IN';
    origin_label?: string;
    status: string;
    status_label?: string;
    patient: {
        medical_record_number: string | null;
        full_name: string | null;
    };
};

type Props = {
    filters: {
        date_from: string;
        date_to: string;
        clinic: string;
        payer: string;
        origin: string;
        care_setting: string;
        status: string;
    };
    rows: RecapRow[];
    pagination: OperationalPaginationMeta;
    totals: {
        all: number;
        online: number;
        walk_in: number;
        cancelled: number;
    };
    clinicOptions: Option[];
    payerOptions: Option[];
};

export default function PendaftaranRekap({
    filters,
    rows,
    pagination,
    totals,
    clinicOptions,
    payerOptions,
}: Props) {
    const { flash } = usePage().props;
    const apply = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        const query: Record<string, string> = {};

        for (const [key, value] of data.entries()) {
            const text = String(value).trim();

            if (text !== '') {
                query[key] = text;
            }
        }

        router.get('/pendaftaran/rekap', query, {
            preserveState: true,
            replace: true,
        });
    };

    const csvHref = (() => {
        const params = new URLSearchParams();

        Object.entries(filters).forEach(([key, value]) => {
            if (value) {
                params.set(key, value);
            }
        });
        params.set('format', 'csv');

        return `/pendaftaran/rekap?${params.toString()}`;
    })();

    return (
        <>
            <Head title="Registration summary" />

            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <CareSettingSubnav
                    items={[
                        {
                            href: '/pendaftaran/rawat-jalan',
                            label: 'Outpatient',
                        },
                        { href: '/pendaftaran/igd', label: 'Emergency' },
                        {
                            href: '/pendaftaran/rawat-inap',
                            label: 'Inpatient',
                        },
                        {
                            href: '/pendaftaran/rekap',
                            label: 'Summary',
                            active: true,
                        },
                    ]}
                />

                <header>
                    <h1 className="text-xl font-semibold text-[#0f172a]">
                        Registration summary
                    </h1>
                    <p className="mt-1 text-sm text-[#64748b]">
                        Comparison of walk-in visits and visits with a booking
                        code (RegOn/online).
                    </p>
                </header>

                {typeof flash?.error === 'string' && flash.error !== '' ? (
                    <div
                        className="rounded-md border border-[#fecaca] bg-[#fef2f2] px-3 py-2 text-sm text-[#991b1b]"
                        role="alert"
                    >
                        {flash.error}
                    </div>
                ) : null}

                <form
                    onSubmit={apply}
                    className="grid min-w-0 grid-cols-1 gap-3 rounded-lg border border-[#e2e8f0] bg-white p-3 md:grid-cols-7"
                >
                    <div className="grid gap-1">
                        <Label htmlFor="date_from">From</Label>
                        <Input
                            id="date_from"
                            name="date_from"
                            type="date"
                            defaultValue={filters.date_from}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="date_to">To</Label>
                        <Input
                            id="date_to"
                            name="date_to"
                            type="date"
                            defaultValue={filters.date_to}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="care_setting">Setting</Label>
                        <select
                            id="care_setting"
                            name="care_setting"
                            defaultValue={filters.care_setting}
                            className="h-9 min-w-0 rounded-md border border-[#e2e8f0] px-2 text-sm"
                        >
                            <option value="OUTPATIENT">Outpatient</option>
                            <option value="EMERGENCY">IGD</option>
                            <option value="INPATIENT">Inpatient</option>
                            <option value="ALL">All</option>
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="clinic">Clinic / unit</Label>
                        <select
                            id="clinic"
                            name="clinic"
                            defaultValue={filters.clinic}
                            className="h-9 min-w-0 rounded-md border border-[#e2e8f0] px-2 text-sm"
                        >
                            <option value="">All</option>
                            {clinicOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="payer">Cara bayar</Label>
                        <select
                            id="payer"
                            name="payer"
                            defaultValue={filters.payer}
                            className="h-9 min-w-0 rounded-md border border-[#e2e8f0] px-2 text-sm"
                        >
                            <option value="">All</option>
                            {payerOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="origin">Asal</Label>
                        <select
                            id="origin"
                            name="origin"
                            defaultValue={filters.origin}
                            className="h-9 min-w-0 rounded-md border border-[#e2e8f0] px-2 text-sm"
                        >
                            <option value="">All</option>
                            <option value="WALK_IN">Walk-in</option>
                            <option value="ONLINE">
                                Online / kode booking
                            </option>
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="status">Visit status</Label>
                        <select
                            id="status"
                            name="status"
                            defaultValue={filters.status}
                            className="h-9 min-w-0 rounded-md border border-[#e2e8f0] px-2 text-sm"
                        >
                            <option value="">All</option>
                            <option value="REGISTERED">Registered</option>
                            <option value="IN_EXAMINATION">
                                In examination
                            </option>
                            <option value="READY_FOR_RM">
                                Ready for medical records
                            </option>
                            <option value="CLOSED">Closed</option>
                            <option value="CANCELLED">Cancelled</option>
                        </select>
                    </div>
                    <div className="flex flex-wrap items-end gap-2 md:col-span-7">
                        <Button
                            type="submit"
                            className="bg-[#1b75bc] hover:bg-[#1665a3]"
                        >
                            Apply
                        </Button>
                        <Button type="button" variant="outline" asChild>
                            <a href={csvHref}>Download CSV</a>
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => window.print()}
                        >
                            Print summary
                        </Button>
                    </div>
                </form>

                <div className="flex flex-wrap gap-3 text-sm">
                    <span className="rounded-md bg-[#f1f5f9] px-3 py-1.5">
                        Total {totals.all}
                    </span>
                    <span className="rounded-md bg-[#fdeee3] px-3 py-1.5 text-[#9a3412]">
                        Online / booking {totals.online}
                    </span>
                    <span className="rounded-md bg-[#e8f1f8] px-3 py-1.5 text-[#123b63]">
                        Walk-in {totals.walk_in}
                    </span>
                    <span className="rounded-md bg-[#fef2f2] px-3 py-1.5 text-[#991b1b]">
                        Cancelled {totals.cancelled}
                    </span>
                </div>

                <section className="min-w-0 overflow-x-auto rounded-lg border border-[#e2e8f0] bg-white">
                    <table className="w-full min-w-[56rem] text-left text-sm">
                        <caption className="sr-only">
                            Visit summary based on registration filters
                        </caption>
                        <thead className="border-b border-[#e2e8f0] text-[0.7rem] tracking-wide text-[#64748b] uppercase">
                            <tr>
                                <th className="px-3 py-2 font-medium">Time</th>
                                <th className="px-3 py-2 font-medium">Queue</th>
                                <th className="px-3 py-2 font-medium">
                                    No. RM
                                </th>
                                <th className="px-3 py-2 font-medium">Name</th>
                                <th className="px-3 py-2 font-medium">Asal</th>
                                <th className="px-3 py-2 font-medium">
                                    Clinic
                                </th>
                                <th className="px-3 py-2 font-medium">Payer</th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={9}
                                        className="px-3 py-4 text-[#64748b]"
                                    >
                                        No visits match these filters.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((row) => (
                                    <tr
                                        key={row.public_id}
                                        className="border-b border-[#f1f5f9]"
                                    >
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            {row.registered_at
                                                ? new Date(
                                                      row.registered_at,
                                                  ).toLocaleString('id-ID')
                                                : '—'}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs">
                                            {row.queue_number != null
                                                ? String(
                                                      row.queue_number,
                                                  ).padStart(3, '0')
                                                : '—'}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs">
                                            {row.patient
                                                .medical_record_number ?? '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.patient.full_name ?? '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span
                                                className={cn(
                                                    'rounded-full px-2 py-0.5 text-[0.7rem] font-medium',
                                                    row.origin === 'ONLINE'
                                                        ? 'bg-[#fdeee3] text-[#9a3412]'
                                                        : 'bg-[#f1f5f9] text-[#475569]',
                                                )}
                                            >
                                                {row.origin_label ??
                                                    (row.origin === 'ONLINE'
                                                        ? 'Online / booking'
                                                        : 'Walk-in')}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.clinic_name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.payer_label ?? row.payer_type}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span
                                                className={cn(
                                                    'rounded-full px-2 py-0.5 text-[0.7rem] font-medium',
                                                    row.status === 'CANCELLED'
                                                        ? 'bg-[#fef2f2] text-[#991b1b]'
                                                        : 'bg-[#f1f5f9] text-[#475569]',
                                                )}
                                            >
                                                {row.status_label ?? row.status}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            {row.status === 'CANCELLED' ? (
                                                <span className="text-xs text-[#64748b]">
                                                    Inactive
                                                </span>
                                            ) : (
                                                <a
                                                    href={`/pendaftaran/kunjungan/${row.public_id}/cetak?docs=bukti,antrian`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="text-sm font-medium text-[#1b75bc] hover:underline"
                                                >
                                                    Print
                                                </a>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <OperationalPagination
                        pagination={pagination}
                        itemLabel="encounters"
                        className="m-3"
                    />
                </section>
            </div>
        </>
    );
}

PendaftaranRekap.layout = () => ({
    breadcrumbs: [
        { title: 'Home', href: '/' },
        { title: 'Registration', href: '/pendaftaran/rawat-jalan' },
        { title: 'Summary', href: '/pendaftaran/rekap' },
    ] satisfies BreadcrumbItem[],
});
