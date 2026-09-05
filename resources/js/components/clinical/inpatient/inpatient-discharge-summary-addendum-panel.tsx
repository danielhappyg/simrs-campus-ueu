import { useForm } from '@inertiajs/react';
import { CheckCircle2, FileClock, ShieldCheck } from 'lucide-react';
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
import { cn } from '@/lib/utils';
import type {
    InpatientSummaryAddendumFields,
    InpatientSummaryAddendumProjection,
    InpatientSummaryCorrectionRequest,
} from './types';

const fieldDefinitions: Array<{
    key: keyof InpatientSummaryAddendumFields;
    label: string;
}> = [
    { key: 'admission_reason', label: 'Reason for admission' },
    { key: 'significant_findings', label: 'Significant findings' },
    {
        key: 'care_and_treatment_summary',
        label: 'Care and treatment summary',
    },
    { key: 'condition_at_discharge', label: 'Condition at discharge' },
    { key: 'follow_up_plan', label: 'Follow-up plan' },
];

const emptyFields: InpatientSummaryAddendumFields = {
    admission_reason: '',
    significant_findings: '',
    care_and_treatment_summary: '',
    condition_at_discharge: '',
    follow_up_plan: '',
};

const requestStateLabel: Record<
    InpatientSummaryCorrectionRequest['state'],
    string
> = {
    SUBMITTED: 'Awaiting decision',
    APPROVED: 'Approved',
    DENIED: 'Denied',
    CONSUMED: 'Correction reviewed by medical records',
};

let operationCounter = 0;

function newOperationKey(operation: string) {
    const value =
        globalThis.crypto?.randomUUID?.() ??
        `${Date.now().toString(36)}-${(++operationCounter).toString(36)}`;

    return `inpatient-summary-addendum-${operation}-${value}`.toLowerCase();
}

function formatDate(value: string | null) {
    return value ? new Date(value).toLocaleString('en-GB') : '—';
}

function ErrorSummary({
    errors,
    attempt,
}: {
    errors: Record<string, string | undefined>;
    attempt: number;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const messages = [
        ...new Set(Object.values(errors).filter(Boolean)),
    ] as string[];
    const signature = messages.join('\u0000');

    useEffect(() => {
        if (messages.length > 0) {
            ref.current?.focus();
        }
    }, [attempt, messages.length, signature]);

    if (messages.length === 0) {
        return null;
    }

    return (
        <div
            ref={ref}
            role="alert"
            tabIndex={-1}
            className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
        >
            <p className="font-semibold">The action could not be processed.</p>
            <ul className="mt-1 list-disc space-y-1 pl-5">
                {messages.map((message) => (
                    <li key={message}>{message}</li>
                ))}
            </ul>
        </div>
    );
}

function RequestForm({
    projection,
    announce,
}: {
    projection: InpatientSummaryAddendumProjection;
    announce: (message: string) => void;
}) {
    const firstReason = projection.reason_options[0];
    const [attempt, setAttempt] = useState(0);
    const form = useForm({
        reason_code: firstReason?.value ?? '',
        note: '',
        idempotency_key: newOperationKey('request'),
    });
    const selectedReason = projection.reason_options.find(
        (option) => option.value === form.data.reason_code,
    );
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!projection.store_url || !projection.can_request) {
            return;
        }

        form.post(projection.store_url, {
            preserveScroll: true,
            onSuccess: () => {
                announce(
                    'The discharge-summary correction request was submitted.',
                );
                form.setData({
                    ...form.data,
                    note: '',
                    idempotency_key: newOperationKey('request'),
                });
            },
            onError: () => setAttempt((current) => current + 1),
        });
    };

    return (
        <form onSubmit={submit} className="mt-4 space-y-4">
            <ErrorSummary errors={errors} attempt={attempt} />
            <div className="grid gap-1.5">
                <Label htmlFor="inpatient-summary-correction-reason">
                    Correction reason
                </Label>
                <select
                    id="inpatient-summary-correction-reason"
                    value={form.data.reason_code}
                    onChange={(event) =>
                        form.setData({
                            ...form.data,
                            reason_code: event.target.value,
                            idempotency_key: newOperationKey('request'),
                        })
                    }
                    aria-invalid={Boolean(errors.reason_code)}
                    className="min-h-11 rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                >
                    {projection.reason_options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="inpatient-summary-correction-note">
                    Reason note
                    {selectedReason?.requires_note
                        ? ' · required'
                        : ' (optional)'}
                </Label>
                <textarea
                    id="inpatient-summary-correction-note"
                    value={form.data.note}
                    required={selectedReason?.requires_note}
                    onChange={(event) =>
                        form.setData({
                            ...form.data,
                            note: event.target.value,
                            idempotency_key: newOperationKey('request'),
                        })
                    }
                    aria-invalid={Boolean(errors.note)}
                    aria-describedby="inpatient-summary-correction-note-help"
                    className="min-h-24 rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                />
                <p
                    id="inpatient-summary-correction-note-help"
                    className="text-xs leading-5 text-muted-foreground"
                >
                    The original discharge summary and episode closure remain
                    intact. The correction is recorded as a separate addendum.
                </p>
            </div>
            <Button
                type="submit"
                disabled={
                    form.processing ||
                    !projection.store_url ||
                    !form.data.reason_code ||
                    (selectedReason?.requires_note === true &&
                        !form.data.note.trim())
                }
                className="min-h-11 w-full sm:w-auto"
            >
                Submit Discharge-Summary Correction
            </Button>
        </form>
    );
}

function BaselineBinding({
    request,
}: {
    request: InpatientSummaryCorrectionRequest;
}) {
    return (
        <dl className="grid gap-2 rounded-lg border border-border bg-muted/30 p-3 text-xs sm:grid-cols-2 xl:grid-cols-4">
            <div>
                <dt className="text-muted-foreground">Original summary</dt>
                <dd className="mt-0.5 font-semibold">
                    Final v{request.baseline.discharge_summary_version}
                </dd>
            </div>
            <div>
                <dt className="text-muted-foreground">Coding source</dt>
                <dd className="mt-0.5 font-semibold">
                    Final v{request.baseline.coding_source_version}
                </dd>
            </div>
            <div>
                <dt className="text-muted-foreground">Medical-record coding</dt>
                <dd className="mt-0.5 font-semibold">
                    Final v{request.baseline.coding_version}
                </dd>
            </div>
            <div>
                <dt className="text-muted-foreground">
                    Medical-record closure
                </dt>
                <dd className="mt-0.5 font-semibold">
                    Sign-off v{request.baseline.review_version}
                </dd>
            </div>
        </dl>
    );
}

function RequestChain({
    request,
    announce,
    onDirtyChange,
}: {
    request: InpatientSummaryCorrectionRequest;
    announce: (message: string) => void;
    onDirtyChange?: (dirty: boolean) => void;
}) {
    const id = useId();
    const [attempt, setAttempt] = useState(0);
    const [finalizeOpen, setFinalizeOpen] = useState(false);
    const [signoffOpen, setSignoffOpen] = useState(false);
    const originalFields = request.addendum?.fields ?? emptyFields;
    const decisionForm = useForm({
        decision: 'APPROVED' as 'APPROVED' | 'DENIED',
        decision_note: '',
        expected_version: request.version,
        idempotency_key: newOperationKey('decision'),
    });
    const addendumForm = useForm({
        expected_version: request.addendum?.version ?? 0,
        fields: originalFields,
        idempotency_key: newOperationKey('draft'),
    });
    const finalizeForm = useForm({
        expected_version: request.addendum?.version ?? 0,
        idempotency_key: newOperationKey('final'),
    });
    const reviewForm = useForm({
        expected_version: request.current_review_version,
        source_fingerprint: request.current_review_source_fingerprint ?? '',
        idempotency_key: newOperationKey('review'),
    });
    const signoffForm = useForm({
        expected_version: request.current_review_version,
        source_fingerprint: request.current_review_source_fingerprint ?? '',
        idempotency_key: newOperationKey('signoff'),
    });
    const addendumDirty =
        JSON.stringify(addendumForm.data.fields) !==
        JSON.stringify(originalFields);
    const addendumFinal = request.addendum?.state === 'FINAL';
    const reviewSignedOff = request.renewed_review?.state === 'SIGNED_OFF';
    const incompleteItems =
        request.renewed_review?.items.filter(
            (item) => item.is_blocking && !item.is_complete,
        ) ?? [];
    const errors = {
        ...(decisionForm.errors as Record<string, string | undefined>),
        ...(addendumForm.errors as Record<string, string | undefined>),
        ...(finalizeForm.errors as Record<string, string | undefined>),
        ...(reviewForm.errors as Record<string, string | undefined>),
        ...(signoffForm.errors as Record<string, string | undefined>),
    };

    useEffect(
        () => onDirtyChange?.(addendumDirty),
        [addendumDirty, onDirtyChange],
    );

    const fail = () => setAttempt((current) => current + 1);
    const decide = (event: FormEvent) => {
        event.preventDefault();

        if (
            !request.actions.decision_url ||
            (decisionForm.data.decision === 'DENIED' &&
                !decisionForm.data.decision_note.trim())
        ) {
            return;
        }

        decisionForm.post(request.actions.decision_url, {
            preserveScroll: true,
            onSuccess: () =>
                announce('The correction-request decision was saved.'),
            onError: fail,
        });
    };
    const saveDraft = (event: FormEvent) => {
        event.preventDefault();

        if (!request.actions.save_addendum_url) {
            return;
        }

        addendumForm.post(request.actions.save_addendum_url, {
            preserveScroll: true,
            onSuccess: () =>
                announce('The discharge-summary addendum draft was saved.'),
            onError: fail,
        });
    };
    const finalize = () => {
        if (!request.actions.finalize_addendum_url) {
            return;
        }

        finalizeForm.post(request.actions.finalize_addendum_url, {
            preserveScroll: true,
            onSuccess: () => {
                setFinalizeOpen(false);
                announce('The discharge-summary addendum was finalized.');
            },
            onError: () => {
                setFinalizeOpen(false);
                fail();
            },
        });
    };
    const saveReview = () => {
        if (!request.actions.save_renewed_review_url) {
            return;
        }

        reviewForm.post(request.actions.save_renewed_review_url, {
            preserveScroll: true,
            onSuccess: () =>
                announce('The correction-review result was saved.'),
            onError: fail,
        });
    };
    const signoff = () => {
        if (!request.actions.signoff_renewed_review_url) {
            return;
        }

        signoffForm.post(request.actions.signoff_renewed_review_url, {
            preserveScroll: true,
            onSuccess: () => {
                setSignoffOpen(false);
                announce(
                    'The discharge-summary correction was signed off by medical records.',
                );
            },
            onError: () => {
                setSignoffOpen(false);
                fail();
            },
        });
    };

    return (
        <li>
            <article className="relative overflow-hidden rounded-xl border border-border bg-card">
                <div
                    aria-hidden="true"
                    className="absolute top-0 bottom-0 left-0 w-1 bg-primary/60"
                />
                <header className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-4 py-3 pl-5">
                    <div>
                        <h3 className="text-sm font-semibold">
                            Discharge-Summary Correction
                        </h3>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Submitted by {request.requester_name ?? '—'} ·{' '}
                            {formatDate(request.requested_at)}
                        </p>
                    </div>
                    <span className="rounded-full bg-secondary px-2.5 py-1 text-xs font-semibold text-secondary-foreground">
                        {requestStateLabel[request.state]}
                    </span>
                </header>

                <div className="space-y-5 p-4 pl-5">
                    <ErrorSummary errors={errors} attempt={attempt} />
                    <BaselineBinding request={request} />

                    <section aria-labelledby={`${id}-request`}>
                        <h4
                            id={`${id}-request`}
                            className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            1 · Physician request
                        </h4>
                        <p className="mt-2 text-sm font-semibold">
                            {request.reason_label}
                        </p>
                        {request.note ? (
                            <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                {request.note}
                            </p>
                        ) : null}
                    </section>

                    <section
                        className="border-t border-border pt-4"
                        aria-labelledby={`${id}-decision`}
                    >
                        <h4
                            id={`${id}-decision`}
                            className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            2 · Second physician decision
                        </h4>
                        {request.permissions.can_decide &&
                        request.actions.decision_url ? (
                            <form onSubmit={decide} className="mt-3 space-y-3">
                                <div className="grid gap-1.5 sm:max-w-sm">
                                    <Label htmlFor={`${id}-decision-value`}>
                                        Decision
                                    </Label>
                                    <select
                                        id={`${id}-decision-value`}
                                        value={decisionForm.data.decision}
                                        onChange={(event) =>
                                            decisionForm.setData({
                                                ...decisionForm.data,
                                                decision: event.target.value as
                                                    'APPROVED' | 'DENIED',
                                                idempotency_key:
                                                    newOperationKey('decision'),
                                            })
                                        }
                                        className="min-h-11 rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                                    >
                                        <option value="APPROVED">
                                            Setujui
                                        </option>
                                        <option value="DENIED">Tolak</option>
                                    </select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`${id}-decision-note`}>
                                        Decision note
                                        {decisionForm.data.decision === 'DENIED'
                                            ? ' · required'
                                            : ' (opsional)'}
                                    </Label>
                                    <textarea
                                        id={`${id}-decision-note`}
                                        value={decisionForm.data.decision_note}
                                        required={
                                            decisionForm.data.decision ===
                                            'DENIED'
                                        }
                                        onChange={(event) =>
                                            decisionForm.setData({
                                                ...decisionForm.data,
                                                decision_note:
                                                    event.target.value,
                                                idempotency_key:
                                                    newOperationKey('decision'),
                                            })
                                        }
                                        className="min-h-20 rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={decisionForm.processing}
                                    className="min-h-11"
                                >
                                    Save decision
                                </Button>
                            </form>
                        ) : request.decided_at ? (
                            <div className="mt-2 text-sm">
                                <p>
                                    {request.state === 'DENIED'
                                        ? 'Denied'
                                        : 'Approved'}{' '}
                                    by {request.decider_name ?? '—'} ·{' '}
                                    {formatDate(request.decided_at)}
                                </p>
                                {request.decision_note ? (
                                    <p className="mt-1 whitespace-pre-wrap text-muted-foreground">
                                        {request.decision_note}
                                    </p>
                                ) : null}
                            </div>
                        ) : (
                            <p className="mt-2 text-sm text-muted-foreground">
                                Awaiting a decision from another authorized
                                physician.
                            </p>
                        )}
                    </section>

                    <section
                        className="border-t border-border pt-4"
                        aria-labelledby={`${id}-addendum`}
                    >
                        <h4
                            id={`${id}-addendum`}
                            className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            3 · Structured addendum
                        </h4>
                        {request.permissions.can_write_addendum &&
                        request.actions.save_addendum_url &&
                        !addendumFinal ? (
                            <form
                                onSubmit={saveDraft}
                                className="mt-3 space-y-3"
                            >
                                <p className="rounded-lg border border-primary/20 bg-primary/5 p-3 text-xs leading-5 text-muted-foreground">
                                    Complete only the sections requiring
                                    correction. Empty sections do not replace
                                    content in the original discharge summary.
                                </p>
                                {fieldDefinitions.map((field) => {
                                    const error =
                                        addendumForm.errors[
                                            `fields.${field.key}`
                                        ];

                                    return (
                                        <div
                                            key={field.key}
                                            className="grid gap-1.5"
                                        >
                                            <Label
                                                htmlFor={`${id}-${field.key}`}
                                            >
                                                {field.label}
                                            </Label>
                                            <textarea
                                                id={`${id}-${field.key}`}
                                                value={
                                                    addendumForm.data.fields[
                                                        field.key
                                                    ]
                                                }
                                                onChange={(event) =>
                                                    addendumForm.setData({
                                                        ...addendumForm.data,
                                                        fields: {
                                                            ...addendumForm.data
                                                                .fields,
                                                            [field.key]:
                                                                event.target
                                                                    .value,
                                                        },
                                                        idempotency_key:
                                                            newOperationKey(
                                                                'draft',
                                                            ),
                                                    })
                                                }
                                                aria-invalid={Boolean(error)}
                                                className="min-h-24 rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                                            />
                                            {error ? (
                                                <p className="text-xs text-destructive">
                                                    {error}
                                                </p>
                                            ) : null}
                                        </div>
                                    );
                                })}
                                <div className="flex flex-col gap-2 sm:flex-row">
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={addendumForm.processing}
                                        className="min-h-11"
                                    >
                                        Save addendum draft
                                    </Button>
                                    {request.permissions
                                        .can_finalize_addendum &&
                                    request.actions.finalize_addendum_url ? (
                                        <Button
                                            type="button"
                                            disabled={
                                                finalizeForm.processing ||
                                                addendumDirty
                                            }
                                            onClick={() =>
                                                setFinalizeOpen(true)
                                            }
                                            className="min-h-11"
                                        >
                                            Finalize Addendum
                                        </Button>
                                    ) : null}
                                </div>
                                {addendumDirty ? (
                                    <p className="text-xs text-warning">
                                        Save draft changes before setting Final.
                                    </p>
                                ) : null}
                            </form>
                        ) : request.addendum ? (
                            <div className="mt-3 space-y-3">
                                <p className="inline-flex items-center gap-1.5 text-sm font-semibold text-foreground">
                                    {addendumFinal ? (
                                        <CheckCircle2
                                            aria-hidden="true"
                                            className="size-4 text-success"
                                        />
                                    ) : (
                                        <FileClock
                                            aria-hidden="true"
                                            className="size-4 text-warning"
                                        />
                                    )}
                                    {addendumFinal ? 'Final' : 'Draft'} ·
                                    version {request.addendum.version}
                                </p>
                                <dl className="grid gap-3 md:grid-cols-2">
                                    {fieldDefinitions.map((field) => (
                                        <div
                                            key={field.key}
                                            className="rounded-lg border border-border bg-muted/20 p-3"
                                        >
                                            <dt className="text-xs font-semibold text-muted-foreground">
                                                {field.label}
                                            </dt>
                                            <dd className="mt-1 text-sm whitespace-pre-wrap">
                                                {request.addendum?.fields[
                                                    field.key
                                                ] ||
                                                    'No correction was entered for this section.'}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        ) : (
                            <p className="mt-2 text-sm text-muted-foreground">
                                The addendum is not yet available.
                            </p>
                        )}
                    </section>

                    <section
                        className="border-t border-border pt-4"
                        aria-labelledby={`${id}-rmik`}
                    >
                        <h4
                            id={`${id}-rmik`}
                            className="flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            <ShieldCheck
                                aria-hidden="true"
                                className="size-4"
                            />{' '}
                            4 · Medical-Record Correction Review
                        </h4>
                        {request.renewed_review ? (
                            <div className="mt-3 space-y-3">
                                <p
                                    className={cn(
                                        'text-sm font-semibold',
                                        reviewSignedOff
                                            ? 'text-success'
                                            : 'text-foreground',
                                    )}
                                >
                                    {reviewSignedOff
                                        ? 'Correction signed off'
                                        : `Review v${request.renewed_review.version}`}
                                </p>
                                <ul
                                    className="space-y-2"
                                    aria-label="Correction completeness checklist"
                                >
                                    {request.renewed_review.items.map(
                                        (item) => (
                                            <li
                                                key={item.item_code}
                                                className="flex items-start gap-2 rounded-lg border border-border p-3 text-sm"
                                            >
                                                <span
                                                    aria-hidden="true"
                                                    className={cn(
                                                        'mt-0.5 size-2.5 shrink-0 rounded-full',
                                                        item.is_complete
                                                            ? 'bg-success'
                                                            : 'bg-destructive',
                                                    )}
                                                />
                                                <span>
                                                    <span className="font-semibold">
                                                        {item.label}
                                                    </span>
                                                    {item.is_blocking &&
                                                    !item.is_complete ? (
                                                        <span className="mt-0.5 block text-xs text-destructive">
                                                            Required before
                                                            sign-off.
                                                        </span>
                                                    ) : null}
                                                </span>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        ) : (
                            <p className="mt-2 text-sm text-muted-foreground">
                                Medical-record review is available after the
                                addendum is final.
                            </p>
                        )}
                        <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                            {request.permissions.can_save_renewed_review &&
                            request.actions.save_renewed_review_url ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={saveReview}
                                    disabled={reviewForm.processing}
                                    className="min-h-11"
                                >
                                    Save correction-review result
                                </Button>
                            ) : null}
                            {request.permissions.can_signoff_renewed_review &&
                            request.actions.signoff_renewed_review_url ? (
                                <Button
                                    type="button"
                                    onClick={() => setSignoffOpen(true)}
                                    disabled={
                                        signoffForm.processing ||
                                        incompleteItems.length > 0
                                    }
                                    className="min-h-11"
                                >
                                    Sign Off Medical-Record Correction
                                </Button>
                            ) : null}
                        </div>
                    </section>
                </div>
            </article>

            <Dialog open={finalizeOpen} onOpenChange={setFinalizeOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Finalize the addendum?</DialogTitle>
                        <DialogDescription>
                            After finalization, the addendum cannot be edited.
                            The original discharge summary remains unchanged.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                            >
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            onClick={finalize}
                            className="min-h-11"
                        >
                            Yes, finalize
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={signoffOpen} onOpenChange={setSignoffOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Sign off the medical-record correction?
                        </DialogTitle>
                        <DialogDescription>
                            Sign-off validates the correction package without
                            reopening the episode or changing closure evidence
                            previously.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                            >
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            onClick={signoff}
                            className="min-h-11"
                        >
                            Yes, sign off correction
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </li>
    );
}

export function InpatientDischargeSummaryAddendumPanel({
    projection,
    onDirtyChange,
}: {
    projection: InpatientSummaryAddendumProjection;
    onDirtyChange?: (dirty: boolean) => void;
}) {
    const [announcement, setAnnouncement] = useState('');

    if (!projection.available && projection.requests.length === 0) {
        return null;
    }

    return (
        <section
            aria-labelledby="inpatient-summary-addendum-title"
            className="space-y-4"
        >
            <div aria-live="polite" className="sr-only">
                {announcement}
            </div>
            <div className="rounded-xl border border-primary/20 bg-primary/5 p-4">
                <p className="text-[0.68rem] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                    Controlled correction after closure
                </p>
                <h2
                    id="inpatient-summary-addendum-title"
                    className="mt-1 text-lg font-semibold"
                >
                    Discharge-Summary Addendum
                </h2>
                <p className="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground">
                    Corrections are recorded as a new traceable sequence. The
                    final document, coding, and previous sign-off remain intact.
                </p>
                {projection.can_request && projection.store_url ? (
                    <RequestForm
                        projection={projection}
                        announce={setAnnouncement}
                    />
                ) : null}
            </div>
            {projection.requests.length > 0 ? (
                <ol
                    className="space-y-4"
                    aria-label="Discharge-summary correction history"
                >
                    {projection.requests.map((request) => (
                        <RequestChain
                            key={`${request.public_id}-${request.version}-${request.addendum?.version ?? 0}-${request.renewed_review?.version ?? 0}`}
                            request={request}
                            announce={setAnnouncement}
                            onDirtyChange={onDirtyChange}
                        />
                    ))}
                </ol>
            ) : (
                <p className="rounded-xl border border-dashed border-border bg-card p-5 text-center text-sm text-muted-foreground">
                    No discharge-summary corrections exist for this episode.
                </p>
            )}
        </section>
    );
}
