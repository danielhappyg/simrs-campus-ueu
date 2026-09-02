import { useForm } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import type { FormEvent } from 'react';
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
import { Label } from '@/components/ui/label';
import type {
    AmendmentReasonOption,
    ClinicalDocument,
    ClinicalDocumentType,
    OutpatientAmendment,
} from './types';

const documentTypeLabel: Record<ClinicalDocumentType, string> = {
    NURSING_ASSESSMENT: 'Asesmen keperawatan',
    MEDICAL_ASSESSMENT: 'Asesmen medis',
};

const requestStateLabel: Record<OutpatientAmendment['state'], string> = {
    SUBMITTED: 'Menunggu keputusan',
    APPROVED: 'Disetujui',
    DENIED: 'Ditolak',
    CONSUMED: 'Adendum telah dibuat',
};

function newOperationKey() {
    return (
        globalThis.crypto?.randomUUID?.() ??
        `web-${Date.now()}-${Math.random().toString(16).slice(2)}`
    );
}

function formatDate(value: string | null) {
    return value
        ? new Date(value).toLocaleString('id-ID')
        : 'Waktu tidak tersedia';
}

function AmendmentErrorSummary({
    errors,
    focusTrigger = 0,
}: {
    errors: Record<string, string | undefined>;
    focusTrigger?: number;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const messages = [
        ...new Set(Object.values(errors).filter(Boolean)),
    ] as string[];
    const errorSignature = messages.join('\u0000');

    useEffect(() => {
        if (messages.length > 0) {
            ref.current?.focus();
        }
    }, [errorSignature, focusTrigger, messages.length]);

    if (messages.length === 0) {
        return null;
    }

    return (
        <div
            ref={ref}
            role="alert"
            tabIndex={-1}
            className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
        >
            <p className="font-semibold">Tindakan belum dapat diproses.</p>
            <ul className="mt-1 list-disc space-y-1 pl-5">
                {messages.map((message) => (
                    <li key={message}>{message}</li>
                ))}
            </ul>
        </div>
    );
}

function RequestAmendmentForm({
    documents,
    reasonOptions,
    storeUrl,
    announce,
}: {
    documents: ClinicalDocument[];
    reasonOptions: AmendmentReasonOption[];
    storeUrl: string;
    announce: (message: string) => void;
}) {
    const firstDocument = documents[0];
    const firstReason = reasonOptions[0];
    const [failureAttempt, setFailureAttempt] = useState(0);
    const form = useForm({
        original_document_public_id: firstDocument?.public_id ?? '',
        original_document_version: firstDocument?.version ?? 0,
        reason_code: firstReason?.value ?? '',
        note: '',
        idempotency_key: newOperationKey(),
    });
    const selectedReason = reasonOptions.find(
        (option) => option.value === form.data.reason_code,
    );
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(storeUrl, {
            preserveScroll: true,
            onSuccess: () => {
                announce('Permintaan adendum berhasil dikirim.');
                form.setData({
                    ...form.data,
                    note: '',
                    idempotency_key: newOperationKey(),
                });
            },
            onError: () => setFailureAttempt((current) => current + 1),
        });
    };

    return (
        <form onSubmit={submit} className="mt-4 space-y-4">
            <AmendmentErrorSummary
                errors={errors}
                focusTrigger={failureAttempt}
            />
            <div className="grid gap-1.5">
                <Label htmlFor="amendment-original-document">
                    Dokumen final yang dirujuk
                </Label>
                <select
                    id="amendment-original-document"
                    value={form.data.original_document_public_id}
                    onChange={(event) => {
                        const document = documents.find(
                            (candidate) =>
                                candidate.public_id === event.target.value,
                        );

                        form.setData({
                            ...form.data,
                            original_document_public_id: event.target.value,
                            original_document_version: document?.version ?? 0,
                        });
                    }}
                    aria-invalid={Boolean(errors.original_document_public_id)}
                    aria-describedby={
                        errors.original_document_public_id
                            ? 'amendment-original-document-error'
                            : 'amendment-original-document-help'
                    }
                    className="min-h-11 rounded-md border border-input bg-background px-3 text-sm focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                >
                    {documents.map((document) => (
                        <option
                            key={document.public_id}
                            value={document.public_id}
                        >
                            {documentTypeLabel[document.document_type]} · versi{' '}
                            {document.version}
                        </option>
                    ))}
                </select>
                <p
                    id="amendment-original-document-help"
                    className="text-xs text-muted-foreground"
                >
                    Dokumen asli tetap final dan tidak diubah.
                </p>
                {errors.original_document_public_id ? (
                    <p
                        id="amendment-original-document-error"
                        className="text-xs text-destructive"
                    >
                        {errors.original_document_public_id}
                    </p>
                ) : null}
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="amendment-reason">Alasan adendum</Label>
                <select
                    id="amendment-reason"
                    value={form.data.reason_code}
                    onChange={(event) =>
                        form.setData('reason_code', event.target.value)
                    }
                    aria-invalid={Boolean(errors.reason_code)}
                    aria-describedby={
                        errors.reason_code
                            ? 'amendment-reason-error'
                            : undefined
                    }
                    className="min-h-11 rounded-md border border-input bg-background px-3 text-sm focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                >
                    {reasonOptions.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
                {errors.reason_code ? (
                    <p
                        id="amendment-reason-error"
                        className="text-xs text-destructive"
                    >
                        {errors.reason_code}
                    </p>
                ) : null}
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="amendment-note">
                    Catatan alasan
                    {!selectedReason?.requires_note ? ' (opsional)' : ''}
                </Label>
                <textarea
                    id="amendment-note"
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                    required={selectedReason?.requires_note}
                    aria-invalid={Boolean(errors.note)}
                    aria-describedby={
                        errors.note
                            ? 'amendment-note-error'
                            : 'amendment-note-help'
                    }
                    className="min-h-24 rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                />
                <p
                    id="amendment-note-help"
                    className="text-xs text-muted-foreground"
                >
                    Jelaskan kebutuhan koreksi tanpa menyalin isi klinis ke
                    judul atau tautan.
                </p>
                {errors.note ? (
                    <p
                        id="amendment-note-error"
                        className="text-xs text-destructive"
                    >
                        {errors.note}
                    </p>
                ) : null}
            </div>

            <Button
                type="submit"
                disabled={
                    form.processing ||
                    !firstDocument ||
                    !firstReason ||
                    !form.data.original_document_public_id ||
                    !form.data.reason_code
                }
                className="min-h-11 w-full sm:w-auto"
            >
                Kirim permintaan adendum
            </Button>
        </form>
    );
}

function AmendmentChainItem({
    amendment,
    announce,
}: {
    amendment: OutpatientAmendment;
    announce: (message: string) => void;
}) {
    const id = useId();
    const [finalizeDialogOpen, setFinalizeDialogOpen] = useState(false);
    const [failureAttempt, setFailureAttempt] = useState(0);
    const decisionForm = useForm({
        decision: 'APPROVED' as 'APPROVED' | 'DENIED',
        decision_note: '',
        expected_version: amendment.version,
        idempotency_key: newOperationKey(),
    });
    const addendumForm = useForm({
        expected_version: amendment.addendum?.version ?? 0,
        fields: {
            addendum_text: amendment.addendum?.fields.addendum_text ?? '',
        },
        idempotency_key: newOperationKey(),
    });
    const finalizeForm = useForm({
        expected_version: amendment.addendum?.version ?? 0,
        idempotency_key: newOperationKey(),
    });
    const reviewForm = useForm({
        expected_version: amendment.current_review_version,
        source_fingerprint: amendment.current_review_source_fingerprint ?? '',
        idempotency_key: newOperationKey(),
    });
    const signoffForm = useForm({
        expected_version: amendment.current_review_version,
        source_fingerprint: amendment.current_review_source_fingerprint ?? '',
        idempotency_key: newOperationKey(),
    });
    const errors = {
        ...(decisionForm.errors as Record<string, string | undefined>),
        ...(addendumForm.errors as Record<string, string | undefined>),
        ...(finalizeForm.errors as Record<string, string | undefined>),
        ...(reviewForm.errors as Record<string, string | undefined>),
        ...(signoffForm.errors as Record<string, string | undefined>),
    };
    const addendumFinal = amendment.addendum?.state === 'FINAL';
    const reviewSignedOff = amendment.renewed_review?.state === 'SIGNED_OFF';
    const incompleteReviewItems =
        amendment.renewed_review?.items.filter(
            (item) => item.is_blocking && !item.is_complete,
        ) ?? [];
    const decisionNoteRequired = decisionForm.data.decision === 'DENIED';
    const addendumTextError = addendumForm.errors['fields.addendum_text'];
    const refocusErrors = () => setFailureAttempt((current) => current + 1);
    const refocusAfterFinalizeError = () => {
        setFinalizeDialogOpen(false);
        refocusErrors();
    };

    const decide = (event: FormEvent) => {
        event.preventDefault();

        if (!amendment.actions.decision_url) {
            return;
        }

        decisionForm.post(amendment.actions.decision_url, {
            preserveScroll: true,
            onSuccess: () =>
                announce('Keputusan permintaan berhasil disimpan.'),
            onError: refocusErrors,
        });
    };

    const saveAddendum = (event: FormEvent) => {
        event.preventDefault();

        if (!amendment.actions.save_addendum_url) {
            return;
        }

        addendumForm.post(amendment.actions.save_addendum_url, {
            preserveScroll: true,
            onSuccess: () => announce('Draf adendum berhasil disimpan.'),
            onError: refocusErrors,
        });
    };

    const finalizeAddendum = () => {
        if (!amendment.actions.finalize_addendum_url) {
            return;
        }

        finalizeForm.post(amendment.actions.finalize_addendum_url, {
            preserveScroll: true,
            onSuccess: () => {
                setFinalizeDialogOpen(false);
                announce('Adendum berhasil difinalisasi.');
            },
            onError: refocusAfterFinalizeError,
        });
    };

    const saveReview = () => {
        if (!amendment.actions.save_renewed_review_url) {
            return;
        }

        reviewForm.post(amendment.actions.save_renewed_review_url, {
            preserveScroll: true,
            onSuccess: () => announce('Review adendum berhasil disimpan.'),
            onError: refocusErrors,
        });
    };

    const signoffReview = () => {
        if (!amendment.actions.signoff_renewed_review_url) {
            return;
        }

        signoffForm.post(amendment.actions.signoff_renewed_review_url, {
            preserveScroll: true,
            onSuccess: () =>
                announce('Sign-off review adendum berhasil disimpan.'),
            onError: refocusErrors,
        });
    };

    return (
        <li>
            <article className="relative rounded-lg border border-border bg-card p-3 pl-5 md:p-4 md:pl-6">
                <span
                    aria-hidden="true"
                    className="absolute top-4 bottom-4 left-2 w-0.5 rounded-full bg-primary/40 md:left-3"
                />
                <header className="flex flex-wrap items-start justify-between gap-3 border-b border-border pb-3">
                    <div>
                        <h3 className="text-sm font-semibold text-foreground">
                            {
                                documentTypeLabel[
                                    amendment.original_document.document_type
                                ]
                            }{' '}
                            · versi {amendment.original_document.version}
                        </h3>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Diminta oleh {amendment.requester_name ?? '—'} ·{' '}
                            {formatDate(amendment.requested_at)}
                        </p>
                    </div>
                    <span className="rounded-md bg-secondary px-2 py-1 text-xs font-semibold text-secondary-foreground">
                        {requestStateLabel[amendment.state]}
                    </span>
                </header>

                <AmendmentErrorSummary
                    errors={errors}
                    focusTrigger={failureAttempt}
                />

                <ol className="mt-4 space-y-4" aria-label="Tahapan adendum">
                    <li>
                        <h4 className="text-xs font-semibold tracking-wide text-secondary-foreground uppercase">
                            1 · Permintaan
                        </h4>
                        <p className="mt-1 text-sm font-medium">
                            {amendment.reason_label}
                        </p>
                        {amendment.note ? (
                            <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                {amendment.note}
                            </p>
                        ) : null}
                        <p className="mt-2 rounded-md border border-border bg-muted/30 p-2 text-xs text-muted-foreground">
                            Dokumen asli tetap final, utuh, dan hanya-baca.
                        </p>
                    </li>

                    <li className="border-t border-border pt-4">
                        <h4 className="text-xs font-semibold tracking-wide text-secondary-foreground uppercase">
                            2 · Keputusan dokter lain
                        </h4>
                        {amendment.state === 'SUBMITTED' &&
                        amendment.permissions.can_decide &&
                        amendment.actions.decision_url ? (
                            <form onSubmit={decide} className="mt-3 space-y-3">
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`${id}-decision`}>
                                        Keputusan
                                    </Label>
                                    <select
                                        id={`${id}-decision`}
                                        value={decisionForm.data.decision}
                                        onChange={(event) =>
                                            decisionForm.setData(
                                                'decision',
                                                event.target.value as
                                                    'APPROVED' | 'DENIED',
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            decisionForm.errors.decision,
                                        )}
                                        aria-describedby={
                                            decisionForm.errors.decision
                                                ? `${id}-decision-error`
                                                : undefined
                                        }
                                        className="min-h-11 rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                                    >
                                        <option value="APPROVED">
                                            Setujui
                                        </option>
                                        <option value="DENIED">Tolak</option>
                                    </select>
                                    {decisionForm.errors.decision ? (
                                        <p
                                            id={`${id}-decision-error`}
                                            className="text-xs text-destructive"
                                        >
                                            {decisionForm.errors.decision}
                                        </p>
                                    ) : null}
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`${id}-decision-note`}>
                                        {decisionNoteRequired
                                            ? 'Catatan keputusan · wajib untuk penolakan'
                                            : 'Catatan keputusan (opsional)'}
                                    </Label>
                                    <textarea
                                        id={`${id}-decision-note`}
                                        value={decisionForm.data.decision_note}
                                        onChange={(event) =>
                                            decisionForm.setData(
                                                'decision_note',
                                                event.target.value,
                                            )
                                        }
                                        required={decisionNoteRequired}
                                        aria-invalid={Boolean(
                                            decisionForm.errors.decision_note,
                                        )}
                                        aria-describedby={
                                            decisionForm.errors.decision_note
                                                ? `${id}-decision-note-error`
                                                : undefined
                                        }
                                        className="min-h-20 rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                                    />
                                    {decisionForm.errors.decision_note ? (
                                        <p
                                            id={`${id}-decision-note-error`}
                                            className="text-xs text-destructive"
                                        >
                                            {decisionForm.errors.decision_note}
                                        </p>
                                    ) : null}
                                </div>
                                <Button
                                    type="submit"
                                    disabled={decisionForm.processing}
                                    className="min-h-11 w-full sm:w-auto"
                                >
                                    Simpan keputusan
                                </Button>
                            </form>
                        ) : amendment.decided_at ? (
                            <div className="mt-2 text-sm">
                                <p>
                                    {amendment.state === 'DENIED'
                                        ? 'Ditolak'
                                        : 'Disetujui'}{' '}
                                    oleh {amendment.decider_name ?? '—'} ·{' '}
                                    {formatDate(amendment.decided_at)}
                                </p>
                                {amendment.decision_note ? (
                                    <p className="mt-1 whitespace-pre-wrap text-muted-foreground">
                                        {amendment.decision_note}
                                    </p>
                                ) : null}
                            </div>
                        ) : (
                            <p className="mt-2 text-sm text-muted-foreground">
                                Menunggu keputusan dokter lain yang memenuhi
                                kewenangan.
                            </p>
                        )}
                    </li>

                    <li className="border-t border-border pt-4">
                        <h4 className="text-xs font-semibold tracking-wide text-secondary-foreground uppercase">
                            3 · Adendum baru
                        </h4>
                        {amendment.permissions.can_write_addendum &&
                        amendment.actions.save_addendum_url &&
                        !addendumFinal ? (
                            <form
                                onSubmit={saveAddendum}
                                className="mt-3 space-y-3"
                            >
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`${id}-addendum-text`}>
                                        Isi adendum · wajib
                                    </Label>
                                    <textarea
                                        id={`${id}-addendum-text`}
                                        value={
                                            addendumForm.data.fields
                                                .addendum_text
                                        }
                                        onChange={(event) =>
                                            addendumForm.setData('fields', {
                                                addendum_text:
                                                    event.target.value,
                                            })
                                        }
                                        required
                                        aria-invalid={Boolean(
                                            addendumTextError,
                                        )}
                                        aria-describedby={`${id}-addendum-help${addendumTextError ? ` ${id}-addendum-error` : ''}`}
                                        className="min-h-32 rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                                    />
                                    <p
                                        id={`${id}-addendum-help`}
                                        className="text-xs text-muted-foreground"
                                    >
                                        Catat informasi tambahan. Isi dokumen
                                        asli tidak akan diganti.
                                    </p>
                                    {addendumTextError ? (
                                        <p
                                            id={`${id}-addendum-error`}
                                            className="text-xs text-destructive"
                                        >
                                            {addendumTextError}
                                        </p>
                                    ) : null}
                                </div>
                                <div className="flex flex-col gap-2 sm:flex-row">
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={addendumForm.processing}
                                        className="min-h-11"
                                    >
                                        Simpan draf adendum
                                    </Button>
                                    {amendment.addendum &&
                                    amendment.permissions
                                        .can_finalize_addendum &&
                                    amendment.actions.finalize_addendum_url ? (
                                        <Button
                                            type="button"
                                            onClick={() =>
                                                setFinalizeDialogOpen(true)
                                            }
                                            disabled={
                                                addendumForm.isDirty ||
                                                finalizeForm.processing
                                            }
                                            aria-describedby={`${id}-finalize-help`}
                                            className="min-h-11"
                                        >
                                            Finalisasi adendum
                                        </Button>
                                    ) : null}
                                </div>
                                {addendumForm.isDirty ? (
                                    <p
                                        id={`${id}-finalize-help`}
                                        className="text-xs text-muted-foreground"
                                    >
                                        Simpan perubahan draf sebelum
                                        finalisasi.
                                    </p>
                                ) : null}
                            </form>
                        ) : amendment.addendum ? (
                            <div className="mt-2 rounded-md border border-border bg-muted/30 p-3">
                                <p className="text-xs font-semibold text-secondary-foreground">
                                    {amendment.addendum.state === 'FINAL'
                                        ? 'Final'
                                        : 'Draf'}{' '}
                                    · versi {amendment.addendum.version}
                                </p>
                                <p className="mt-2 text-sm whitespace-pre-wrap">
                                    {amendment.addendum.fields.addendum_text ||
                                        '—'}
                                </p>
                                <p className="mt-2 text-xs text-muted-foreground">
                                    Penulis{' '}
                                    {amendment.addendum.author_name ?? '—'}
                                    {amendment.addendum.finalized_at
                                        ? ` · Final ${formatDate(amendment.addendum.finalized_at)}`
                                        : ''}
                                </p>
                            </div>
                        ) : (
                            <p className="mt-2 text-sm text-muted-foreground">
                                {amendment.state === 'DENIED'
                                    ? 'Permintaan ditolak; adendum tidak dapat dibuat.'
                                    : 'Adendum belum tersedia.'}
                            </p>
                        )}
                    </li>

                    <li className="border-t border-border pt-4">
                        <h4 className="text-xs font-semibold tracking-wide text-secondary-foreground uppercase">
                            4 · Review ulang RMIK
                        </h4>
                        {amendment.renewed_review ? (
                            <div className="mt-2 space-y-3">
                                <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                    <p>
                                        {reviewSignedOff
                                            ? 'Sudah sign-off'
                                            : 'Draf review'}{' '}
                                        · versi{' '}
                                        {amendment.renewed_review.version}
                                    </p>
                                    <p className="font-mono text-xs text-muted-foreground">
                                        {
                                            amendment.renewed_review
                                                .source_fingerprint
                                        }
                                    </p>
                                </div>
                                <ul className="space-y-2">
                                    {amendment.renewed_review.items.map(
                                        (item) => (
                                            <li
                                                key={item.item_code}
                                                className="flex min-h-11 items-start justify-between gap-3 rounded-md border border-border p-2 text-sm"
                                            >
                                                <span>{item.label}</span>
                                                <span
                                                    className={
                                                        item.is_complete
                                                            ? 'font-semibold text-success'
                                                            : 'font-semibold text-destructive'
                                                    }
                                                >
                                                    {item.is_complete
                                                        ? 'Lengkap'
                                                        : 'Belum lengkap'}
                                                </span>
                                            </li>
                                        ),
                                    )}
                                </ul>
                                <div className="flex flex-col gap-2 sm:flex-row">
                                    {amendment.permissions
                                        .can_save_renewed_review &&
                                    amendment.actions
                                        .save_renewed_review_url ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={saveReview}
                                            disabled={reviewForm.processing}
                                            className="min-h-11"
                                        >
                                            Simpan review adendum
                                        </Button>
                                    ) : null}
                                    {amendment.permissions
                                        .can_signoff_renewed_review &&
                                    amendment.actions
                                        .signoff_renewed_review_url ? (
                                        <Button
                                            type="button"
                                            onClick={signoffReview}
                                            disabled={
                                                signoffForm.processing ||
                                                incompleteReviewItems.length >
                                                    0 ||
                                                reviewSignedOff
                                            }
                                            className="min-h-11"
                                        >
                                            Sign-off review adendum
                                        </Button>
                                    ) : null}
                                </div>
                            </div>
                        ) : amendment.permissions.can_save_renewed_review &&
                          amendment.actions.save_renewed_review_url ? (
                            <div className="mt-2">
                                <p className="text-sm text-muted-foreground">
                                    Buat snapshot kelengkapan baru setelah
                                    adendum final.
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={saveReview}
                                    disabled={
                                        reviewForm.processing || !addendumFinal
                                    }
                                    className="mt-3 min-h-11"
                                >
                                    Mulai review adendum
                                </Button>
                            </div>
                        ) : (
                            <p className="mt-2 text-sm text-muted-foreground">
                                Review ulang RMIK belum tersedia.
                            </p>
                        )}
                    </li>
                </ol>

                {amendment.addendum &&
                amendment.permissions.can_finalize_addendum &&
                amendment.actions.finalize_addendum_url ? (
                    <Dialog
                        open={finalizeDialogOpen}
                        onOpenChange={setFinalizeDialogOpen}
                    >
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Finalisasi adendum?</DialogTitle>
                                <DialogDescription>
                                    Adendum final menjadi hanya-baca. Dokumen
                                    asli tetap final dan tidak berubah.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        Batal
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="button"
                                    onClick={finalizeAddendum}
                                    disabled={finalizeForm.processing}
                                    className="min-h-11"
                                >
                                    Ya, finalisasi adendum
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                ) : null}
            </article>
        </li>
    );
}

export function PostClosureAmendmentPanel({
    encounterClosed,
    originalDocuments,
    reasonOptions,
    amendments,
    canRequest,
    storeUrl,
}: {
    encounterClosed: boolean;
    originalDocuments: ClinicalDocument[];
    reasonOptions: AmendmentReasonOption[];
    amendments: OutpatientAmendment[];
    canRequest: boolean;
    storeUrl: string | null;
}) {
    const [announcement, setAnnouncement] = useState('');
    const finalDocuments = originalDocuments.filter(
        (document) => document.document_state === 'FINAL',
    );

    if (!encounterClosed) {
        return null;
    }

    return (
        <section
            aria-labelledby="post-closure-amendment-title"
            className="rounded-lg border border-border bg-card p-3 md:p-4"
        >
            <p className="sr-only" role="status" aria-live="polite">
                {announcement}
            </p>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2
                        id="post-closure-amendment-title"
                        className="text-base font-semibold text-secondary-foreground"
                    >
                        Adendum pascapenutupan
                    </h2>
                    <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                        Kunjungan tetap ditutup. Koreksi dibuat sebagai adendum
                        baru, lalu ditinjau ulang oleh RMIK tanpa mengubah
                        dokumen atau sign-off awal.
                    </p>
                </div>
                <span className="rounded-md border border-border bg-muted px-2 py-1 text-xs font-semibold text-muted-foreground">
                    Arsip asli tetap utuh
                </span>
            </div>

            {canRequest && storeUrl ? (
                finalDocuments.length > 0 && reasonOptions.length > 0 ? (
                    <div className="mt-4 rounded-lg border border-primary/25 bg-primary/5 p-3 md:p-4">
                        <h3 className="text-sm font-semibold">
                            Ajukan adendum baru
                        </h3>
                        <RequestAmendmentForm
                            documents={finalDocuments}
                            reasonOptions={reasonOptions}
                            storeUrl={storeUrl}
                            announce={setAnnouncement}
                        />
                    </div>
                ) : (
                    <p
                        role="status"
                        className="mt-4 rounded-md border border-warning/30 bg-warning/5 p-3 text-sm text-warning"
                    >
                        Permintaan belum dapat dibuat karena dokumen final atau
                        pilihan alasan belum tersedia.
                    </p>
                )
            ) : null}

            <div className="mt-5">
                <h3 className="text-sm font-semibold">
                    Riwayat rantai adendum
                </h3>
                {amendments.length > 0 ? (
                    <ol
                        aria-label="Riwayat permintaan dan adendum"
                        className="mt-3 space-y-3"
                    >
                        {amendments.map((amendment) => (
                            <AmendmentChainItem
                                key={[
                                    amendment.public_id,
                                    amendment.state,
                                    amendment.version,
                                    amendment.addendum?.public_id ?? 'none',
                                    amendment.addendum?.state ?? 'none',
                                    amendment.addendum?.version ?? 0,
                                    amendment.renewed_review?.public_id ??
                                        'none',
                                    amendment.renewed_review?.state ?? 'none',
                                    amendment.current_review_version,
                                    amendment.current_review_source_fingerprint ??
                                        'none',
                                ].join(':')}
                                amendment={amendment}
                                announce={setAnnouncement}
                            />
                        ))}
                    </ol>
                ) : (
                    <p className="mt-2 text-sm text-muted-foreground">
                        Belum ada permintaan adendum untuk kunjungan ini.
                    </p>
                )}
            </div>
        </section>
    );
}
