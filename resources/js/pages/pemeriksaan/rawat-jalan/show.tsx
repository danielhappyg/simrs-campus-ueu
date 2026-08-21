import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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
    entries: ClinicalEntryRow[];
};

type Props = {
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

export default function PemeriksaanRawatJalanShow({
    encounter,
    entryTypeOptions,
    canWriteNursing,
    canWriteMedical,
}: Props) {
    const allowedOptions = entryTypeOptions.filter((option) => option.allowed);
    const canWrite = canWriteNursing || canWriteMedical;
    const closed = encounter.status === 'CLOSED';

    const form = useForm({
        entry_type: allowedOptions[0]?.value ?? '',
        body: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/pemeriksaan/rawat-jalan/${encounter.public_id}/entries`, {
            preserveScroll: true,
            onSuccess: () => form.reset('body'),
        });
    };

    return (
        <>
            <Head
                title={`Pemeriksaan — ${encounter.patient.full_name ?? 'Kunjungan'}`}
            />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 px-4 py-8 md:px-6">
                <div>
                    <Link
                        href="/pemeriksaan/rawat-jalan"
                        className="text-sm font-medium text-[#1b75bc] hover:underline"
                    >
                        ← Kembali ke daftar
                    </Link>
                </div>

                <header className="rounded-xl border border-[#e2e8f0] bg-white p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight text-[#0f172a]">
                                {encounter.patient.full_name}
                            </h1>
                            <p className="mt-1 font-mono text-sm text-[#64748b]">
                                {encounter.patient.medical_record_number}
                            </p>
                        </div>
                        <span className="rounded-md bg-[#f1f5f9] px-2.5 py-1 text-xs font-medium text-[#123b63]">
                            {statusLabel[encounter.status] ?? encounter.status}
                        </span>
                    </div>

                    <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-[#64748b]">Tanggal lahir</dt>
                            <dd className="font-medium text-[#0f172a]">
                                {encounter.patient.date_of_birth ?? '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[#64748b]">Jenis kelamin</dt>
                            <dd className="font-medium text-[#0f172a]">
                                {encounter.patient.sex
                                    ? (sexLabel[encounter.patient.sex] ??
                                      encounter.patient.sex)
                                    : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[#64748b]">Klinik</dt>
                            <dd className="font-medium text-[#0f172a]">
                                {encounter.clinic_name}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[#64748b]">Keluhan utama</dt>
                            <dd className="font-medium text-[#0f172a]">
                                {encounter.chief_complaint || '—'}
                            </dd>
                        </div>
                    </dl>
                </header>

                <section className="rounded-xl border border-[#e2e8f0] bg-white p-5">
                    <h2 className="text-sm font-semibold text-[#123b63]">
                        Catatan klinis
                    </h2>

                    <div className="mt-4 space-y-3">
                        {encounter.entries.length === 0 ? (
                            <p className="text-sm text-[#64748b]">
                                Belum ada catatan.
                            </p>
                        ) : (
                            encounter.entries.map((entry) => (
                                <article
                                    key={entry.public_id}
                                    className="rounded-lg border border-[#e2e8f0] bg-[#f8fafc] p-4"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h3 className="text-sm font-semibold text-[#0f172a]">
                                            {entryTypeLabel[entry.entry_type] ??
                                                entry.entry_type}
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

                {canWrite && !closed && (
                    <section className="rounded-xl border border-[#e2e8f0] bg-white p-5">
                        <h2 className="text-sm font-semibold text-[#123b63]">
                            Tambah catatan
                        </h2>
                        <form onSubmit={submit} className="mt-4 space-y-4">
                            <div className="grid gap-2">
                                <Label htmlFor="entry_type">Jenis catatan</Label>
                                <select
                                    id="entry_type"
                                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
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
                                <InputError message={form.errors.entry_type} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="body">Isi catatan</Label>
                                <textarea
                                    id="body"
                                    className="border-input min-h-32 rounded-md border bg-transparent px-3 py-2 text-sm"
                                    value={form.data.body}
                                    onChange={(e) =>
                                        form.setData('body', e.target.value)
                                    }
                                    required
                                />
                                <InputError message={form.errors.body} />
                            </div>

                            <Button
                                type="submit"
                                disabled={form.processing || !form.data.entry_type}
                                className="bg-[#1b75bc] hover:bg-[#1665a3]"
                            >
                                Simpan catatan
                            </Button>
                        </form>
                    </section>
                )}
            </div>
        </>
    );
}

PemeriksaanRawatJalanShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Beranda', href: '/' },
        { title: 'Pemeriksaan', href: '/pemeriksaan/rawat-jalan' },
        {
            title: props.encounter.patient.full_name ?? 'Detail',
            href: `/pemeriksaan/rawat-jalan/${props.encounter.public_id}`,
        },
    ] satisfies BreadcrumbItem[],
});
