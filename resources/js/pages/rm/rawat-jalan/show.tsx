import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ClinicalDocumentHistory } from '@/components/clinical/outpatient/clinical-document-history';
import { PostClosureAmendmentPanel } from '@/components/clinical/outpatient/post-closure-amendment-panel';
import type {
    AmendmentReasonOption,
    ClinicalDocument,
    ClinicalDocumentVersion,
    OutpatientAmendment,
    OutpatientEncounter,
} from '@/components/clinical/outpatient/types';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { BreadcrumbItem } from '@/types';

type ChecklistItem = {
    code: string;
    label: string;
    status: 'PASS' | 'FAIL' | 'NOT_APPLICABLE';
    reason: string | null;
};

type Blocker = {
    code: string;
    label: string;
    reason: string;
};

type Props = {
    encounter: OutpatientEncounter;
    clinicalSources: ClinicalDocument[];
    documentVersions: ClinicalDocumentVersion[];
    review: {
        public_id: string | null;
        status: 'NOT_REVIEWED' | 'INCOMPLETE' | 'COMPLETE' | 'SIGNED_OFF';
        version: number;
        source_fingerprint: string;
        checklist_items: ChecklistItem[];
        reviewer_name: string | null;
        reviewed_at: string | null;
        signed_off_by_name: string | null;
        signed_off_at: string | null;
    };
    blockers: Blocker[];
    permissions: {
        can_save_review: boolean;
        can_signoff: boolean;
        can_request_amendment?: boolean;
    };
    actions: {
        save_review_url: string;
        signoff_url: string;
        store_amendment_url?: string | null;
    };
    amendmentReasonOptions?: AmendmentReasonOption[];
    amendments?: OutpatientAmendment[];
};

const reviewStatusLabel: Record<string, string> = {
    NOT_REVIEWED: 'Belum ditinjau',
    INCOMPLETE: 'Belum lengkap',
    COMPLETE: 'Lengkap, siap sign-off',
    SIGNED_OFF: 'Sudah sign-off',
};

export default function RmRawatJalanShow({
    encounter,
    clinicalSources,
    documentVersions,
    review,
    blockers,
    permissions,
    actions,
    amendmentReasonOptions = [],
    amendments = [],
}: Props) {
    const { flash } = usePage().props;
    const [dialogOpen, setDialogOpen] = useState(false);
    const reviewForm = useForm({
        expected_version: review.version,
        source_fingerprint: review.source_fingerprint,
    });
    const signoffForm = useForm({
        expected_version: review.version,
        source_fingerprint: review.source_fingerprint,
    });
    const failedItems = review.checklist_items.filter(
        (item) => item.status === 'FAIL',
    );
    const closed =
        encounter.status === 'CLOSED' || review.status === 'SIGNED_OFF';
    const canSignoff =
        !closed &&
        permissions.can_signoff &&
        review.version > 0 &&
        review.status === 'COMPLETE' &&
        blockers.length === 0 &&
        failedItems.length === 0;

    const saveReview = () => {
        reviewForm.setData({
            expected_version: review.version,
            source_fingerprint: review.source_fingerprint,
        });
        reviewForm.post(actions.save_review_url, { preserveScroll: true });
    };

    const signoff = () => {
        signoffForm.setData({
            expected_version: review.version,
            source_fingerprint: review.source_fingerprint,
        });
        signoffForm.post(actions.signoff_url, {
            preserveScroll: true,
            onSuccess: () => setDialogOpen(false),
        });
    };

    return (
        <>
            <Head
                title={`Tinjau RM — ${encounter.patient.full_name ?? 'Kunjungan'}`}
            />
            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-3 px-3 py-4 md:px-5">
                {typeof flash?.error === 'string' && flash.error ? (
                    <div
                        role="alert"
                        className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                    >
                        {flash.error}
                    </div>
                ) : null}
                {typeof flash?.success === 'string' && flash.success ? (
                    <div
                        role="status"
                        className="rounded-md border border-success/30 bg-success/5 p-3 text-sm text-success"
                    >
                        {flash.success}
                    </div>
                ) : null}

                <Link
                    href="/rm/rawat-jalan"
                    className="min-h-11 self-start py-2 text-sm font-medium text-primary hover:underline"
                >
                    ← Kembali ke daftar RM
                </Link>

                <header className="rounded-lg border border-border bg-card p-3 md:p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 className="text-xl font-semibold">
                                Tinjau kelengkapan RM ·{' '}
                                {encounter.patient.full_name ??
                                    'Nama pasien belum tersedia'}
                            </h1>
                            <p className="mt-1 font-mono text-xs text-muted-foreground">
                                {encounter.patient.medical_record_number ?? '—'}{' '}
                                · {encounter.public_id}
                            </p>
                        </div>
                        <span className="rounded-md bg-secondary px-2 py-1 text-xs font-semibold text-secondary-foreground">
                            {reviewStatusLabel[review.status] ?? review.status}
                        </span>
                    </div>
                    <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Klinik
                            </dt>
                            <dd className="font-semibold">
                                {encounter.clinic_name}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Dokter
                            </dt>
                            <dd className="font-semibold">
                                {encounter.doctor_name ?? '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Tanggal kunjungan
                            </dt>
                            <dd className="font-semibold">
                                {encounter.visit_date ?? '—'}
                            </dd>
                        </div>
                    </dl>
                </header>

                <aside
                    aria-label="Status sumber dan review"
                    className="sticky top-2 z-10 grid gap-2 rounded-lg border border-sidebar-border bg-sidebar px-3 py-2 text-sidebar-foreground shadow-sm sm:grid-cols-4"
                >
                    <div>
                        <p className="text-[0.65rem] uppercase opacity-70">
                            Sumber klinis
                        </p>
                        <p className="text-xs font-semibold">
                            {clinicalSources.length} dokumen
                        </p>
                    </div>
                    <div>
                        <p className="text-[0.65rem] uppercase opacity-70">
                            Fingerprint
                        </p>
                        <p
                            className="truncate font-mono text-xs"
                            title={review.source_fingerprint}
                        >
                            {review.source_fingerprint}
                        </p>
                    </div>
                    <div>
                        <p className="text-[0.65rem] uppercase opacity-70">
                            Versi review
                        </p>
                        <p className="font-mono text-xs">v{review.version}</p>
                    </div>
                    <div>
                        <p className="text-[0.65rem] uppercase opacity-70">
                            Blocker
                        </p>
                        <p className="text-xs font-semibold">
                            {blockers.length}
                        </p>
                    </div>
                </aside>

                <div className="grid gap-3 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                    <section className="space-y-2 rounded-lg border border-border bg-card p-3 md:p-4">
                        <div>
                            <h2 className="text-sm font-semibold text-secondary-foreground">
                                Sumber klinis hanya-baca
                            </h2>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Petugas RMIK menilai kelengkapan; isi klinis
                                tidak dapat diubah dari meja ini.
                            </p>
                        </div>
                        <ClinicalDocumentHistory
                            versions={documentVersions}
                            emptyMessage="Belum ada riwayat versi sumber klinis terstruktur."
                        />
                    </section>

                    <div className="space-y-3">
                        <section className="rounded-lg border border-border bg-card p-3 md:p-4">
                            <h2 className="text-sm font-semibold text-secondary-foreground">
                                Checklist otomatis
                            </h2>
                            <ul className="mt-3 space-y-2">
                                {review.checklist_items.map((item) => (
                                    <li
                                        key={item.code}
                                        className="rounded-md border border-border bg-muted/40 p-3"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <p className="text-sm font-semibold">
                                                    {item.label}
                                                </p>
                                                <p className="mt-0.5 font-mono text-xs text-muted-foreground">
                                                    {item.code}
                                                </p>
                                            </div>
                                            <span
                                                className={
                                                    item.status === 'PASS'
                                                        ? 'text-xs font-semibold text-success'
                                                        : item.status === 'FAIL'
                                                          ? 'text-xs font-semibold text-destructive'
                                                          : 'text-xs font-semibold text-muted-foreground'
                                                }
                                            >
                                                {item.status === 'PASS'
                                                    ? 'Sesuai'
                                                    : item.status === 'FAIL'
                                                      ? 'Belum sesuai'
                                                      : 'Tidak berlaku'}
                                            </span>
                                        </div>
                                        {item.reason ? (
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                {item.reason}
                                            </p>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        </section>

                        <section className="rounded-lg border border-border bg-card p-3 md:p-4">
                            <h2 className="text-sm font-semibold text-secondary-foreground">
                                Blocker penutupan
                            </h2>
                            {blockers.length ? (
                                <ul className="mt-3 space-y-2">
                                    {blockers.map((blocker) => (
                                        <li
                                            key={blocker.code}
                                            className="rounded-md border border-warning/30 bg-warning/5 p-3"
                                        >
                                            <p className="text-sm font-semibold text-warning">
                                                {blocker.label}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {blocker.reason}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p
                                    role="status"
                                    className="mt-3 text-sm text-success"
                                >
                                    Tidak ada blocker lifecycle aktif.
                                </p>
                            )}
                        </section>

                        <section className="rounded-lg border border-border bg-card p-3 md:p-4">
                            <h2 className="text-sm font-semibold text-secondary-foreground">
                                Tindakan review
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Penyimpanan mengikat hasil checklist ke versi
                                sumber yang sedang tampil. Perubahan sumber
                                harus ditinjau ulang.
                            </p>
                            {closed ? (
                                <div
                                    role="status"
                                    className="mt-3 rounded-md border border-success/30 bg-success/5 p-3 text-sm text-success"
                                >
                                    Kunjungan sudah ditutup setelah sign-off.
                                    Review dan seluruh versi dokumen ditampilkan
                                    sebagai arsip hanya-baca.
                                </div>
                            ) : (
                                <div className="mt-3 flex flex-col gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={
                                            !permissions.can_save_review ||
                                            reviewForm.processing ||
                                            review.status === 'SIGNED_OFF'
                                        }
                                        onClick={saveReview}
                                        title={
                                            !permissions.can_save_review
                                                ? 'Akun ini tidak memiliki hak menyimpan review.'
                                                : undefined
                                        }
                                    >
                                        Simpan hasil review
                                    </Button>
                                    <Button
                                        type="button"
                                        disabled={!canSignoff}
                                        onClick={() => setDialogOpen(true)}
                                        aria-describedby="rm-signoff-help"
                                        title={
                                            !canSignoff
                                                ? 'Sign-off tersedia setelah semua item sesuai dan tidak ada blocker.'
                                                : undefined
                                        }
                                        className="bg-primary text-primary-foreground hover:bg-primary/90"
                                    >
                                        Sign-off dan tutup kunjungan
                                    </Button>
                                </div>
                            )}
                            {!closed && !canSignoff ? (
                                <p
                                    id="rm-signoff-help"
                                    className="mt-2 text-xs text-muted-foreground"
                                >
                                    Simpan review yang lengkap terlebih dahulu;
                                    seluruh item harus sesuai dan tidak boleh
                                    ada blocker.
                                </p>
                            ) : null}
                            {review.reviewer_name ? (
                                <p className="mt-3 text-xs text-muted-foreground">
                                    Ditinjau oleh {review.reviewer_name}
                                    {review.reviewed_at
                                        ? ` · ${new Date(review.reviewed_at).toLocaleString('id-ID')}`
                                        : ''}
                                </p>
                            ) : null}
                            {review.signed_off_by_name ? (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Sign-off oleh {review.signed_off_by_name}
                                    {review.signed_off_at
                                        ? ` · ${new Date(review.signed_off_at).toLocaleString('id-ID')}`
                                        : ''}
                                </p>
                            ) : null}
                        </section>
                    </div>
                </div>

                <PostClosureAmendmentPanel
                    encounterClosed={encounter.status === 'CLOSED'}
                    originalDocuments={clinicalSources}
                    reasonOptions={amendmentReasonOptions}
                    amendments={amendments}
                    canRequest={Boolean(permissions.can_request_amendment)}
                    storeUrl={actions.store_amendment_url ?? null}
                />
            </div>

            {!closed ? (
                <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                    <DialogContent
                        showCloseButton={false}
                        className="motion-reduce:animate-none motion-reduce:transition-none"
                    >
                        <DialogHeader>
                            <DialogTitle>
                                Konfirmasi sign-off kelengkapan RM
                            </DialogTitle>
                            <DialogDescription>
                                Sistem akan memeriksa ulang versi sumber,
                                checklist, hak akses, dan blocker. Jika seluruh
                                pemeriksaan berhasil, kunjungan ditutup.
                                Tindakan ini bukan pernyataan tanda tangan
                                elektronik legal.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button
                                type="button"
                                disabled={signoffForm.processing}
                                onClick={signoff}
                                className="bg-primary text-primary-foreground hover:bg-primary/90"
                            >
                                Ya, sign-off dan tutup
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            ) : null}
        </>
    );
}

RmRawatJalanShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Beranda', href: '/' },
        { title: 'RM', href: '/rm/rawat-jalan' },
        { title: 'Rawat Jalan', href: '/rm/rawat-jalan' },
        {
            title: props.encounter.patient.full_name ?? 'Tinjau',
            href: `/rm/rawat-jalan/${props.encounter.public_id}`,
        },
    ] satisfies BreadcrumbItem[],
});
