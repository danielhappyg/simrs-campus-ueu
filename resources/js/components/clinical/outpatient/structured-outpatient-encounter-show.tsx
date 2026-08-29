import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { ClinicalDocumentHistory } from './clinical-document-history';
import { StructuredDocumentForm } from './structured-document-form';
import type { ClinicalDocumentType, OutpatientShowProps } from './types';
import { useUnsavedChangesGuard } from './use-unsaved-changes-guard';

const statusLabel: Record<string, string> = {
    REGISTERED: 'Terdaftar',
    IN_EXAMINATION: 'Dalam pemeriksaan',
    READY_FOR_RM: 'Siap tinjau RM',
    CLOSED: 'Ditutup',
};

const documentTypeLabel: Record<ClinicalDocumentType, string> = {
    NURSING_ASSESSMENT: 'Asesmen keperawatan',
    MEDICAL_ASSESSMENT: 'Asesmen medis',
};

export default function StructuredOutpatientEncounterShow({
    encounter,
    legacyEntries,
    documentation,
    permissions,
    actions,
    labTestOptions,
}: OutpatientShowProps) {
    const { flash } = usePage().props;
    const [section, setSection] = useState<'documentation' | 'lab' | 'history'>(
        'documentation',
    );
    const [dirtyDocuments, setDirtyDocuments] = useState<
        Record<ClinicalDocumentType, boolean>
    >({ NURSING_ASSESSMENT: false, MEDICAL_ASSESSMENT: false });
    const labForm = useForm({
        test_code: labTestOptions[0]?.code ?? '',
        clinical_question: '',
    });
    const closed = encounter.status === 'CLOSED';
    const nursingDocument =
        documentation.active_drafts.find(
            (document) => document.document_type === 'NURSING_ASSESSMENT',
        ) ??
        documentation.documents.find(
            (document) => document.document_type === 'NURSING_ASSESSMENT',
        );
    const medicalDocument =
        documentation.active_drafts.find(
            (document) => document.document_type === 'MEDICAL_ASSESSMENT',
        ) ??
        documentation.documents.find(
            (document) => document.document_type === 'MEDICAL_ASSESSMENT',
        );
    const handleDirtyChange = useCallback(
        (type: ClinicalDocumentType, isDirty: boolean) => {
            setDirtyDocuments((current) =>
                current[type] === isDirty
                    ? current
                    : { ...current, [type]: isDirty },
            );
        },
        [],
    );
    const hasUnsavedClinicalChanges =
        Object.values(dirtyDocuments).some(Boolean);

    useUnsavedChangesGuard(hasUnsavedClinicalChanges);

    const submitLab = (event: FormEvent) => {
        event.preventDefault();
        labForm.post(actions.store_lab_order_url, {
            preserveScroll: true,
            onSuccess: () => labForm.reset('clinical_question'),
        });
    };

    return (
        <>
            <Head
                title={`Pemeriksaan RJ — ${encounter.patient.full_name ?? 'Kunjungan'}`}
            />
            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-3 px-3 py-4 md:px-5">
                {typeof flash?.error === 'string' && flash.error ? (
                    <div
                        role="alert"
                        className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                    >
                        {flash.error}
                    </div>
                ) : null}
                {typeof flash?.success === 'string' && flash.success ? (
                    <div
                        role="status"
                        className="rounded-md border border-success/30 bg-success/5 px-3 py-2 text-sm text-success"
                    >
                        {flash.success}
                    </div>
                ) : null}

                <Link
                    href="/pemeriksaan/rawat-jalan"
                    className="min-h-11 self-start py-2 text-sm font-medium text-primary hover:underline"
                >
                    ← Kembali ke worklist rawat jalan
                </Link>

                <header className="rounded-lg border border-border bg-card p-3 md:p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-semibold text-foreground">
                                    {encounter.patient.full_name ??
                                        'Nama pasien belum tersedia'}
                                </h1>
                                <span className="rounded-md bg-secondary px-2 py-1 text-xs font-semibold text-secondary-foreground">
                                    {statusLabel[encounter.status] ??
                                        encounter.status}
                                </span>
                            </div>
                            <p className="mt-1 font-mono text-xs text-muted-foreground">
                                {encounter.patient.medical_record_number ?? '—'}
                                {encounter.queue_number != null
                                    ? ` · Antrian ${encounter.queue_number}`
                                    : ''}
                            </p>
                        </div>
                        <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-xs">
                            <div>
                                <dt className="text-muted-foreground">
                                    Klinik
                                </dt>
                                <dd className="font-semibold">
                                    {encounter.clinic_name}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Dokter
                                </dt>
                                <dd className="font-semibold">
                                    {encounter.doctor_name ?? '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Tanggal
                                </dt>
                                <dd className="font-semibold">
                                    {encounter.visit_date ?? '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Keluhan utama
                                </dt>
                                <dd className="font-semibold">
                                    {encounter.chief_complaint || '—'}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </header>

                <aside
                    aria-label="Provenans dokumentasi"
                    className="sticky top-2 z-10 grid gap-2 rounded-lg border border-sidebar-border bg-sidebar px-3 py-2 text-sidebar-foreground shadow-sm sm:grid-cols-4"
                >
                    <div>
                        <p className="text-[0.65rem] uppercase opacity-70">
                            Kunjungan
                        </p>
                        <p className="font-mono text-xs">
                            {encounter.public_id}
                        </p>
                    </div>
                    <div>
                        <p className="text-[0.65rem] uppercase opacity-70">
                            Definisi dokumen
                        </p>
                        <p className="font-mono text-xs">
                            {documentation.definition_version}
                        </p>
                    </div>
                    <div>
                        <p className="text-[0.65rem] uppercase opacity-70">
                            Keperawatan
                        </p>
                        <p className="text-xs font-semibold">
                            {nursingDocument
                                ? `${nursingDocument.document_state === 'FINAL' ? 'Final' : 'Draf'} v${nursingDocument.version} · ${nursingDocument.author_name ?? '—'}`
                                : 'Belum ada draf aktif'}
                        </p>
                    </div>
                    <div>
                        <p className="text-[0.65rem] uppercase opacity-70">
                            Medis
                        </p>
                        <p className="text-xs font-semibold">
                            {medicalDocument
                                ? `${medicalDocument.document_state === 'FINAL' ? 'Final' : 'Draf'} v${medicalDocument.version} · ${medicalDocument.author_name ?? '—'}`
                                : 'Belum ada draf aktif'}
                        </p>
                    </div>
                </aside>

                <nav
                    aria-label="Bagian pemeriksaan"
                    className="flex flex-wrap gap-1 border-b border-border"
                >
                    {(
                        [
                            ['documentation', 'Dokumentasi'],
                            ['lab', 'Order Lab'],
                            ['history', 'Riwayat'],
                        ] as const
                    ).map(([value, label]) => (
                        <button
                            key={value}
                            type="button"
                            aria-current={
                                section === value ? 'page' : undefined
                            }
                            onClick={() => setSection(value)}
                            className={
                                section === value
                                    ? 'min-h-11 border-b-2 border-primary px-3 text-sm font-semibold text-primary'
                                    : 'min-h-11 px-3 text-sm text-muted-foreground hover:text-foreground'
                            }
                        >
                            {label}
                        </button>
                    ))}
                    {['SOAP', 'Diagnosa', 'Tindakan', 'Resep', 'Order Rad'].map(
                        (label) => (
                            <button
                                key={label}
                                type="button"
                                disabled
                                title="Belum tersedia pada tahap ini"
                                className="min-h-11 px-3 text-sm text-muted-foreground opacity-50"
                            >
                                {label} · Soon
                            </button>
                        ),
                    )}
                </nav>

                <div
                    hidden={section !== 'documentation'}
                    className="grid gap-3 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]"
                >
                    <StructuredDocumentForm
                        key={`nursing-${nursingDocument?.public_id ?? 'new'}-${nursingDocument?.version ?? 0}-${nursingDocument?.document_state ?? 'NEW'}`}
                        type="NURSING_ASSESSMENT"
                        definitionVersion={documentation.definition_version}
                        draft={nursingDocument}
                        permission={permissions.nursing}
                        actions={actions.nursing}
                        encounterClosed={closed}
                        onDirtyChange={handleDirtyChange}
                    />
                    <StructuredDocumentForm
                        key={`medical-${medicalDocument?.public_id ?? 'new'}-${medicalDocument?.version ?? 0}-${medicalDocument?.document_state ?? 'NEW'}`}
                        type="MEDICAL_ASSESSMENT"
                        definitionVersion={documentation.definition_version}
                        draft={medicalDocument}
                        permission={permissions.medical}
                        actions={actions.medical}
                        encounterClosed={closed}
                        onDirtyChange={handleDirtyChange}
                    />
                </div>

                {section === 'history' ? (
                    <div className="grid gap-3 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                        <section className="space-y-2 rounded-lg border border-border bg-card p-3 md:p-4">
                            <h2 className="text-sm font-semibold text-secondary-foreground">
                                Riwayat versi dokumen terstruktur
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Setiap versi bersifat hanya-baca dan
                                mempertahankan aktor serta waktu pencatatan.
                            </p>
                            <ClinicalDocumentHistory
                                versions={documentation.versions}
                            />
                        </section>
                        <section className="space-y-2 rounded-lg border border-border bg-card p-3 md:p-4">
                            <h2 className="text-sm font-semibold text-secondary-foreground">
                                Riwayat catatan lama
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Catatan ini dipertahankan sebagai sumber baca
                                dan tidak diubah menjadi dokumen terstruktur
                                secara otomatis.
                            </p>
                            {legacyEntries.length ? (
                                legacyEntries.map((entry) => (
                                    <article
                                        key={entry.public_id}
                                        className="rounded-md border border-border bg-muted/50 p-3"
                                    >
                                        <div className="flex justify-between gap-2">
                                            <h3 className="text-sm font-semibold">
                                                {entry.entry_type}
                                            </h3>
                                            <p className="text-xs text-muted-foreground">
                                                {entry.author_name ?? '—'}
                                                {entry.created_at
                                                    ? ` · ${new Date(entry.created_at).toLocaleString('id-ID')}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <p className="mt-2 text-sm whitespace-pre-wrap">
                                            {entry.body}
                                        </p>
                                    </article>
                                ))
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Tidak ada catatan lama.
                                </p>
                            )}
                        </section>
                    </div>
                ) : null}

                {section === 'lab' ? (
                    <div className="grid gap-3 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                        <section className="space-y-2 rounded-lg border border-border bg-card p-3 md:p-4">
                            <div className="flex justify-between gap-2">
                                <h2 className="text-sm font-semibold text-secondary-foreground">
                                    Order laboratorium
                                </h2>
                                <Link
                                    href="/pemeriksaan/laboratorium"
                                    className="text-sm font-medium text-primary hover:underline"
                                >
                                    Buka meja lab →
                                </Link>
                            </div>
                            {(encounter.lab_orders ?? []).length ? (
                                encounter.lab_orders?.map((order) => (
                                    <article
                                        key={order.public_id}
                                        className="rounded-md border border-border bg-muted/40 p-3"
                                    >
                                        <div className="flex justify-between gap-2">
                                            <h3 className="text-sm font-semibold">
                                                {order.test_label}{' '}
                                                <span className="font-mono text-xs font-normal text-muted-foreground">
                                                    {order.test_code}
                                                </span>
                                            </h3>
                                            <span className="text-xs font-semibold text-secondary-foreground">
                                                {order.status}
                                            </span>
                                        </div>
                                        {order.clinical_question ? (
                                            <p className="mt-2 text-sm">
                                                Klinis:{' '}
                                                {order.clinical_question}
                                            </p>
                                        ) : null}
                                        {order.result ? (
                                            <div className="mt-2 border-l-2 border-success pl-3">
                                                <p className="text-xs font-semibold text-success">
                                                    Hasil {order.result.status}
                                                </p>
                                                <p className="text-sm whitespace-pre-wrap">
                                                    {order.result.result_text}
                                                </p>
                                            </div>
                                        ) : null}
                                    </article>
                                ))
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada order lab.
                                </p>
                            )}
                        </section>
                        <section className="rounded-lg border border-border bg-card p-3 md:p-4">
                            <h2 className="text-sm font-semibold text-secondary-foreground">
                                Buat order lab
                            </h2>
                            {permissions.can_create_lab_order && !closed ? (
                                <form
                                    onSubmit={submitLab}
                                    className="mt-3 space-y-3"
                                >
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="lab-test-code">
                                            Pemeriksaan
                                        </Label>
                                        <select
                                            id="lab-test-code"
                                            required
                                            value={labForm.data.test_code}
                                            onChange={(event) =>
                                                labForm.setData(
                                                    'test_code',
                                                    event.target.value,
                                                )
                                            }
                                            className="min-h-11 rounded-md border border-input bg-background px-3 text-sm"
                                        >
                                            {labTestOptions.map((option) => (
                                                <option
                                                    key={option.code}
                                                    value={option.code}
                                                >
                                                    {option.label} (
                                                    {option.code})
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="lab-clinical-question">
                                            Pertanyaan klinis (opsional)
                                        </Label>
                                        <textarea
                                            id="lab-clinical-question"
                                            value={
                                                labForm.data.clinical_question
                                            }
                                            onChange={(event) =>
                                                labForm.setData(
                                                    'clinical_question',
                                                    event.target.value,
                                                )
                                            }
                                            className="min-h-28 rounded-md border border-input bg-background px-3 py-2 text-sm"
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={
                                            labForm.processing ||
                                            !labForm.data.test_code
                                        }
                                        className="w-full bg-primary text-primary-foreground hover:bg-primary/90"
                                    >
                                        Simpan order lab
                                    </Button>
                                </form>
                            ) : (
                                <p className="mt-3 text-sm text-muted-foreground">
                                    {closed
                                        ? 'Kunjungan ditutup; order baru tidak dapat dibuat.'
                                        : 'Akun ini tidak memiliki hak membuat order lab.'}
                                </p>
                            )}
                        </section>
                    </div>
                ) : null}
            </div>
        </>
    );
}

export { documentTypeLabel };
