import { useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    CircleDot,
    ClipboardPlus,
    FileCheck2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    formatRadiologyDate,
    newRadiologyOperationKey,
    radiologyFieldClass,
} from './operation';
import type {
    RadiologyEncounterProjection,
    RadiologyOrderProjection,
} from './types';

export type RadiologyEncounterPanelProps = {
    projection: RadiologyEncounterProjection;
};

const stateLabel = {
    ORDERED: 'Awaiting examination',
    PERFORMED: 'Examination completed',
    REPORTED_VERIFIED: 'Verified result',
    CANCELLED: 'Cancelled',
} as const;

function ErrorSummary({ errors }: { errors: Record<string, string> }) {
    const ref = useRef<HTMLDivElement>(null);
    const messages = Object.values(errors);
    const errorFingerprint = messages.join('|');

    useEffect(() => {
        if (messages.length > 0) {
            ref.current?.focus();
        }
    }, [errorFingerprint, messages.length]);

    if (messages.length === 0) {
        return null;
    }

    return (
        <div
            ref={ref}
            tabIndex={-1}
            role="alert"
            className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-900 outline-none focus-visible:ring-2 focus-visible:ring-red-600"
        >
            <p className="font-semibold">Review the following fields:</p>
            <ul className="mt-1 list-disc pl-5">
                {messages.map((message) => (
                    <li key={message}>{message}</li>
                ))}
            </ul>
        </div>
    );
}

function ResultRail({ order }: { order: RadiologyOrderProjection }) {
    const steps = [
        { label: 'Dipesan', complete: true },
        {
            label: 'Examined',
            complete: ['PERFORMED', 'REPORTED_VERIFIED'].includes(order.state),
        },
        { label: 'Diverifikasi', complete: order.report?.state === 'VERIFIED' },
        {
            label: 'Acknowledged by physician',
            complete: order.report?.acknowledgement?.is_current === true,
        },
    ];

    return (
        <ol
            aria-label="Radiology result workflow"
            className="grid grid-cols-4 gap-1"
        >
            {steps.map((step, index) => (
                <li key={step.label} className="min-w-0 text-center">
                    <div className="flex items-center" aria-hidden="true">
                        <span
                            className={`h-px flex-1 ${index === 0 ? 'bg-transparent' : step.complete ? 'bg-[#1b75bc]' : 'bg-slate-200'}`}
                        />
                        <span
                            className={`grid size-7 shrink-0 place-items-center rounded-full border-2 ${step.complete ? 'border-[#1b75bc] bg-[#1b75bc] text-white' : 'border-slate-300 bg-white text-slate-400'}`}
                        >
                            {step.complete ? (
                                <CheckCircle2 className="size-4" />
                            ) : (
                                <CircleDot className="size-3" />
                            )}
                        </span>
                        <span
                            className={`h-px flex-1 ${index === steps.length - 1 ? 'bg-transparent' : steps[index + 1]?.complete ? 'bg-[#1b75bc]' : 'bg-slate-200'}`}
                        />
                    </div>
                    <span className="mt-1 block truncate text-[11px] font-medium text-slate-600">
                        {step.label}
                    </span>
                </li>
            ))}
        </ol>
    );
}

function EncounterOrderCard({
    order,
    projection,
}: {
    order: RadiologyOrderProjection;
    projection: RadiologyEncounterProjection;
}) {
    const [showCancel, setShowCancel] = useState(false);
    const cancelForm = useForm({
        expected_version: order.version,
        reason_code: '',
        note: '',
        idempotency_key: newRadiologyOperationKey('cancel'),
    });
    const acknowledgeForm = useForm({
        expected_order_version: order.version,
        expected_report_version: order.report?.version ?? 0,
        idempotency_key: newRadiologyOperationKey('acknowledge'),
    });
    const canCancel =
        projection.permissions.can_cancel_own_order &&
        order.actions.cancel_url !== null &&
        order.state === 'ORDERED';
    const canAcknowledge =
        projection.permissions.can_acknowledge_own_order &&
        order.actions.acknowledge_url !== null &&
        order.report?.state === 'VERIFIED' &&
        order.report.acknowledgement?.is_current !== true;

    const cancel = (event: FormEvent) => {
        event.preventDefault();

        if (!canCancel || !order.actions.cancel_url) {
            return;
        }

        cancelForm.post(order.actions.cancel_url, {
            preserveScroll: true,
            errorBag: `radiologyCancel.${order.public_id}`,
        });
    };

    const acknowledge = () => {
        if (!canAcknowledge || !order.actions.acknowledge_url) {
            return;
        }

        acknowledgeForm.post(order.actions.acknowledge_url, {
            preserveScroll: true,
            errorBag: `radiologyAcknowledge.${order.public_id}`,
        });
    };

    return (
        <article className="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div className="border-b border-slate-100 px-4 py-3 sm:flex sm:items-start sm:justify-between sm:gap-4">
                <div>
                    <p className="font-mono text-xs font-semibold tracking-wide text-[#145a8d]">
                        {order.examination.code}
                    </p>
                    <h4 className="font-['IBM_Plex_Sans_Condensed'] text-lg font-semibold text-slate-950">
                        {order.examination.display_name}
                    </h4>
                    <p className="mt-1 text-sm text-slate-600">
                        {formatRadiologyDate(order.ordered_at)} ·{' '}
                        {order.ordering_physician_name}
                    </p>
                </div>
                <span className="mt-2 inline-flex min-h-7 items-center rounded-full bg-[#e8f4fb] px-3 text-xs font-semibold text-[#145a8d] sm:mt-0">
                    {stateLabel[order.state]}
                </span>
            </div>

            {order.state !== 'CANCELLED' ? (
                <div className="border-b border-slate-100 px-4 py-4">
                    <ResultRail order={order} />
                </div>
            ) : null}

            <div className="space-y-3 px-4 py-4 text-sm">
                <div>
                    <p className="font-semibold text-slate-800">
                        Clinical question
                    </p>
                    <p className="mt-1 whitespace-pre-wrap text-slate-700">
                        {order.clinical_question}
                    </p>
                </div>
                {order.examination.preparation_instruction ? (
                    <p className="rounded-md bg-amber-50 px-3 py-2 text-amber-950">
                        <span className="font-semibold">Preparation:</span>{' '}
                        {order.examination.preparation_instruction}
                    </p>
                ) : null}
                {order.report?.state === 'VERIFIED' ? (
                    <section
                        aria-label="Verified radiology result"
                        className="rounded-md border-l-4 border-[#1b75bc] bg-slate-50 p-3"
                    >
                        <div className="flex items-center gap-2 text-slate-950">
                            <FileCheck2 className="size-4 text-[#1b75bc]" />
                            <p className="font-semibold">Verified impression</p>
                        </div>
                        <p className="mt-2 whitespace-pre-wrap text-slate-800">
                            {order.report.impression}
                        </p>
                        {order.report.amendments.map((amendment) => (
                            <div
                                key={amendment.public_id}
                                className="mt-3 border-t border-slate-200 pt-3"
                            >
                                <p className="font-semibold text-slate-800">
                                    Verified addendum
                                </p>
                                <p className="mt-1 whitespace-pre-wrap text-slate-700">
                                    {amendment.amended_statement}
                                </p>
                            </div>
                        ))}
                        {order.report.acknowledgement ? (
                            <p
                                className={`mt-3 text-xs font-semibold ${order.report.acknowledgement.is_current ? 'text-emerald-700' : 'text-amber-800'}`}
                            >
                                {order.report.acknowledgement.is_current
                                    ? `Acknowledged · ${order.report.acknowledgement.physician_name}`
                                    : 'The previous acknowledgement is no longer current because of a new amendment.'}
                            </p>
                        ) : null}
                    </section>
                ) : null}
                {order.cancellation ? (
                    <p className="rounded-md bg-slate-100 px-3 py-2 text-slate-700">
                        Cancelled: {order.cancellation.reason_label}
                        {order.cancellation.note
                            ? ` · ${order.cancellation.note}`
                            : ''}
                    </p>
                ) : null}

                <ErrorSummary
                    errors={{ ...cancelForm.errors, ...acknowledgeForm.errors }}
                />

                <div className="flex flex-wrap gap-2">
                    {canAcknowledge ? (
                        <Button
                            type="button"
                            className="min-h-11"
                            onClick={acknowledge}
                            disabled={acknowledgeForm.processing}
                        >
                            Mark as Acknowledged
                        </Button>
                    ) : null}
                    {canCancel ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setShowCancel((value) => !value)}
                        >
                            Cancel request
                        </Button>
                    ) : null}
                </div>

                {showCancel && canCancel ? (
                    <form
                        onSubmit={cancel}
                        className="space-y-3 rounded-md border border-red-200 bg-red-50/50 p-3"
                    >
                        <div>
                            <Label htmlFor={`cancel-reason-${order.public_id}`}>
                                Cancellation reason
                            </Label>
                            <select
                                id={`cancel-reason-${order.public_id}`}
                                className={radiologyFieldClass}
                                value={cancelForm.data.reason_code}
                                onChange={(event) =>
                                    cancelForm.setData(
                                        'reason_code',
                                        event.target.value,
                                    )
                                }
                                required
                            >
                                <option value="">Select a reason</option>
                                {projection.cancellation_reason_options.map(
                                    (option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor={`cancel-note-${order.public_id}`}>
                                Note
                            </Label>
                            <textarea
                                id={`cancel-note-${order.public_id}`}
                                className={radiologyFieldClass}
                                rows={3}
                                value={cancelForm.data.note}
                                onChange={(event) =>
                                    cancelForm.setData(
                                        'note',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="destructive"
                            className="min-h-11"
                            disabled={cancelForm.processing}
                        >
                            Confirm Cancellation
                        </Button>
                    </form>
                ) : null}
            </div>
        </article>
    );
}

export function RadiologyEncounterPanel({
    projection,
}: RadiologyEncounterPanelProps) {
    const form = useForm({
        definition_version: projection.definition_version,
        examination_public_id: '',
        clinical_question: '',
        idempotency_key: newRadiologyOperationKey('order'),
    });
    const canOrder =
        projection.permissions.can_order &&
        projection.commands.create_order_url !== null;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!canOrder || !projection.commands.create_order_url) {
            return;
        }

        form.post(projection.commands.create_order_url, {
            preserveScroll: true,
            errorBag: 'radiologyOrder',
            onSuccess: () =>
                form.reset('examination_public_id', 'clinical_question'),
        });
    };

    return (
        <section
            aria-labelledby="radiology-encounter-title"
            className="space-y-4 rounded-xl border border-slate-200 bg-slate-50/70 p-4 sm:p-5"
        >
            <div className="flex items-start gap-3">
                <span
                    className="grid size-11 shrink-0 place-items-center rounded-lg bg-[#1b75bc] text-white"
                    aria-hidden="true"
                >
                    <ClipboardPlus className="size-5" />
                </span>
                <div>
                    <h3
                        id="radiology-encounter-title"
                        className="font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold text-slate-950"
                    >
                        Radiology
                    </h3>
                    <p className="text-sm text-slate-600">
                        Requests and examination results for this episode.
                    </p>
                </div>
            </div>

            <p className="sr-only" role="status" aria-live="polite">
                {form.processing
                    ? 'Saving radiology request.'
                    : `${projection.orders.length} radiology requests displayed.`}
            </p>

            {canOrder ? (
                <form
                    onSubmit={submit}
                    className="space-y-4 rounded-lg border border-[#b9d9ed] bg-white p-4 shadow-sm"
                >
                    <h4 className="font-semibold text-slate-950">
                        Create examination request
                    </h4>
                    <ErrorSummary errors={form.errors} />
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <Label htmlFor="radiology-examination">
                                Examination
                            </Label>
                            <select
                                id="radiology-examination"
                                className={radiologyFieldClass}
                                value={form.data.examination_public_id}
                                onChange={(event) =>
                                    form.setData(
                                        'examination_public_id',
                                        event.target.value,
                                    )
                                }
                                required
                            >
                                <option value="">
                                    Select an active examination
                                </option>
                                {projection.examination_options.map(
                                    (option) => (
                                        <option
                                            key={option.public_id}
                                            value={option.public_id}
                                        >
                                            {option.code} ·{' '}
                                            {option.display_name}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="radiology-clinical-question">
                                Clinical question
                            </Label>
                            <textarea
                                id="radiology-clinical-question"
                                className={radiologyFieldClass}
                                rows={3}
                                value={form.data.clinical_question}
                                onChange={(event) =>
                                    form.setData(
                                        'clinical_question',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                        </div>
                    </div>
                    <Button
                        type="submit"
                        className="min-h-11"
                        disabled={form.processing}
                    >
                        Save request
                    </Button>
                </form>
            ) : null}

            <div className="space-y-3">
                {projection.orders.length ? (
                    projection.orders.map((order) => (
                        <EncounterOrderCard
                            key={`${order.public_id}:${order.version}:${order.report?.version ?? 0}:${order.report?.amendments.length ?? 0}`}
                            order={order}
                            projection={projection}
                        />
                    ))
                ) : (
                    <p className="rounded-lg border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">
                        No radiology requests for this episode.
                    </p>
                )}
            </div>
        </section>
    );
}
