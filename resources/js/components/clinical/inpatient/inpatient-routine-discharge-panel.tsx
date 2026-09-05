import { useForm } from '@inertiajs/react';
import { BedSingle, CheckCircle2, ClipboardCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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
import { formatClinicalDate } from './presentation';
import type { InpatientRoutineDischargeProjection } from './types';

let dischargeKeyFallback = 0;

export function newRoutineDischargeIdempotencyKey() {
    const randomPart =
        globalThis.crypto?.randomUUID?.() ??
        `${Date.now().toString(36)}-${(++dischargeKeyFallback).toString(36)}`;

    return `inpatient-routine-discharge-${randomPart}`.toLowerCase();
}

type Props = {
    projection: InpatientRoutineDischargeProjection;
    dischargeSummaryFinal: boolean;
    codingSourceFinal: boolean;
    disabledByUnsavedDocument: boolean;
};

export function InpatientRoutineDischargePanel({
    projection,
    dischargeSummaryFinal,
    codingSourceFinal,
    disabledByUnsavedDocument,
}: Props) {
    const [confirmationOpen, setConfirmationOpen] = useState(false);
    const errorSummaryRef = useRef<HTMLDivElement>(null);
    const terminalStatusRef = useRef<HTMLDivElement>(null);
    const previousRecordPublicId = useRef(projection.record?.public_id ?? null);
    const requirementsRevision = `${projection.requirements.expected_summary_version ?? 'none'}:${projection.requirements.current_location_sequence}:${projection.requirements.source_bed_public_id ?? 'none'}:${projection.actions.execute_url ?? 'none'}`;
    const previousRequirementsRevision = useRef(requirementsRevision);
    const form = useForm({
        expected_summary_version:
            projection.requirements.expected_summary_version ?? 0,
        expected_location_sequence:
            projection.requirements.current_location_sequence,
        source_bed_public_id:
            projection.requirements.source_bed_public_id ?? '',
        idempotency_key: newRoutineDischargeIdempotencyKey(),
    });
    const errors = Object.entries(form.errors).filter(
        (entry): entry is [string, string] => Boolean(entry[1]),
    );
    const canExecute =
        projection.record === null &&
        projection.permission.can_execute &&
        projection.actions.execute_url !== null &&
        projection.requirements.expected_summary_version !== null &&
        projection.requirements.source_bed_public_id !== null &&
        dischargeSummaryFinal &&
        codingSourceFinal &&
        !disabledByUnsavedDocument;

    useEffect(() => {
        if (previousRequirementsRevision.current === requirementsRevision) {
            return;
        }

        previousRequirementsRevision.current = requirementsRevision;
        setConfirmationOpen(false);
        form.setData({
            expected_summary_version:
                projection.requirements.expected_summary_version ?? 0,
            expected_location_sequence:
                projection.requirements.current_location_sequence,
            source_bed_public_id:
                projection.requirements.source_bed_public_id ?? '',
            idempotency_key: newRoutineDischargeIdempotencyKey(),
        });
    }, [
        form,
        projection.requirements.current_location_sequence,
        projection.requirements.expected_summary_version,
        projection.requirements.source_bed_public_id,
        requirementsRevision,
    ]);

    useEffect(() => {
        if (errors.length > 0) {
            errorSummaryRef.current?.focus();
        }
    }, [errors.length]);

    useEffect(() => {
        const currentRecordPublicId = projection.record?.public_id ?? null;

        if (
            previousRecordPublicId.current === null &&
            currentRecordPublicId !== null
        ) {
            terminalStatusRef.current?.focus();
        }

        previousRecordPublicId.current = currentRecordPublicId;
    }, [projection.record?.public_id]);

    const execute = () => {
        if (!canExecute || !projection.actions.execute_url || form.processing) {
            return;
        }

        form.post(projection.actions.execute_url, {
            preserveScroll: true,
            onError: () => {
                setConfirmationOpen(false);
                requestAnimationFrame(() => errorSummaryRef.current?.focus());
            },
            onSuccess: () => {
                setConfirmationOpen(false);
                form.setData(
                    'idempotency_key',
                    newRoutineDischargeIdempotencyKey(),
                );
            },
        });
    };

    if (projection.record) {
        return (
            <section
                ref={terminalStatusRef}
                tabIndex={-1}
                role="status"
                aria-labelledby="inpatient-discharge-complete-title"
                className="border-t border-success/25 bg-success/5 px-4 py-4 outline-none focus-visible:ring-2 focus-visible:ring-ring md:px-5"
            >
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-3">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-success/10 text-success">
                            <CheckCircle2
                                aria-hidden="true"
                                className="size-5"
                            />
                        </span>
                        <div>
                            <p className="text-[0.68rem] font-semibold tracking-[0.12em] text-success uppercase">
                                Episode Completion
                            </p>
                            <h3
                                id="inpatient-discharge-complete-title"
                                className="mt-0.5 text-base font-semibold text-foreground"
                            >
                                Episode complete · Ready for medical records
                            </h3>
                        </div>
                    </div>
                    <span className="rounded-full bg-success/10 px-2.5 py-1 text-xs font-semibold text-success">
                        Bed released
                    </span>
                </div>

                <p className="mt-3 text-sm leading-6 text-muted-foreground">
                    The episode is awaiting medical-record processing. Closure
                    by medical records is completed in a separate step.
                </p>
                <dl className="mt-4 grid gap-3 rounded-lg border border-success/20 bg-card/80 p-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Discharge method
                        </dt>
                        <dd className="mt-0.5 font-semibold">
                            {projection.record.disposition_label}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Discharge time
                        </dt>
                        <dd className="mt-0.5 font-semibold">
                            {formatClinicalDate(
                                projection.record.discharged_at,
                            )}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Discharge summary
                        </dt>
                        <dd className="mt-0.5 font-semibold">
                            Final · version{' '}
                            {projection.record.discharge_summary_version}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Last placement
                        </dt>
                        <dd className="mt-0.5 font-mono text-xs font-semibold">
                            {projection.record.source_bed_code}
                        </dd>
                    </div>
                </dl>
            </section>
        );
    }

    return (
        <section
            aria-labelledby="inpatient-routine-discharge-title"
            className="border-t border-primary/20 bg-secondary/30 px-4 py-4 md:px-5"
        >
            <div className="flex items-start gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <BedSingle aria-hidden="true" className="size-5" />
                </span>
                <div className="min-w-0">
                    <p className="text-[0.68rem] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                        Next step after finalization
                    </p>
                    <h3
                        id="inpatient-routine-discharge-title"
                        className="mt-0.5 text-base font-semibold text-foreground"
                    >
                        Episode Completion
                    </h3>
                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                        Record the discharge method, mark the episode ready for
                        medical records, and release the bed in one action.
                    </p>
                </div>
            </div>

            <div className="mt-4 flex flex-col gap-3 rounded-lg border border-border bg-card p-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="space-y-3">
                    <div className="flex items-center gap-2">
                        <ClipboardCheck
                            aria-hidden="true"
                            className="size-4 text-primary"
                        />
                        <div>
                            <p className="text-xs text-muted-foreground">
                                Discharge method
                            </p>
                            <p className="text-sm font-semibold">
                                {projection.disposition.label}
                            </p>
                        </div>
                    </div>
                    <dl className="grid gap-x-4 gap-y-1 text-xs sm:grid-cols-2">
                        <div className="flex items-center justify-between gap-3 sm:block">
                            <dt className="text-muted-foreground">
                                Discharge summary
                            </dt>
                            <dd className="font-semibold">
                                {dischargeSummaryFinal ? 'Final' : 'Not final'}
                            </dd>
                        </div>
                        <div className="flex items-center justify-between gap-3 sm:block">
                            <dt className="text-muted-foreground">
                                Final diagnosis and procedures
                            </dt>
                            <dd className="font-semibold">
                                {codingSourceFinal ? 'Final' : 'Not final'}
                            </dd>
                        </div>
                    </dl>
                </div>
                <Button
                    type="button"
                    className="min-h-11 sm:max-w-xs"
                    disabled={!canExecute || form.processing}
                    onClick={() => setConfirmationOpen(true)}
                >
                    Complete Episode and Release Bed
                </Button>
            </div>

            {!dischargeSummaryFinal || !codingSourceFinal ? (
                <p className="mt-2 text-xs leading-5 text-muted-foreground">
                    Finalize both documents before completing the episode and
                    releasing the bed.
                </p>
            ) : disabledByUnsavedDocument ? (
                <p className="mt-2 text-xs leading-5 text-muted-foreground">
                    Save or discard document changes before completing episode.
                </p>
            ) : !projection.permission.can_execute ||
              !projection.actions.execute_url ? (
                <p className="mt-2 text-xs leading-5 text-muted-foreground">
                    Episode completion is unavailable for this account, status,
                    or penempatan ini.
                </p>
            ) : null}

            {errors.length > 0 ? (
                <div
                    ref={errorSummaryRef}
                    tabIndex={-1}
                    role="alert"
                    className="mt-3 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive outline-none focus-visible:ring-2 focus-visible:ring-destructive"
                >
                    <p className="font-semibold">
                        The episode cannot yet be completed
                    </p>
                    <ul className="mt-1 list-disc space-y-1 pl-5 text-xs">
                        {errors.map(([key, message]) => (
                            <li key={`${key}-${message}`}>{message}</li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <Dialog
                open={confirmationOpen}
                onOpenChange={(open) => {
                    if (!form.processing) {
                        setConfirmationOpen(open);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Complete the inpatient episode?
                        </DialogTitle>
                        <DialogDescription>
                            This records {projection.disposition.label}, marks
                            the episode ready for medical records, and releases
                            the bed. The final discharge summary remains
                            unchanged.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={form.processing}
                            >
                                Review again
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            onClick={execute}
                            disabled={!canExecute || form.processing}
                        >
                            {form.processing
                                ? 'Completing episode…'
                                : 'Yes, complete episode'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
