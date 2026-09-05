import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DiagnosisTable } from './diagnosis-table';
import { DocumentErrorSummary } from './document-error-summary';
import type {
    ClinicalDocument,
    ClinicalDocumentFieldValue,
    ClinicalDocumentType,
    DocumentActions,
    DocumentPermission,
} from './types';

type TerminologyOption = {
    code: string;
    display: string;
    system: 'ICD-10' | 'ICD-9-CM';
};

type FieldDefinition = {
    key: string;
    label: string;
    requiredForFinal?: boolean;
};

const definitions: Record<ClinicalDocumentType, FieldDefinition[]> = {
    NURSING_ASSESSMENT: [
        {
            key: 'nursing_assessment',
            label: 'Nursing assessment',
            requiredForFinal: true,
        },
        { key: 'additional_notes', label: 'Additional notes (optional)' },
    ],
    MEDICAL_ASSESSMENT: [
        {
            key: 'anamnesis',
            label: 'Subjective',
            requiredForFinal: true,
        },
        {
            key: 'objective_examination',
            label: 'Objective',
            requiredForFinal: true,
        },
        {
            key: 'clinical_assessment',
            label: 'Assessment',
            requiredForFinal: true,
        },
        {
            key: 'care_plan',
            label: 'Plan',
            requiredForFinal: true,
        },
        { key: 'additional_notes', label: 'Additional notes (optional)' },
    ],
};

function textValue(value: ClinicalDocumentFieldValue | undefined): string {
    return typeof value === 'string' ? value : '';
}

function selectionValue(value: ClinicalDocumentFieldValue | undefined) {
    return value && typeof value === 'object' && !Array.isArray(value)
        ? value
        : null;
}

function selectionsValue(value: ClinicalDocumentFieldValue | undefined) {
    return Array.isArray(value) ? value : [];
}

function TerminologyPicker({
    id,
    label,
    system,
    selected,
    multiple = false,
    readOnly,
    lookupUrl,
    onSelect,
    onRemove,
}: {
    id: string;
    label: string;
    system: TerminologyOption['system'];
    selected: TerminologyOption[];
    multiple?: boolean;
    readOnly: boolean;
    lookupUrl?: string;
    onSelect: (option: TerminologyOption) => void;
    onRemove: (code: string) => void;
}) {
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState<TerminologyOption[]>([]);
    const [message, setMessage] = useState(
        'Type at least 2 characters to search the official code catalogue.',
    );
    const canSearch = query.trim().length >= 2;
    const visibleOptions = canSearch ? options : [];
    const helpMessage = canSearch
        ? message
        : 'Type at least 2 characters to search the official code catalogue.';

    useEffect(() => {
        if (query.trim().length < 2) {
            return;
        }

        if (!lookupUrl) {
            queueMicrotask(() => {
                setOptions([]);
                setMessage(
                    'The code catalogue is unavailable for this encounter.',
                );
            });

            return;
        }

        const controller = new AbortController();
        const timeout = window.setTimeout(() => {
            setMessage('Searching the official catalogue…');
            fetch(
                `${lookupUrl}?system=${encodeURIComponent(system)}&q=${encodeURIComponent(query.trim())}`,
                { signal: controller.signal },
            )
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error('lookup_failed');
                    }

                    return response.json() as Promise<{
                        options?: TerminologyOption[];
                        source?: { authority?: string; dataset?: string };
                    }>;
                })
                .then((result) => {
                    setOptions(result.options ?? []);
                    setMessage(
                        result.options?.length
                            ? `Results from ${result.source?.authority ?? 'the official catalogue'}${result.source?.dataset ? ` · ${result.source.dataset}` : ''}.`
                            : 'No codes found. Try a different keyword or code.',
                    );
                })
                .catch((error: unknown) => {
                    if ((error as { name?: string }).name !== 'AbortError') {
                        setOptions([]);
                        setMessage(
                            'The code catalogue could not be loaded. Try again.',
                        );
                    }
                });
        }, 300);

        return () => {
            window.clearTimeout(timeout);
            controller.abort();
        };
    }, [lookupUrl, query, system]);

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                value={query}
                onChange={(event) => {
                    setQuery(event.target.value);
                    setOptions([]);
                    setMessage('Waiting for a search term…');
                }}
                disabled={readOnly}
                placeholder={
                    system === 'ICD-10'
                        ? 'Search an ICD-10 code or diagnosis'
                        : 'Search an ICD-9-CM code or procedure'
                }
                aria-describedby={`${id}-help`}
            />
            <p
                id={`${id}-help`}
                role="status"
                aria-live="polite"
                className="text-xs text-muted-foreground"
            >
                {helpMessage}
            </p>
            {visibleOptions.length ? (
                <ul
                    className="max-h-44 overflow-y-auto rounded-md border border-border bg-background"
                    aria-label={`Results for ${label}`}
                >
                    {visibleOptions.map((option) => (
                        <li key={`${option.system}-${option.code}`}>
                            <button
                                type="button"
                                disabled={
                                    readOnly ||
                                    (!multiple && selected.length > 0)
                                }
                                onClick={() => {
                                    onSelect(option);
                                    setQuery('');
                                    setOptions([]);
                                }}
                                className="flex w-full items-start gap-2 px-3 py-2 text-left text-sm hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <span className="shrink-0 font-mono text-xs font-semibold">
                                    {option.code}
                                </span>
                                <span>{option.display}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
            {selected.length ? (
                <ul
                    className="flex flex-wrap gap-1.5"
                    aria-label={`Selected ${label}`}
                >
                    {selected.map((item) => (
                        <li
                            key={item.code}
                            className="inline-flex max-w-full items-center gap-1 rounded-md bg-muted px-2 py-1 text-xs"
                        >
                            <span className="font-mono font-semibold">
                                {item.code}
                            </span>
                            <span className="break-words">{item.display}</span>
                            {!readOnly ? (
                                <button
                                    type="button"
                                    onClick={() => onRemove(item.code)}
                                    className="ml-1 rounded px-1 text-muted-foreground hover:bg-background hover:text-foreground"
                                    aria-label={`Remove ${item.code}`}
                                >
                                    ×
                                </button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}

export function StructuredDocumentForm({
    type,
    definitionVersion,
    draft,
    permission,
    actions,
    encounterClosed,
    terminologyLookupUrl,
    onDirtyChange,
}: {
    type: ClinicalDocumentType;
    definitionVersion: string;
    draft?: ClinicalDocument;
    permission: DocumentPermission;
    actions: DocumentActions;
    encounterClosed: boolean;
    terminologyLookupUrl?: string;
    onDirtyChange?: (type: ClinicalDocumentType, isDirty: boolean) => void;
}) {
    const fields = definitions[type];
    const form = useForm({
        definition_version: definitionVersion,
        expected_version: draft?.version ?? 0,
        fields: {
            ...Object.fromEntries(
                fields.map((field) => [
                    field.key,
                    textValue(draft?.fields[field.key]),
                ]),
            ),
            ...(type === 'MEDICAL_ASSESSMENT'
                ? {
                      diagnosis_text: textValue(draft?.fields.diagnosis_text),
                      primary_icd10: selectionValue(
                          draft?.fields.primary_icd10,
                      ),
                      secondary_icd10: selectionsValue(
                          draft?.fields.secondary_icd10,
                      ),
                      procedures_icd9cm: selectionsValue(
                          draft?.fields.procedures_icd9cm,
                      ),
                  }
                : {}),
        } as Record<string, ClinicalDocumentFieldValue>,
    });
    const finalizeForm = useForm({ expected_version: draft?.version ?? 0 });
    const readOnly = encounterClosed || draft?.document_state === 'FINAL';
    const [hasPendingDiagnosis, setHasPendingDiagnosis] = useState(false);
    const hasUnsavedChanges = form.isDirty || hasPendingDiagnosis;
    const finalizeHelpId = `${type}-finalize-help`;

    const saveDraft = (event: FormEvent) => {
        event.preventDefault();

        if (hasPendingDiagnosis) {
            return;
        }

        form.post(actions.save_draft_url, { preserveScroll: true });
    };

    const finalize = () => {
        finalizeForm.setData('expected_version', draft?.version ?? 0);
        finalizeForm.post(actions.finalize_url, { preserveScroll: true });
    };

    const errors = { ...form.errors, ...finalizeForm.errors } as Record<
        string,
        string | undefined
    >;

    useEffect(() => {
        onDirtyChange?.(type, hasUnsavedChanges);

        return () => onDirtyChange?.(type, false);
    }, [hasUnsavedChanges, onDirtyChange, type]);

    const renderTextField = (field: FieldDefinition) => {
        const error = form.errors[`fields.${field.key}`];
        const errorId = `${type}-${field.key}-error`;

        return (
            <div key={field.key} className="grid gap-1.5">
                <Label htmlFor={`${type}-${field.key}`}>
                    {field.label}
                    {field.requiredForFinal ? (
                        <span className="ml-1 text-xs text-muted-foreground">
                            · required to finalize
                        </span>
                    ) : null}
                </Label>
                <textarea
                    id={`${type}-${field.key}`}
                    value={textValue(form.data.fields[field.key])}
                    onChange={(event) =>
                        form.setData('fields', {
                            ...form.data.fields,
                            [field.key]: event.target.value,
                        })
                    }
                    readOnly={readOnly || !permission.can_save_draft}
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? errorId : undefined}
                    className="min-h-28 rounded-md border border-input bg-background px-3 py-2 text-sm read-only:bg-muted/60 focus-visible:border-ring disabled:opacity-60"
                />
                {error ? (
                    <p id={errorId} className="text-xs text-destructive">
                        {error}
                    </p>
                ) : null}
            </div>
        );
    };

    const diagnosisCoding = (
        <div className="grid gap-4 border-t border-border pt-4">
            <div>
                <Label htmlFor="MEDICAL_ASSESSMENT-diagnosis-text">
                    Clinical diagnosis{' '}
                    <span className="ml-1 text-xs text-muted-foreground">
                        · required to finalize
                    </span>
                </Label>
                <textarea
                    id="MEDICAL_ASSESSMENT-diagnosis-text"
                    value={textValue(form.data.fields.diagnosis_text)}
                    onChange={(event) =>
                        form.setData('fields', {
                            ...form.data.fields,
                            diagnosis_text: event.target.value,
                        })
                    }
                    readOnly={readOnly || !permission.can_save_draft}
                    className="mt-1.5 min-h-24 w-full rounded-md border border-input bg-background px-3 py-2 text-sm read-only:bg-muted/60 focus-visible:border-ring"
                />
            </div>
            <DiagnosisTable
                primary={
                    selectionValue(form.data.fields.primary_icd10) as {
                        code: string;
                        display: string;
                    } | null
                }
                secondary={
                    selectionsValue(form.data.fields.secondary_icd10) as {
                        code: string;
                        display: string;
                    }[]
                }
                lookupUrl={terminologyLookupUrl}
                disabled={
                    readOnly ||
                    !permission.can_save_draft ||
                    form.processing ||
                    finalizeForm.processing
                }
                onPendingChange={setHasPendingDiagnosis}
                onChange={({ primary, secondary }) =>
                    form.setData('fields', {
                        ...form.data.fields,
                        primary_icd10: primary,
                        secondary_icd10: secondary,
                    })
                }
            />
        </div>
    );

    const procedureCoding = (
        <div className="border-t border-border pt-4">
            <TerminologyPicker
                id="MEDICAL_ASSESSMENT-procedures-icd9cm"
                label="Procedures (ICD-9-CM)"
                system="ICD-9-CM"
                multiple
                selected={selectionsValue(
                    form.data.fields.procedures_icd9cm,
                ).map((item) => ({ ...item, system: 'ICD-9-CM' as const }))}
                readOnly={readOnly || !permission.can_save_draft}
                lookupUrl={terminologyLookupUrl}
                onSelect={(option) =>
                    form.setData('fields', {
                        ...form.data.fields,
                        procedures_icd9cm: [
                            ...selectionsValue(
                                form.data.fields.procedures_icd9cm,
                            ),
                            { code: option.code, display: option.display },
                        ],
                    })
                }
                onRemove={(code) =>
                    form.setData('fields', {
                        ...form.data.fields,
                        procedures_icd9cm: selectionsValue(
                            form.data.fields.procedures_icd9cm,
                        ).filter((item) => item.code !== code),
                    })
                }
            />
        </div>
    );

    return (
        <section className="rounded-lg border border-border bg-card p-3 md:p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 className="text-sm font-semibold text-secondary-foreground">
                        {type === 'NURSING_ASSESSMENT'
                            ? 'Nursing documentation'
                            : 'Medical documentation'}
                    </h2>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Save drafts as you work. Finalizing is a separate action
                        that makes this version read-only.
                    </p>
                </div>
                <span className="rounded-md bg-muted px-2 py-1 font-mono text-xs text-muted-foreground">
                    {draft
                        ? `v${draft.version} · ${draft.document_state === 'FINAL' ? 'Final' : 'Draft'}`
                        : 'No draft yet'}
                </span>
            </div>

            <form onSubmit={saveDraft} className="mt-4 flex flex-col gap-4">
                <DocumentErrorSummary errors={errors} />
                {type === 'NURSING_ASSESSMENT' ? (
                    fields.map(renderTextField)
                ) : (
                    <>
                        {renderTextField(fields[0])}
                        {renderTextField(fields[1])}
                        {renderTextField(fields[2])}
                        {diagnosisCoding}
                        {renderTextField(fields[3])}
                        {procedureCoding}
                        {renderTextField(fields[4])}
                    </>
                )}
                {!readOnly &&
                (permission.can_save_draft || permission.can_finalize) ? (
                    <>
                        <div className="flex flex-col-reverse gap-2 border-t border-border pt-3 sm:flex-row sm:justify-end">
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={
                                    !permission.can_save_draft ||
                                    form.processing ||
                                    hasPendingDiagnosis
                                }
                                title={
                                    permission.can_save_draft
                                        ? hasPendingDiagnosis
                                            ? 'Add, update, or cancel the diagnosis currently being composed before saving.'
                                            : undefined
                                        : 'You do not have permission to save this draft.'
                                }
                            >
                                Save draft
                            </Button>
                            <Button
                                type="button"
                                onClick={finalize}
                                aria-describedby={finalizeHelpId}
                                disabled={
                                    !draft ||
                                    hasUnsavedChanges ||
                                    !permission.can_finalize ||
                                    finalizeForm.processing
                                }
                                title={
                                    !draft
                                        ? 'Save a draft before finalizing.'
                                        : hasUnsavedChanges
                                          ? 'Save draft changes before finalizing.'
                                          : !permission.can_finalize
                                            ? 'You do not have permission to finalize this document.'
                                            : undefined
                                }
                                className="bg-primary text-primary-foreground hover:bg-primary/90"
                            >
                                Finalize version
                            </Button>
                        </div>
                        {!draft || hasUnsavedChanges ? (
                            <p
                                id={finalizeHelpId}
                                className="text-xs text-muted-foreground sm:text-right"
                            >
                                {hasUnsavedChanges
                                    ? hasPendingDiagnosis
                                        ? 'Add, update, or cancel the diagnosis currently being composed before saving or finalizing.'
                                        : 'There are unsaved changes. Save the draft before finalizing.'
                                    : 'Save a draft before finalizing.'}
                            </p>
                        ) : null}
                    </>
                ) : (
                    <p className="border-t border-border pt-3 text-sm text-muted-foreground">
                        {encounterClosed
                            ? 'This encounter is closed. Documentation is read-only.'
                            : draft?.document_state === 'FINAL'
                              ? 'Final versions are read-only.'
                              : 'You have read-only access.'}
                    </p>
                )}
            </form>
        </section>
    );
}
