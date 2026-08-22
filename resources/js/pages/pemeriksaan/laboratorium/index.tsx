import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import type { FormEvent } from 'react';
import { CareSettingSubnav } from '@/components/care-setting-subnav';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BreadcrumbItem } from '@/types';

type OrderRow = {
    public_id: string;
    test_code: string;
    test_label: string;
    clinical_question: string | null;
    requested_at: string;
    requested_by_name: string | null;
    encounter: {
        public_id: string | null;
        clinic_name: string | null;
        status: string | null;
    };
    patient: {
        full_name: string | null;
        medical_record_number: string | null;
    };
};

type Props = {
    orders: OrderRow[];
    filters: { q: string };
    canEnterResult: boolean;
};

const fieldClass =
    'border-input h-8 rounded-md border bg-white px-2.5 text-sm shadow-xs outline-none focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/30';

export default function PemeriksaanLaboratoriumIndex({
    orders,
    filters,
    canEnterResult,
}: Props) {
    const { flash } = usePage().props;
    const [q, setQ] = useState(filters.q);
    const [expandedOrderId, setExpandedOrderId] = useState<string | null>(null);

    const resultForm = useForm({
        result_text: '',
        status: 'FINAL',
    });

    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            '/pemeriksaan/laboratorium',
            { q: q || undefined },
            { preserveState: true, replace: true },
        );
    };

    const submitResult = (orderId: string, event: FormEvent) => {
        event.preventDefault();
        resultForm.post(`/pemeriksaan/laboratorium/${orderId}/results`, {
            preserveScroll: true,
            onSuccess: () => {
                resultForm.reset();
                setExpandedOrderId(null);
            },
        });
    };

    return (
        <>
            <Head title="Pemeriksaan · Laboratorium" />

            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-3 px-3 py-4 md:px-5 md:py-5">
                {typeof flash?.error === 'string' && flash.error !== '' ? (
                    <div className="rounded-md border border-[#fecaca] bg-[#fef2f2] px-3 py-2 text-sm text-[#991b1b]">
                        {flash.error}
                    </div>
                ) : null}
                {typeof flash?.success === 'string' &&
                flash.success !== '' ? (
                    <div className="rounded-md border border-[#bbf7d0] bg-[#f0fdf4] px-3 py-2 text-sm text-[#166534]">
                        {flash.success}
                    </div>
                ) : null}

                <CareSettingSubnav
                    items={[
                        {
                            href: '/pemeriksaan/rawat-jalan',
                            label: 'Rawat Jalan',
                        },
                        {
                            href: '/pemeriksaan/igd',
                            label: 'IGD',
                        },
                        {
                            href: '/pemeriksaan/rawat-inap',
                            label: 'Rawat Inap',
                        },
                        {
                            href: '/pemeriksaan/triage',
                            label: 'Triage',
                        },
                        {
                            href: '/pemeriksaan/laboratorium',
                            label: 'Laboratorium',
                            active: true,
                        },
                    ]}
                />

                <header className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight text-[#0f172a] md:text-2xl">
                            Pemeriksaan · Laboratorium
                        </h1>
                        <p className="mt-0.5 text-xs text-[#64748b]">
                            Worklist order lab aktif (sintetis). Masukkan hasil
                            untuk menyelesaikan order.
                        </p>
                    </div>
                </header>

                <form
                    onSubmit={applyFilters}
                    className="flex flex-wrap items-end gap-2 rounded-lg border border-[#e2e8f0] bg-white p-3"
                >
                    <div className="min-w-[220px] flex-1">
                        <Label htmlFor="q" className="text-xs text-[#64748b]">
                            Cari pasien / pemeriksaan
                        </Label>
                        <Input
                            id="q"
                            className={fieldClass}
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Nama, RM, kode lab…"
                        />
                    </div>
                    <Button
                        type="submit"
                        className="bg-[#1b75bc] hover:bg-[#1665a3]"
                    >
                        Terapkan
                    </Button>
                </form>

                <section className="overflow-hidden rounded-lg border border-[#e2e8f0] bg-white">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="border-b border-[#e2e8f0] bg-[#f8fafc] text-xs text-[#64748b] uppercase">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">
                                        Pasien
                                    </th>
                                    <th className="px-3 py-2 font-semibold">
                                        Pemeriksaan
                                    </th>
                                    <th className="px-3 py-2 font-semibold">
                                        Klinik
                                    </th>
                                    <th className="px-3 py-2 font-semibold">
                                        Diminta
                                    </th>
                                    <th className="px-3 py-2 font-semibold">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="px-3 py-8 text-center text-[#64748b]"
                                        >
                                            Tidak ada order lab aktif.
                                        </td>
                                    </tr>
                                ) : (
                                    orders.map((order) => (
                                        <Fragment key={order.public_id}>
                                            <tr className="border-b border-[#e2e8f0] hover:bg-[#f8fafc]">
                                                <td className="px-3 py-2.5">
                                                    <p className="font-medium text-[#0f172a]">
                                                        {order.patient
                                                            .full_name ?? '—'}
                                                    </p>
                                                    <p className="font-mono text-xs text-[#64748b]">
                                                        {
                                                            order.patient
                                                                .medical_record_number
                                                        }
                                                    </p>
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    <p className="font-medium text-[#0f172a]">
                                                        {order.test_label}
                                                    </p>
                                                    <p className="font-mono text-xs text-[#64748b]">
                                                        {order.test_code}
                                                    </p>
                                                    {order.clinical_question ? (
                                                        <p className="mt-0.5 text-xs text-[#64748b]">
                                                            {
                                                                order.clinical_question
                                                            }
                                                        </p>
                                                    ) : null}
                                                </td>
                                                <td className="px-3 py-2.5 text-[#0f172a]">
                                                    {order.encounter
                                                        .clinic_name ?? '—'}
                                                </td>
                                                <td className="px-3 py-2.5 text-xs text-[#64748b]">
                                                    {order.requested_by_name}
                                                    <br />
                                                    {new Date(
                                                        order.requested_at,
                                                    ).toLocaleString('id-ID')}
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    <div className="flex flex-wrap gap-2">
                                                        {order.encounter
                                                            .public_id ? (
                                                            <Link
                                                                href={`/pemeriksaan/rawat-jalan/${order.encounter.public_id}`}
                                                                className="text-xs font-medium text-[#1b75bc] hover:underline"
                                                            >
                                                                Kunjungan
                                                            </Link>
                                                        ) : null}
                                                        {canEnterResult ? (
                                                            <button
                                                                type="button"
                                                                className="text-xs font-medium text-[#1b75bc] hover:underline"
                                                                onClick={() =>
                                                                    setExpandedOrderId(
                                                                        expandedOrderId ===
                                                                            order.public_id
                                                                            ? null
                                                                            : order.public_id,
                                                                    )
                                                                }
                                                            >
                                                                {expandedOrderId ===
                                                                order.public_id
                                                                    ? 'Tutup'
                                                                    : 'Hasil'}
                                                            </button>
                                                        ) : null}
                                                    </div>
                                                </td>
                                            </tr>
                                            {expandedOrderId ===
                                                order.public_id &&
                                            canEnterResult ? (
                                                <tr className="border-b border-[#e2e8f0] bg-[#f8fafc]">
                                                    <td
                                                        colSpan={5}
                                                        className="px-3 py-3"
                                                    >
                                                        <form
                                                            onSubmit={(e) =>
                                                                submitResult(
                                                                    order.public_id,
                                                                    e,
                                                                )
                                                            }
                                                            className="mx-auto max-w-2xl space-y-3"
                                                        >
                                                            <div className="grid gap-1.5">
                                                                <Label htmlFor={`result-${order.public_id}`}>
                                                                    Hasil
                                                                    pemeriksaan
                                                                </Label>
                                                                <textarea
                                                                    id={`result-${order.public_id}`}
                                                                    className="min-h-28 rounded-md border border-input bg-white px-2.5 py-2 text-sm"
                                                                    value={
                                                                        resultForm
                                                                            .data
                                                                            .result_text
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        resultForm.setData(
                                                                            'result_text',
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    required
                                                                    placeholder="Contoh: Hb 12.4 g/dL, GDS 118 mg/dL…"
                                                                />
                                                                <InputError
                                                                    message={
                                                                        resultForm
                                                                            .errors
                                                                            .result_text
                                                                    }
                                                                />
                                                            </div>
                                                            <div className="grid gap-1.5 sm:max-w-xs">
                                                                <Label
                                                                    htmlFor={`status-${order.public_id}`}
                                                                >
                                                                    Status hasil
                                                                </Label>
                                                                <select
                                                                    id={`status-${order.public_id}`}
                                                                    className="h-8 rounded-md border border-input bg-white px-2.5 text-sm"
                                                                    value={
                                                                        resultForm
                                                                            .data
                                                                            .status
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        resultForm.setData(
                                                                            'status',
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                >
                                                                    <option value="FINAL">
                                                                        Final
                                                                    </option>
                                                                    <option value="PRELIMINARY">
                                                                        Preliminer
                                                                    </option>
                                                                </select>
                                                                <InputError
                                                                    message={
                                                                        resultForm
                                                                            .errors
                                                                            .status
                                                                    }
                                                                />
                                                            </div>
                                                            <Button
                                                                type="submit"
                                                                disabled={
                                                                    resultForm.processing
                                                                }
                                                                className="bg-[#1b75bc] hover:bg-[#1665a3]"
                                                            >
                                                                Simpan hasil lab
                                                            </Button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            ) : null}
                                        </Fragment>
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

PemeriksaanLaboratoriumIndex.layout = () => ({
    breadcrumbs: [
        { title: 'Beranda', href: '/' },
        { title: 'Pemeriksaan', href: '/pemeriksaan/rawat-jalan' },
        { title: 'Laboratorium', href: '/pemeriksaan/laboratorium' },
    ] satisfies BreadcrumbItem[],
});
