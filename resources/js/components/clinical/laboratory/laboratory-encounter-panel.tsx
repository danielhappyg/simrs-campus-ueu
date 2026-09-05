import { useForm } from '@inertiajs/react';
import { FlaskConical, TestTube2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    LaboratoryErrors,
    LaboratoryProgressRail,
    LaboratoryVerifiedEvidence,
} from './laboratory-shared';
import {
    formatLaboratoryDate,
    laboratoryFieldClass,
    newLaboratoryOperationKey,
} from './operation';
import type {
    LaboratoryEncounterProjection,
    LaboratoryOrderProjection,
} from './types';

export type LaboratoryEncounterPanelProps = {
    projection: LaboratoryEncounterProjection;
};

const stateLabel = {
    ORDERED: 'Awaiting specimen',
    SPECIMEN_ACCEPTED: 'Specimen accepted',
    REPORTED_VERIFIED: 'Verified result',
    CANCELLED: 'Cancelled',
} as const;

function SpecimenHistory({ order }: { order: LaboratoryOrderProjection }) {
    if (!order.specimens.length) {
        return null;
    }

    return (
        <section aria-label="Specimen history" className="space-y-2">
            <p className="font-semibold text-slate-800">Specimen history</p>
            <ol className="space-y-2">
                {order.specimens.map((specimen) => (
                    <li
                        key={specimen.public_id}
                        className="rounded-md border border-slate-200 bg-slate-50 p-3"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="font-mono text-xs font-semibold text-slate-800">
                                {specimen.label_identifier} · Percobaan{' '}
                                {specimen.attempt_number}
                            </p>
                            <span
                                className={`rounded-full px-2.5 py-1 text-xs font-semibold ${
                                    specimen.state === 'ACCEPTED'
                                        ? 'bg-emerald-50 text-emerald-800'
                                        : specimen.state === 'REJECTED'
                                          ? 'bg-red-50 text-red-800'
                                          : 'bg-[#e8f4fb] text-[#145a8d]'
                                }`}
                            >
                                {specimen.state === 'COLLECTED'
                                    ? 'Dikumpulkan'
                                    : specimen.state === 'RECEIVED'
                                      ? 'Accepted by laboratory unit'
                                      : specimen.state === 'ACCEPTED'
                                        ? 'Suitable for examination'
                                        : 'Rejected'}
                            </span>
                        </div>
                        <p className="mt-1 text-xs text-slate-600">
                            {formatLaboratoryDate(specimen.collected_at)} ·{' '}
                            {specimen.collector_name}
                        </p>
                        {specimen.rejection_reason_label ? (
                            <p className="mt-2 text-sm text-red-800">
                                {specimen.rejection_reason_label}
                                {specimen.rejection_note
                                    ? ` · ${specimen.rejection_note}`
                                    : ''}
                            </p>
                        ) : null}
                    </li>
                ))}
            </ol>
        </section>
    );
}

function EncounterOrderCard({
    order,
    projection,
}: {
    order: LaboratoryOrderProjection;
    projection: LaboratoryEncounterProjection;
}) {
    const [openTask, setOpenTask] = useState<'cancel' | 'collect' | null>(null);
    const isLegacy = order.source === 'LEGACY_READ_ONLY';
    const cancelForm = useForm({
        expected_order_version: order.version,
        reason_code: '',
        note: '',
        idempotency_key: newLaboratoryOperationKey('cancel'),
    });
    const collectForm = useForm({
        expected_order_version: order.version,
        note: '',
        idempotency_key: newLaboratoryOperationKey('collect'),
    });
    const acknowledgeForm = useForm({
        expected_order_version: order.version,
        expected_result_version: order.result?.version ?? 0,
        idempotency_key: newLaboratoryOperationKey('acknowledge'),
    });
    const canCancel =
        !isLegacy &&
        projection.permissions.can_cancel_own_order &&
        order.actions.cancel_url !== null;
    const canCollect =
        !isLegacy &&
        projection.permissions.can_collect &&
        order.actions.collect_url !== null;
    const canAcknowledge =
        !isLegacy &&
        projection.permissions.can_acknowledge_own_order &&
        order.actions.acknowledge_url !== null &&
        order.result?.state === 'VERIFIED' &&
        order.result.acknowledgement?.is_current !== true;

    const cancel = (event: FormEvent) => {
        event.preventDefault();

        if (!canCancel || !order.actions.cancel_url) {
            return;
        }

        cancelForm.post(order.actions.cancel_url, {
            preserveScroll: true,
            errorBag: `laboratoryCancel.${order.public_id}`,
        });
    };
    const collect = (event: FormEvent) => {
        event.preventDefault();

        if (!canCollect || !order.actions.collect_url) {
            return;
        }

        collectForm.post(order.actions.collect_url, {
            preserveScroll: true,
            errorBag: `laboratoryCollect.${order.public_id}`,
        });
    };
    const acknowledge = () => {
        if (!canAcknowledge || !order.actions.acknowledge_url) {
            return;
        }

        acknowledgeForm.post(order.actions.acknowledge_url, {
            preserveScroll: true,
            errorBag: `laboratoryAcknowledge.${order.public_id}`,
        });
    };

    const visibleResult =
        order.result?.state === 'VERIFIED' ? order.result : null;

    return (
        <article className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div className="border-b border-slate-100 px-4 py-3 sm:flex sm:items-start sm:justify-between sm:gap-4">
                <div>
                    <p className="font-mono text-xs font-semibold tracking-wide text-[#145a8d]">
                        {order.examination.code} ·{' '}
                        {order.priority === 'URGENT' ? 'URGENT' : 'ROUTINE'}
                    </p>
                    <h4 className="font-['IBM_Plex_Sans_Condensed'] text-lg font-semibold text-slate-950">
                        {order.examination.display_name}
                    </h4>
                    <p className="mt-1 text-sm text-slate-600">
                        {formatLaboratoryDate(order.ordered_at)} ·{' '}
                        {order.ordering_physician_name}
                    </p>
                </div>
                <div className="mt-2 flex flex-wrap gap-2 sm:mt-0 sm:justify-end">
                    {isLegacy ? (
                        <span className="inline-flex min-h-7 items-center rounded-full bg-slate-200 px-3 text-xs font-semibold text-slate-800">
                            Read-only archive
                        </span>
                    ) : null}
                    <span className="inline-flex min-h-7 items-center rounded-full bg-[#e8f4fb] px-3 text-xs font-semibold text-[#145a8d]">
                        {stateLabel[order.state]}
                    </span>
                </div>
            </div>

            {!isLegacy && order.state !== 'CANCELLED' ? (
                <div className="border-b border-slate-100 px-4 py-4">
                    <LaboratoryProgressRail order={order} />
                </div>
            ) : null}

            <div className="space-y-4 px-4 py-4 text-sm">
                <div className="grid gap-3 md:grid-cols-2">
                    <div>
                        <p className="font-semibold text-slate-800">
                            Clinical question
                        </p>
                        <p className="mt-1 whitespace-pre-wrap text-slate-700">
                            {order.clinical_question}
                        </p>
                    </div>
                    <div>
                        <p className="font-semibold text-slate-800">Specimen</p>
                        <p className="mt-1 text-slate-700">
                            {order.examination.specimen_type}
                        </p>
                    </div>
                </div>
                {order.examination.collection_instruction ? (
                    <p className="rounded-md bg-amber-50 px-3 py-2 text-amber-950">
                        <span className="font-semibold">
                            Collection instructions:
                        </span>{' '}
                        {order.examination.collection_instruction}
                    </p>
                ) : null}

                <SpecimenHistory order={order} />

                {visibleResult ? (
                    <section
                        aria-label="Verified laboratory result"
                        className="rounded-md border-l-4 border-[#1b75bc] bg-[#f4f9fc] p-3"
                    >
                        <LaboratoryVerifiedEvidence
                            result={visibleResult}
                            showAcknowledgement={!isLegacy}
                        />
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

                <LaboratoryErrors
                    errors={{
                        ...cancelForm.errors,
                        ...collectForm.errors,
                        ...acknowledgeForm.errors,
                    }}
                />

                <div className="flex flex-wrap gap-2">
                    {canCollect ? (
                        <Button
                            type="button"
                            className="min-h-11"
                            onClick={() => setOpenTask('collect')}
                        >
                            <TestTube2 className="mr-2 size-4" /> Catat
                            collection
                        </Button>
                    ) : null}
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
                            onClick={() => setOpenTask('cancel')}
                        >
                            Cancel request
                        </Button>
                    ) : null}
                </div>

                {openTask === 'collect' && canCollect ? (
                    <form
                        onSubmit={collect}
                        className="space-y-3 rounded-md border border-[#b9d9ed] bg-[#f4f9fc] p-3"
                    >
                        <h5 className="font-semibold text-slate-950">
                            Collection: {order.examination.specimen_type}
                        </h5>
                        <div>
                            <Label
                                htmlFor={`collection-note-${order.public_id}`}
                            >
                                Collection note (optional)
                            </Label>
                            <textarea
                                id={`collection-note-${order.public_id}`}
                                rows={3}
                                className={laboratoryFieldClass}
                                value={collectForm.data.note}
                                onChange={(event) =>
                                    collectForm.setData(
                                        'note',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <Button
                            type="submit"
                            className="min-h-11"
                            disabled={collectForm.processing}
                        >
                            Save collection
                        </Button>
                    </form>
                ) : null}

                {openTask === 'cancel' && canCancel ? (
                    <form
                        onSubmit={cancel}
                        className="space-y-3 rounded-md border border-red-200 bg-red-50/50 p-3"
                    >
                        <div>
                            <Label htmlFor={`lab-cancel-${order.public_id}`}>
                                Cancellation reason
                            </Label>
                            <select
                                id={`lab-cancel-${order.public_id}`}
                                className={laboratoryFieldClass}
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
                                    (reason) => (
                                        <option
                                            key={reason.value}
                                            value={reason.value}
                                        >
                                            {reason.label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                        <div>
                            <Label
                                htmlFor={`lab-cancel-note-${order.public_id}`}
                            >
                                Note (optional)
                            </Label>
                            <textarea
                                id={`lab-cancel-note-${order.public_id}`}
                                rows={3}
                                className={laboratoryFieldClass}
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

export function LaboratoryEncounterPanel({
    projection,
}: LaboratoryEncounterPanelProps) {
    const form = useForm({
        definition_version: projection.definition_version,
        examination_public_id: '',
        priority: 'ROUTINE' as 'ROUTINE' | 'URGENT',
        clinical_question: '',
        idempotency_key: newLaboratoryOperationKey('order'),
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
            errorBag: 'laboratoryOrder',
            onSuccess: () =>
                form.reset('examination_public_id', 'clinical_question'),
        });
    };

    return (
        <section
            aria-labelledby="laboratory-encounter-title"
            className="space-y-4 rounded-xl border border-slate-200 bg-slate-50/70 p-4 sm:p-5"
        >
            <div className="flex items-start gap-3">
                <span
                    className="grid size-11 shrink-0 place-items-center rounded-lg bg-[#1b75bc] text-white"
                    aria-hidden="true"
                >
                    <FlaskConical className="size-5" />
                </span>
                <div>
                    <h3
                        id="laboratory-encounter-title"
                        className="font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold text-slate-950"
                    >
                        Laboratory
                    </h3>
                    <p className="text-sm text-slate-600">
                        Requests, specimens, and results for this episode.
                    </p>
                </div>
            </div>

            <p className="sr-only" role="status" aria-live="polite">
                {form.processing
                    ? 'Saving laboratory request.'
                    : `${projection.orders.length} laboratory requests displayed.`}
            </p>

            {canOrder ? (
                <form
                    onSubmit={submit}
                    className="space-y-4 rounded-lg border border-[#b9d9ed] bg-white p-4 shadow-sm"
                >
                    <h4 className="font-semibold text-slate-950">
                        Create examination request
                    </h4>
                    <LaboratoryErrors errors={form.errors} />
                    <div className="grid gap-4 md:grid-cols-3">
                        <div>
                            <Label htmlFor="laboratory-examination">
                                Examination
                            </Label>
                            <select
                                id="laboratory-examination"
                                className={laboratoryFieldClass}
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
                            <Label htmlFor="laboratory-priority">
                                Priority
                            </Label>
                            <select
                                id="laboratory-priority"
                                className={laboratoryFieldClass}
                                value={form.data.priority}
                                onChange={(event) =>
                                    form.setData(
                                        'priority',
                                        event.target.value as
                                            'ROUTINE' | 'URGENT',
                                    )
                                }
                            >
                                {projection.priority_options.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="laboratory-clinical-question">
                                Clinical question
                            </Label>
                            <textarea
                                id="laboratory-clinical-question"
                                rows={3}
                                className={laboratoryFieldClass}
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
                            key={`${order.public_id}:${order.version}:${order.result?.version ?? 0}:${order.result?.amendments.length ?? 0}`}
                            order={order}
                            projection={projection}
                        />
                    ))
                ) : (
                    <p className="rounded-lg border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">
                        No laboratory requests for this episode.
                    </p>
                )}
            </div>
        </section>
    );
}
