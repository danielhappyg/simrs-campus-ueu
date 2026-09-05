import { useForm } from '@inertiajs/react';
import { CheckCircle2, FileClock, FileText, LockKeyhole } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { DocumentErrorSummary } from '../outpatient/document-error-summary';
import { EmergencyEmptyState, EvidenceTime } from './emergency-shared';
import { emergencyFieldClass, newEmergencyOperationKey } from './operation';
import type {
    EmergencyDocument,
    EmergencyDocumentProjection,
    EmergencyDocumentType,
    EmergencyDocumentVersion,
} from './types';

type FieldDefinition = { key: string; label: string; help: string };

const fields: Record<EmergencyDocumentType, FieldDefinition[]> = {
    NURSING: [
        {
            key: 'arrival_condition',
            label: 'Condition on arrival',
            help: 'The patient’s condition when emergency care began.',
        },
        {
            key: 'focused_assessment',
            label: 'Focused assessment',
            help: 'Nursing findings relevant to the current problem.',
        },
        {
            key: 'interventions',
            label: 'Nursing interventions',
            help: 'Interventions actually performed.',
        },
        {
            key: 'response_evaluation',
            label: 'Response and evaluation',
            help: 'Patient response after intervention and the latest evaluation.',
        },
        {
            key: 'safety_observation_needs',
            label: 'Safety and observation needs',
            help: 'Current risks, monitoring, and safety needs.',
        },
        {
            key: 'handoff_note',
            label: 'Handoff note',
            help: 'Information to pass to the next clinician.',
        },
    ],
    MEDICAL: [
        {
            key: 'anamnesis',
            label: 'Anamnesis',
            help: 'Complaint history and clinical information obtained.',
        },
        {
            key: 'focused_physical_examination',
            label: 'Focused physical examination',
            help: 'Relevant physical-examination findings.',
        },
        {
            key: 'clinical_impression',
            label: 'Clinical impression',
            help: 'The physician’s clinical impression; not a diagnosis code.',
        },
        {
            key: 'problem_list',
            label: 'Problem list',
            help: 'One or more problems currently being managed.',
        },
        {
            key: 'treatment_action_plan',
            label: 'Intervention / treatment plan',
            help: 'Clinical plan and interventions for this episode.',
        },
        {
            key: 'diagnostic_order_rationale',
            label: 'Diagnostic-test rationale',
            help: 'Clinical reason for laboratory or radiology tests.',
        },
        {
            key: 'disposition_readiness_note',
            label: 'Disposition readiness',
            help: 'Clinical considerations before determining the episode outcome.',
        },
    ],
};

const documentLabel: Record<EmergencyDocumentType, string> = {
    NURSING: 'Emergency Department nursing documentation',
    MEDICAL: 'Emergency Department medical documentation',
};

function baseline(
    type: EmergencyDocumentType,
    document: EmergencyDocument | null,
) {
    return Object.fromEntries(
        fields[type].map(({ key }) => [key, document?.fields[key] ?? '']),
    );
}

function EmergencyDocumentForm({
    type,
    projection,
}: {
    type: EmergencyDocumentType;
    projection: EmergencyDocumentProjection;
}) {
    const key = type === 'NURSING' ? 'nursing' : 'medical';
    const titleId = `emergency-document-${type.toLowerCase()}-title`;
    const document = projection.current[key];
    const permission = projection.permissions[key];
    const actions = projection.actions[key];
    const initialFields = useMemo(
        () => baseline(type, document),
        [document, type],
    );
    const [savedFields, setSavedFields] = useState(initialFields);
    const [attemptedFinal, setAttemptedFinal] = useState(false);
    const draftForm = useForm({
        definition_version: projection.definition_version,
        expected_version: document?.version ?? 0,
        fields: initialFields,
        idempotency_key: newEmergencyOperationKey(
            `${type.toLowerCase()}-draft`,
        ),
    });
    const finalForm = useForm({
        definition_version: projection.definition_version,
        expected_version: document?.version ?? 0,
        idempotency_key: newEmergencyOperationKey(
            `${type.toLowerCase()}-final`,
        ),
    });
    const dirty =
        JSON.stringify(draftForm.data.fields) !== JSON.stringify(savedFields);
    const missing = fields[type]
        .filter(
            ({ key: fieldKey }) =>
                !(draftForm.data.fields[fieldKey] ?? '').trim(),
        )
        .map(({ label }) => label);
    const errors = {
        ...draftForm.errors,
        ...finalForm.errors,
        ...(attemptedFinal && missing.length
            ? {
                  required_fields: `Complete before finalization: ${missing.join(', ')}.`,
              }
            : {}),
    };
    const isFinal = document?.state === 'FINAL';
    const canSave =
        !isFinal && permission.can_save_draft && actions.save_draft_url;
    const canFinalize =
        document?.state === 'DRAFT' &&
        permission.can_finalize &&
        actions.finalize_url;

    const save = (event: FormEvent) => {
        event.preventDefault();

        if (!canSave) {
            return;
        }

        draftForm.post(actions.save_draft_url!, {
            preserveScroll: true,
            onSuccess: () => {
                setSavedFields(draftForm.data.fields);
                draftForm.setData(
                    'idempotency_key',
                    newEmergencyOperationKey(`${type.toLowerCase()}-draft`),
                );
            },
        });
    };

    const finalize = () => {
        setAttemptedFinal(true);

        if (!canFinalize || dirty || missing.length) {
            return;
        }

        finalForm.post(actions.finalize_url!, {
            preserveScroll: true,
            onSuccess: () =>
                finalForm.setData(
                    'idempotency_key',
                    newEmergencyOperationKey(`${type.toLowerCase()}-final`),
                ),
        });
    };

    return (
        <section
            aria-labelledby={titleId}
            className="overflow-hidden rounded-xl border border-border bg-card"
        >
            <header
                className={cn(
                    'border-b border-border p-4',
                    type === 'NURSING' ? 'bg-secondary/60' : 'bg-card',
                )}
            >
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-[0.68rem] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                            {type === 'NURSING' ? 'Nurse' : 'Physician'}
                        </p>
                        <h3 id={titleId} className="mt-0.5 font-semibold">
                            {documentLabel[type]}
                        </h3>
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
                            ? `${document.state === 'FINAL' ? 'Final' : 'Draft'} · v${document.version}`
                            : 'Not created yet'}
                    </span>
                </div>
                {document ? (
                    <div className="mt-2 flex flex-wrap items-center gap-3">
                        <span className="text-xs font-semibold">
                            {document.author.name ?? 'Author unavailable'}
                        </span>
                        <EvidenceTime
                            value={document.finalized_at ?? document.updated_at}
                        />
                    </div>
                ) : null}
            </header>
            <form onSubmit={save} className="space-y-4 p-4">
                <DocumentErrorSummary errors={errors} />
                {fields[type].map(({ key: fieldKey, label, help }) => (
                    <label
                        key={fieldKey}
                        className="block text-sm font-semibold"
                    >
                        {label}
                        <span className="mt-0.5 block text-xs font-normal text-muted-foreground">
                            {help}
                        </span>
                        <textarea
                            value={draftForm.data.fields[fieldKey] ?? ''}
                            onChange={(event) =>
                                draftForm.setData('fields', {
                                    ...draftForm.data.fields,
                                    [fieldKey]: event.target.value,
                                })
                            }
                            disabled={!canSave}
                            className={cn(emergencyFieldClass, 'min-h-24')}
                            required
                        />
                    </label>
                ))}
                {canSave || canFinalize ? (
                    <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                        {canSave ? (
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={draftForm.processing}
                                className="min-h-11"
                            >
                                Save draft
                            </Button>
                        ) : null}
                        {canFinalize ? (
                            <Button
                                type="button"
                                onClick={finalize}
                                disabled={dirty || finalForm.processing}
                                className="min-h-11"
                            >
                                Finalize document
                            </Button>
                        ) : null}
                        {dirty && canFinalize ? (
                            <p className="w-full text-right text-xs text-warning">
                                Save draft changes before finalizing.
                            </p>
                        ) : null}
                    </div>
                ) : (
                    <div className="flex items-center gap-2 rounded-md border border-border bg-muted/30 p-3 text-xs text-muted-foreground">
                        <LockKeyhole aria-hidden="true" className="size-4" />{' '}
                        This document is displayed as read-only evidence.
                    </div>
                )}
            </form>
        </section>
    );
}

function VersionCard({ version }: { version: EmergencyDocumentVersion }) {
    return (
        <li className="rounded-lg border border-border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="text-sm font-semibold">
                        {documentLabel[version.document_type]}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {version.state === 'FINAL' ? 'Final' : 'Draft'} ·
                        version {version.version}
                    </p>
                </div>
                <div className="text-right">
                    <p className="text-xs font-semibold">
                        {version.author.name ?? 'Author unavailable'}
                    </p>
                    <EvidenceTime
                        value={version.finalized_at ?? version.recorded_at}
                    />
                </div>
            </div>
            <dl className="mt-3 grid gap-3 md:grid-cols-2">
                {fields[version.document_type].map(({ key, label }) => (
                    <div key={key}>
                        <dt className="text-xs font-semibold text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="mt-0.5 text-sm whitespace-pre-wrap">
                            {version.fields[key] || '—'}
                        </dd>
                    </div>
                ))}
            </dl>
            {version.content_digest ? (
                <p className="mt-3 font-mono text-[0.65rem] text-muted-foreground">
                    Digest {version.content_digest}
                </p>
            ) : null}
        </li>
    );
}

export function EmergencyDocumentationPanel({
    projection,
}: {
    projection: EmergencyDocumentProjection;
}) {
    return (
        <section
            aria-labelledby="emergency-documentation-title"
            className="space-y-4"
        >
            <header>
                <p className="text-[0.68rem] font-semibold tracking-[0.13em] text-muted-foreground uppercase">
                    Structured notes
                </p>
                <h2
                    id="emergency-documentation-title"
                    className="mt-0.5 flex items-center gap-2 text-lg font-semibold"
                >
                    <FileText
                        aria-hidden="true"
                        className="size-5 text-primary"
                    />{' '}
                    Emergency Department clinical documentation
                </h2>
            </header>
            <div className="grid gap-4 xl:grid-cols-2">
                <EmergencyDocumentForm type="NURSING" projection={projection} />
                <EmergencyDocumentForm type="MEDICAL" projection={projection} />
            </div>
            <section className="clinical-shadow rounded-xl border border-border bg-muted/20 p-4">
                <h3 className="font-semibold">Complete version history</h3>
                <p className="mt-1 text-xs text-muted-foreground">
                    Draft and final documents are stored as separate versions.
                    Final documents cannot be overwritten.
                </p>
                {projection.versions.length ? (
                    <ol className="mt-4 grid gap-3 xl:grid-cols-2">
                        {projection.versions.map((version) => (
                            <VersionCard
                                key={version.public_id}
                                version={version}
                            />
                        ))}
                    </ol>
                ) : (
                    <div className="mt-4">
                        <EmergencyEmptyState
                            title="No document versions yet"
                            body="Documentation can begin after the initial triage assessment is finalized."
                        />
                    </div>
                )}
            </section>
        </section>
    );
}
