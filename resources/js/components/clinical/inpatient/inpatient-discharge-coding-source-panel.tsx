import { useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    FileClock,
    History,
    LockKeyhole,
    Plus,
    Stethoscope,
    Trash2,
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
import { formatClinicalDate } from './presentation';
import type {
    InpatientDischargeCodingProcedureAttestation,
    InpatientDischargeCodingSourceFields,
    InpatientDischargeCodingSourceProjection,
} from './types';

type EditableFields = Omit<
    InpatientDischargeCodingSourceFields,
    'procedure_attestation'
> & {
    procedure_attestation: InpatientDischargeCodingProcedureAttestation | '';
};

const emptyFields: EditableFields = {
    principal_diagnosis_statement: '',
    secondary_diagnosis_statements: [],
    procedure_attestation: '',
    performed_procedure_statements: [],
};

const maximumStatements = 20;
let idempotencyFallback = 0;

export function newDischargeCodingSourceIdempotencyKey(
    operation: 'draft' | 'final',
) {
    const randomPart =
        globalThis.crypto?.randomUUID?.() ??
        `${Date.now().toString(36)}-${(++idempotencyFallback).toString(36)}`;

    return `inpatient-discharge-coding-${operation}-${randomPart}`.toLowerCase();
}

function serverErrors(errors: Record<string, string | undefined>) {
    return Object.entries(errors).filter((entry): entry is [string, string] =>
        Boolean(entry[1]),
    );
}

function validationErrors(fields: EditableFields, requirePrincipal: boolean) {
    const errors: string[] = [];

    if (requirePrincipal && !fields.principal_diagnosis_statement.trim()) {
        errors.push('Diagnosis utama wajib diisi sebelum Final.');
    }

    if (!fields.procedure_attestation) {
        errors.push('Pilih pernyataan prosedur sebelum menyimpan.');
    }

    if (
        fields.secondary_diagnosis_statements.some(
            (statement) => !statement.trim(),
        )
    ) {
        errors.push(
            'Hapus atau isi setiap diagnosis sekunder yang ditambahkan.',
        );
    }

    if (
        fields.procedure_attestation === 'PROCEDURES_RECORDED' &&
        (fields.performed_procedure_statements.length === 0 ||
            fields.performed_procedure_statements.some(
                (statement) => !statement.trim(),
            ))
    ) {
        errors.push(
            'Isi setiap prosedur yang dilakukan, atau pilih tidak ada prosedur.',
        );
    }

    return errors;
}

type Props = {
    projection: InpatientDischargeCodingSourceProjection;
    onDirtyChange?: (dirty: boolean) => void;
};

export function InpatientDischargeCodingSourcePanel({
    projection,
    onDirtyChange,
}: Props) {
    const source = projection.source;
    const baseline = useMemo<EditableFields>(
        () => source?.fields ?? emptyFields,
        [source?.fields],
    );
    const [savedFields, setSavedFields] = useState(baseline);
    const [confirmationOpen, setConfirmationOpen] = useState(false);
    const [validationMode, setValidationMode] = useState<
        'draft' | 'final' | null
    >(null);
    const [serverErrorAttempt, setServerErrorAttempt] = useState(0);
    const errorSummaryRef = useRef<HTMLDivElement>(null);
    const projectionRevision = `${source?.public_id ?? 'new'}:${source?.version ?? 0}:${source?.state ?? 'NONE'}:${projection.definition_version}:${JSON.stringify(baseline)}`;
    const previousProjectionRevisionRef = useRef(projectionRevision);
    const draftForm = useForm({
        definition_version: projection.definition_version,
        expected_version: source?.version ?? 0,
        fields: baseline,
        idempotency_key: newDischargeCodingSourceIdempotencyKey('draft'),
    });
    const finalForm = useForm({
        definition_version: projection.definition_version,
        expected_version: source?.version ?? 0,
        idempotency_key: newDischargeCodingSourceIdempotencyKey('final'),
    });
    const isFinal = source?.state === 'FINAL';
    const canSave =
        !isFinal &&
        projection.permission.can_save_draft &&
        projection.actions.save_draft_url !== null;
    const canFinalize =
        source?.state === 'DRAFT' &&
        projection.permission.can_finalize &&
        projection.actions.finalize_url !== null;
    const dirty =
        JSON.stringify(draftForm.data.fields) !== JSON.stringify(savedFields);
    const errors = {
        ...(draftForm.errors as Record<string, string | undefined>),
        ...(finalForm.errors as Record<string, string | undefined>),
    };
    const domainErrors = serverErrors(errors);
    const displayedValidationErrors =
        validationMode !== null
            ? validationErrors(
                  validationMode === 'final'
                      ? (source?.fields ?? baseline)
                      : draftForm.data.fields,
                  validationMode === 'final',
              )
            : [];
    const processing = draftForm.processing || finalForm.processing;
    const readOnly = isFinal || !canSave || processing;
    const errorFingerprint = [
        ...domainErrors.map(([key, message]) => `${key}:${message}`),
        ...displayedValidationErrors,
    ].join('|');

    useEffect(() => {
        if (previousProjectionRevisionRef.current === projectionRevision) {
            return;
        }

        const preserveUnsavedFields = dirty;
        const synchronizedVersion = source?.version ?? 0;

        if (!preserveUnsavedFields) {
            startTransition(() => setSavedFields(baseline));
        }

        draftForm.setData((current) => ({
            ...current,
            definition_version: projection.definition_version,
            expected_version: synchronizedVersion,
            fields: preserveUnsavedFields ? current.fields : baseline,
            idempotency_key: newDischargeCodingSourceIdempotencyKey('draft'),
        }));
        finalForm.setData((current) => ({
            ...current,
            definition_version: projection.definition_version,
            expected_version: synchronizedVersion,
            idempotency_key: newDischargeCodingSourceIdempotencyKey('final'),
        }));
        previousProjectionRevisionRef.current = projectionRevision;
    }, [
        baseline,
        dirty,
        draftForm,
        finalForm,
        projection.definition_version,
        projectionRevision,
        source?.version,
    ]);

    useEffect(() => {
        onDirtyChange?.(dirty);
    }, [dirty, onDirtyChange]);

    useEffect(() => {
        if (
            (serverErrorAttempt > 0 || validationMode !== null) &&
            (domainErrors.length > 0 || displayedValidationErrors.length > 0)
        ) {
            errorSummaryRef.current?.focus();
        }
    }, [
        displayedValidationErrors.length,
        domainErrors.length,
        errorFingerprint,
        serverErrorAttempt,
        validationMode,
    ]);

    const updateFields = (
        updater: (fields: EditableFields) => EditableFields,
    ) => {
        if (readOnly) {
            return;
        }

        draftForm.setData((current) => ({
            ...current,
            fields: updater(current.fields),
            idempotency_key: newDischargeCodingSourceIdempotencyKey('draft'),
        }));
        setValidationMode(null);
    };

    const saveDraft = (event: FormEvent) => {
        event.preventDefault();

        if (!canSave || !projection.actions.save_draft_url || processing) {
            return;
        }

        const clientErrors = validationErrors(draftForm.data.fields, false);

        if (clientErrors.length > 0) {
            setValidationMode('draft');

            return;
        }

        const submittedFields = {
            ...draftForm.data.fields,
            secondary_diagnosis_statements: [
                ...draftForm.data.fields.secondary_diagnosis_statements,
            ],
            performed_procedure_statements: [
                ...draftForm.data.fields.performed_procedure_statements,
            ],
        };

        draftForm.post(projection.actions.save_draft_url, {
            preserveScroll: true,
            onError: () => setServerErrorAttempt((attempt) => attempt + 1),
            onSuccess: () => {
                setServerErrorAttempt(0);
                setSavedFields(submittedFields);
                draftForm.setData((current) => ({
                    ...current,
                    idempotency_key:
                        newDischargeCodingSourceIdempotencyKey('draft'),
                }));
            },
        });
    };

    const requestFinalization = () => {
        if (!canFinalize || dirty || processing) {
            return;
        }

        const clientErrors = validationErrors(source?.fields ?? baseline, true);

        if (clientErrors.length > 0) {
            setValidationMode('final');

            return;
        }

        setConfirmationOpen(true);
    };

    const finalize = () => {
        if (
            !canFinalize ||
            !projection.actions.finalize_url ||
            dirty ||
            processing
        ) {
            return;
        }

        finalForm.post(projection.actions.finalize_url, {
            preserveScroll: true,
            onError: () => {
                setConfirmationOpen(false);
                setServerErrorAttempt((attempt) => attempt + 1);
            },
            onSuccess: () => {
                setConfirmationOpen(false);
                setServerErrorAttempt(0);
                finalForm.setData((current) => ({
                    ...current,
                    idempotency_key:
                        newDischargeCodingSourceIdempotencyKey('final'),
                }));
            },
        });
    };

    const addSecondary = () =>
        updateFields((fields) => ({
            ...fields,
            secondary_diagnosis_statements: [
                ...fields.secondary_diagnosis_statements,
                '',
            ],
        }));
    const addProcedure = () =>
        updateFields((fields) => ({
            ...fields,
            performed_procedure_statements: [
                ...fields.performed_procedure_statements,
                '',
            ],
        }));

    return (
        <section
            aria-labelledby="discharge-coding-source-title"
            className="border-t border-primary/20 bg-card"
        >
            <div className="border-b border-border bg-muted/25 px-4 py-4 md:px-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-3">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Stethoscope
                                aria-hidden="true"
                                className="size-5"
                            />
                        </span>
                        <div>
                            <p className="text-[0.68rem] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                                Sumber klinis untuk telaah rekam medis
                            </p>
                            <h3
                                id="discharge-coding-source-title"
                                className="mt-0.5 text-base font-semibold"
                            >
                                Diagnosis dan prosedur akhir
                            </h3>
                        </div>
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
                        {source
                            ? `${isFinal ? 'Final' : 'Draf'} · v${source.version}`
                            : 'Belum dibuat'}
                    </span>
                </div>
                <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                    Dokter menuliskan diagnosis dan prosedur dalam bahasa
                    klinis. Kode diagnosis dan prosedur ditetapkan pada telaah
                    rekam medis.
                </p>
            </div>

            <form
                onSubmit={saveDraft}
                className="space-y-5 p-4 md:p-5"
                noValidate
            >
                {domainErrors.length > 0 ||
                displayedValidationErrors.length > 0 ? (
                    <div
                        ref={errorSummaryRef}
                        role="alert"
                        tabIndex={-1}
                        className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive outline-none focus-visible:ring-2 focus-visible:ring-destructive"
                    >
                        <p className="font-semibold">
                            Diagnosis dan prosedur akhir belum dapat disimpan.
                        </p>
                        <ul className="mt-1 list-disc space-y-1 pl-5 text-xs">
                            {displayedValidationErrors.map((message) => (
                                <li key={message}>{message}</li>
                            ))}
                            {domainErrors.map(([key, message]) => (
                                <li key={`${key}-${message}`}>{message}</li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                <div className="space-y-1.5">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <Label htmlFor="principal-diagnosis-statement">
                            Diagnosis utama
                        </Label>
                        <span className="text-[0.68rem] text-muted-foreground">
                            Wajib untuk Final
                        </span>
                    </div>
                    <textarea
                        id="principal-diagnosis-statement"
                        value={
                            draftForm.data.fields.principal_diagnosis_statement
                        }
                        onChange={(event) =>
                            updateFields((fields) => ({
                                ...fields,
                                principal_diagnosis_statement:
                                    event.target.value,
                            }))
                        }
                        readOnly={readOnly}
                        rows={3}
                        maxLength={2_000}
                        aria-describedby="principal-diagnosis-help"
                        className="w-full resize-y rounded-lg border border-input bg-background px-3 py-2 text-sm leading-6 shadow-xs outline-none read-only:cursor-default read-only:bg-muted/50 focus:border-ring focus:ring-3 focus:ring-ring/20"
                    />
                    <p
                        id="principal-diagnosis-help"
                        className="text-xs text-muted-foreground"
                    >
                        Kondisi utama yang paling bertanggung jawab atas episode
                        perawatan ini.
                    </p>
                </div>

                <fieldset className="space-y-3">
                    <legend className="text-sm font-medium">
                        Diagnosis sekunder
                    </legend>
                    <div className="flex justify-end">
                        {!readOnly ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={addSecondary}
                                disabled={
                                    draftForm.data.fields
                                        .secondary_diagnosis_statements
                                        .length >= maximumStatements
                                }
                            >
                                <Plus aria-hidden="true" className="size-4" />
                                Tambah diagnosis sekunder
                            </Button>
                        ) : null}
                    </div>
                    {draftForm.data.fields.secondary_diagnosis_statements
                        .length === 0 ? (
                        <p className="text-xs text-muted-foreground">
                            Tidak ada diagnosis sekunder yang dicatat.
                        </p>
                    ) : (
                        <div className="space-y-2">
                            {draftForm.data.fields.secondary_diagnosis_statements.map(
                                (statement, index) => (
                                    <div
                                        key={`secondary-${index}`}
                                        className="flex items-start gap-2"
                                    >
                                        <Label
                                            htmlFor={`secondary-diagnosis-${index}`}
                                            className="sr-only"
                                        >
                                            Diagnosis sekunder {index + 1}
                                        </Label>
                                        <input
                                            id={`secondary-diagnosis-${index}`}
                                            value={statement}
                                            onChange={(event) =>
                                                updateFields((fields) => ({
                                                    ...fields,
                                                    secondary_diagnosis_statements:
                                                        fields.secondary_diagnosis_statements.map(
                                                            (
                                                                value,
                                                                itemIndex,
                                                            ) =>
                                                                itemIndex ===
                                                                index
                                                                    ? event
                                                                          .target
                                                                          .value
                                                                    : value,
                                                        ),
                                                }))
                                            }
                                            readOnly={readOnly}
                                            maxLength={2_000}
                                            className="min-h-11 min-w-0 flex-1 rounded-lg border border-input bg-background px-3 py-2 text-sm shadow-xs outline-none read-only:bg-muted/50 focus:border-ring focus:ring-3 focus:ring-ring/20"
                                        />
                                        {!readOnly ? (
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                aria-label={`Hapus diagnosis sekunder ${index + 1}`}
                                                onClick={() =>
                                                    updateFields((fields) => ({
                                                        ...fields,
                                                        secondary_diagnosis_statements:
                                                            fields.secondary_diagnosis_statements.filter(
                                                                (
                                                                    _,
                                                                    itemIndex,
                                                                ) =>
                                                                    itemIndex !==
                                                                    index,
                                                            ),
                                                    }))
                                                }
                                            >
                                                <Trash2
                                                    aria-hidden="true"
                                                    className="size-4"
                                                />
                                            </Button>
                                        ) : null}
                                    </div>
                                ),
                            )}
                        </div>
                    )}
                </fieldset>

                <fieldset className="space-y-3">
                    <legend className="text-sm font-medium">
                        Pernyataan prosedur
                    </legend>
                    <p className="text-xs text-muted-foreground">
                        Pilih satu pernyataan untuk setiap draf.
                    </p>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {[
                            {
                                value: 'NO_PROCEDURE_RECORDED' as const,
                                label: 'Tidak ada prosedur yang dilakukan',
                            },
                            {
                                value: 'PROCEDURES_RECORDED' as const,
                                label: 'Ada prosedur yang dilakukan',
                            },
                        ].map((option) => (
                            <label
                                key={option.value}
                                className={cn(
                                    'flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm',
                                    draftForm.data.fields
                                        .procedure_attestation === option.value
                                        ? 'border-primary bg-primary/5'
                                        : 'border-input bg-background',
                                    readOnly && 'cursor-default bg-muted/50',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="procedure-attestation"
                                    value={option.value}
                                    checked={
                                        draftForm.data.fields
                                            .procedure_attestation ===
                                        option.value
                                    }
                                    disabled={readOnly}
                                    onChange={() =>
                                        updateFields((fields) => ({
                                            ...fields,
                                            procedure_attestation: option.value,
                                            performed_procedure_statements:
                                                option.value ===
                                                'PROCEDURES_RECORDED'
                                                    ? fields
                                                          .performed_procedure_statements
                                                          .length > 0
                                                        ? fields.performed_procedure_statements
                                                        : ['']
                                                    : [],
                                        }))
                                    }
                                    className="size-4 accent-primary"
                                />
                                <span>{option.label}</span>
                            </label>
                        ))}
                    </div>
                </fieldset>

                {draftForm.data.fields.procedure_attestation ===
                'PROCEDURES_RECORDED' ? (
                    <fieldset className="space-y-3">
                        <legend className="text-sm font-medium">
                            Prosedur yang dilakukan
                        </legend>
                        <div className="flex justify-end">
                            {!readOnly ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={addProcedure}
                                    disabled={
                                        draftForm.data.fields
                                            .performed_procedure_statements
                                            .length >= maximumStatements
                                    }
                                >
                                    <Plus
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    Tambah prosedur
                                </Button>
                            ) : null}
                        </div>
                        <div className="space-y-2">
                            {draftForm.data.fields.performed_procedure_statements.map(
                                (statement, index) => (
                                    <div
                                        key={`procedure-${index}`}
                                        className="flex items-start gap-2"
                                    >
                                        <Label
                                            htmlFor={`performed-procedure-${index}`}
                                            className="sr-only"
                                        >
                                            Prosedur yang dilakukan {index + 1}
                                        </Label>
                                        <input
                                            id={`performed-procedure-${index}`}
                                            value={statement}
                                            onChange={(event) =>
                                                updateFields((fields) => ({
                                                    ...fields,
                                                    performed_procedure_statements:
                                                        fields.performed_procedure_statements.map(
                                                            (
                                                                value,
                                                                itemIndex,
                                                            ) =>
                                                                itemIndex ===
                                                                index
                                                                    ? event
                                                                          .target
                                                                          .value
                                                                    : value,
                                                        ),
                                                }))
                                            }
                                            readOnly={readOnly}
                                            maxLength={2_000}
                                            className="min-h-11 min-w-0 flex-1 rounded-lg border border-input bg-background px-3 py-2 text-sm shadow-xs outline-none read-only:bg-muted/50 focus:border-ring focus:ring-3 focus:ring-ring/20"
                                        />
                                        {!readOnly ? (
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                aria-label={`Hapus prosedur ${index + 1}`}
                                                onClick={() =>
                                                    updateFields((fields) => ({
                                                        ...fields,
                                                        performed_procedure_statements:
                                                            fields.performed_procedure_statements.filter(
                                                                (
                                                                    _,
                                                                    itemIndex,
                                                                ) =>
                                                                    itemIndex !==
                                                                    index,
                                                            ),
                                                    }))
                                                }
                                            >
                                                <Trash2
                                                    aria-hidden="true"
                                                    className="size-4"
                                                />
                                            </Button>
                                        ) : null}
                                    </div>
                                ),
                            )}
                        </div>
                    </fieldset>
                ) : null}

                {source?.assigned_physician.name ? (
                    <p className="text-xs text-muted-foreground">
                        Dokter penanggung jawab dokumen:{' '}
                        <span className="font-semibold text-foreground">
                            {source.assigned_physician.name}
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
                        <LockKeyhole aria-hidden="true" className="size-3.5" />
                        Diagnosis dan prosedur akhir sudah Final dan hanya dapat
                        dibaca.
                    </p>
                ) : null}
                {!isFinal && !canSave && !canFinalize ? (
                    <p className="text-xs text-muted-foreground">
                        Tidak ada tindakan yang tersedia untuk akun ini.
                    </p>
                ) : null}

                {canSave || canFinalize ? (
                    <div className="flex flex-col-reverse gap-2 border-t border-border pt-4 sm:flex-row sm:justify-end">
                        {canSave ? (
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                            >
                                {draftForm.processing
                                    ? 'Menyimpan…'
                                    : 'Simpan draf diagnosis dan prosedur'}
                            </Button>
                        ) : null}
                        {canFinalize ? (
                            <Button
                                type="button"
                                onClick={requestFinalization}
                                disabled={dirty || processing}
                            >
                                {finalForm.processing
                                    ? 'Menjadikan Final…'
                                    : 'Jadikan diagnosis dan prosedur Final'}
                            </Button>
                        ) : null}
                    </div>
                ) : null}
            </form>

            {projection.versions.length > 0 ? (
                <div className="border-t border-border px-4 py-4 md:px-5">
                    <div className="flex items-center gap-2">
                        <History
                            aria-hidden="true"
                            className="size-4 text-primary"
                        />
                        <h4 className="text-sm font-semibold">
                            Riwayat versi diagnosis dan prosedur
                        </h4>
                    </div>
                    <ol className="mt-3 space-y-2">
                        {projection.versions.map((version) => (
                            <li
                                key={version.public_id}
                                className="rounded-lg border border-border bg-muted/20 p-3"
                            >
                                <details>
                                    <summary className="cursor-pointer text-sm font-semibold">
                                        Versi {version.version} ·{' '}
                                        {version.state === 'FINAL'
                                            ? 'Final'
                                            : 'Draf'}
                                    </summary>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {version.actor_name ??
                                            'Aktor tidak tersedia'}{' '}
                                        ·{' '}
                                        {formatClinicalDate(version.created_at)}
                                    </p>
                                    <dl className="mt-3 space-y-2 text-sm">
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Diagnosis utama
                                            </dt>
                                            <dd>
                                                {version.fields
                                                    .principal_diagnosis_statement ||
                                                    '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Diagnosis sekunder
                                            </dt>
                                            <dd>
                                                {version.fields.secondary_diagnosis_statements.join(
                                                    '; ',
                                                ) || 'Tidak ada'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Prosedur
                                            </dt>
                                            <dd>
                                                {version.fields
                                                    .procedure_attestation ===
                                                'NO_PROCEDURE_RECORDED'
                                                    ? 'Tidak ada prosedur yang dilakukan'
                                                    : version.fields.performed_procedure_statements.join(
                                                          '; ',
                                                      )}
                                            </dd>
                                        </div>
                                    </dl>
                                </details>
                            </li>
                        ))}
                    </ol>
                </div>
            ) : null}

            <Dialog
                open={confirmationOpen}
                onOpenChange={(open) => {
                    if (!processing) {
                        setConfirmationOpen(open);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Jadikan diagnosis dan prosedur akhir Final?
                        </DialogTitle>
                        <DialogDescription>
                            Versi draf tersimpan akan dikunci dan menjadi sumber
                            klinis tetap untuk telaah rekam medis. Pastikan
                            isinya sudah lengkap dan benar.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={processing}
                            >
                                Periksa kembali
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            onClick={finalize}
                            disabled={processing}
                        >
                            {finalForm.processing
                                ? 'Menjadikan Final…'
                                : 'Ya, jadikan Final'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
