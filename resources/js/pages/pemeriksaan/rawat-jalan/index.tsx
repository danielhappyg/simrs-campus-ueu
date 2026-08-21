import { Head, Link } from '@inertiajs/react';
import type { BreadcrumbItem } from '@/types';

type EncounterRow = {
    public_id: string;
    status: string;
    clinic_name: string;
    payer_type: string;
    registered_at: string | null;
    chief_complaint: string | null;
    patient: {
        public_id: string | null;
        medical_record_number: string | null;
        full_name: string | null;
        date_of_birth: string | null;
        sex: string | null;
    };
};

type Props = {
    encounters: EncounterRow[];
    canOpen: boolean;
};

const statusLabel: Record<string, string> = {
    REGISTERED: 'Terdaftar',
    IN_EXAMINATION: 'Dalam pemeriksaan',
};

const payerLabel: Record<string, string> = {
    UMUM: 'Umum',
    BPJS: 'BPJS',
    LAINNYA: 'Lainnya',
};

export default function PemeriksaanRawatJalanIndex({
    encounters,
    canOpen,
}: Props) {
    return (
        <>
            <Head title="Pemeriksaan Rawat Jalan" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-8 md:px-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight text-[#0f172a] md:text-3xl">
                        Pemeriksaan Rawat Jalan
                    </h1>
                    <p className="text-sm text-[#64748b]">
                        Daftar kunjungan yang menunggu atau sedang diperiksa.
                    </p>
                </header>

                <section className="rounded-xl border border-[#e2e8f0] bg-white p-5">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[44rem] text-left text-sm">
                            <thead className="border-b border-[#e2e8f0] text-xs tracking-wide text-[#64748b] uppercase">
                                <tr>
                                    <th className="px-2 py-2 font-medium">
                                        No. RM
                                    </th>
                                    <th className="px-2 py-2 font-medium">
                                        Nama
                                    </th>
                                    <th className="px-2 py-2 font-medium">
                                        Klinik
                                    </th>
                                    <th className="px-2 py-2 font-medium">
                                        Penjamin
                                    </th>
                                    <th className="px-2 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-2 py-2 font-medium">
                                        Keluhan
                                    </th>
                                    <th className="px-2 py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {encounters.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-2 py-6 text-[#64748b]"
                                        >
                                            Tidak ada kunjungan aktif.
                                        </td>
                                    </tr>
                                ) : (
                                    encounters.map((encounter) => (
                                        <tr
                                            key={encounter.public_id}
                                            className="border-b border-[#f1f5f9]"
                                        >
                                            <td className="px-2 py-2.5 font-mono text-xs">
                                                {
                                                    encounter.patient
                                                        .medical_record_number
                                                }
                                            </td>
                                            <td className="px-2 py-2.5">
                                                {encounter.patient.full_name}
                                            </td>
                                            <td className="px-2 py-2.5">
                                                {encounter.clinic_name}
                                            </td>
                                            <td className="px-2 py-2.5">
                                                {payerLabel[
                                                    encounter.payer_type
                                                ] ?? encounter.payer_type}
                                            </td>
                                            <td className="px-2 py-2.5">
                                                {statusLabel[
                                                    encounter.status
                                                ] ?? encounter.status}
                                            </td>
                                            <td className="max-w-[12rem] truncate px-2 py-2.5 text-[#64748b]">
                                                {encounter.chief_complaint ||
                                                    '—'}
                                            </td>
                                            <td className="px-2 py-2.5 text-right">
                                                {canOpen && (
                                                    <Link
                                                        href={`/pemeriksaan/rawat-jalan/${encounter.public_id}`}
                                                        className="text-sm font-medium text-[#1b75bc] hover:underline"
                                                    >
                                                        Buka
                                                    </Link>
                                                )}
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

PemeriksaanRawatJalanIndex.layout = {
    breadcrumbs: [
        { title: 'Beranda', href: '/' },
        { title: 'Pemeriksaan', href: '/pemeriksaan/rawat-jalan' },
        { title: 'Rawat Jalan', href: '/pemeriksaan/rawat-jalan' },
    ] satisfies BreadcrumbItem[],
};
