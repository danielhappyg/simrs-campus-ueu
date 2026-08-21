import { Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { BreadcrumbItem } from '@/types';

type EncounterRow = {
    public_id: string;
    status: string;
    clinic_name: string;
    payer_type: string;
    registered_at: string | null;
    entry_count: number;
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
    canComplete: boolean;
};

const payerLabel: Record<string, string> = {
    UMUM: 'Umum',
    BPJS: 'BPJS',
    LAINNYA: 'Lainnya',
};

export default function RmRawatJalan({ encounters, canComplete }: Props) {
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

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-8 md:px-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight text-[#0f172a] md:text-3xl">
                        RM Rawat Jalan
                    </h1>
                    <p className="text-sm text-[#64748b]">
                        Kunjungan yang siap ditinjau dan ditutup oleh rekam
                        medis.
                    </p>
                </header>

                <section className="rounded-xl border border-[#e2e8f0] bg-white p-5">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[40rem] text-left text-sm">
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
                                        Catatan
                                    </th>
                                    <th className="px-2 py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {encounters.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-2 py-6 text-[#64748b]"
                                        >
                                            Tidak ada kunjungan siap RM.
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
                                                {encounter.entry_count}
                                            </td>
                                            <td className="px-2 py-2.5 text-right">
                                                {canComplete && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        className="bg-[#1b75bc] hover:bg-[#1665a3]"
                                                        onClick={() =>
                                                            complete(
                                                                encounter.public_id,
                                                            )
                                                        }
                                                    >
                                                        Selesai RM
                                                    </Button>
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

RmRawatJalan.layout = {
    breadcrumbs: [
        { title: 'Beranda', href: '/' },
        { title: 'RM', href: '/rm/rawat-jalan' },
        { title: 'Rawat Jalan', href: '/rm/rawat-jalan' },
    ] satisfies BreadcrumbItem[],
};
