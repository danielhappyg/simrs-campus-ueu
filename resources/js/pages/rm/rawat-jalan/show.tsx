import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ClinicalDocumentHistory } from '@/components/clinical/outpatient/clinical-document-history';
import { PostClosureAmendmentPanel } from '@/components/clinical/outpatient/post-closure-amendment-panel';
import type {
    AmendmentReasonOption,
    ClinicalDocument,
    ClinicalDocumentVersion,
    OutpatientAmendment,
    OutpatientEncounter,
} from '@/components/clinical/outpatient/types';
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
import type { BreadcrumbItem } from '@/types';

type ChecklistItem = {
    code: string;
    label: string;
    status: 'PASS' | 'FAIL' | 'NOT_APPLICABLE';
    reason: string | null;
};

type Blocker = {
    code: string;
    label: string;
    reason: string;
};

type Props = {
    encounter: OutpatientEncounter;
    clinicalSources: ClinicalDocument[];
    documentVersions: ClinicalDocumentVersion[];
    review: {
        public_id: string | null;
        status: 'NOT_REVIEWED' | 'INCOMPLETE' | 'COMPLETE' | 'SIGNED_OFF';
        version: number;
        source_fingerprint: string;
        checklist_items: ChecklistItem[];
        reviewer_name: string | null;
        reviewed_at: string | null;
        signed_off_by_name: string | null;
        signed_off_at: string | null;
    };
    blockers: Blocker[];
    permissions: {
        can_save_review: boolean;
        can_signoff: boolean;
        can_request_amendment?: boolean;
    };
    actions: {
        save_review_url: string;
        signoff_url: string;
        store_amendment_url?: string | null;
    };
    amendmentReasonOptions?: AmendmentReasonOption[];
    amendments?: OutpatientAmendment[];
};

const reviewStatusLabel: Record<string, string> = {
    NOT_REVIEWED: 'Not reviewed',
    INCOMPLETE: 'Incomplete',
    COMPLETE: 'Complete, ready for sign-off',
    SIGNED_OFF: 'Signed off',
};

export default function RmRawatJalanShow({
    encounter,
    clinicalSources,
    documentVersions,
    review,
    blockers,
    permissions,
    actions,
    amendmentReasonOptions = [],
    amendments = [],
}: Props) {
    const { flash } = usePage().props;
    const [dialogOpen, setDialogOpen] = useState(false);
    const reviewForm = useForm({
        expected_version: review.version,
        source_fingerprint: review.source_fingerprint,
    });
    const signoffForm = useForm({
        expected_version: review.version,
        source_fingerprint: review.source_fingerprint,
    });
    const failedItems = review.checklist_items.filter(
        (item) => item.status === 'FAIL',
    );
    const closed =
        encounter.status === 'CLOSED' || review.status === 'SIGNED_OFF';
    const canSignoff =
        !closed &&
        permissions.can_signoff &&
        review.version > 0 &&
        review.status === 'COMPLETE' &&
        blockers.length === 0 &&
        failedItems.length === 0;

    const saveReview = () => {
        reviewForm.setData({
            expected_version: review.version,
            source_fingerprint: review.source_fingerprint,
        });
        reviewForm.post(actions.save_review_url, { preserveScroll: true });
    };

    const signoff = () => {
        signoffForm.setData({
            expected_version: review.version,
            source_fingerprint: review.source_fingerprint,
        });
        signoffForm.post(actions.signoff_url, {
            preserveScroll: true,
            onSuccess: () => setDialogOpen(false),
        });
    };

    return (
        <>
            <Head
                title={`Review medical record — ${encounter.patient.full_name ?? 'Encounter'}`}
            />
            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-3 px-3 py-4 md:px-5">
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
                        className="rounded-md border border-success/30 bg-success/5 p-3 text-sm text-success"
                    >
                        {flash.success}
                    </div>
                ) : null}

                <Link
                    href="/rm/rawat-jalan"
                    className="min-h-11 self-start py-2 text-sm font-medium text-primary hover:underline"
                >
                    ← Back to medical records
                </Link>

                <header className="rounded-lg border border-border bg-card p-3 md:p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 className="text-xl font-semibold">
                                Review medical-record completeness ·{' '}
                                {encounter.patient.full_name ??
                                    'Patient name unavailable'}
                            </h1>
                            <p className="mt-1 font-mono text-xs text-muted-foreground">
                                {encounter.patient.medical_record_number ?? '—'}{' '}
                                · {encounter.public_id}
                            </p>
                        </div>
                        <span className="rounded-md bg-secondary px-2 py-1 text-xs font-semibold text-secondary-foreground">
                            {reviewStatusLabel[review.status] ?? review.status}
                        </span>
                    </div>
                    <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Clinic
                            </dt>
                            <dd className="font-semibold">
                                {encounter.clinic_name}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Physician
                            </dt>
                            <dd className="font-semibold">
                                {encounter.doctor_name ?? '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Visit date
                            </dt>
                            <dd className="font-semibold">
                                {encounter.visit_date ?? '—'}
                            </dd>
                        </div>
                    </dl>
                </header>

                <aside
                    aria-label="Source and review status"
                    className="sticky top-2 z-10 grid gap-2 rounded-lg border border-sidebar-border bg-sidebar px-3 py-2 text-sidebar-foreground shadow-sm sm:grid-cols-4"
                >
                    <div>
                        <p className="text-[0.65rem] uppercase opacity-70">
                            Clinical sources
                        </p>
                        <p className="text-xs font-semibold">
                            {clinicalSources.length} documents
                        </p>
                    </div>
                    <div>
                        <p className="text-[0.65rem] uppercase opacity-70">
                            Fingerprint
                        </p>
                        <p
                            className="truncate font-mono text-xs"
                            title={review.source_fingerprint}
                        >
                            {review.source_fingerprint}
                        </p>
                    </div>
                    <div>
                        <p className="text-[0.65rem] uppercase opacity-70">
                            Review version
                        </p>
                        <p className="font-mono text-xs">v{review.version}</p>
                    </div>
                    <div>
                        <p className="text-[0.65rem] uppercase opacity-70">
                            Blocker
                        </p>
                        <p className="text-xs font-semibold">
                            {blockers.length}
                        </p>
                    </div>
                </aside>

                <div className="grid gap-3 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                    <section className="space-y-2 rounded-lg border border-border bg-card p-3 md:p-4">
                        <div>
                            <h2 className="text-sm font-semibold text-secondary-foreground">
                                Read-only clinical source
                            </h2>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Medical-record staff assess completeness;
                                clinical content cannot be changed from this
                                workspace.
                            </p>
                        </div>
                        <ClinicalDocumentHistory
                            versions={documentVersions}
                            emptyMessage="No structured clinical source version history yet."
                        />
                    </section>

                    <div className="space-y-3">
                        <section className="rounded-lg border border-border bg-card p-3 md:p-4">
                            <h2 className="text-sm font-semibold text-secondary-foreground">
                                Automated checklist
                            </h2>
                            <ul className="mt-3 space-y-2">
                                {review.checklist_items.map((item) => (
                                    <li
                                        key={item.code}
                                        className="rounded-md border border-border bg-muted/40 p-3"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <p className="text-sm font-semibold">
                                                    {item.label}
                                                </p>
                                                <p className="mt-0.5 font-mono text-xs text-muted-foreground">
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
                                                    ? 'Aligned'
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
                                    </li>
                                ))}
                            </ul>
                        </section>

                        <section className="rounded-lg border border-border bg-card p-3 md:p-4">
                            <h2 className="text-sm font-semibold text-secondary-foreground">
                                Closure blockers
                            </h2>
                            {blockers.length ? (
                                <ul className="mt-3 space-y-2">
                                    {blockers.map((blocker) => (
                                        <li
                                            key={blocker.code}
                                            className="rounded-md border border-warning/30 bg-warning/5 p-3"
                                        >
                                            <p className="text-sm font-semibold text-warning">
                                                {blocker.label}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {blocker.reason}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p
                                    role="status"
                                    className="mt-3 text-sm text-success"
                                >
                                    No active lifecycle blockers.
                                </p>
                            )}
                        </section>

                        <section className="rounded-lg border border-border bg-card p-3 md:p-4">
                            <h2 className="text-sm font-semibold text-secondary-foreground">
                                Review actions
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Saving binds the checklist result to the
                                displayed source version. Source changes require
                                another review.
                            </p>
                            {closed ? (
                                <div
                                    role="status"
                                    className="mt-3 rounded-md border border-success/30 bg-success/5 p-3 text-sm text-success"
                                >
                                    The encounter is closed after sign-off. The
                                    review and every document version are
                                    displayed as read-only archive records.
                                </div>
                            ) : (
                                <div className="mt-3 flex flex-col gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={
                                            !permissions.can_save_review ||
                                            reviewForm.processing ||
                                            review.status === 'SIGNED_OFF'
                                        }
                                        onClick={saveReview}
                                        title={
                                            !permissions.can_save_review
                                                ? 'This account cannot save reviews.'
                                                : undefined
                                        }
                                    >
                                        Save review result
                                    </Button>
                                    <Button
                                        type="button"
                                        disabled={!canSignoff}
                                        onClick={() => setDialogOpen(true)}
                                        aria-describedby="rm-signoff-help"
                                        title={
                                            !canSignoff
                                                ? 'Sign-off is available after every item is aligned and there are no blockers.'
                                                : undefined
                                        }
                                        className="bg-primary text-primary-foreground hover:bg-primary/90"
                                    >
                                        Sign off and close encounter
                                    </Button>
                                </div>
                            )}
                            {!closed && !canSignoff ? (
                                <p
                                    id="rm-signoff-help"
                                    className="mt-2 text-xs text-muted-foreground"
                                >
                                    Save a complete review first; every item
                                    must be aligned and there must be no
                                    blockers.
                                </p>
                            ) : null}
                            {review.reviewer_name ? (
                                <p className="mt-3 text-xs text-muted-foreground">
                                    Reviewed by {review.reviewer_name}
                                    {review.reviewed_at
                                        ? ` · ${new Date(review.reviewed_at).toLocaleString('en-GB')}`
                                        : ''}
                                </p>
                            ) : null}
                            {review.signed_off_by_name ? (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Signed off by {review.signed_off_by_name}
                                    {review.signed_off_at
                                        ? ` · ${new Date(review.signed_off_at).toLocaleString('en-GB')}`
                                        : ''}
                                </p>
                            ) : null}
                        </section>
                    </div>
                </div>

                <PostClosureAmendmentPanel
                    encounterClosed={encounter.status === 'CLOSED'}
                    originalDocuments={clinicalSources}
                    reasonOptions={amendmentReasonOptions}
                    amendments={amendments}
                    canRequest={Boolean(permissions.can_request_amendment)}
                    storeUrl={actions.store_amendment_url ?? null}
                />
            </div>

            {!closed ? (
                <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                    <DialogContent
                        showCloseButton={false}
                        className="motion-reduce:animate-none motion-reduce:transition-none"
                    >
                        <DialogHeader>
                            <DialogTitle>
                                Confirm medical-record completeness sign-off
                            </DialogTitle>
                            <DialogDescription>
                                The system will recheck the source version,
                                checklist, access rights, and blockers. If every
                                check passes, the encounter will close. This is
                                not a legally binding electronic signature.
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
                                className="bg-primary text-primary-foreground hover:bg-primary/90"
                            >
                                Yes, sign off and close
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            ) : null}
        </>
    );
}

RmRawatJalanShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Home', href: '/' },
        { title: 'Medical Records', href: '/rm/rawat-jalan' },
        { title: 'Outpatient Care', href: '/rm/rawat-jalan' },
        {
            title: props.encounter.patient.full_name ?? 'Review',
            href: `/rm/rawat-jalan/${props.encounter.public_id}`,
        },
    ] satisfies BreadcrumbItem[],
});
