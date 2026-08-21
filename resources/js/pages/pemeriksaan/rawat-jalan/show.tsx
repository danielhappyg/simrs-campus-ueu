import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
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

type EncounterDetail = {
    public_id: string;
    status: string;
    clinic_name: string;
    doctor_name: string | null;
    schedule_label: string | null;
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
};

type Props = {
    variant?: 'rawat-jalan' | 'igd';
    indexPath?: string;
    showPathPrefix?: string;
    storeEntryPath?: string;
    encounter: EncounterDetail;
    entryTypeOptions: EntryTypeOption[];
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

const payerLabel: Record<string, string> = {
    UMUM: 'Umum',
    BPJS: 'BPJS',
    LAINNYA: 'Lainnya',
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
    encounter,
    entryTypeOptions,
    canWriteNursing,
    canWriteMedical,
}: Props) {
    const isIgd = variant === 'igd';
    const entryPostPath =
        storeEntryPath ??
        `/pemeriksaan/rawat-jalan/${encounter.public_id}/entries`;
    const allowedOptions = entryTypeOptions.filter((option) => option.allowed);
    const canWrite = canWriteNursing || canWriteMedical;
    const closed = encounter.status === 'CLOSED';
    const [activeTab, setActiveTab] =
        useState<(typeof clinicalTabs)[number]>('Asesmen');

    const form = useForm({
        entry_type: allowedOptions[0]?.value ?? '',
        body: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(entryPostPath, {
            preserveScroll: true,
            onSuccess: () => form.reset('body'),
        });
    };

    return (
        <>
            <Head
                title={`Pemeriksaan — ${encounter.patient.full_name ?? 'Kunjungan'}`}
            />

            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-3 px-3 py-4 md:px-5 md:py-5">
                <div>
                    <Link
                        href={indexPath}
                        className="text-sm font-medium text-[#1b75bc] hover:underline"
                    >
                        ← Kembali ke worklist {isIgd ? 'IGD' : 'rawat jalan'}
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
                                Klinik / Dokter
                            </dt>
                            <dd className="font-medium text-[#0f172a]">
                                {encounter.clinic_name}
                                {encounter.doctor_name
                                    ? ` · ${encounter.doctor_name}`
                                    : ''}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[0.65rem] tracking-wide text-[#64748b] uppercase">
                                Jadwal / Penjamin
                            </dt>
                            <dd className="font-medium text-[#0f172a]">
                                {encounter.schedule_label ?? '—'}
                                {' · '}
                                {payerLabel[encounter.payer_type] ??
                                    encounter.payer_type}
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
                        const live = tab === 'Asesmen' || tab === 'Riwayat';
                        return (
                            <button
                                key={tab}
                                type="button"
                                disabled={!live}
                                onClick={() => live && setActiveTab(tab)}
                                className={cn(
                                    'rounded-t-md px-3 py-1.5 text-xs font-medium',
                                    activeTab === tab && live
                                        ? 'bg-white text-[#1b75bc] ring-1 ring-[#e2e8f0] ring-b-white'
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
                                            <p className="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-[#0f172a]">
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
                                            className="border-input h-8 rounded-md border bg-white px-2.5 text-sm"
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
                                            className="border-input min-h-36 rounded-md border bg-white px-2.5 py-2 text-sm"
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
            : '/pemeriksaan/rawat-jalan');
    const showPrefix =
        props.showPathPrefix ??
        (props.variant === 'igd'
            ? '/pemeriksaan/igd'
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
