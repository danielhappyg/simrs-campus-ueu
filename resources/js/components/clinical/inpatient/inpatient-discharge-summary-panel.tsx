import { useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    ClipboardCheck,
    FileClock,
    History,
    LockKeyhole,
} from 'lucide-react';
import { startTransition, useEffect, useMemo, useRef, useState } from 'react';
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
import { cn } from '@/lib/utils';
import { InpatientDischargeCodingSourcePanel } from './inpatient-discharge-coding-source-panel';
import { InpatientDischargeSummaryAddendumPanel } from './inpatient-discharge-summary-addendum-panel';
import { InpatientRoutineDischargePanel } from './inpatient-routine-discharge-panel';
import { formatClinicalDate } from './presentation';
import type {
    InpatientDischargeSummaryFields,
    InpatientDischargeCodingSourceProjection,
    InpatientDischargeSummaryProjection,
    InpatientRoutineDischargeProjection,
    InpatientSummaryAddendumProjection,
} from './types';

const fieldDefinitions: Array<{
    key: keyof InpatientDischargeSummaryFields;
    label: string;
    help: string;
}> = [
    {
        key: 'admission_reason',
        label: 'Alasan Masuk',
        help: 'Kondisi atau masalah utama yang mendasari perawatan pada episode ini.',
    },
    {
        key: 'significant_findings',
        label: 'Temuan Penting',
        help: 'Temuan klinis, pemeriksaan penunjang, dan perubahan penting selama perawatan.',
    },
    {
        key: 'care_and_treatment_summary',
        label: 'Ringkasan Perawatan dan Pengobatan',
        help: 'Rangkum tindakan, terapi, dan respons pasien yang relevan.',
    },
    {
        key: 'condition_at_discharge',
        label: 'Kondisi Saat Pulang',
        help: 'Jelaskan keadaan klinis pasien pada saat dokumen ini diselesaikan.',
    },
    {
        key: 'follow_up_plan',
        label: 'Rencana Tindak Lanjut',
        help: 'Tuliskan kontrol, pemantauan, terapi lanjutan, dan arahan yang diperlukan.',
    },
];

const emptyFields: InpatientDischargeSummaryFields = {
    admission_reason: '',
    significant_findings: '',
    care_and_treatment_summary: '',
    condition_at_discharge: '',
    follow_up_plan: '',
};

let idempotencyFallback = 0;

export function newDischargeSummaryIdempotencyKey(
    operation: 'draft' | 'final',
) {
    const randomPart =
        globalThis.crypto?.randomUUID?.() ??
        `${Date.now().toString(36)}-${(++idempotencyFallback).toString(36)}`;

    return `inpatient-discharge-${operation}-${randomPart}`.toLowerCase();
}

function nonEmptyErrors(errors: Record<string, string | undefined>) {
    return Object.entries(errors).filter((entry): entry is [string, string] =>
        Boolean(entry[1]),
    );
}

type Props = {
    projection: InpatientDischargeSummaryProjection;
    codingSourceProjection?: InpatientDischargeCodingSourceProjection;
    routineDischargeProjection: InpatientRoutineDischargeProjection;
    summaryAddendumProjection?: InpatientSummaryAddendumProjection;
    disabledByUnsavedDocument: boolean;
    onDirtyChange?: (dirty: boolean) => void;
    onCodingSourceDirtyChange?: (dirty: boolean) => void;
    onSummaryAddendumDirtyChange?: (dirty: boolean) => void;
};

const unavailableCodingSourceProjection: InpatientDischargeCodingSourceProjection =
    {
        definition_version: 'INPATIENT_DISCHARGE_CODING_SOURCE_V1',
        source: null,
        versions: [],
        permission: { can_save_draft: false, can_finalize: false },
        actions: { save_draft_url: null, finalize_url: null },
    };

export function InpatientDischargeSummaryPanel({
    projection,
    codingSourceProjection = unavailableCodingSourceProjection,
    routineDischargeProjection,
    summaryAddendumProjection,
    disabledByUnsavedDocument,
    onDirtyChange,
    onCodingSourceDirtyChange,
    onSummaryAddendumDirtyChange,
}: Props) {
    const summary = projection.summary;
    const baseline = useMemo(
        () => summary?.fields ?? emptyFields,
        [summary?.fields],
    );
    const [savedFields, setSavedFields] = useState(baseline);
    const [confirmationOpen, setConfirmationOpen] = useState(false);
    const [validationAttempt, setValidationAttempt] = useState(0);
    const [serverErrorAttempt, setServerErrorAttempt] = useState(0);
    const errorSummaryRef = useRef<HTMLDivElement>(null);
    const projectionRevision = `${summary?.public_id ?? 'new'}:${summary?.version ?? 0}:${summary?.state ?? 'NONE'}:${projection.definition_version}:${JSON.stringify(baseline)}`;
    const previousProjectionRevisionRef = useRef(projectionRevision);
    const draftForm = useForm({
        definition_version: projection.definition_version,
        expected_version: summary?.version ?? 0,
        fields: baseline,
        idempotency_key: newDischargeSummaryIdempotencyKey('draft'),
    });
    const finalForm = useForm({
        definition_version: projection.definition_version,
        expected_version: summary?.version ?? 0,
        idempotency_key: newDischargeSummaryIdempotencyKey('final'),
    });
    const isFinal = summary?.state === 'FINAL';
    const canSave =
        !isFinal &&
        projection.permission.can_save_draft &&
        projection.actions.save_draft_url !== null;
    const canFinalize =
        summary?.state === 'DRAFT' &&
        projection.permission.can_finalize &&
        projection.actions.finalize_url !== null;
    const dirty =
        JSON.stringify(draftForm.data.fields) !== JSON.stringify(savedFields);
    const missingFinalFields = fieldDefinitions
        .filter(({ key }) => !(summary?.fields[key] ?? '').trim())
        .map(({ label }) => label);
    const errors = {
        ...(draftForm.errors as Record<string, string | undefined>),
        ...(finalForm.errors as Record<string, string | undefined>),
    };
    const serverErrors = nonEmptyErrors(errors);
    const displayedMissingFields =
        validationAttempt > 0 ? missingFinalFields : [];
    const summaryFingerprint = [
        ...serverErrors.map(([key, message]) => `${key}:${message}`),
        ...displayedMissingFields,
    ].join('|');

    useEffect(() => {
        if (previousProjectionRevisionRef.current === projectionRevision) {
            return;
        }

        const preserveUnsavedFields = dirty;
        const synchronizedVersion = summary?.version ?? 0;

        if (!preserveUnsavedFields) {
            startTransition(() => setSavedFields(baseline));
        }

        draftForm.setData((current) => ({
            ...current,
            definition_version: projection.definition_version,
            expected_version: synchronizedVersion,
            fields: preserveUnsavedFields ? current.fields : baseline,
            idempotency_key: newDischargeSummaryIdempotencyKey('draft'),
        }));
        finalForm.setData((current) => ({
            ...current,
            definition_version: projection.definition_version,
            expected_version: synchronizedVersion,
            idempotency_key: newDischargeSummaryIdempotencyKey('final'),
        }));

        previousProjectionRevisionRef.current = projectionRevision;
    }, [
        baseline,
        dirty,
        draftForm,
        finalForm,
        projection.definition_version,
        projectionRevision,
        savedFields,
        summary?.version,
    ]);

    useEffect(() => {
        onDirtyChange?.(dirty);
    }, [dirty, onDirtyChange]);

    useEffect(() => {
        if (
            (serverErrorAttempt > 0 || validationAttempt > 0) &&
            (serverErrors.length > 0 || displayedMissingFields.length > 0)
        ) {
            errorSummaryRef.current?.focus();
        }
    }, [
        displayedMissingFields.length,
        serverErrorAttempt,
        serverErrors.length,
        summaryFingerprint,
        validationAttempt,
    ]);

    const updateField = (
        key: keyof InpatientDischargeSummaryFields,
        value: string,
    ) => {
        if (draftForm.processing || finalForm.processing) {
            return;
        }

        draftForm.setData((current) => ({
            ...current,
            fields: { ...current.fields, [key]: value },
            idempotency_key: newDischargeSummaryIdempotencyKey('draft'),
        }));
        setValidationAttempt(0);
    };

    const saveDraft = (event: FormEvent) => {
        event.preventDefault();

        if (
            !canSave ||
            !projection.actions.save_draft_url ||
            draftForm.processing ||
            finalForm.processing
        ) {
            return;
        }

        const submittedFields = { ...draftForm.data.fields };

        draftForm.post(projection.actions.save_draft_url, {
            preserveScroll: true,
            onError: () =>
                setServerErrorAttempt((currentAttempt) => currentAttempt + 1),
            onSuccess: () => {
                setServerErrorAttempt(0);
                setSavedFields(submittedFields);
                draftForm.setData((current) => ({
                    ...current,
                    idempotency_key: newDischargeSummaryIdempotencyKey('draft'),
                }));
            },
        });
    };

    const requestFinalization = () => {
        if (
            !canFinalize ||
            dirty ||
            draftForm.processing ||
            finalForm.processing
        ) {
            return;
        }

        if (missingFinalFields.length > 0) {
            setValidationAttempt((currentAttempt) => currentAttempt + 1);

            return;
        }

        setConfirmationOpen(true);
    };

    const finalize = () => {
        if (
            !canFinalize ||
            !projection.actions.finalize_url ||
            dirty ||
            draftForm.processing ||
            finalForm.processing
        ) {
            return;
        }

        finalForm.post(projection.actions.finalize_url, {
            preserveScroll: true,
            onError: () => {
                setConfirmationOpen(false);
                setServerErrorAttempt((currentAttempt) => currentAttempt + 1);
            },
            onSuccess: () => {
                setConfirmationOpen(false);
                setServerErrorAttempt(0);
                finalForm.setData((current) => ({
                    ...current,
                    idempotency_key: newDischargeSummaryIdempotencyKey('final'),
                }));
            },
        });
    };

    const hasAnyAction = canSave || canFinalize;

    return (
        <div className="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(19rem,0.55fr)]">
            <section
                aria-labelledby="inpatient-discharge-summary-title"
                className="clinical-shadow min-w-0 overflow-hidden rounded-xl border border-border bg-card"
            >
                <div className="border-b border-primary/20 bg-secondary/60 px-4 py-3 md:px-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-[0.68rem] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                                Dokumen transisi perawatan
                            </p>
                            <h2
                                id="inpatient-discharge-summary-title"
                                className="mt-0.5 text-lg font-semibold text-foreground"
                            >
                                Ringkasan pulang
                            </h2>
                        </div>
                        <span
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold',
                                isFinal
                                    ? 'bg-success/10 text-success'
                                    : 'bg-warning/10 text-warning',
                            )}
                        >
                            {isFinal ? (
                                <CheckCircle2
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            ) : (
                                <FileClock
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            )}
                            {summary
                                ? `${isFinal ? 'Final' : 'Draf'} · v${summary.version}`
                                : 'Belum dibuat'}
                        </span>
                    </div>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                        {routineDischargeProjection.record
                            ? 'Ringkasan pulang tetap Final. Episode kini Siap RM dan penempatan terakhir tetap tersimpan dalam riwayat.'
                            : isFinal
                              ? 'Ringkasan pulang sudah Final. Selesaikan episode melalui tindakan terpisah di bawah untuk mengirimnya ke RM dan melepaskan tempat tidur.'
                              : 'Final menyelesaikan dokumen ringkasan pulang. Status episode dan penggunaan tempat tidur belum berubah sampai penyelesaian episode dilakukan.'}
                    </p>
                </div>

                <form
                    onSubmit={saveDraft}
                    className="space-y-4 p-4 md:p-5"
                    noValidate
                >
                    {serverErrors.length > 0 ||
                    displayedMissingFields.length > 0 ? (
                        <div
                            ref={errorSummaryRef}
                            role="alert"
                            tabIndex={-1}
                            className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                        >
                            <p className="font-semibold">
                                Ringkasan pulang belum dapat diselesaikan.
                            </p>
                            <ul className="mt-1 list-disc space-y-1 pl-5">
                                {displayedMissingFields.map((label) => (
                                    <li key={label}>
                                        {label} wajib diisi sebelum Final.
                                    </li>
                                ))}
                                {serverErrors.map(([key, message]) => (
                                    <li key={`${key}-${message}`}>{message}</li>
                                ))}
                            </ul>
                        </div>
                    ) : null}

                    {fieldDefinitions.map((field) => {
                        const fieldError = errors[`fields.${field.key}`];
                        const fieldId = `discharge-summary-${field.key}`;

                        return (
                            <div key={field.key} className="space-y-1.5">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <Label htmlFor={fieldId}>
                                        {field.label}
                                    </Label>
                                    <span className="text-[0.68rem] text-muted-foreground">
                                        Wajib untuk Final
                                    </span>
                                </div>
                                <textarea
                                    id={fieldId}
                                    value={draftForm.data.fields[field.key]}
                                    onChange={(event) =>
                                        updateField(
                                            field.key,
                                            event.target.value,
                                        )
                                    }
                                    readOnly={
                                        isFinal ||
                                        !canSave ||
                                        draftForm.processing ||
                                        finalForm.processing
                                    }
                                    maxLength={10_000}
                                    rows={
                                        field.key ===
                                        'care_and_treatment_summary'
                                            ? 5
                                            : 3
                                    }
                                    aria-invalid={fieldError ? true : undefined}
                                    aria-describedby={`${fieldId}-help${fieldError ? ` ${fieldId}-error` : ''}`}
                                    className="w-full resize-y rounded-lg border border-input bg-background px-3 py-2 text-sm leading-6 text-foreground shadow-xs transition outline-none read-only:cursor-default read-only:bg-muted/50 focus:border-ring focus:ring-3 focus:ring-ring/20"
                                />
                                <p
                                    id={`${fieldId}-help`}
                                    className="text-xs text-muted-foreground"
                                >
                                    {field.help}
                                </p>
                                {fieldError ? (
                                    <p
                                        id={`${fieldId}-error`}
                                        className="text-xs font-medium text-destructive"
                                    >
                                        {fieldError}
                                    </p>
                                ) : null}
                            </div>
                        );
                    })}

                    {summary?.assigned_physician.name ? (
                        <p className="flex items-center gap-2 text-xs text-muted-foreground">
                            <ClipboardCheck
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            Dokter penanggung jawab dokumen:{' '}
                            <span className="font-semibold text-foreground">
                                {summary.assigned_physician.name}
                            </span>
                        </p>
                    ) : null}
                    {dirty && canFinalize ? (
                        <p
                            role="status"
                            className="text-xs font-medium text-warning"
                        >
                            Simpan perubahan draf sebelum melakukan Final.
                        </p>
                    ) : null}
                    {isFinal ? (
                        <p className="flex items-center gap-2 text-xs text-muted-foreground">
                            <LockKeyhole
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            Ringkasan pulang Final dan seluruh versinya hanya
                            dapat dibaca.
                        </p>
                    ) : null}
                    {!isFinal && !hasAnyAction ? (
                        <p className="text-xs text-muted-foreground">
                            Tidak ada tindakan ringkasan pulang yang tersedia
                            untuk akun ini.
                        </p>
                    ) : null}

                    {hasAnyAction ? (
                        <div className="flex flex-col-reverse gap-2 border-t border-border pt-4 sm:flex-row sm:justify-end">
                            {canSave ? (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={
                                        draftForm.processing ||
                                        finalForm.processing
                                    }
                                >
                                    {draftForm.processing
                                        ? 'Menyimpan…'
                                        : 'Simpan draf'}
                                </Button>
                            ) : null}
                            {canFinalize ? (
                                <Button
                                    type="button"
                                    onClick={requestFinalization}
                                    disabled={
                                        dirty ||
                                        draftForm.processing ||
                                        finalForm.processing
                                    }
                                >
                                    {finalForm.processing
                                        ? 'Menjadikan Final…'
                                        : 'Jadikan Final'}
                                </Button>
                            ) : null}
                        </div>
                    ) : null}
                </form>

                {summaryAddendumProjection ? (
                    <div className="border-t border-border p-4 md:p-5">
                        <InpatientDischargeSummaryAddendumPanel
                            projection={summaryAddendumProjection}
                            onDirtyChange={onSummaryAddendumDirtyChange}
                        />
                    </div>
                ) : null}

                <InpatientDischargeCodingSourcePanel
                    projection={codingSourceProjection}
                    onDirtyChange={onCodingSourceDirtyChange}
                />

                <InpatientRoutineDischargePanel
                    projection={routineDischargeProjection}
                    dischargeSummaryFinal={isFinal}
                    codingSourceFinal={
                        codingSourceProjection.source?.state === 'FINAL'
                    }
                    disabledByUnsavedDocument={disabledByUnsavedDocument}
                />
            </section>

            <section
                aria-labelledby="discharge-summary-history-title"
                className="rounded-xl border border-border bg-card p-4 md:p-5"
            >
                <div className="flex items-center gap-2">
                    <History
                        aria-hidden="true"
                        className="size-4 text-primary"
                    />
                    <h2
                        id="discharge-summary-history-title"
                        className="text-sm font-semibold"
                    >
                        Riwayat versi
                    </h2>
                </div>
                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                    Setiap simpan dan Final membentuk versi tetap dalam urutan
                    pencatatan.
                </p>
                {projection.versions.length > 0 ? (
                    <ol className="mt-4 space-y-0 border-l border-border pl-4">
                        {projection.versions.map((version) => (
                            <li
                                key={version.public_id}
                                className="relative pb-5 last:pb-0"
                            >
                                <span
                                    aria-hidden="true"
                                    className="absolute top-1 -left-[1.21rem] size-2 rounded-full bg-primary ring-4 ring-card"
                                />
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <h3 className="text-sm font-semibold">
                                        Versi {version.version} ·{' '}
                                        {version.state === 'FINAL'
                                            ? 'Final'
                                            : 'Draf'}
                                    </h3>
                                    <time className="text-[0.7rem] text-muted-foreground">
                                        {formatClinicalDate(version.created_at)}
                                    </time>
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {version.actor_name ??
                                        'Aktor tidak tersedia'}
                                </p>
                                <details className="mt-2 rounded-md border border-border bg-muted/25 px-2.5 py-2">
                                    <summary className="cursor-pointer text-xs font-semibold text-primary focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                                        Lihat isi versi {version.version}
                                    </summary>
                                    <dl className="mt-3 space-y-3 border-t border-border pt-3">
                                        {fieldDefinitions.map((field) => (
                                            <div key={field.key}>
                                                <dt className="text-[0.68rem] font-semibold text-muted-foreground">
                                                    {field.label}
                                                </dt>
                                                <dd className="mt-0.5 text-xs leading-5 whitespace-pre-wrap text-foreground">
                                                    {version.fields[
                                                        field.key
                                                    ] || '—'}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </details>
                            </li>
                        ))}
                    </ol>
                ) : (
                    <p className="mt-4 rounded-lg border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                        Belum ada versi ringkasan pulang.
                    </p>
                )}
            </section>

            <Dialog open={confirmationOpen} onOpenChange={setConfirmationOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Jadikan ringkasan pulang Final?
                        </DialogTitle>
                        <DialogDescription>
                            Setelah Final, isi dokumen tidak dapat diubah.
                            Status episode dan penggunaan tempat tidur belum
                            berubah melalui tindakan ini.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Periksa kembali
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            onClick={finalize}
                            disabled={finalForm.processing}
                        >
                            {finalForm.processing
                                ? 'Menjadikan Final…'
                                : 'Ya, jadikan Final'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
