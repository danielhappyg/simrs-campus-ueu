import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
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
    canComplete: boolean;
};

const payerLabel: Record<string, string> = {
    UMUM: 'Umum',
    BPJS: 'BPJS',
    LAINNYA: 'Lainnya',
};

const admissionLabel: Record<string, string> = {
    DATANG_SENDIRI: 'Datang sendiri',
    RUJUKAN: 'Rujukan',
    IGD: 'Dari IGD',
};

const fieldClass =
    'border-input h-8 rounded-md border bg-white px-2.5 text-sm shadow-xs outline-none focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/30';

export default function RmRawatJalan({
    encounters,
    clinics,
    payerOptions,
    filters,
    canComplete,
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

    const complete = (publicId: string) => {
        if (
            !window.confirm(
                'Tandai rekam medis selesai dan tutup kunjungan ini?',
            )
        ) {
            return;
        }

        router.post(`/rm/rawat-jalan/${publicId}/complete`);
    };

    return (
        <>
            <Head title="RM Rawat Jalan" />

            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-3 px-3 py-4 md:px-5 md:py-5">
                <header>
                    <h1 className="text-xl font-semibold tracking-tight text-[#0f172a] md:text-2xl">
                        RM · Rawat Jalan
                    </h1>
                    <p className="mt-0.5 text-xs text-[#64748b]">
                        Antrian siap tinjau RMIK (sintetis). Filter densitas
                        mengikuti CAP-RMIK-001.
                    </p>
                </header>

                <form
                    onSubmit={applyFilters}
                    className="rounded-lg border border-[#d7e6f3] bg-[#f5f9fc] p-3"
                >
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid min-w-[12rem] flex-1 gap-1">
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                No. RM / Nama
                            </label>
                            <Input
                                className={cn(fieldClass, 'bg-white')}
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="No.RM / Nama"
                            />
                        </div>
                        <div className="grid min-w-[9rem] gap-1">
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                Klinik
                            </label>
                            <select
                                className={fieldClass}
                                value={clinic}
                                onChange={(e) => setClinic(e.target.value)}
                            >
                                <option value="">Semua klinik</option>
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
                                Cara bayar
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
                                Dari
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
                            Semua cara masuk · stub
                        </label>
                        <label className="inline-flex items-center gap-1.5 opacity-70">
                            <input
                                type="checkbox"
                                disabled
                                className="accent-[#1b75bc]"
                            />
                            Semua status klaim · stub
                        </label>
                        <label className="inline-flex items-center gap-1.5">
                            <input
                                type="checkbox"
                                checked={showBatal}
                                onChange={(e) => setShowBatal(e.target.checked)}
                                className="accent-[#1b75bc]"
                            />
                            Tampilkan pasien batal
                            <span className="text-[0.65rem] text-[#94a3b8]">
                                · stub
                            </span>
                        </label>
                    </div>
                </form>

                <section className="rounded-lg border border-[#e2e8f0] bg-white p-3">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[52rem] text-left text-sm">
                            <thead className="border-b border-[#e2e8f0] text-[0.7rem] tracking-wide text-[#64748b] uppercase">
                                <tr>
                                    <th className="px-2 py-1.5 font-medium">
                                        Antrian
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        No. RM
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Nama
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Klinik
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Dokter
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Cara masuk
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Penjamin
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Catatan
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
                                            Tidak ada kunjungan siap RM untuk
                                            filter ini.
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
                                                {encounter.entry_count}
                                            </td>
                                            <td className="px-2 py-1.5 text-right">
                                                {canComplete ? (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        className="h-7 bg-[#1b75bc] hover:bg-[#1665a3]"
                                                        onClick={() =>
                                                            complete(
                                                                encounter.public_id,
                                                            )
                                                        }
                                                    >
                                                        Selesai RM
                                                    </Button>
                                                ) : null}
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
        { title: 'Beranda', href: '/' },
        { title: 'RM', href: '/rm/rawat-jalan' },
        { title: 'Rawat Jalan', href: '/rm/rawat-jalan' },
    ] satisfies BreadcrumbItem[],
};
