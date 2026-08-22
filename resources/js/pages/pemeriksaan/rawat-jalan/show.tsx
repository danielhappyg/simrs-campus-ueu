import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type EntryTypeOption = {
    value: string;
    label: string;
    allowed: boolean;
};

type ClinicalEntryRow = {
    public_id: string;
    entry_type: string;
    body: string;
    created_at: string | null;
    author_name: string | null;
};

type LabOrderRow = {
    public_id: string;
    test_code: string;
    test_label: string;
    clinical_question: string | null;
    status: string;
    requested_at: string;
    requested_by_name: string | null;
    result: {
        public_id: string;
        status: string;
        result_text: string;
        issued_at: string;
        entered_by_name: string | null;
    } | null;
};

type LabTestOption = {
    code: string;
    label: string;
};

type EncounterDetail = {
    public_id: string;
    status: string;
    clinic_name: string;
    doctor_name: string | null;
    schedule_label: string | null;
    ward_name?: string | null;
    ward_class?: string | null;
    bed_code?: string | null;
    continue_from?: string | null;
    payer_type: string;
    case_type?: string | null;
    accident_type?: string | null;
    queue_number: number | null;
    registered_at: string | null;
    visit_date: string | null;
    chief_complaint: string | null;
    patient: {
        public_id: string | null;
        medical_record_number: string | null;
        full_name: string | null;
        date_of_birth: string | null;
        sex: string | null;
        nik: string | null;
    };
    entries: ClinicalEntryRow[];
    lab_orders?: LabOrderRow[];
};

type Props = {
    variant?: 'rawat-jalan' | 'igd' | 'rawat-inap';
    indexPath?: string;
    showPathPrefix?: string;
    storeEntryPath?: string;
    storeLabOrderPath?: string;
    encounter: EncounterDetail;
    entryTypeOptions: EntryTypeOption[];
    labTestOptions?: LabTestOption[];
    canCreateLabOrder?: boolean;
    canWriteNursing: boolean;
    canWriteMedical: boolean;
};

const statusLabel: Record<string, string> = {
    REGISTERED: 'Terdaftar',
    IN_EXAMINATION: 'Dalam pemeriksaan',
    READY_FOR_RM: 'Siap RM',
    CLOSED: 'Ditutup',
};

const entryTypeLabel: Record<string, string> = {
    NURSING_INTAKE: 'Asesmen keperawatan',
    MEDICAL_ASSESSMENT: 'Asesmen medis',
};

const sexLabel: Record<string, string> = {
    LAKI_LAKI: 'Laki-laki',
    PEREMPUAN: 'Perempuan',
    TIDAK_DIKETAHUI: 'Tidak diketahui',
};

const labOrderStatusLabel: Record<string, string> = {
    ACTIVE: 'Menunggu hasil',
    COMPLETED: 'Selesai',
    CANCELLED: 'Dibatalkan',
};

const labResultStatusLabel: Record<string, string> = {
    PRELIMINARY: 'Preliminer',
    FINAL: 'Final',
};

const payerLabel: Record<string, string> = {
    UMUM: 'Umum',
    BPJS: 'BPJS',
    LAINNYA: 'Lainnya',
};

const continueLabel: Record<string, string> = {
    LANGSUNG: 'Langsung',
    DARI_IGD: 'Dari IGD',
    DARI_RJ: 'Dari RJ',
};

const clinicalTabs = [
    'Asesmen',
    'SOAP',
    'Diagnosa',
    'Tindakan',
    'Resep',
    'Order Lab',
    'Order Rad',
    'Riwayat',
] as const;

export default function PemeriksaanRawatJalanShow({
    variant = 'rawat-jalan',
    indexPath = '/pemeriksaan/rawat-jalan',
    storeEntryPath,
    storeLabOrderPath,
    encounter,
    entryTypeOptions,
    labTestOptions = [],
    canCreateLabOrder = false,
    canWriteNursing,
    canWriteMedical,
}: Props) {
    const { flash } = usePage().props;
    const isIgd = variant === 'igd';
    const isInpatient = variant === 'rawat-inap';
    const isOutpatient = variant === 'rawat-jalan';
    const entryPostPath =
        storeEntryPath ??
        `/pemeriksaan/rawat-jalan/${encounter.public_id}/entries`;
    const labOrderPostPath =
        storeLabOrderPath ??
        `/pemeriksaan/rawat-jalan/${encounter.public_id}/lab-orders`;
    const labOrders = encounter.lab_orders ?? [];
    const allowedOptions = entryTypeOptions.filter((option) => option.allowed);
    const canWrite = canWriteNursing || canWriteMedical;
    const closed = encounter.status === 'CLOSED';
    const [activeTab, setActiveTab] =
        useState<(typeof clinicalTabs)[number]>('Asesmen');

    const form = useForm({
        entry_type: allowedOptions[0]?.value ?? '',
        body: '',
    });

    const labForm = useForm({
        test_code: labTestOptions[0]?.code ?? '',
        clinical_question: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(entryPostPath, {
            preserveScroll: true,
            onSuccess: () => form.reset('body'),
        });
    };

    const submitLabOrder = (event: FormEvent) => {
        event.preventDefault();
        labForm.post(labOrderPostPath, {
            preserveScroll: true,
            onSuccess: () => labForm.reset('clinical_question'),
        });
    };

    const tabIsLive = (tab: (typeof clinicalTabs)[number]) => {
        if (tab === 'Asesmen' || tab === 'Riwayat') {
            return true;
        }

        return tab === 'Order Lab' && isOutpatient;
    };

    return (
        <>
            <Head
                title={`Pemeriksaan — ${encounter.patient.full_name ?? 'Kunjungan'}`}
            />

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

                <div>
                    <Link
                        href={indexPath}
                        className="text-sm font-medium text-[#1b75bc] hover:underline"
                    >
                        ← Kembali ke worklist{' '}
                        {isInpatient
                            ? 'rawat inap'
                            : isIgd
                              ? 'IGD'
                              : 'rawat jalan'}
                    </Link>
                </div>

                <header className="rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-semibold tracking-tight text-[#0f172a]">
                                    {encounter.patient.full_name}
                                </h1>
                                <span className="rounded-md bg-[#f1f5f9] px-2 py-0.5 text-[0.7rem] font-semibold text-[#123b63]">
                                    {statusLabel[encounter.status] ??
                                        encounter.status}
                                </span>
                                {encounter.queue_number != null ? (
                                    <span className="rounded-md bg-[#e8f2fa] px-2 py-0.5 font-mono text-[0.7rem] font-semibold text-[#123b63]">
                                        Antrian {encounter.queue_number}
                                    </span>
                                ) : null}
                            </div>
                            <p className="mt-1 font-mono text-xs text-[#64748b]">
                                {encounter.patient.medical_record_number}
                                {encounter.patient.nik
                                    ? ` · NIK ${encounter.patient.nik}`
                                    : ''}
                                {isIgd && encounter.case_type
                                    ? ` · Kasus ${encounter.case_type}`
                                    : ''}
                                {isInpatient && encounter.bed_code
                                    ? ` · TT ${encounter.bed_code}`
                                    : ''}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {(
                                [
                                    'Cetak',
                                    'Riwayat EMR',
                                    'Order',
                                    'Resep',
                                ] as const
                            ).map((label) => (
                                <button
                                    key={label}
                                    type="button"
                                    disabled
                                    title="Belum tersedia di demo pengajaran"
                                    className="inline-flex h-8 items-center rounded-md border border-[#c5d9eb] bg-[#f8fbfe] px-2.5 text-xs font-medium text-[#64748b] opacity-70"
                                >
                                    {label}
                                    <span className="ml-1.5 text-[0.65rem] text-[#94a3b8]">
                                        · stub
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>

                    <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt className="text-[0.65rem] tracking-wide text-[#64748b] uppercase">
                                Tgl lahir / JK
                            </dt>
                            <dd className="font-medium text-[#0f172a]">
                                {encounter.patient.date_of_birth ?? '—'}
                                {' · '}
                                {encounter.patient.sex
                                    ? (sexLabel[encounter.patient.sex] ??
                                      encounter.patient.sex)
                                    : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[0.65rem] tracking-wide text-[#64748b] uppercase">
                                {isInpatient
                                    ? 'Bangsal / Kelas'
                                    : 'Klinik / Dokter'}
                            </dt>
                            <dd className="font-medium text-[#0f172a]">
                                {isInpatient
                                    ? `${encounter.ward_name ?? encounter.clinic_name} · ${encounter.ward_class ?? '—'}`
                                    : encounter.clinic_name}
                                {!isInpatient && encounter.doctor_name
                                    ? ` · ${encounter.doctor_name}`
                                    : ''}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[0.65rem] tracking-wide text-[#64748b] uppercase">
                                {isInpatient
                                    ? 'TT / Penjamin'
                                    : 'Jadwal / Penjamin'}
                            </dt>
                            <dd className="font-medium text-[#0f172a]">
                                {isInpatient
                                    ? (encounter.bed_code ??
                                      encounter.schedule_label ??
                                      '—')
                                    : (encounter.schedule_label ?? '—')}
                                {' · '}
                                {payerLabel[encounter.payer_type] ??
                                    encounter.payer_type}
                                {isInpatient && encounter.continue_from
                                    ? ` · ${continueLabel[encounter.continue_from] ?? encounter.continue_from}`
                                    : ''}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[0.65rem] tracking-wide text-[#64748b] uppercase">
                                Keluhan utama
                            </dt>
                            <dd className="font-medium text-[#0f172a]">
                                {encounter.chief_complaint || '—'}
                            </dd>
                        </div>
                    </dl>
                </header>

                <div className="flex flex-wrap gap-1 border-b border-[#e2e8f0] pb-px">
                    {clinicalTabs.map((tab) => {
                        const live = tabIsLive(tab);

                        return (
                            <button
                                key={tab}
                                type="button"
                                disabled={!live}
                                onClick={() => live && setActiveTab(tab)}
                                className={cn(
                                    'rounded-t-md px-3 py-1.5 text-xs font-medium',
                                    activeTab === tab && live
                                        ? 'ring-b-white bg-white text-[#1b75bc] ring-1 ring-[#e2e8f0]'
                                        : 'text-[#64748b]',
                                    !live && 'cursor-not-allowed opacity-50',
                                )}
                                title={
                                    live
                                        ? undefined
                                        : 'Tab klinis lanjutan — stub pengajaran'
                                }
                            >
                                {tab}
                                {!live ? (
                                    <span className="ml-1 text-[0.6rem] text-[#94a3b8]">
                                        stub
                                    </span>
                                ) : null}
                            </button>
                        );
                    })}
                </div>

                {activeTab === 'Order Lab' && isOutpatient && (
                    <div className="grid gap-3 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                        <section className="rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="text-xs font-semibold tracking-wide text-[#123b63] uppercase">
                                    Order laboratorium
                                </h2>
                                <Link
                                    href="/pemeriksaan/laboratorium"
                                    className="text-xs font-medium text-[#1b75bc] hover:underline"
                                >
                                    Buka meja lab →
                                </Link>
                            </div>
                            <div className="mt-3 space-y-2">
                                {labOrders.length === 0 ? (
                                    <p className="text-sm text-[#64748b]">
                                        Belum ada order lab untuk kunjungan ini.
                                    </p>
                                ) : (
                                    labOrders.map((order) => (
                                        <article
                                            key={order.public_id}
                                            className="rounded-md border border-[#e2e8f0] bg-[#f8fafc] p-3"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <h3 className="text-sm font-semibold text-[#0f172a]">
                                                    {order.test_label}
                                                    <span className="ml-2 font-mono text-xs font-normal text-[#64748b]">
                                                        {order.test_code}
                                                    </span>
                                                </h3>
                                                <span className="rounded-md bg-[#e8f2fa] px-2 py-0.5 text-[0.65rem] font-semibold text-[#123b63]">
                                                    {labOrderStatusLabel[
                                                        order.status
                                                    ] ?? order.status}
                                                </span>
                                            </div>
                                            {order.clinical_question ? (
                                                <p className="mt-2 text-xs text-[#64748b]">
                                                    Klinis:{' '}
                                                    {order.clinical_question}
                                                </p>
                                            ) : null}
                                            <p className="mt-1 text-xs text-[#64748b]">
                                                {order.requested_by_name}
                                                {' · '}
                                                {new Date(
                                                    order.requested_at,
                                                ).toLocaleString('id-ID')}
                                            </p>
                                            {order.result ? (
                                                <div className="mt-3 rounded-md border border-[#bbf7d0] bg-[#f0fdf4] p-2.5">
                                                    <p className="text-xs font-semibold text-[#166534]">
                                                        Hasil (
                                                        {labResultStatusLabel[
                                                            order.result.status
                                                        ] ??
                                                            order.result.status}
                                                        )
                                                    </p>
                                                    <p className="mt-1 text-sm whitespace-pre-wrap text-[#0f172a]">
                                                        {
                                                            order.result
                                                                .result_text
                                                        }
                                                    </p>
                                                    <p className="mt-1 text-xs text-[#64748b]">
                                                        {
                                                            order.result
                                                                .entered_by_name
                                                        }
                                                        {' · '}
                                                        {new Date(
                                                            order.result
                                                                .issued_at,
                                                        ).toLocaleString(
                                                            'id-ID',
                                                        )}
                                                    </p>
                                                </div>
                                            ) : null}
                                        </article>
                                    ))
                                )}
                            </div>
                        </section>

                        {canCreateLabOrder && !closed ? (
                            <section className="rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4">
                                <h2 className="text-xs font-semibold tracking-wide text-[#123b63] uppercase">
                                    Buat order lab
                                </h2>
                                <form
                                    onSubmit={submitLabOrder}
                                    className="mt-3 space-y-3"
                                >
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="test_code">
                                            Pemeriksaan
                                        </Label>
                                        <select
                                            id="test_code"
                                            className="h-8 rounded-md border border-input bg-white px-2.5 text-sm"
                                            value={labForm.data.test_code}
                                            onChange={(e) =>
                                                labForm.setData(
                                                    'test_code',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        >
                                            {labTestOptions.map((test) => (
                                                <option
                                                    key={test.code}
                                                    value={test.code}
                                                >
                                                    {test.label} ({test.code})
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={labForm.errors.test_code}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="clinical_question">
                                            Pertanyaan klinis (opsional)
                                        </Label>
                                        <textarea
                                            id="clinical_question"
                                            className="min-h-24 rounded-md border border-input bg-white px-2.5 py-2 text-sm"
                                            value={
                                                labForm.data.clinical_question
                                            }
                                            onChange={(e) =>
                                                labForm.setData(
                                                    'clinical_question',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Contoh: evaluasi anemia, kontrol DM…"
                                        />
                                        <InputError
                                            message={
                                                labForm.errors.clinical_question
                                            }
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={
                                            labForm.processing ||
                                            !labForm.data.test_code
                                        }
                                        className="w-full bg-[#1b75bc] hover:bg-[#1665a3]"
                                    >
                                        Simpan order lab
                                    </Button>
                                </form>
                            </section>
                        ) : (
                            <section className="rounded-lg border border-dashed border-[#e2e8f0] bg-[#f8fafc] p-4 text-sm text-[#64748b]">
                                {closed
                                    ? 'Kunjungan sudah ditutup — order lab tidak dapat ditambah.'
                                    : 'Akun ini tidak punya hak membuat order lab.'}
                            </section>
                        )}
                    </div>
                )}

                {(activeTab === 'Asesmen' || activeTab === 'Riwayat') && (
                    <div className="grid gap-3 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                        <section className="rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-[#123b63] uppercase">
                                Catatan klinis
                            </h2>
                            <div className="mt-3 space-y-2">
                                {encounter.entries.length === 0 ? (
                                    <p className="text-sm text-[#64748b]">
                                        Belum ada catatan.
                                    </p>
                                ) : (
                                    encounter.entries.map((entry) => (
                                        <article
                                            key={entry.public_id}
                                            className="rounded-md border border-[#e2e8f0] bg-[#f8fafc] p-3"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <h3 className="text-sm font-semibold text-[#0f172a]">
                                                    {entryTypeLabel[
                                                        entry.entry_type
                                                    ] ?? entry.entry_type}
                                                </h3>
                                                <p className="text-xs text-[#64748b]">
                                                    {entry.author_name}
                                                    {entry.created_at
                                                        ? ` · ${new Date(entry.created_at).toLocaleString('id-ID')}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <p className="mt-2 text-sm leading-relaxed whitespace-pre-wrap text-[#0f172a]">
                                                {entry.body}
                                            </p>
                                        </article>
                                    ))
                                )}
                            </div>
                        </section>

                        {canWrite && !closed ? (
                            <section className="rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4">
                                <h2 className="text-xs font-semibold tracking-wide text-[#123b63] uppercase">
                                    Tambah asesmen
                                </h2>
                                <form
                                    onSubmit={submit}
                                    className="mt-3 space-y-3"
                                >
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="entry_type">
                                            Jenis catatan
                                        </Label>
                                        <select
                                            id="entry_type"
                                            className="h-8 rounded-md border border-input bg-white px-2.5 text-sm"
                                            value={form.data.entry_type}
                                            onChange={(e) =>
                                                form.setData(
                                                    'entry_type',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        >
                                            {allowedOptions.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={form.errors.entry_type}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="body">
                                            Isi catatan
                                        </Label>
                                        <textarea
                                            id="body"
                                            className="min-h-36 rounded-md border border-input bg-white px-2.5 py-2 text-sm"
                                            value={form.data.body}
                                            onChange={(e) =>
                                                form.setData(
                                                    'body',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                            placeholder="Tuliskan asesmen teaching/sintetis…"
                                        />
                                        <InputError
                                            message={form.errors.body}
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={
                                            form.processing ||
                                            !form.data.entry_type
                                        }
                                        className="w-full bg-[#1b75bc] hover:bg-[#1665a3]"
                                    >
                                        Simpan catatan
                                    </Button>
                                </form>
                            </section>
                        ) : (
                            <section className="rounded-lg border border-dashed border-[#e2e8f0] bg-[#f8fafc] p-4 text-sm text-[#64748b]">
                                {closed
                                    ? 'Kunjungan sudah ditutup — catatan tidak dapat ditambah.'
                                    : 'Akun ini tidak punya hak menulis catatan klinis.'}
                            </section>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

PemeriksaanRawatJalanShow.layout = (props: Props) => {
    const indexPath =
        props.indexPath ??
        (props.variant === 'igd'
            ? '/pemeriksaan/igd'
            : props.variant === 'rawat-inap'
              ? '/pemeriksaan/rawat-inap'
              : '/pemeriksaan/rawat-jalan');
    const showPrefix =
        props.showPathPrefix ??
        (props.variant === 'igd'
            ? '/pemeriksaan/igd'
            : props.variant === 'rawat-inap'
              ? '/pemeriksaan/rawat-inap'
              : '/pemeriksaan/rawat-jalan');

    return {
        breadcrumbs: [
            { title: 'Beranda', href: '/' },
            {
                title: 'Pemeriksaan',
                href: indexPath,
            },
            {
                title: props.encounter.patient.full_name ?? 'Detail',
                href: `${showPrefix}/${props.encounter.public_id}`,
            },
        ] satisfies BreadcrumbItem[],
    };
};
