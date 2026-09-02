import { useForm } from '@inertiajs/react';
import { CheckCircle2, FileClock, LockKeyhole } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { PlacementSnapshot } from './placement-snapshot';
import {
    formatClinicalDate,
    inpatientDocumentStateLabel,
    inpatientDocumentTypeLabel,
} from './presentation';
import type {
    InpatientDailyDocument,
    InpatientDailyDocumentType,
    InpatientDocumentActions,
    InpatientDocumentPermission,
} from './types';

type FieldDefinition = {
    key: string;
    label: string;
    help: string;
    requiredForFinal: boolean;
};

const fieldDefinitions: Record<InpatientDailyDocumentType, FieldDefinition[]> =
    {
        NURSING_DAILY: [
            {
                key: 'nursing_observation',
                label: 'Observasi keperawatan',
                help: 'Temuan dan perkembangan pasien pada hari pelayanan ini.',
                requiredForFinal: true,
            },
            {
                key: 'nursing_intervention',
                label: 'Intervensi keperawatan',
                help: 'Tindakan keperawatan yang telah dilakukan.',
                requiredForFinal: true,
            },
            {
                key: 'nursing_evaluation',
                label: 'Evaluasi keperawatan',
                help: 'Respons pasien dan hasil evaluasi setelah intervensi.',
                requiredForFinal: true,
            },
            {
                key: 'additional_notes',
                label: 'Catatan tambahan',
                help: 'Informasi relevan lain yang belum tercakup.',
                requiredForFinal: false,
            },
        ],
        MEDICAL_DAILY: [
            {
                key: 'subjective',
                label: 'Subjektif',
                help: 'Keluhan dan perkembangan yang disampaikan pasien.',
                requiredForFinal: true,
            },
            {
                key: 'objective',
                label: 'Objektif',
                help: 'Temuan pemeriksaan yang relevan pada hari ini.',
                requiredForFinal: true,
            },
            {
                key: 'assessment',
                label: 'Asesmen',
                help: 'Penilaian klinis berdasarkan data hari ini.',
                requiredForFinal: true,
            },
            {
                key: 'plan',
                label: 'Rencana',
                help: 'Rencana pemantauan dan tindak lanjut klinis.',
                requiredForFinal: true,
            },
            {
                key: 'additional_notes',
                label: 'Catatan tambahan',
                help: 'Informasi relevan lain yang belum tercakup.',
                requiredForFinal: false,
            },
        ],
    };

let idempotencyFallback = 0;

export function newInpatientIdempotencyKey(operation: 'draft' | 'final') {
    const randomPart =
        globalThis.crypto?.randomUUID?.() ??
        `${Date.now().toString(36)}-${(++idempotencyFallback).toString(36)}`;

    return `inpatient-${operation}-${randomPart}`.toLowerCase();
}

function initialFields(
    type: InpatientDailyDocumentType,
    document?: InpatientDailyDocument,
) {
    return Object.fromEntries(
        fieldDefinitions[type].map(({ key }) => [
            key,
            document?.fields[key] ?? '',
        ]),
    );
}

function nonEmptyErrors(errors: Record<string, string | undefined>) {
    return Object.entries(errors).filter((entry): entry is [string, string] =>
        Boolean(entry[1]),
    );
}

type Props = {
    type: InpatientDailyDocumentType;
    definitionVersion: string;
    document?: InpatientDailyDocument;
    permission: InpatientDocumentPermission;
    actions: InpatientDocumentActions;
    onDirtyChange?: (type: InpatientDailyDocumentType, dirty: boolean) => void;
};

export function InpatientDailyDocumentForm({
    type,
    definitionVersion,
    document,
    permission,
    actions,
    onDirtyChange,
}: Props) {
    const definitions = fieldDefinitions[type];
    const baseline = useMemo(
        () => initialFields(type, document),
        [document, type],
    );
    const [savedFields, setSavedFields] = useState(baseline);
    const draftForm = useForm({
        definition_version: definitionVersion,
        expected_version: document?.version ?? 0,
        fields: baseline,
        idempotency_key: newInpatientIdempotencyKey('draft'),
    });
    const finalForm = useForm({
        definition_version: definitionVersion,
        expected_version: document?.version ?? 0,
        idempotency_key: newInpatientIdempotencyKey('final'),
    });
    const [serverErrorAttempt, setServerErrorAttempt] = useState(0);
    const [finalValidationAttempt, setFinalValidationAttempt] = useState(0);
    const errorSummaryRef = useRef<HTMLDivElement>(null);
    const isFinal = document?.state === 'FINAL';
    const canSave =
        !isFinal &&
        permission.can_save_draft &&
        actions.save_draft_url !== null;
    const canFinalize =
        document?.state === 'DRAFT' &&
        permission.can_finalize &&
        actions.finalize_url !== null;
    const dirty =
        JSON.stringify(draftForm.data.fields) !== JSON.stringify(savedFields);
    const missingFinalFields = definitions
        .filter(
            ({ key, requiredForFinal }) =>
                requiredForFinal &&
                !(document?.fields[key] ?? '').trim().length,
        )
        .map(({ label }) => label);
    const errors = {
        ...(draftForm.errors as Record<string, string | undefined>),
        ...(finalForm.errors as Record<string, string | undefined>),
    };
    const serverErrors = nonEmptyErrors(errors);
    const displayedMissingFields =
        finalValidationAttempt > 0 ? missingFinalFields : [];
    const summaryFingerprint = [
        ...serverErrors.map(([key, message]) => `${key}:${message}`),
        ...displayedMissingFields,
    ].join('|');

    useEffect(() => {
        onDirtyChange?.(type, dirty);
    }, [dirty, onDirtyChange, type]);

    useEffect(() => {
        if (
            (serverErrorAttempt > 0 || finalValidationAttempt > 0) &&
            (serverErrors.length > 0 || displayedMissingFields.length > 0)
        ) {
            errorSummaryRef.current?.focus();
        }
    }, [
        displayedMissingFields.length,
        finalValidationAttempt,
        serverErrorAttempt,
        serverErrors.length,
        summaryFingerprint,
    ]);

    const updateField = (key: string, value: string) => {
        draftForm.setData((current) => ({
            ...current,
            fields: { ...current.fields, [key]: value },
            idempotency_key: newInpatientIdempotencyKey('draft'),
        }));
        setFinalValidationAttempt(0);
    };

    const saveDraft = (event: FormEvent) => {
        event.preventDefault();

        if (!canSave || !actions.save_draft_url) {
            return;
        }

        draftForm.post(actions.save_draft_url, {
            preserveScroll: true,
            onError: () =>
                setServerErrorAttempt((currentAttempt) => currentAttempt + 1),
            onSuccess: () => {
                setServerErrorAttempt(0);
                setSavedFields(draftForm.data.fields);
                draftForm.setData((current) => ({
                    ...current,
                    idempotency_key: newInpatientIdempotencyKey('draft'),
                }));
            },
        });
    };

    const finalize = () => {
        if (!canFinalize || !actions.finalize_url || dirty) {
            return;
        }

        if (missingFinalFields.length > 0) {
            setFinalValidationAttempt((currentAttempt) => currentAttempt + 1);

            return;
        }

        finalForm.post(actions.finalize_url, {
            preserveScroll: true,
            onError: () =>
                setServerErrorAttempt((currentAttempt) => currentAttempt + 1),
            onSuccess: () => {
                setServerErrorAttempt(0);
                finalForm.setData((current) => ({
                    ...current,
                    idempotency_key: newInpatientIdempotencyKey('final'),
                }));
            },
        });
    };

    const titleId = `daily-document-${type.toLowerCase()}`;
    const hasAnyAction = canSave || canFinalize;

    return (
        <section
            aria-labelledby={titleId}
            className="clinical-shadow min-w-0 overflow-hidden rounded-xl border border-border bg-card"
        >
            <div
                className={cn(
                    'border-b px-4 py-3',
                    type === 'NURSING_DAILY'
                        ? 'border-primary/20 bg-secondary/70'
                        : 'border-signal/20 bg-card',
                )}
            >
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-[0.68rem] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                            {type === 'NURSING_DAILY'
                                ? 'Dokumentasi perawat'
                                : 'Dokumentasi dokter'}
                        </p>
                        <h2
                            id={titleId}
                            className="mt-0.5 text-base font-semibold text-foreground"
                        >
                            {inpatientDocumentTypeLabel[type]}
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
                        {document
                            ? `${inpatientDocumentStateLabel[document.state]} · v${document.version}`
                            : 'Belum dibuat'}
                    </span>
                </div>
                <dl className="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                    <div>
                        <dt className="text-muted-foreground">
                            Hari pelayanan
                        </dt>
                        <dd className="font-semibold">
                            {document
                                ? formatClinicalDate(document.service_date)
                                : 'Ditentukan server saat draf pertama disimpan'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Penulis</dt>
                        <dd className="font-semibold">
                            {document?.author.name ?? 'Akun Anda saat disimpan'}
                        </dd>
                    </div>
                </dl>
            </div>

            <form onSubmit={saveDraft} className="space-y-4 p-4" noValidate>
                {serverErrors.length > 0 ||
                displayedMissingFields.length > 0 ? (
                    <div
                        ref={errorSummaryRef}
                        role="alert"
                        tabIndex={-1}
                        className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                    >
                        <p className="font-semibold">
                            Dokumen belum dapat diselesaikan.
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

                {definitions.map((field) => {
                    const fieldError = errors[`fields.${field.key}`];
                    const fieldId = `${type.toLowerCase()}-${field.key}`;

                    return (
                        <div key={field.key} className="space-y-1.5">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <Label htmlFor={fieldId}>{field.label}</Label>
                                <span className="text-[0.68rem] text-muted-foreground">
                                    {field.requiredForFinal
                                        ? 'Wajib untuk Final'
                                        : 'Opsional'}
                                </span>
                            </div>
                            <textarea
                                id={fieldId}
                                value={draftForm.data.fields[field.key] ?? ''}
                                onChange={(event) =>
                                    updateField(field.key, event.target.value)
                                }
                                readOnly={isFinal || !canSave}
                                maxLength={10_000}
                                rows={field.key === 'additional_notes' ? 3 : 4}
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

                {document?.placement_snapshot ? (
                    <div className="rounded-lg border border-border bg-muted/40 p-3">
                        <div className="mb-2 flex items-center gap-2 text-xs font-semibold text-secondary-foreground">
                            <LockKeyhole
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            Snapshot penempatan versi saat ini
                        </div>
                        <PlacementSnapshot
                            snapshot={document.placement_snapshot}
                            compact
                        />
                    </div>
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
                        <LockKeyhole aria-hidden="true" className="size-3.5" />
                        Dokumen Final dan seluruh versinya hanya dapat dibaca.
                    </p>
                ) : null}
                {!isFinal && !hasAnyAction ? (
                    <p className="text-xs text-muted-foreground">
                        Tidak ada tindakan dokumentasi yang tersedia untuk akun
                        ini.
                    </p>
                ) : null}

                {hasAnyAction ? (
                    <div className="flex flex-col-reverse gap-2 border-t border-border pt-4 sm:flex-row sm:justify-end">
                        {canSave ? (
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={draftForm.processing}
                            >
                                {draftForm.processing
                                    ? 'Menyimpan…'
                                    : 'Simpan draf'}
                            </Button>
                        ) : null}
                        {canFinalize ? (
                            <Button
                                type="button"
                                onClick={finalize}
                                disabled={dirty || finalForm.processing}
                            >
                                {finalForm.processing
                                    ? 'Menjadikan Final…'
                                    : 'Jadikan Final'}
                            </Button>
                        ) : null}
                    </div>
                ) : null}
            </form>
        </section>
    );
}
