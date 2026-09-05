import { useForm } from '@inertiajs/react';
import {
    BedSingle,
    CheckCircle2,
    ClipboardCheck,
    History,
    UserCheck,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { DocumentErrorSummary } from '../outpatient/document-error-summary';
import { EmergencyDiagnosticAssignmentHistory } from './emergency-follow-up-history';
import {
    EmergencyEmptyState,
    EvidenceTime,
    PendingRequirement,
} from './emergency-shared';
import {
    emergencyFieldClass,
    newEmergencyOperationKey,
    toLocalDateTimeInput,
} from './operation';
import type {
    EmergencyCorrectionIntent,
    EmergencyDiagnosticFollowUpItem,
    EmergencyDispositionCode,
    EmergencyDispositionDetails,
    EmergencyDispositionProjection,
    EmergencyDispositionVersion,
    EmergencyFollowUpProjection,
} from './types';

const dispositionOptions: Array<{
    code: EmergencyDispositionCode;
    label: string;
    description: string;
}> = [
    {
        code: 'PULANG',
        label: 'Discharge',
        description:
            'The patient is discharged with instructions and follow-up.',
    },
    {
        code: 'DIRUJUK',
        label: 'Referral',
        description: 'Referral plan and local handoff.',
    },
    {
        code: 'RAWAT_INAP',
        label: 'Inpatient admission',
        description:
            'Physician decision; bed placement is completed by registration.',
    },
    {
        code: 'MENINGGAL_DI_IGD',
        label: 'Death in Emergency Department',
        description: 'Episode facts recorded by the physician.',
    },
    {
        code: 'DOA',
        label: 'DOA',
        description: 'Arrival facts recorded by the physician.',
    },
];

const detailFields: Record<
    EmergencyDispositionCode,
    Array<{ key: string; label: string }>
> = {
    PULANG: [
        { key: 'condition_at_discharge', label: 'Condition at discharge' },
        { key: 'instructions', label: 'Patient and family instructions' },
        { key: 'warning_signs', label: 'Warning signs' },
        { key: 'follow_up_plan', label: 'Follow-up plan' },
    ],
    DIRUJUK: [
        { key: 'destination', label: 'Referral destination' },
        { key: 'clinical_reason', label: 'Clinical reason' },
        { key: 'transport_plan', label: 'Transport plan' },
        { key: 'handoff_note', label: 'Handoff note' },
    ],
    RAWAT_INAP: [
        { key: 'admission_reason', label: 'Admission reason' },
        {
            key: 'receiving_unit_handoff_note',
            label: 'Receiving unit note',
        },
    ],
    MENINGGAL_DI_IGD: [
        { key: 'event_time', label: 'Event time' },
        { key: 'clinical_note', label: 'Brief clinical note' },
    ],
    DOA: [
        {
            key: 'arrival_declaration_time',
            label: 'Arrival or declaration time',
        },
        { key: 'clinical_note', label: 'Brief clinical note' },
    ],
};

function FollowUpItem({
    item,
    projection,
}: {
    item: EmergencyDiagnosticFollowUpItem;
    projection: EmergencyFollowUpProjection;
}) {
    const proposeForm = useForm({
        order_type: item.order_type,
        order_public_id: item.order_public_id,
        expected_result_fingerprint: item.fingerprint,
        assignee_physician_public_id:
            projection.physician_options[0]?.value ?? '',
        assignment_reason: '',
        effective_at: toLocalDateTimeInput(),
        handoff_note: '',
        expected_assignment_version: item.current?.version ?? 0,
        idempotency_key: newEmergencyOperationKey(
            'diagnostic-follow-up-propose',
        ),
    });
    const acceptForm = useForm({
        order_type: item.order_type,
        order_public_id: item.order_public_id,
        proposal_public_id: item.current?.public_id ?? '',
        expected_proposal_fingerprint: item.current?.fingerprint ?? '',
        expected_result_fingerprint: item.fingerprint,
        idempotency_key: newEmergencyOperationKey(
            'diagnostic-follow-up-accept',
        ),
    });
    const propose = (event: FormEvent) => {
        event.preventDefault();

        if (!item.actions.propose_url) {
            return;
        }

        proposeForm.post(item.actions.propose_url, {
            preserveScroll: true,
        });
    };

    return (
        <article className="rounded-lg border border-border bg-card p-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-semibold">{item.label}</p>
                    <p className="text-xs text-muted-foreground">
                        {item.order_type === 'LABORATORY'
                            ? 'Laboratory'
                            : 'Radiology'}{' '}
                        · {item.order_public_id}
                    </p>
                </div>
                <span className="rounded-full bg-warning/10 px-2 py-1 text-xs font-semibold text-warning">
                    Incomplete
                </span>
            </div>
            {item.current ? (
                <div className="mt-3 rounded-lg border border-border bg-muted/25 p-3 text-sm">
                    <p className="font-semibold">
                        {item.current.assignee.name ?? 'Physician unavailable'}
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {item.current.state === 'ACCEPTED'
                            ? 'Assignment accepted'
                            : 'Menunggu penerimaan'}{' '}
                        · v{item.current.version}
                    </p>
                    <p className="mt-2 whitespace-pre-wrap">
                        {item.current.reason}
                    </p>
                    <EvidenceTime
                        value={
                            item.current.accepted_at ?? item.current.proposed_at
                        }
                    />
                </div>
            ) : (
                <div className="mt-3">
                    <EmergencyEmptyState
                        title="No assignment yet"
                        body="The ordering physician remains responsible until the assignment is accepted."
                    />
                </div>
            )}
            {projection.permission.can_propose && item.actions.propose_url ? (
                <form
                    onSubmit={propose}
                    className="mt-4 grid gap-3 md:grid-cols-2"
                >
                    <label className="text-xs font-semibold">
                        Receiving physician
                        <select
                            value={
                                proposeForm.data.assignee_physician_public_id
                            }
                            onChange={(e) =>
                                proposeForm.setData(
                                    'assignee_physician_public_id',
                                    e.target.value,
                                )
                            }
                            className={emergencyFieldClass}
                            required
                        >
                            {projection.physician_options.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-xs font-semibold">
                        Mulai berlaku
                        <input
                            type="datetime-local"
                            value={proposeForm.data.effective_at}
                            onChange={(e) =>
                                proposeForm.setData(
                                    'effective_at',
                                    e.target.value,
                                )
                            }
                            className={emergencyFieldClass}
                            required
                        />
                    </label>
                    <label className="text-xs font-semibold">
                        Assignment reason
                        <textarea
                            value={proposeForm.data.assignment_reason}
                            onChange={(e) =>
                                proposeForm.setData(
                                    'assignment_reason',
                                    e.target.value,
                                )
                            }
                            className={cn(emergencyFieldClass, 'min-h-20')}
                            required
                        />
                    </label>
                    <label className="text-xs font-semibold">
                        Handoff note
                        <textarea
                            value={proposeForm.data.handoff_note}
                            onChange={(e) =>
                                proposeForm.setData(
                                    'handoff_note',
                                    e.target.value,
                                )
                            }
                            className={cn(emergencyFieldClass, 'min-h-20')}
                            required
                        />
                    </label>
                    <div className="md:col-span-2 md:text-right">
                        <Button
                            type="submit"
                            disabled={proposeForm.processing}
                            className="min-h-11"
                        >
                            Propose responsible physician
                        </Button>
                    </div>
                </form>
            ) : null}
            {projection.permission.can_accept &&
            item.current?.state === 'PROPOSED' &&
            item.actions.accept_url ? (
                <div className="mt-3 text-right">
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        disabled={acceptForm.processing}
                        onClick={() =>
                            acceptForm.post(item.actions.accept_url!, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Accept assignment
                    </Button>
                </div>
            ) : null}
            {item.history.length > 0 ? (
                <details className="mt-4 border-t border-border pt-3">
                    <summary className="min-h-11 cursor-pointer py-2 text-sm font-semibold">
                        Assignment history ({item.history.length})
                    </summary>
                    <ol className="mt-2 space-y-2">
                        {item.history.map((assignment) => (
                            <li
                                key={assignment.public_id}
                                className="rounded-md bg-muted/30 p-3 text-xs"
                            >
                                <p className="font-semibold">
                                    v{assignment.version} ·{' '}
                                    {assignment.assignee.name ??
                                        'Physician unavailable'}{' '}
                                    · {assignment.state}
                                </p>
                                <p className="mt-1">{assignment.reason}</p>
                                <p className="mt-1 text-muted-foreground">
                                    Proposed{' '}
                                    {assignment.proposed_by.name ?? '—'} ·{' '}
                                    {assignment.proposed_at
                                        ? new Date(
                                              assignment.proposed_at,
                                          ).toLocaleString('id-ID')
                                        : '—'}
                                </p>
                            </li>
                        ))}
                    </ol>
                </details>
            ) : null}
        </article>
    );
}

function FollowUpPanel({
    projection,
}: {
    projection: EmergencyFollowUpProjection;
}) {
    return (
        <section
            aria-labelledby="follow-up-title"
            className="rounded-xl border border-border bg-card p-4"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-[0.68rem] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                        Diagnostic results are pending
                    </p>
                    <h3
                        id="follow-up-title"
                        className="mt-0.5 flex items-center gap-2 font-semibold"
                    >
                        <UserCheck
                            aria-hidden="true"
                            className="size-4 text-primary"
                        />{' '}
                        Responsible clinician for each order
                    </h3>
                </div>
                <span className="rounded-full bg-warning/10 px-2.5 py-1 text-xs font-semibold text-warning">
                    {projection.unresolved_diagnostic_count} pending
                </span>
            </div>
            {projection.unresolved_diagnostics.length ? (
                <div className="mt-3 space-y-3">
                    {projection.unresolved_diagnostics.map((item) => (
                        <FollowUpItem
                            key={`${item.order_type}:${item.order_public_id}`}
                            item={item}
                            projection={projection}
                        />
                    ))}
                </div>
            ) : (
                <div className="mt-3">
                    <EmergencyEmptyState
                        title="No results need reassignment"
                        body="All diagnostic tests are complete or remain with the ordering physician."
                    />
                </div>
            )}
            <div className="mt-4">
                <EmergencyDiagnosticAssignmentHistory
                    items={projection.diagnostic_assignment_history}
                />
            </div>
        </section>
    );
}

function DispositionForm({
    projection,
}: {
    projection: EmergencyDispositionProjection;
}) {
    const [initialExpiry] = useState(() =>
        toLocalDateTimeInput(
            new Date(Date.now() + 60 * 60 * 1000).toISOString(),
        ),
    );
    const [code, setCode] = useState<EmergencyDispositionCode>('PULANG');
    const [details, setDetails] = useState<EmergencyDispositionDetails>({});
    const createsCorrectionIntent =
        projection.current?.code === 'RAWAT_INAP' &&
        projection.handoff?.state === 'COMPLETED' &&
        projection.actions.create_correction_intent_url !== null;
    const form = useForm({
        expected_disposition_version: projection.current?.version ?? 0,
        disposition_type: code,
        payload: details,
        replacement_type: code,
        replacement_payload: details,
        reason: '',
        expires_at: initialExpiry,
        idempotency_key: newEmergencyOperationKey('disposition-sign'),
    });
    const [attempted, setAttempted] = useState(false);
    const requirementsComplete = Object.values(projection.requirements).every(
        Boolean,
    );
    const actionUrl = createsCorrectionIntent
        ? projection.actions.create_correction_intent_url
        : projection.current
          ? projection.actions.correct_url
          : projection.actions.sign_url;
    const required = detailFields[code].filter(
        ({ key }) => !(details[key] ?? '').trim(),
    );
    const errors = {
        ...form.errors,
        ...(attempted && required.length
            ? {
                  details: `Complete: ${required.map(({ label }) => label).join(', ')}.`,
              }
            : {}),
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setAttempted(true);

        if (!actionUrl || required.length) {
            return;
        }

        form.transform((data) => ({
            ...(projection.current
                ? {
                      expected_disposition_version:
                          data.expected_disposition_version,
                      replacement_type: code,
                      replacement_payload: details,
                      reason: data.reason,
                      ...(createsCorrectionIntent
                          ? { expires_at: data.expires_at }
                          : {}),
                  }
                : {
                      disposition_type: code,
                      payload: details,
                  }),
            idempotency_key: data.idempotency_key,
        }));
        form.post(actionUrl, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <DocumentErrorSummary errors={errors} />
            <fieldset>
                <legend className="text-sm font-semibold">
                    Select disposition
                </legend>
                <div className="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                    {dispositionOptions.map((option) => (
                        <label
                            key={option.code}
                            className={cn(
                                'flex min-h-24 cursor-pointer gap-2 rounded-lg border p-3 focus-within:ring-2 focus-within:ring-ring',
                                code === option.code
                                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                    : 'border-border',
                            )}
                        >
                            <input
                                type="radio"
                                name="disposition"
                                value={option.code}
                                checked={code === option.code}
                                onChange={() => {
                                    setCode(option.code);
                                    setDetails({});
                                }}
                                className="mt-0.5 size-4"
                            />
                            <span>
                                <span className="block text-sm font-semibold">
                                    {option.label}
                                </span>
                                <span className="mt-1 block text-xs text-muted-foreground">
                                    {option.description}
                                </span>
                            </span>
                        </label>
                    ))}
                </div>
            </fieldset>
            <div className="grid gap-3 md:grid-cols-2">
                {detailFields[code].map(({ key, label }) => (
                    <label key={key} className="text-sm font-semibold">
                        {label}
                        {key.endsWith('_at') || key.endsWith('_time') ? (
                            <input
                                type="datetime-local"
                                value={details[key] ?? ''}
                                onChange={(e) =>
                                    setDetails((current) => ({
                                        ...current,
                                        [key]: e.target.value,
                                    }))
                                }
                                className={emergencyFieldClass}
                                required
                            />
                        ) : (
                            <textarea
                                value={details[key] ?? ''}
                                onChange={(e) =>
                                    setDetails((current) => ({
                                        ...current,
                                        [key]: e.target.value,
                                    }))
                                }
                                className={cn(emergencyFieldClass, 'min-h-24')}
                                required
                            />
                        )}
                    </label>
                ))}
            </div>
            {projection.current ? (
                <label className="block text-sm font-semibold">
                    Correction reason
                    <textarea
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        className={cn(emergencyFieldClass, 'min-h-20')}
                        required
                    />
                </label>
            ) : null}
            {createsCorrectionIntent ? (
                <label className="block text-sm font-semibold">
                    Valid until
                    <input
                        type="datetime-local"
                        value={form.data.expires_at}
                        onChange={(event) =>
                            form.setData('expires_at', event.target.value)
                        }
                        className={emergencyFieldClass}
                        required
                    />
                    <span className="mt-1 block text-xs font-normal text-muted-foreground">
                        Registration staff must execute the correction intent
                        before this time and before the inpatient episode has
                        subsequent evidence.
                    </span>
                </label>
            ) : null}
            <div className="flex justify-end">
                <Button
                    type="submit"
                    disabled={form.processing || !requirementsComplete}
                    className="min-h-11"
                >
                    {createsCorrectionIntent
                        ? 'Submit correction intent'
                        : projection.current
                          ? 'Sign correction'
                          : 'Sign disposition'}
                </Button>
            </div>
            {!requirementsComplete ? (
                <p role="status" className="text-right text-xs text-warning">
                    Complete all requirements above before signing.
                </p>
            ) : null}
        </form>
    );
}

function RevokeCorrectionIntent({
    intent,
}: {
    intent: EmergencyCorrectionIntent;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        expected_intent_fingerprint: intent.fingerprint,
        reason: '',
        idempotency_key: newEmergencyOperationKey('correction-intent-revoke'),
    });

    if (intent.state !== 'PENDING' || !intent.actions.revoke_url) {
        return null;
    }

    return open ? (
        <form
            className="mt-3 rounded-md border border-destructive/25 bg-background p-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(intent.actions.revoke_url!, { preserveScroll: true });
            }}
        >
            <label className="text-sm font-semibold">
                Revocation reason
                <textarea
                    className={cn(emergencyFieldClass, 'min-h-20')}
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    required
                />
            </label>
            <div className="mt-3 flex flex-wrap gap-2">
                <Button
                    type="submit"
                    variant="destructive"
                    className="min-h-11"
                    disabled={form.processing || !form.data.reason.trim()}
                >
                    Revoke correction intent
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    onClick={() => setOpen(false)}
                >
                    Back
                </Button>
            </div>
        </form>
    ) : (
        <Button
            type="button"
            variant="outline"
            className="mt-3 min-h-11 text-destructive"
            onClick={() => setOpen(true)}
        >
            Revoke correction intent
        </Button>
    );
}

function HandoffPanel({
    projection,
}: {
    projection: EmergencyDispositionProjection;
}) {
    const handoffForm = useForm({
        disposition_public_id: projection.current?.public_id ?? '',
        expected_disposition_version: projection.current?.version ?? 0,
        bed_public_id: projection.bed_options[0]?.public_id ?? '',
        idempotency_key: newEmergencyOperationKey('inpatient-handoff'),
    });
    const pendingIntent = projection.correction_intents.find(
        (intent) => intent.state === 'PENDING',
    );
    const compensateForm = useForm({
        correction_intent_public_id: pendingIntent?.public_id ?? '',
        idempotency_key: newEmergencyOperationKey('handoff-compensate'),
    });

    if (projection.current?.code !== 'RAWAT_INAP' && !projection.handoff) {
        return null;
    }

    return (
        <section className="rounded-xl border border-border bg-card p-4">
            <h3 className="flex items-center gap-2 font-semibold">
                <BedSingle aria-hidden="true" className="size-4 text-primary" />{' '}
                Handoff to Inpatient Care
            </h3>
            {projection.handoff ? (
                <div className="mt-3 rounded-lg border border-success/30 bg-success/5 p-3 text-sm">
                    <p className="font-semibold text-success">
                        {projection.handoff.state === 'COMPENSATED'
                            ? 'Handoff has been compensated'
                            : 'Handoff complete'}
                    </p>
                    <p className="mt-1">
                        {projection.handoff.ward_display_name ?? 'Unit'} ·{' '}
                        {projection.handoff.bed_code ?? 'Bed'}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Registrar: {projection.handoff.registrar_name ?? '—'} ·{' '}
                        {projection.handoff.completed_at
                            ? new Date(
                                  projection.handoff.completed_at,
                              ).toLocaleString('id-ID')
                            : '—'}
                    </p>
                    {projection.handoff.target_encounter_url ? (
                        <a
                            href={projection.handoff.target_encounter_url}
                            className="mt-2 inline-flex min-h-11 items-center font-semibold text-primary hover:underline"
                        >
                            Open Inpatient Care episode
                        </a>
                    ) : null}
                </div>
            ) : null}
            {projection.permissions.can_handoff &&
            projection.actions.handoff_url &&
            !projection.handoff ? (
                <form
                    className="mt-4 flex flex-col gap-3 md:flex-row md:items-end"
                    onSubmit={(event) => {
                        event.preventDefault();
                        handoffForm.post(projection.actions.handoff_url!, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className="flex-1 text-sm font-semibold">
                        Available bed
                        <select
                            value={handoffForm.data.bed_public_id}
                            onChange={(e) =>
                                handoffForm.setData(
                                    'bed_public_id',
                                    e.target.value,
                                )
                            }
                            className={emergencyFieldClass}
                            required
                        >
                            {projection.bed_options.map((bed) => (
                                <option
                                    key={bed.public_id}
                                    value={bed.public_id}
                                >
                                    {bed.ward_display_name} · {bed.room_label} ·{' '}
                                    {bed.display_name} · {bed.service_class}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button
                        type="submit"
                        disabled={
                            handoffForm.processing ||
                            !handoffForm.data.bed_public_id
                        }
                        className="min-h-11"
                    >
                        Place patient
                    </Button>
                </form>
            ) : null}
            {pendingIntent ? (
                <div className="mt-4 rounded-lg border border-warning/30 bg-warning/5 p-3 text-sm">
                    <p className="font-semibold text-warning">
                        Physician correction awaiting execution
                    </p>
                    <p className="mt-1">
                        Replacement: {pendingIntent.replacement_label}
                    </p>
                    <p className="mt-1 text-xs">{pendingIntent.reason}</p>
                    {projection.permissions.can_compensate &&
                    projection.actions.compensate_url ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="mt-3 min-h-11"
                            disabled={compensateForm.processing}
                            onClick={() =>
                                compensateForm.post(
                                    projection.actions.compensate_url!,
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Cancel inpatient episode and activate correction
                        </Button>
                    ) : null}
                    <RevokeCorrectionIntent intent={pendingIntent} />
                </div>
            ) : null}
        </section>
    );
}

function DispositionVersionCard({
    version,
}: {
    version: EmergencyDispositionVersion;
}) {
    return (
        <li className="rounded-lg border border-border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-semibold">{version.label}</p>
                    <p className="text-xs text-muted-foreground">
                        Version {version.version}
                        {version.supersedes_public_id
                            ? ' · correction'
                            : ' · original'}
                    </p>
                </div>
                <div className="text-right">
                    <p className="text-xs font-semibold">
                        {version.physician.name ?? 'Physician unavailable'}
                    </p>
                    <EvidenceTime value={version.signed_at} />
                </div>
            </div>
            {version.correction_reason ? (
                <p className="mt-3 rounded-md bg-warning/5 p-2 text-sm">
                    <span className="font-semibold">Correction reason:</span>{' '}
                    {version.correction_reason}
                </p>
            ) : null}
            <dl className="mt-3 grid gap-2 md:grid-cols-2">
                {Object.entries(version.details).map(([key, value]) => (
                    <div key={key}>
                        <dt className="text-xs font-semibold text-muted-foreground">
                            {key.replaceAll('_', ' ')}
                        </dt>
                        <dd className="mt-0.5 text-sm whitespace-pre-wrap">
                            {value || '—'}
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

export function EmergencyDispositionPanel({
    disposition,
    followUp,
}: {
    disposition: EmergencyDispositionProjection;
    followUp: EmergencyFollowUpProjection;
}) {
    const requirementsComplete = Object.values(disposition.requirements).every(
        Boolean,
    );
    const canShowForm =
        (disposition.permissions.can_sign ||
            disposition.permissions.can_correct) &&
        (disposition.actions.sign_url ||
            disposition.actions.correct_url ||
            disposition.actions.create_correction_intent_url);

    return (
        <section aria-labelledby="disposition-title" className="space-y-4">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-[0.68rem] font-semibold tracking-[0.13em] text-muted-foreground uppercase">
                        Physician decision
                    </p>
                    <h2
                        id="disposition-title"
                        className="mt-0.5 flex items-center gap-2 text-lg font-semibold"
                    >
                        <ClipboardCheck
                            aria-hidden="true"
                            className="size-5 text-primary"
                        />{' '}
                        Disposition and handoff
                    </h2>
                </div>
                {disposition.current ? (
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-xs font-semibold text-success">
                        <CheckCircle2 aria-hidden="true" className="size-3.5" />{' '}
                        {disposition.current.label} · v
                        {disposition.current.version}
                    </span>
                ) : null}
            </header>
            <div className="grid gap-4 xl:grid-cols-[20rem_minmax(0,1fr)]">
                <div className="rounded-xl border border-border bg-card p-4">
                    <h3 className="font-semibold">Signing requirements</h3>
                    <ul className="mt-3 space-y-2">
                        <PendingRequirement
                            complete={
                                disposition.requirements.initial_triage_final
                            }
                        >
                            Initial triage assessment is final
                        </PendingRequirement>
                        <PendingRequirement
                            complete={disposition.requirements.nursing_final}
                        >
                            Nursing documentation is final
                        </PendingRequirement>
                        <PendingRequirement
                            complete={disposition.requirements.medical_final}
                        >
                            Medical documentation is final
                        </PendingRequirement>
                        <PendingRequirement
                            complete={
                                disposition.requirements
                                    .diagnostic_follow_up_resolved
                            }
                        >
                            Diagnostic-result follow-up has been accepted or is
                            not required
                        </PendingRequirement>
                    </ul>
                </div>
                <FollowUpPanel projection={followUp} />
            </div>
            {canShowForm ? (
                <div
                    className={cn(
                        'rounded-xl border bg-card p-4',
                        requirementsComplete
                            ? 'border-border'
                            : 'border-warning/30',
                    )}
                >
                    <DispositionForm projection={disposition} />
                </div>
            ) : null}
            <HandoffPanel projection={disposition} />
            <section className="rounded-xl border border-border bg-muted/20 p-4">
                <h3 className="flex items-center gap-2 font-semibold">
                    <History aria-hidden="true" className="size-4" />{' '}
                    Disposition history
                </h3>
                {disposition.history.length ? (
                    <ol className="mt-3 space-y-3">
                        {disposition.history.map((version) => (
                            <DispositionVersionCard
                                key={version.public_id}
                                version={version}
                            />
                        ))}
                    </ol>
                ) : (
                    <div className="mt-3">
                        <EmergencyEmptyState
                            title="No disposition yet"
                            body="The physician can sign the final decision only after required documents are final."
                        />
                    </div>
                )}
                {disposition.correction_intents.length ? (
                    <details className="mt-4 border-t border-border pt-3">
                        <summary className="min-h-11 cursor-pointer py-2 text-sm font-semibold">
                            Correction intent history (
                            {disposition.correction_intents.length})
                        </summary>
                        <ol className="mt-2 space-y-2">
                            {disposition.correction_intents.map((intent) => (
                                <li
                                    key={intent.public_id}
                                    className="rounded-md bg-card p-3 text-xs"
                                >
                                    <p className="font-semibold">
                                        {intent.replacement_label} ·{' '}
                                        {intent.state}
                                    </p>
                                    <p className="mt-1">{intent.reason}</p>
                                    <p className="mt-1 text-muted-foreground">
                                        {intent.physician_name ?? 'Physician'} ·
                                        valid until{' '}
                                        {new Date(
                                            intent.expires_at,
                                        ).toLocaleString('id-ID')}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </details>
                ) : null}
            </section>
        </section>
    );
}
