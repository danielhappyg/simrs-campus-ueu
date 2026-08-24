import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { DocumentErrorSummary } from './document-error-summary';
import type {
    ClinicalDocument,
    ClinicalDocumentType,
    DocumentActions,
    DocumentPermission,
} from './types';

type FieldDefinition = {
    key: string;
    label: string;
    requiredForFinal?: boolean;
};

const definitions: Record<ClinicalDocumentType, FieldDefinition[]> = {
    NURSING_ASSESSMENT: [
        {
            key: 'nursing_assessment',
            label: 'Asesmen keperawatan',
            requiredForFinal: true,
        },
        { key: 'additional_notes', label: 'Catatan tambahan (opsional)' },
    ],
    MEDICAL_ASSESSMENT: [
        { key: 'anamnesis', label: 'Anamnesis', requiredForFinal: true },
        {
            key: 'objective_examination',
            label: 'Pemeriksaan objektif',
            requiredForFinal: true,
        },
        {
            key: 'clinical_assessment',
            label: 'Asesmen klinis',
            requiredForFinal: true,
        },
        {
            key: 'care_plan',
            label: 'Rencana pelayanan',
            requiredForFinal: true,
        },
        { key: 'additional_notes', label: 'Catatan tambahan (opsional)' },
    ],
};

export function StructuredDocumentForm({
    type,
    definitionVersion,
    draft,
    permission,
    actions,
    encounterClosed,
    onDirtyChange,
}: {
    type: ClinicalDocumentType;
    definitionVersion: string;
    draft?: ClinicalDocument;
    permission: DocumentPermission;
    actions: DocumentActions;
    encounterClosed: boolean;
    onDirtyChange?: (type: ClinicalDocumentType, isDirty: boolean) => void;
}) {
    const fields = definitions[type];
    const form = useForm({
        definition_version: definitionVersion,
        expected_version: draft?.version ?? 0,
        fields: Object.fromEntries(
            fields.map((field) => [field.key, draft?.fields[field.key] ?? '']),
        ) as Record<string, string>,
    });
    const finalizeForm = useForm({ expected_version: draft?.version ?? 0 });
    const readOnly = encounterClosed || draft?.document_state === 'FINAL';
    const hasUnsavedChanges = form.isDirty;
    const finalizeHelpId = `${type}-finalize-help`;

    const saveDraft = (event: FormEvent) => {
        event.preventDefault();
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

    return (
        <section className="rounded-lg border border-border bg-card p-3 md:p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 className="text-sm font-semibold text-secondary-foreground">
                        {type === 'NURSING_ASSESSMENT'
                            ? 'Dokumentasi keperawatan'
                            : 'Dokumentasi medis'}
                    </h2>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Simpan draf dapat dilakukan bertahap. Finalisasi adalah
                        tindakan terpisah dan membuat versi ini hanya-baca.
                    </p>
                </div>
                <span className="rounded-md bg-muted px-2 py-1 font-mono text-xs text-muted-foreground">
                    {draft
                        ? `v${draft.version} · ${draft.document_state === 'FINAL' ? 'Final' : 'Draf'}`
                        : 'Belum ada draf'}
                </span>
            </div>

            <form onSubmit={saveDraft} className="mt-4 space-y-4">
                <DocumentErrorSummary errors={errors} />
                {fields.map((field) => {
                    const error = form.errors[`fields.${field.key}`];
                    const errorId = `${type}-${field.key}-error`;

                    return (
                        <div key={field.key} className="grid gap-1.5">
                            <Label htmlFor={`${type}-${field.key}`}>
                                {field.label}
                                {field.requiredForFinal ? (
                                    <span className="ml-1 text-xs text-muted-foreground">
                                        · wajib saat finalisasi
                                    </span>
                                ) : null}
                            </Label>
                            <textarea
                                id={`${type}-${field.key}`}
                                value={form.data.fields[field.key]}
                                onChange={(event) =>
                                    form.setData('fields', {
                                        ...form.data.fields,
                                        [field.key]: event.target.value,
                                    })
                                }
                                readOnly={
                                    readOnly || !permission.can_save_draft
                                }
                                aria-invalid={Boolean(error)}
                                aria-describedby={error ? errorId : undefined}
                                className="min-h-28 rounded-md border border-input bg-background px-3 py-2 text-sm read-only:bg-muted/60 focus-visible:border-ring disabled:opacity-60"
                            />
                            {error ? (
                                <p
                                    id={errorId}
                                    className="text-xs text-destructive"
                                >
                                    {error}
                                </p>
                            ) : null}
                        </div>
                    );
                })}
                {!readOnly &&
                (permission.can_save_draft || permission.can_finalize) ? (
                    <>
                        <div className="flex flex-col-reverse gap-2 border-t border-border pt-3 sm:flex-row sm:justify-end">
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={
                                    !permission.can_save_draft ||
                                    form.processing
                                }
                                title={
                                    permission.can_save_draft
                                        ? undefined
                                        : 'Akun ini tidak memiliki hak menyimpan draf ini.'
                                }
                            >
                                Simpan draf
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
                                        ? 'Simpan draf sebelum finalisasi.'
                                        : hasUnsavedChanges
                                          ? 'Simpan perubahan draf sebelum finalisasi.'
                                          : !permission.can_finalize
                                            ? 'Akun ini tidak memiliki hak finalisasi.'
                                            : undefined
                                }
                                className="bg-primary text-primary-foreground hover:bg-primary/90"
                            >
                                Finalisasi versi
                            </Button>
                        </div>
                        {!draft || hasUnsavedChanges ? (
                            <p
                                id={finalizeHelpId}
                                className="text-xs text-muted-foreground sm:text-right"
                            >
                                {hasUnsavedChanges
                                    ? 'Ada perubahan yang belum disimpan. Simpan draf sebelum finalisasi.'
                                    : 'Simpan draf terlebih dahulu sebelum finalisasi.'}
                            </p>
                        ) : null}
                    </>
                ) : (
                    <p className="border-t border-border pt-3 text-sm text-muted-foreground">
                        {encounterClosed
                            ? 'Kunjungan sudah ditutup. Dokumentasi hanya dapat dibaca.'
                            : draft?.document_state === 'FINAL'
                              ? 'Versi final hanya dapat dibaca.'
                              : 'Akun ini hanya memiliki akses baca.'}
                    </p>
                )}
            </form>
        </section>
    );
}
