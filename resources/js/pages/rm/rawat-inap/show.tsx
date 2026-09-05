import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CareSettingSubnav } from '@/components/care-setting-subnav';
import { InpatientDischargeSummaryAddendumPanel } from '@/components/clinical/inpatient/inpatient-discharge-summary-addendum-panel';
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
import { Input } from '@/components/ui/input';
import type { BreadcrumbItem } from '@/types';
import type {
    CodingAssignment,
    CodingSourceStatement,
    InpatientRmDetail,
} from './types';
import { newInpatientRmIdempotencyKey, reviewStatusLabel } from './types';

type Props = { inpatient_rm: InpatientRmDetail };
function formattedDate(value: string | null) {
    return value ? new Date(value).toLocaleString('en-GB') : '—';
}

function assignmentLabel(kind: CodingAssignment['kind']) {
    if (kind === 'PRINCIPAL_DIAGNOSIS') {
        return 'Primary diagnosis';
    }

    if (kind === 'SECONDARY_DIAGNOSIS') {
        return 'Secondary diagnosis';
    }

    return 'Procedures';
}

function useUnsavedRmikGuard(shouldWarn: boolean) {
    useEffect(() => {
        if (!shouldWarn) {
            return;
        }

        const warning =
            'There are unsaved medical-record changes. Leave this page and discard them?';
        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        const remove = router.on('before', (event) => {
            if (
                event.detail.visit.method.toLowerCase() === 'get' &&
                !window.confirm(warning)
            ) {
                event.preventDefault();
            }
        });
        window.addEventListener('beforeunload', onBeforeUnload);

        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            remove();
        };
    }, [shouldWarn]);
}

export default function RmRawatInapShow({ inpatient_rm: data }: Props) {
    const { flash } = usePage().props;
    const [confirmationOpen, setConfirmationOpen] = useState(false);
    const [codingSaved, setCodingSaved] = useState(data.coding.assignments);
    const errorSummaryRef = useRef<HTMLDivElement>(null);
    const codingForm = useForm({
        expected_version: data.coding.version,
        idempotency_key: newInpatientRmIdempotencyKey('coding-draft'),
        assignments: data.coding.assignments,
    });
    const reviewForm = useForm({
        expected_version: data.completeness.version,
        source_fingerprint: data.completeness.source_fingerprint,
        coding_version: data.coding.version,
        coding_digest: data.coding.content_digest,
        idempotency_key: newInpatientRmIdempotencyKey('review'),
    });
    const signoffForm = useForm({
        expected_version: data.completeness.version,
        source_fingerprint: data.completeness.source_fingerprint,
        coding_version: data.coding.version,
        coding_digest: data.coding.content_digest,
        idempotency_key: newInpatientRmIdempotencyKey('signoff'),
    });
    const codingDirty =
        JSON.stringify(codingForm.data.assignments) !==
        JSON.stringify(codingSaved);
    const serverCodingBinding = `${data.coding.version}:${data.coding.content_digest}`;
    const serverReviewBinding = [
        data.completeness.version,
        data.completeness.source_fingerprint,
        data.coding.version,
        data.coding.content_digest,
    ].join(':');
    const lastCodingBinding = useRef(serverCodingBinding);
    const lastReviewBinding = useRef(serverReviewBinding);
    const latestData = useRef(data);
    const codingFormRef = useRef(codingForm);
    const reviewFormRef = useRef(reviewForm);
    const signoffFormRef = useRef(signoffForm);

    useEffect(() => {
        latestData.current = data;
        codingFormRef.current = codingForm;
        reviewFormRef.current = reviewForm;
        signoffFormRef.current = signoffForm;
    }, [codingForm, data, reviewForm, signoffForm]);

    useEffect(() => {
        if (lastCodingBinding.current === serverCodingBinding || codingDirty) {
            return;
        }

        const current = latestData.current;
        lastCodingBinding.current = serverCodingBinding;
        setCodingSaved(current.coding.assignments);
        codingFormRef.current.setData({
            expected_version: current.coding.version,
            idempotency_key: newInpatientRmIdempotencyKey('coding-draft'),
            assignments: current.coding.assignments,
        });
    }, [codingDirty, serverCodingBinding]);

    useEffect(() => {
        if (lastReviewBinding.current === serverReviewBinding) {
            return;
        }

        const current = latestData.current;
        lastReviewBinding.current = serverReviewBinding;
        const binding = {
            expected_version: current.completeness.version,
            source_fingerprint: current.completeness.source_fingerprint,
            coding_version: current.coding.version,
            coding_digest: current.coding.content_digest,
        };
        reviewFormRef.current.setData({
            ...binding,
            idempotency_key: newInpatientRmIdempotencyKey('review'),
        });
        signoffFormRef.current.setData({
            ...binding,
            idempotency_key: newInpatientRmIdempotencyKey('signoff'),
        });
    }, [serverReviewBinding]);
    const processing =
        codingForm.processing ||
        reviewForm.processing ||
        signoffForm.processing;
    const closed =
        data.completeness.status === 'SIGNED_OFF' ||
        data.encounter.status === 'CLOSED';
    const principalAssigned = codingForm.data.assignments.some(
        (item) => item.kind === 'PRINCIPAL_DIAGNOSIS' && item.code.trim(),
    );
    const allSourcesAssigned = data.coding.source_statements.every((source) =>
        codingForm.data.assignments.some(
            (assignment) =>
                assignment.source_statement_kind === source.kind &&
                assignment.source_statement_index === source.index &&
                assignment.source_statement_text_hash === source.text_hash &&
                assignment.code.trim() &&
                assignment.description?.trim(),
        ),
    );
    const hasFailedReview = data.completeness.items.some(
        (item) => item.status === 'FAIL',
    );
    const canSignoff =
        !closed &&
        data.completeness.can_signoff &&
        !processing &&
        !codingDirty &&
        !hasFailedReview &&
        data.completeness.blockers.length === 0 &&
        principalAssigned &&
        allSourcesAssigned;
    useUnsavedRmikGuard(codingDirty);

    const combinedErrors = useMemo(
        () =>
            Object.values({
                ...codingForm.errors,
                ...reviewForm.errors,
                ...signoffForm.errors,
            }).filter(Boolean),
        [codingForm.errors, reviewForm.errors, signoffForm.errors],
    );
    useEffect(() => {
        if (combinedErrors.length) {
            errorSummaryRef.current?.focus();
        }
    }, [combinedErrors.length]);

    const updateAssignments = (
        updater: (items: CodingAssignment[]) => CodingAssignment[],
    ) => {
        if (closed || processing) {
            return;
        }

        codingForm.setData((current) => ({
            ...current,
            assignments: updater(current.assignments),
            idempotency_key: newInpatientRmIdempotencyKey('coding-draft'),
        }));
    };
    const saveCoding = () => {
        if (
            !data.actions.save_coding_draft_url ||
            !data.coding.can_save_draft ||
            processing ||
            closed
        ) {
            return;
        }

        if (!principalAssigned) {
            errorSummaryRef.current?.focus();

            return;
        }

        codingForm.post(data.actions.save_coding_draft_url, {
            preserveScroll: true,
            onSuccess: () => {
                setCodingSaved(codingForm.data.assignments);
                codingForm.setData((current) => ({
                    ...current,
                    idempotency_key:
                        newInpatientRmIdempotencyKey('coding-draft'),
                }));
            },
        });
    };
    const saveReview = () => {
        if (
            !data.actions.save_review_url ||
            !data.completeness.can_save_review ||
            processing ||
            closed
        ) {
            return;
        }

        reviewForm.post(data.actions.save_review_url, {
            preserveScroll: true,
            onSuccess: () => {
                reviewForm.setData((current) => ({
                    ...current,
                    idempotency_key: newInpatientRmIdempotencyKey('review'),
                }));
            },
        });
    };
    const signoff = () => {
        if (!data.actions.signoff_url || !canSignoff) {
            return;
        }

        signoffForm.post(data.actions.signoff_url, {
            preserveScroll: true,
            onSuccess: () => {
                setConfirmationOpen(false);
                signoffForm.setData((current) => ({
                    ...current,
                    idempotency_key: newInpatientRmIdempotencyKey('signoff'),
                }));
            },
        });
    };

    return (
        <>
            <Head
                title={`Review Inpatient Medical Record — ${data.patient.full_name ?? 'Episode'}`}
            />
            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-3 px-3 py-4 md:px-5">
                <CareSettingSubnav
                    items={[
                        { href: '/rm/rawat-jalan', label: 'Outpatient Care' },
                        {
                            href: '/rm/rawat-inap',
                            label: 'Inpatient Care',
                            active: true,
                        },
                    ]}
                />
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
                        aria-live="polite"
                        className="rounded-md border border-success/30 bg-success/5 p-3 text-sm text-success"
                    >
                        {flash.success}
                    </div>
                ) : null}
                <Link
                    href="/rm/rawat-inap"
                    className="min-h-11 self-start py-2 text-sm font-medium text-primary hover:underline"
                >
                    ← Back to inpatient worklist
                </Link>

                <header className="rounded-lg border border-border bg-card p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 className="text-xl font-semibold">
                                Inpatient medical-record review ·{' '}
                                {data.patient.full_name ??
                                    'Patient name unavailable'}
                            </h1>
                            <p className="mt-1 font-mono text-xs text-muted-foreground">
                                {data.patient.medical_record_number ?? '—'} ·{' '}
                                {data.encounter.public_id}
                            </p>
                        </div>
                        <span className="rounded-full bg-secondary px-3 py-1 text-xs font-semibold text-secondary-foreground">
                            {closed
                                ? 'Episode closed by Medical Records'
                                : reviewStatusLabel[data.completeness.status]}
                        </span>
                    </div>
                    <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                        <Meta
                            label="Discharge date"
                            value={formattedDate(
                                data.discharge?.discharged_at ??
                                    data.encounter.discharged_at,
                            )}
                        />
                        <Meta
                            label="Last ward"
                            value={data.encounter.last_ward_name ?? '—'}
                        />
                        <Meta
                            label="Payer"
                            value={data.encounter.payer_type ?? '—'}
                        />
                    </dl>
                </header>

                {combinedErrors.length ||
                (!allSourcesAssigned && codingDirty) ? (
                    <div
                        ref={errorSummaryRef}
                        role="alert"
                        tabIndex={-1}
                        className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive outline-none focus-visible:ring-2 focus-visible:ring-destructive"
                    >
                        <p className="font-semibold">
                            Review the data before continuing.
                        </p>
                        <ul className="mt-1 list-disc pl-5 text-xs">
                            {!allSourcesAssigned && codingDirty ? (
                                <li>
                                    Every source statement needs a code and code
                                    description before the coding draft is
                                    saved.
                                </li>
                            ) : null}
                            {combinedErrors.map((error) => (
                                <li key={error}>{error}</li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                <section
                    aria-labelledby="rmik-final-discharge-summary-title"
                    className="rounded-lg border border-border bg-card p-4"
                >
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p className="text-[0.68rem] font-semibold tracking-wide text-muted-foreground uppercase">
                                Read-only clinical evidence
                            </p>
                            <h2
                                id="rmik-final-discharge-summary-title"
                                className="mt-0.5 font-semibold"
                            >
                                Final discharge summary
                            </h2>
                        </div>
                        <span className="font-mono text-xs text-muted-foreground">
                            Final v{data.discharge_summary?.version ?? '—'}
                        </span>
                    </div>
                    <SummaryFields
                        fields={data.discharge_summary?.fields ?? {}}
                    />
                </section>

                <InpatientDischargeSummaryAddendumPanel
                    projection={data.summary_addendum}
                />

                <aside
                    aria-label="Read-only clinical source"
                    className="rounded-lg border border-sidebar-border bg-sidebar px-4 py-3 text-sidebar-foreground shadow-sm"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p className="text-[0.68rem] font-semibold tracking-wide uppercase opacity-70">
                                Read-only clinical source
                            </p>
                            <p className="text-sm">
                                Source data cannot be changed from the
                                medical-record workspace.
                            </p>
                        </div>
                        <p className="font-mono text-xs">
                            Summary v{data.discharge_summary?.version ?? '—'} ·
                            Diagnoses/procedures v
                            {data.coding_source?.version ?? '—'}
                        </p>
                    </div>
                    <div className="mt-3 grid gap-3 text-sm lg:grid-cols-3">
                        <SourceCard
                            title="Primary diagnosis"
                            value={
                                data.coding_source
                                    ?.principal_diagnosis_statement ??
                                'Unavailable'
                            }
                        />
                        <SourceList
                            title="Secondary diagnoses"
                            values={
                                data.coding_source
                                    ?.secondary_diagnosis_statements ?? []
                            }
                        />
                        <SourceList
                            title="Clinical procedures"
                            values={
                                data.coding_source
                                    ?.performed_procedure_statements ?? []
                            }
                        />
                    </div>
                </aside>

                <div className="grid gap-3 xl:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)]">
                    <section
                        aria-labelledby="coding-title"
                        className="rounded-lg border border-border bg-card p-4"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h2 id="coding-title" className="font-semibold">
                                    Medical-record coding
                                </h2>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Enter codes manually using the displayed
                                    clinical source. This field is not a code
                                    catalogue search.
                                </p>
                            </div>
                            <span className="font-mono text-xs text-muted-foreground">
                                {data.coding.state === 'FINAL'
                                    ? 'Final'
                                    : 'Draft'}{' '}
                                v{data.coding.version}
                            </span>
                        </div>
                        <CodingGroup
                            label="Primary diagnosis"
                            kind="PRINCIPAL_DIAGNOSIS"
                            sources={data.coding.source_statements}
                            assignments={codingForm.data.assignments}
                            disabled={
                                closed ||
                                processing ||
                                !data.coding.can_save_draft
                            }
                            onUpdate={updateAssignments}
                            required
                        />
                        <CodingGroup
                            label="Secondary diagnoses"
                            kind="SECONDARY_DIAGNOSIS"
                            sources={data.coding.source_statements}
                            assignments={codingForm.data.assignments}
                            disabled={
                                closed ||
                                processing ||
                                !data.coding.can_save_draft
                            }
                            onUpdate={updateAssignments}
                        />
                        <CodingGroup
                            label="Procedures"
                            kind="PROCEDURE"
                            sources={data.coding.source_statements}
                            assignments={codingForm.data.assignments}
                            disabled={
                                closed ||
                                processing ||
                                !data.coding.can_save_draft
                            }
                            onUpdate={updateAssignments}
                        />
                        {closed ? (
                            <p
                                role="status"
                                className="mt-4 text-sm font-medium text-success"
                            >
                                The episode is closed by medical records. Coding
                                and its history are read-only.
                            </p>
                        ) : (
                            <div className="mt-5 flex flex-wrap items-center gap-2 border-t border-border pt-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={
                                        !data.coding.can_save_draft ||
                                        processing ||
                                        !codingDirty ||
                                        !principalAssigned
                                    }
                                    onClick={saveCoding}
                                >
                                    {codingForm.processing
                                        ? 'Saving…'
                                        : 'Save coding draft'}
                                </Button>
                                {codingDirty ? (
                                    <p
                                        role="status"
                                        className="text-xs text-warning"
                                    >
                                        Coding changes are not saved yet.
                                    </p>
                                ) : null}
                            </div>
                        )}
                    </section>

                    <section
                        aria-labelledby="review-title"
                        className="rounded-lg border border-border bg-card p-4"
                    >
                        <h2 id="review-title" className="font-semibold">
                            Completeness review
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Completeness is calculated from source facts
                            currently bound to the episode.
                        </p>
                        <div className="mt-3 space-y-2">
                            {data.completeness.items.map((item) => (
                                <ReadOnlyReviewItem
                                    key={item.code}
                                    item={item}
                                />
                            ))}
                        </div>
                        {data.completeness.blockers.length ? (
                            <div className="mt-4 rounded-md border border-warning/30 bg-warning/5 p-3">
                                <p className="text-sm font-semibold text-warning">
                                    Closure blockers
                                </p>
                                <ul className="mt-1 list-disc pl-5 text-xs text-muted-foreground">
                                    {data.completeness.blockers.map(
                                        (blocker) => (
                                            <li key={blocker.code}>
                                                {blocker.label}:{' '}
                                                {blocker.reason}
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        ) : null}
                        {closed ? (
                            <p
                                role="status"
                                className="mt-4 text-sm font-medium text-success"
                            >
                                The episode is closed by medical records.
                            </p>
                        ) : (
                            <div className="mt-4 flex flex-wrap gap-2 border-t border-border pt-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={
                                        !data.completeness.can_save_review ||
                                        processing
                                    }
                                    onClick={saveReview}
                                >
                                    {reviewForm.processing
                                        ? 'Saving…'
                                        : 'Save review result'}
                                </Button>
                                <Button
                                    type="button"
                                    disabled={!canSignoff}
                                    aria-describedby="signoff-help"
                                    onClick={() => setConfirmationOpen(true)}
                                >
                                    Medical-record sign-off and close episode
                                </Button>
                            </div>
                        )}
                        {!closed && !canSignoff ? (
                            <p
                                id="signoff-help"
                                className="mt-2 text-xs text-muted-foreground"
                            >
                                Sign-off is available after coding is saved,
                                every source statement has a code and
                                description, all completeness facts are aligned,
                                and there are no blockers.
                            </p>
                        ) : null}
                    </section>
                </div>

                <section
                    aria-labelledby="history-title"
                    className="rounded-lg border border-border bg-card p-4"
                >
                    <h2 id="history-title" className="font-semibold">
                        Immutable history
                    </h2>
                    <div className="mt-3 grid gap-3 lg:grid-cols-2">
                        <HistoryList
                            title="Coding history"
                            entries={data.coding.history.map((entry) => ({
                                event: `Coding v${entry.version}`,
                                actor_name: entry.actor_name,
                                occurred_at: entry.created_at,
                                detail:
                                    entry.assignments
                                        .map(
                                            (item) =>
                                                `${assignmentLabel(item.kind)}: ${item.code}`,
                                        )
                                        .join(' · ') || 'No codes yet',
                            }))}
                        />
                        <HistoryList
                            title="Episode history"
                            entries={data.history}
                        />
                    </div>
                </section>
            </div>
            {!closed ? (
                <Dialog
                    open={confirmationOpen}
                    onOpenChange={setConfirmationOpen}
                >
                    <DialogContent showCloseButton={false}>
                        <DialogHeader>
                            <DialogTitle>
                                Confirm medical-record closure of this episode
                            </DialogTitle>
                            <DialogDescription>
                                The system will recheck the review version,
                                coding, source, and blockers before closing the
                                episode. Once completed, all history is
                                read-only.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button
                                type="button"
                                disabled={signoffForm.processing}
                                onClick={signoff}
                            >
                                {signoffForm.processing
                                    ? 'Processing…'
                                    : 'Yes, sign off and close episode'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            ) : null}
        </>
    );
}

function Meta({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 font-semibold">{value}</dd>
        </div>
    );
}

function SummaryFields({ fields }: { fields: Record<string, string | null> }) {
    const definitions = [
        ['admission_reason', 'Reason for admission'],
        ['significant_findings', 'Temuan penting'],
        ['care_and_treatment_summary', 'Care and treatment summary'],
        ['condition_at_discharge', 'Kondisi saat pulang'],
        ['follow_up_plan', 'Follow-up plan'],
    ] as const;

    return (
        <dl className="mt-3 grid gap-3 md:grid-cols-2">
            {definitions.map(([key, label]) => (
                <div
                    key={key}
                    className="rounded-md border border-border bg-muted/20 p-3"
                >
                    <dt className="text-xs font-semibold text-muted-foreground">
                        {label}
                    </dt>
                    <dd className="mt-1 text-sm whitespace-pre-wrap">
                        {fields[key] || '—'}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

function SourceCard({ title, value }: { title: string; value: string }) {
    return (
        <div className="rounded-md bg-black/10 p-3">
            <p className="text-xs font-semibold">{title}</p>
            <p className="mt-1 text-sm opacity-90">{value}</p>
        </div>
    );
}
function SourceList({ title, values }: { title: string; values: string[] }) {
    return (
        <div className="rounded-md bg-black/10 p-3">
            <p className="text-xs font-semibold">{title}</p>
            {values.length ? (
                <ul className="mt-1 list-disc pl-4 text-sm opacity-90">
                    {values.map((value, index) => (
                        <li key={`${value}-${index}`}>{value}</li>
                    ))}
                </ul>
            ) : (
                <p className="mt-1 text-sm opacity-70">Nothing recorded</p>
            )}
        </div>
    );
}

function CodingGroup({
    label,
    kind,
    sources,
    assignments,
    disabled,
    onUpdate,
    required = false,
}: {
    label: string;
    kind: CodingAssignment['kind'];
    sources: CodingSourceStatement[];
    assignments: CodingAssignment[];
    disabled: boolean;
    onUpdate: (
        updater: (items: CodingAssignment[]) => CodingAssignment[],
    ) => void;
    required?: boolean;
}) {
    const entries = sources.filter((source) => source.kind === kind);

    return (
        <fieldset className="mt-5">
            <legend className="text-sm font-medium">
                {label}
                {required ? (
                    <span className="ml-1 text-destructive">*</span>
                ) : null}
                <span className="ml-1 text-xs font-normal text-muted-foreground">
                    · {kind === 'PROCEDURE' ? 'ICD-9-CM' : 'ICD-10'}
                </span>
            </legend>
            <p className="mt-1 text-xs text-muted-foreground">
                Every code is bound to one immutable source statement.
            </p>
            <div className="mt-2 space-y-2">
                {entries.map((source) => {
                    const assignmentIndex = assignments.findIndex(
                        (item) =>
                            item.source_statement_kind === source.kind &&
                            item.source_statement_index === source.index &&
                            item.source_statement_text_hash ===
                                source.text_hash,
                    );
                    const assignment =
                        assignmentIndex >= 0
                            ? assignments[assignmentIndex]
                            : null;

                    return (
                        <div
                            key={`${source.kind}-${source.index}-${source.text_hash}`}
                            className="grid gap-2 rounded-md border border-border p-2 sm:grid-cols-[minmax(0,0.38fr)_minmax(0,0.3fr)_minmax(0,0.32fr)]"
                        >
                            <div className="rounded bg-muted/50 p-2 text-xs">
                                <p className="font-semibold">
                                    Source statement {source.index + 1}
                                </p>
                                <p className="mt-1 text-muted-foreground">
                                    {source.text}
                                </p>
                            </div>
                            <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                                Enter code
                                <Input
                                    value={assignment?.code ?? ''}
                                    disabled={disabled}
                                    maxLength={20}
                                    placeholder={
                                        kind === 'PROCEDURE'
                                            ? 'Example: 89.52'
                                            : 'Example: A09'
                                    }
                                    onChange={(event) =>
                                        onUpdate((items) => {
                                            const next = assignment ?? {
                                                kind: source.kind,
                                                source_statement_kind:
                                                    source.kind,
                                                source_statement_index:
                                                    source.index,
                                                source_statement_text_hash:
                                                    source.text_hash,
                                                source_statement: source.text,
                                                code: '',
                                                description: '',
                                            };

                                            return assignmentIndex >= 0
                                                ? items.map(
                                                      (
                                                          current,
                                                          currentIndex,
                                                      ) =>
                                                          currentIndex ===
                                                          assignmentIndex
                                                              ? {
                                                                    ...current,
                                                                    code: event
                                                                        .target
                                                                        .value,
                                                                }
                                                              : current,
                                                  )
                                                : [
                                                      ...items,
                                                      {
                                                          ...next,
                                                          code: event.target
                                                              .value,
                                                      },
                                                  ];
                                        })
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                                Code description
                                <Input
                                    value={assignment?.description ?? ''}
                                    disabled={disabled}
                                    maxLength={255}
                                    placeholder="Manual description"
                                    onChange={(event) =>
                                        onUpdate((items) => {
                                            const next = assignment ?? {
                                                kind: source.kind,
                                                source_statement_kind:
                                                    source.kind,
                                                source_statement_index:
                                                    source.index,
                                                source_statement_text_hash:
                                                    source.text_hash,
                                                source_statement: source.text,
                                                code: '',
                                                description: '',
                                            };

                                            return assignmentIndex >= 0
                                                ? items.map(
                                                      (
                                                          current,
                                                          currentIndex,
                                                      ) =>
                                                          currentIndex ===
                                                          assignmentIndex
                                                              ? {
                                                                    ...current,
                                                                    description:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                }
                                                              : current,
                                                  )
                                                : [
                                                      ...items,
                                                      {
                                                          ...next,
                                                          description:
                                                              event.target
                                                                  .value,
                                                      },
                                                  ];
                                        })
                                    }
                                />
                            </label>
                        </div>
                    );
                })}
            </div>
            {!entries.length ? (
                <p className="mt-2 text-xs text-muted-foreground">
                    No source statement for this category.
                </p>
            ) : null}
        </fieldset>
    );
}

function ReadOnlyReviewItem({
    item,
}: {
    item: InpatientRmDetail['completeness']['items'][number];
}) {
    return (
        <div className="rounded-md border border-border p-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="text-sm font-medium">{item.label}</p>
                    <p className="font-mono text-xs text-muted-foreground">
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
                          ? 'Not aligned'
                          : 'Not applicable'}
                </span>
            </div>
            {item.reason ? (
                <p className="mt-2 text-xs text-muted-foreground">
                    {item.reason}
                </p>
            ) : null}
        </div>
    );
}
function HistoryList({
    title,
    entries,
}: {
    title: string;
    entries: InpatientRmDetail['history'];
}) {
    return (
        <div>
            <h3 className="text-sm font-medium">{title}</h3>
            {entries.length ? (
                <ol className="mt-2 space-y-2 border-l border-border pl-3">
                    {entries.map((entry, index) => (
                        <li
                            key={`${entry.event}-${entry.occurred_at}-${index}`}
                            className="text-sm"
                        >
                            <p className="font-medium">{entry.event}</p>
                            {entry.detail ? (
                                <p className="text-xs text-muted-foreground">
                                    {entry.detail}
                                </p>
                            ) : null}
                            <p className="text-xs text-muted-foreground">
                                {entry.actor_name ?? 'Sistem'} ·{' '}
                                {formattedDate(entry.occurred_at)}
                            </p>
                        </li>
                    ))}
                </ol>
            ) : (
                <p className="mt-2 text-sm text-muted-foreground">
                    No history yet.
                </p>
            )}
        </div>
    );
}

RmRawatInapShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Home', href: '/' },
        { title: 'Medical Records', href: '/rm/rawat-jalan' },
        { title: 'Inpatient Care', href: '/rm/rawat-inap' },
        {
            title: props.inpatient_rm.patient.full_name ?? 'Telaah',
            href: `/rm/rawat-inap/${props.inpatient_rm.encounter.public_id}`,
        },
    ] satisfies BreadcrumbItem[],
});
