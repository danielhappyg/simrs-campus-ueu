import { Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Banknote,
    CheckCircle2,
    ClipboardCheck,
    Clock3,
    FileWarning,
    Printer,
    ReceiptText,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    formatFinanceDate,
    formatPrintedFinanceDate,
    formatPrintedRupiah,
    formatRupiah,
} from './finance-shared';
import type {
    FinanceCashSettlementCorrectionCase,
    FinanceCashSettlementCorrectionDetailProps,
    FinanceCashSettlementCorrectionEvent,
    FinanceCashSettlementCorrectionReasonCode,
    FinanceCashSettlementCorrectionState,
    FinanceCashSettlementCorrectionWorklistProps,
    FinanceCashSettlementRefundReceiptProps,
    FinanceCashSettlementSummary,
} from './types';

export const correctionReasonLabels: Record<
    FinanceCashSettlementCorrectionReasonCode,
    string
> = {
    WRONG_BILL: 'Settlement recorded against the wrong bill',
    DUPLICATE_COLLECTION: 'Duplicate cash collection recorded',
    CASHIER_INPUT_CONTEXT_ERROR: 'Incorrect cashier input context',
    OTHER_SUPERVISOR_REVIEW: 'Other reason requiring supervisor review',
};

export const correctionStateLabels: Record<
    FinanceCashSettlementCorrectionState,
    string
> = {
    ACTIVE: 'Settlement active',
    CORRECTION_REQUESTED: 'Pending supervisor review',
    REVIEW_REJECTED: 'Request rejected',
    REFUND_APPROVED: 'Refund approved, cash not yet returned',
    REFUND_COMPLETED: 'Cash refund completed',
};

const printedCorrectionReasonLabels: Record<
    FinanceCashSettlementCorrectionReasonCode,
    string
> = {
    WRONG_BILL: 'Pelunasan dicatat pada tagihan yang keliru',
    DUPLICATE_COLLECTION: 'Penerimaan tunai tercatat ganda',
    CASHIER_INPUT_CONTEXT_ERROR: 'Konteks input kasir keliru',
    OTHER_SUPERVISOR_REVIEW: 'Alasan lain yang memerlukan tinjauan supervisor',
};

const stateClasses: Record<FinanceCashSettlementCorrectionState, string> = {
    ACTIVE: 'border-emerald-300 bg-emerald-50 text-emerald-950',
    CORRECTION_REQUESTED: 'border-amber-300 bg-amber-50 text-amber-950',
    REVIEW_REJECTED: 'border-red-300 bg-red-50 text-red-950',
    REFUND_APPROVED: 'border-amber-400 bg-amber-50 text-amber-950',
    REFUND_COMPLETED: 'border-[#7fbcb6] bg-[#e8f5f3] text-[#0b4147]',
};

const eventLabels: Record<
    FinanceCashSettlementCorrectionEvent['event_type'],
    string
> = {
    REVIEW_REJECTED: 'Supervisor rejected request',
    REFUND_APPROVED: 'Supervisor approved full refund',
    REFUND_COMPLETED: 'Cash returned and completion recorded',
};

function newCorrectionKey(operation: 'request' | 'review' | 'refund'): string {
    return `finance-correction-${operation}-${Date.now()}-${crypto.randomUUID()}`;
}

function uniqueErrors(errors: Record<string, string | undefined>): string[] {
    return Array.from(
        new Set(
            Object.values(errors).filter((value): value is string => !!value),
        ),
    );
}

function CorrectionStateBadge({
    state,
}: {
    state: FinanceCashSettlementCorrectionState;
}) {
    return (
        <span
            className={`inline-flex rounded-full border px-3 py-1 text-xs font-semibold ${stateClasses[state]}`}
        >
            {correctionStateLabels[state]}
        </span>
    );
}

function ErrorSummary({
    errors,
    heading,
    errorRef,
}: {
    errors: string[];
    heading: string;
    errorRef: React.RefObject<HTMLDivElement | null>;
}) {
    if (!errors.length) {
        return null;
    }

    return (
        <div
            ref={errorRef}
            tabIndex={-1}
            role="alert"
            className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-950 outline-none focus:ring-2 focus:ring-red-600"
        >
            <p className="font-semibold">{heading}</p>
            <ul className="mt-1 list-inside list-disc">
                {errors.map((error) => (
                    <li key={error}>{error}</li>
                ))}
            </ul>
        </div>
    );
}

export function FinanceSettlementCorrectionRequestPanel({
    settlement,
    requestUrl,
}: {
    settlement: FinanceCashSettlementSummary;
    requestUrl: string;
}) {
    const [status, setStatus] = useState('');
    const errorRef = useRef<HTMLDivElement>(null);
    const form = useForm({
        reason_code: '' as FinanceCashSettlementCorrectionReasonCode | '',
        explanation: '',
        expected_settlement_digest: settlement.content_digest,
        confirm_request: false,
        idempotency_key: newCorrectionKey('request'),
    });
    const errors = useMemo(() => uniqueErrors(form.errors), [form.errors]);

    useEffect(() => {
        if (errors.length) {
            errorRef.current?.focus();
        }
    }, [errors]);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setStatus('Submitting correction request…');
        form.post(requestUrl, {
            preserveScroll: true,
            onError: () => setStatus('Correction request was not sent.'),
            onSuccess: () => setStatus('Request submitted for review.'),
        });
    };

    return (
        <section
            aria-labelledby="finance-correction-request-heading"
            className="mt-5 border-t border-slate-200 pt-5"
        >
            <div className="flex items-start gap-3">
                <span className="rounded-lg bg-amber-50 p-2 text-amber-900">
                    <FileWarning aria-hidden="true" className="size-5" />
                </span>
                <div>
                    <h3
                        id="finance-correction-request-heading"
                        className="font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold"
                    >
                        Request Settlement Correction
                    </h3>
                    <p className="mt-1 max-w-3xl text-sm text-slate-700">
                        The request does not change the receipt. A cashier
                        supervisor will review the reason before a refund can be
                        approved.
                    </p>
                </div>
            </div>

            <form onSubmit={submit} className="mt-4 space-y-4">
                <ErrorSummary
                    errors={errors}
                    heading="The correction request could not be submitted"
                    errorRef={errorRef}
                />
                <div>
                    <Label htmlFor="finance-correction-reason">
                        Correction reason
                    </Label>
                    <select
                        id="finance-correction-reason"
                        value={form.data.reason_code}
                        onChange={(event) =>
                            form.setData(
                                'reason_code',
                                event.target.value as
                                    | FinanceCashSettlementCorrectionReasonCode
                                    | '',
                            )
                        }
                        required
                        className="mt-2 min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-950 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        <option value="">Select the applicable reason</option>
                        {Object.entries(correctionReasonLabels).map(
                            ([code, label]) => (
                                <option key={code} value={code}>
                                    {label}
                                </option>
                            ),
                        )}
                    </select>
                </div>
                <div>
                    <Label htmlFor="finance-correction-explanation">
                        Incident explanation
                    </Label>
                    <textarea
                        id="finance-correction-explanation"
                        value={form.data.explanation}
                        onChange={(event) =>
                            form.setData('explanation', event.target.value)
                        }
                        minLength={8}
                        maxLength={500}
                        required
                        aria-describedby="finance-correction-explanation-help"
                        className="mt-2 min-h-28 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    />
                    <p
                        id="finance-correction-explanation-help"
                        className="mt-1 text-xs text-slate-600"
                    >
                        Explain what is incorrect and the evidence that needs to
                        be reviewed by a supervisor. Minimum 8 and maximum 500
                        characters.
                    </p>
                </div>
                <label className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 focus-within:ring-2 focus-within:ring-[#1b75bc]">
                    <input
                        type="checkbox"
                        checked={form.data.confirm_request}
                        onChange={(event) =>
                            form.setData(
                                'confirm_request',
                                event.target.checked,
                            )
                        }
                        className="mt-0.5 size-5 accent-[#0f5b62]"
                    />
                    <span>
                        I confirm this request for receipt{' '}
                        <strong>{settlement.receipt_number}</strong> in the
                        amount of{' '}
                        <strong>{formatRupiah(settlement.amount)}</strong>. The
                        refund amount cannot be changed on this form.
                    </span>
                </label>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p
                        role="status"
                        aria-live="polite"
                        className="min-h-11 py-3 text-sm font-medium text-slate-700"
                    >
                        {status}
                    </p>
                    <Button
                        type="submit"
                        disabled={
                            form.processing ||
                            !form.data.reason_code ||
                            form.data.explanation.trim().length < 8 ||
                            !form.data.confirm_request
                        }
                        className="min-h-11 bg-[#0f5b62] px-5 hover:bg-[#0b4147]"
                    >
                        {form.processing
                            ? 'Mengirim…'
                            : 'Submit Correction Request'}
                    </Button>
                </div>
            </form>
        </section>
    );
}

export function FinanceSettlementCorrectionWorklist({
    cases,
    generated_at,
}: FinanceCashSettlementCorrectionWorklistProps) {
    return (
        <main className="min-h-screen bg-slate-50 pb-12">
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="relative overflow-hidden rounded-2xl bg-[#123b5d] p-6 text-white shadow-sm">
                    <span
                        aria-hidden="true"
                        className="absolute inset-y-0 left-0 w-2 bg-[#24a69a]"
                    />
                    <div className="relative flex flex-wrap items-start justify-between gap-5">
                        <div>
                            <p className="flex items-center gap-2 text-sm font-semibold text-sky-100">
                                <ClipboardCheck
                                    aria-hidden="true"
                                    className="size-5"
                                />
                                Cashier and supervisor controls
                            </p>
                            <h1 className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold">
                                Cash Settlement Correction
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm text-sky-50">
                                Review requests, supervisor decisions, and full
                                refund evidence without changing the original
                                receipt.
                            </p>
                        </div>
                        <p className="font-['IBM_Plex_Mono'] text-xs text-sky-100">
                            Updated {generated_at}
                        </p>
                    </div>
                </header>

                <section
                    aria-labelledby="finance-correction-worklist-heading"
                    className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
                >
                    <header className="border-b border-slate-200 p-5">
                        <h2
                            id="finance-correction-worklist-heading"
                            className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                        >
                            Correction Cases
                        </h2>
                        <p className="mt-1 text-sm text-slate-600">
                            Status “approved” mean cash still must handed over
                            and recorded completed.
                        </p>
                    </header>
                    {cases.length ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[58rem] text-left text-sm">
                                <caption className="sr-only">
                                    Cash settlement correction cases
                                </caption>
                                <thead className="border-b border-slate-300 bg-slate-100 text-xs tracking-wide text-slate-700 uppercase">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            Case and receipt
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Requester
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Reason
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right"
                                        >
                                            Value
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200">
                                    {cases.map((correctionCase) => (
                                        <tr
                                            key={correctionCase.public_id}
                                            className="align-top"
                                        >
                                            <th
                                                scope="row"
                                                className="px-4 py-4 font-normal"
                                            >
                                                <span className="font-['IBM_Plex_Mono'] font-semibold text-slate-950">
                                                    {
                                                        correctionCase.correction_number
                                                    }
                                                </span>
                                                <span className="mt-1 block font-['IBM_Plex_Mono'] text-xs text-slate-600">
                                                    {
                                                        correctionCase.receipt_number
                                                    }
                                                </span>
                                                <span className="mt-1 block text-xs text-slate-500">
                                                    {formatFinanceDate(
                                                        correctionCase.requested_at,
                                                    )}
                                                </span>
                                            </th>
                                            <td className="px-4 py-4 font-semibold text-slate-800">
                                                {
                                                    correctionCase.requesting_cashier_name
                                                }
                                            </td>
                                            <td className="max-w-xs px-4 py-4 text-slate-700">
                                                {
                                                    correctionReasonLabels[
                                                        correctionCase
                                                            .reason_code
                                                    ]
                                                }
                                            </td>
                                            <td className="px-4 py-4 text-right font-['IBM_Plex_Mono'] font-semibold tabular-nums">
                                                {formatRupiah(
                                                    correctionCase.amount,
                                                )}
                                            </td>
                                            <td className="px-4 py-4">
                                                <CorrectionStateBadge
                                                    state={correctionCase.state}
                                                />
                                            </td>
                                            <td className="px-4 py-4">
                                                <Link
                                                    href={
                                                        correctionCase.show_url
                                                    }
                                                    className="inline-flex min-h-11 items-center rounded-md border border-[#0f5b62] bg-white px-4 font-semibold text-[#0f5b62] hover:bg-[#e8f5f3] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                                                >
                                                    Open Case
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="p-8 text-center">
                            <CheckCircle2
                                aria-hidden="true"
                                className="mx-auto size-8 text-[#0f5b62]"
                            />
                            <p className="mt-3 font-semibold text-slate-900">
                                No case correction
                            </p>
                            <p className="mt-1 text-sm text-slate-600">
                                Requests from settled receipts will appear here.
                            </p>
                        </div>
                    )}
                </section>
            </div>
        </main>
    );
}

function CaseTimeline({
    correctionCase,
}: {
    correctionCase: FinanceCashSettlementCorrectionCase;
}) {
    const entries = [
        {
            key: `request-${correctionCase.public_id}`,
            label: 'Cashier mengajukan correction',
            actor: correctionCase.requesting_cashier_name,
            explanation: correctionCase.explanation,
            occurredAt: correctionCase.requested_at,
            amount: null,
        },
        ...correctionCase.events.map((event) => ({
            key: event.public_id,
            label: eventLabels[event.event_type],
            actor: event.actor_name,
            explanation: event.explanation,
            occurredAt: event.occurred_at,
            amount: event.amount,
        })),
    ];

    return (
        <section
            aria-labelledby="finance-correction-timeline-heading"
            className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
        >
            <h2
                id="finance-correction-timeline-heading"
                className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
            >
                Linimasa Evidence
            </h2>
            <ol className="mt-5 space-y-0">
                {entries.map((entry, index) => (
                    <li
                        key={entry.key}
                        className="grid grid-cols-[2.75rem_1fr] gap-3"
                    >
                        <div className="flex flex-col items-center">
                            <span className="flex size-9 items-center justify-center rounded-full border-2 border-[#24a69a] bg-white font-['IBM_Plex_Mono'] text-sm font-bold text-[#0f5b62]">
                                {index + 1}
                            </span>
                            {index < entries.length - 1 ? (
                                <span
                                    aria-hidden="true"
                                    className="min-h-12 w-0.5 grow bg-[#7fbcb6]"
                                />
                            ) : null}
                        </div>
                        <article className="pb-6">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <h3 className="font-semibold text-slate-950">
                                    {entry.label}
                                </h3>
                                <time className="text-xs text-slate-500">
                                    {formatFinanceDate(entry.occurredAt)}
                                </time>
                            </div>
                            <p className="mt-1 text-sm font-semibold text-[#0d5275]">
                                {entry.actor}
                            </p>
                            {entry.explanation ? (
                                <p className="mt-2 text-sm text-slate-700">
                                    {entry.explanation}
                                </p>
                            ) : null}
                            {entry.amount !== null ? (
                                <p className="mt-2 font-['IBM_Plex_Mono'] text-sm font-semibold text-slate-950 tabular-nums">
                                    Full amount {formatRupiah(entry.amount)}
                                </p>
                            ) : null}
                        </article>
                    </li>
                ))}
            </ol>
        </section>
    );
}

function SupervisorReviewPanel({
    correctionCase,
    url,
}: {
    correctionCase: FinanceCashSettlementCorrectionCase;
    url: string;
}) {
    const [status, setStatus] = useState('');
    const errorRef = useRef<HTMLDivElement>(null);
    const form = useForm({
        decision: '' as 'REVIEW_REJECTED' | 'REFUND_APPROVED' | '',
        explanation: '',
        expected_case_fingerprint: correctionCase.fingerprint,
        confirm_review: false,
        idempotency_key: newCorrectionKey('review'),
    });
    const errors = useMemo(() => uniqueErrors(form.errors), [form.errors]);

    useEffect(() => {
        if (errors.length) {
            errorRef.current?.focus();
        }
    }, [errors]);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setStatus('Recording supervisor decision…');
        form.post(url, {
            preserveScroll: true,
            onError: () => setStatus('Decision was not recorded.'),
            onSuccess: () => setStatus('Supervisor decision recorded.'),
        });
    };

    return (
        <section
            aria-labelledby="finance-correction-review-heading"
            className="rounded-xl border border-[#7fbcb6] bg-white p-5 shadow-sm"
        >
            <div className="flex items-start gap-3">
                <span className="rounded-lg bg-[#e8f5f3] p-2 text-[#0f5b62]">
                    <ShieldCheck aria-hidden="true" className="size-5" />
                </span>
                <div>
                    <h2
                        id="finance-correction-review-heading"
                        className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                    >
                        Cashier Supervisor Review
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        The decision is final. Approval does not mean the cash
                        has been returned.
                    </p>
                </div>
            </div>
            <form onSubmit={submit} className="mt-4 space-y-4">
                <ErrorSummary
                    errors={errors}
                    heading="The decision could not be recorded"
                    errorRef={errorRef}
                />
                <fieldset>
                    <legend className="text-sm font-semibold text-slate-900">
                        Supervisor decision
                    </legend>
                    <div className="mt-2 grid gap-3 sm:grid-cols-2">
                        <label className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-red-300 p-3 text-sm focus-within:ring-2 focus-within:ring-[#1b75bc]">
                            <input
                                type="radio"
                                name="correction-decision"
                                value="REVIEW_REJECTED"
                                checked={
                                    form.data.decision === 'REVIEW_REJECTED'
                                }
                                onChange={() =>
                                    form.setData('decision', 'REVIEW_REJECTED')
                                }
                                className="mt-0.5 size-5 accent-red-700"
                            />
                            <span>
                                <strong className="block text-red-950">
                                    Reject request
                                </strong>
                                The original receipt remains fully active.
                            </span>
                        </label>
                        <label className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-amber-300 p-3 text-sm focus-within:ring-2 focus-within:ring-[#1b75bc]">
                            <input
                                type="radio"
                                name="correction-decision"
                                value="REFUND_APPROVED"
                                checked={
                                    form.data.decision === 'REFUND_APPROVED'
                                }
                                onChange={() =>
                                    form.setData('decision', 'REFUND_APPROVED')
                                }
                                className="mt-0.5 size-5 accent-amber-700"
                            />
                            <span>
                                <strong className="block text-amber-950">
                                    Approve full refund
                                </strong>
                                Cash in the amount of{' '}
                                {formatRupiah(correctionCase.amount)} still must
                                handed over and recorded completed.
                            </span>
                        </label>
                    </div>
                </fieldset>
                <div>
                    <Label htmlFor="finance-correction-review-explanation">
                        Decision basis
                    </Label>
                    <textarea
                        id="finance-correction-review-explanation"
                        value={form.data.explanation}
                        onChange={(event) =>
                            form.setData('explanation', event.target.value)
                        }
                        minLength={8}
                        maxLength={500}
                        required
                        className="mt-2 min-h-28 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    />
                </div>
                <label className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-slate-300 p-3 text-sm focus-within:ring-2 focus-within:ring-[#1b75bc]">
                    <input
                        type="checkbox"
                        checked={form.data.confirm_review}
                        onChange={(event) =>
                            form.setData('confirm_review', event.target.checked)
                        }
                        className="mt-0.5 size-5 accent-[#0f5b62]"
                    />
                    <span>
                        I have matched the receipt, reason, and case evidence
                        before menetapkan decision this.
                    </span>
                </label>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p
                        role="status"
                        aria-live="polite"
                        className="min-h-11 py-3 text-sm font-medium text-slate-700"
                    >
                        {status}
                    </p>
                    <Button
                        type="submit"
                        disabled={
                            form.processing ||
                            !form.data.decision ||
                            form.data.explanation.trim().length < 8 ||
                            !form.data.confirm_review
                        }
                        className="min-h-11 bg-[#0f5b62] px-5 hover:bg-[#0b4147]"
                    >
                        {form.processing ? 'Recording…' : 'Record Decision'}
                    </Button>
                </div>
            </form>
        </section>
    );
}

function RefundCompletionPanel({
    correctionCase,
    url,
}: {
    correctionCase: FinanceCashSettlementCorrectionCase;
    url: string;
}) {
    const [status, setStatus] = useState('');
    const errorRef = useRef<HTMLDivElement>(null);
    const form = useForm({
        expected_case_fingerprint: correctionCase.fingerprint,
        confirm_cash_returned: false,
        idempotency_key: newCorrectionKey('refund'),
    });
    const errors = useMemo(() => uniqueErrors(form.errors), [form.errors]);

    useEffect(() => {
        if (errors.length) {
            errorRef.current?.focus();
        }
    }, [errors]);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setStatus('Recording cash handover…');
        form.post(url, {
            preserveScroll: true,
            onError: () => setStatus('Cash handover not yet recorded.'),
            onSuccess: () => setStatus('Cash return completed recorded.'),
        });
    };

    return (
        <section
            aria-labelledby="finance-refund-completion-heading"
            className="rounded-xl border-2 border-amber-400 bg-white p-5 shadow-sm"
        >
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                    <span className="rounded-lg bg-amber-50 p-2 text-amber-900">
                        <Banknote aria-hidden="true" className="size-5" />
                    </span>
                    <div>
                        <h2
                            id="finance-refund-completion-heading"
                            className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                        >
                            Confirm Handover Cash
                        </h2>
                        <p className="mt-1 max-w-2xl text-sm text-slate-700">
                            Approval has been recorded, but the refund has not
                            yet been completed. Record it only after the cash
                            has actually been returned.
                        </p>
                    </div>
                </div>
                <p className="font-['IBM_Plex_Mono'] text-2xl font-bold text-amber-950 tabular-nums">
                    {formatRupiah(correctionCase.amount)}
                </p>
            </div>
            <form onSubmit={submit} className="mt-4 space-y-4">
                <ErrorSummary
                    errors={errors}
                    heading="The completed refund could not be recorded"
                    errorRef={errorRef}
                />
                <label className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-amber-400 bg-amber-50 p-4 text-sm text-amber-950 focus-within:ring-2 focus-within:ring-[#1b75bc]">
                    <input
                        type="checkbox"
                        checked={form.data.confirm_cash_returned}
                        onChange={(event) =>
                            form.setData(
                                'confirm_cash_returned',
                                event.target.checked,
                            )
                        }
                        className="mt-0.5 size-5 accent-[#0f5b62]"
                    />
                    <span>
                        I confirm the exact cash amount of{' '}
                        <strong>{formatRupiah(correctionCase.amount)}</strong>{' '}
                        has been returned. Once recorded, the original receipt
                        is no longer an active settlement.
                    </span>
                </label>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p
                        role="status"
                        aria-live="polite"
                        className="min-h-11 py-3 text-sm font-medium text-slate-700"
                    >
                        {status}
                    </p>
                    <Button
                        type="submit"
                        disabled={
                            form.processing || !form.data.confirm_cash_returned
                        }
                        className="min-h-11 bg-[#0f5b62] px-5 hover:bg-[#0b4147]"
                    >
                        {form.processing
                            ? 'Recording…'
                            : 'Record Completed Refund'}
                    </Button>
                </div>
            </form>
        </section>
    );
}

export function FinanceSettlementCorrectionCaseView({
    case: correctionCase,
    permissions,
    commands,
    back_url,
    generated_at,
}: FinanceCashSettlementCorrectionDetailProps) {
    return (
        <main className="min-h-screen bg-slate-50 pb-12">
            <div className="mx-auto max-w-6xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                <Link
                    href={back_url}
                    className="inline-flex min-h-11 items-center gap-2 rounded-md border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                >
                    <ArrowLeft aria-hidden="true" className="size-4" />
                    Back to Correction List
                </Link>

                <header className="relative overflow-hidden rounded-2xl bg-[#123b5d] p-6 text-white shadow-sm">
                    <span
                        aria-hidden="true"
                        className="absolute inset-y-0 left-0 w-2 bg-[#24a69a]"
                    />
                    <div className="relative flex flex-wrap items-start justify-between gap-5">
                        <div>
                            <p className="font-['IBM_Plex_Mono'] text-sm font-semibold text-sky-100">
                                {correctionCase.correction_number}
                            </p>
                            <h1 className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold">
                                Settlement Correction Case
                            </h1>
                            <p className="mt-2 font-['IBM_Plex_Mono'] text-sm text-sky-50">
                                Receipt {correctionCase.receipt_number}
                            </p>
                        </div>
                        <div className="space-y-2 text-right">
                            <CorrectionStateBadge
                                state={correctionCase.state}
                            />
                            <p className="font-['IBM_Plex_Mono'] text-xs text-sky-100">
                                Updated {generated_at}
                            </p>
                        </div>
                    </div>
                </header>

                {correctionCase.state === 'REFUND_APPROVED' ? (
                    <div
                        role="status"
                        className="flex items-start gap-3 rounded-xl border border-amber-400 bg-amber-50 p-4 text-sm text-amber-950"
                    >
                        <Clock3
                            aria-hidden="true"
                            className="mt-0.5 size-5 shrink-0"
                        />
                        <div>
                            <p className="font-semibold">
                                Refund not yet completed
                            </p>
                            <p className="mt-1">
                                A supervisor approved the full amount, but the
                                cash has not yet been recorded as returned to
                                the payer.
                            </p>
                        </div>
                    </div>
                ) : null}

                <section
                    aria-labelledby="finance-correction-summary-heading"
                    className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2
                                id="finance-correction-summary-heading"
                                className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                            >
                                Case Summary
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                The value is bound to the original settlement
                                and cannot be edited.
                            </p>
                        </div>
                        <p className="font-['IBM_Plex_Mono'] text-2xl font-bold text-[#0b4147] tabular-nums">
                            {formatRupiah(correctionCase.amount)}
                        </p>
                    </div>
                    <dl className="mt-4 grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="bg-white p-4">
                            <dt className="text-xs font-semibold text-slate-500 uppercase">
                                Requester
                            </dt>
                            <dd className="mt-2 font-semibold text-slate-950">
                                {correctionCase.requesting_cashier_name}
                            </dd>
                        </div>
                        <div className="bg-white p-4">
                            <dt className="text-xs font-semibold text-slate-500 uppercase">
                                Reason closed
                            </dt>
                            <dd className="mt-2 text-sm font-semibold text-slate-950">
                                {
                                    correctionReasonLabels[
                                        correctionCase.reason_code
                                    ]
                                }
                            </dd>
                        </div>
                        <div className="bg-white p-4 sm:col-span-2">
                            <dt className="text-xs font-semibold text-slate-500 uppercase">
                                Penjelasan
                            </dt>
                            <dd className="mt-2 text-sm text-slate-800">
                                {correctionCase.explanation}
                            </dd>
                        </div>
                    </dl>
                </section>

                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,0.8fr)]">
                    <CaseTimeline correctionCase={correctionCase} />
                    <div className="space-y-5">
                        {permissions.can_review &&
                        correctionCase.state === 'CORRECTION_REQUESTED' ? (
                            <SupervisorReviewPanel
                                correctionCase={correctionCase}
                                url={commands.review_url}
                            />
                        ) : null}
                        {permissions.can_complete_refund &&
                        correctionCase.state === 'REFUND_APPROVED' ? (
                            <RefundCompletionPanel
                                correctionCase={correctionCase}
                                url={commands.complete_refund_url}
                            />
                        ) : null}
                        {correctionCase.state === 'REVIEW_REJECTED' ? (
                            <div className="flex items-start gap-3 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-950">
                                <XCircle
                                    aria-hidden="true"
                                    className="mt-0.5 size-5 shrink-0"
                                />
                                <div>
                                    <p className="font-semibold">
                                        Case closed without a refund
                                    </p>
                                    <p className="mt-1">
                                        The original receipt and settlement
                                        remain fully active.
                                    </p>
                                </div>
                            </div>
                        ) : null}
                        {correctionCase.state === 'REFUND_COMPLETED' ? (
                            <div className="rounded-xl border border-[#7fbcb6] bg-[#e8f5f3] p-4 text-sm text-[#0b4147]">
                                <div className="flex items-start gap-3">
                                    <CheckCircle2
                                        aria-hidden="true"
                                        className="mt-0.5 size-5 shrink-0"
                                    />
                                    <div>
                                        <p className="font-semibold">
                                            Return has been completed
                                        </p>
                                        <p className="mt-1">
                                            The original settlement remains
                                            stored as evidence, but no longer
                                            contributes to net cash collected.
                                        </p>
                                    </div>
                                </div>
                                {correctionCase.refund_receipt_url ? (
                                    <Link
                                        href={correctionCase.refund_receipt_url}
                                        className="mt-4 inline-flex min-h-11 items-center rounded-md bg-[#0f5b62] px-4 font-semibold text-white hover:bg-[#0b4147] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                                    >
                                        View Cash Return Receipt
                                    </Link>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                </div>
            </div>
        </main>
    );
}

export function FinanceSettlementRefundReceiptView({
    receipt,
    back_url,
    generated_at,
}: FinanceCashSettlementRefundReceiptProps) {
    return (
        <main className="min-h-screen bg-slate-100 px-4 py-6 sm:px-6 print:bg-white print:p-0">
            <div className="mx-auto max-w-3xl space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3 print:hidden">
                    <Link
                        href={back_url}
                        className="inline-flex min-h-11 items-center gap-2 rounded-md border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        <ArrowLeft aria-hidden="true" className="size-4" />
                        Back to Case
                    </Link>
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="inline-flex min-h-11 items-center gap-2 rounded-md bg-[#0f5b62] px-4 text-sm font-semibold text-white hover:bg-[#0b4147] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        <Printer aria-hidden="true" className="size-4" />
                        Print Refund Receipt
                    </button>
                </div>

                <article
                    aria-labelledby="refund-receipt-heading"
                    className="overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-sm print:rounded-none print:border-0 print:shadow-none"
                >
                    <header className="border-b-4 border-[#24a69a] bg-[#123b5d] p-6 text-white print:border-slate-900 print:bg-white print:text-slate-950">
                        <div className="flex flex-wrap items-start justify-between gap-5">
                            <div>
                                <p className="flex items-center gap-2 text-sm font-semibold text-sky-100 print:text-slate-600">
                                    <ReceiptText
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    SIMRS Campus UEU
                                </p>
                                <h1
                                    id="refund-receipt-heading"
                                    className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold"
                                >
                                    Bukti Pengembalian Tunai
                                </h1>
                            </div>
                            <div className="text-right">
                                <p className="font-['IBM_Plex_Mono'] text-sm font-semibold">
                                    {receipt.correction_number}
                                </p>
                                <p className="mt-1 text-xs text-sky-100 print:text-slate-600">
                                    Dicetak {generated_at}
                                </p>
                            </div>
                        </div>
                    </header>
                    <div className="space-y-6 p-6">
                        <section
                            aria-labelledby="refund-receipt-status-heading"
                            className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-[#7fbcb6] bg-[#e8f5f3] p-5 text-[#0b4147]"
                        >
                            <div className="flex items-center gap-3">
                                <CheckCircle2
                                    aria-hidden="true"
                                    className="size-6"
                                />
                                <div>
                                    <h2
                                        id="refund-receipt-status-heading"
                                        className="font-semibold"
                                    >
                                        Pengembalian tunai selesai
                                    </h2>
                                    <p className="mt-1 text-sm">
                                        {formatPrintedFinanceDate(
                                            receipt.completed_at,
                                        )}
                                    </p>
                                </div>
                            </div>
                            <p className="font-['IBM_Plex_Mono'] text-2xl font-bold tabular-nums">
                                {formatPrintedRupiah(receipt.amount)}
                            </p>
                        </section>

                        <dl className="grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-2">
                            <div className="bg-white p-4">
                                <dt className="text-xs font-semibold text-slate-500 uppercase">
                                    Kuitansi asal
                                </dt>
                                <dd className="mt-2 font-['IBM_Plex_Mono'] font-semibold text-slate-950">
                                    {receipt.original_receipt_number}
                                </dd>
                            </div>
                            <div className="bg-white p-4">
                                <dt className="text-xs font-semibold text-slate-500 uppercase">
                                    Alasan koreksi
                                </dt>
                                <dd className="mt-2 text-sm font-semibold text-slate-950">
                                    {
                                        printedCorrectionReasonLabels[
                                            receipt.reason_code
                                        ]
                                    }
                                </dd>
                            </div>
                            <div className="bg-white p-4 sm:col-span-2">
                                <dt className="text-xs font-semibold text-slate-500 uppercase">
                                    Penjelasan
                                </dt>
                                <dd className="mt-2 text-sm text-slate-800">
                                    {receipt.explanation}
                                </dd>
                            </div>
                        </dl>

                        <section aria-labelledby="refund-receipt-actors-heading">
                            <h2
                                id="refund-receipt-actors-heading"
                                className="font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold"
                            >
                                Atribusi Proses
                            </h2>
                            <dl className="mt-3 grid gap-4 text-sm sm:grid-cols-3">
                                <div>
                                    <dt className="text-slate-500">
                                        Diminta oleh
                                    </dt>
                                    <dd className="mt-1 font-semibold text-slate-950">
                                        {receipt.requesting_cashier_name}
                                    </dd>
                                    <dd className="mt-1 text-xs text-slate-600">
                                        {formatPrintedFinanceDate(
                                            receipt.requested_at,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">
                                        Disetujui oleh
                                    </dt>
                                    <dd className="mt-1 font-semibold text-slate-950">
                                        {receipt.approving_supervisor_name}
                                    </dd>
                                    <dd className="mt-1 text-xs text-slate-600">
                                        {formatPrintedFinanceDate(
                                            receipt.approved_at,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">
                                        Diselesaikan oleh
                                    </dt>
                                    <dd className="mt-1 font-semibold text-slate-950">
                                        {receipt.completion_actor_name}
                                    </dd>
                                    <dd className="mt-1 text-xs text-slate-600">
                                        {formatPrintedFinanceDate(
                                            receipt.completed_at,
                                        )}
                                    </dd>
                                </div>
                            </dl>
                        </section>

                        <footer className="border-t border-slate-200 pt-4 text-xs text-slate-500">
                            <p className="font-['IBM_Plex_Mono'] break-all">
                                Bukti: {receipt.correction_public_id} ·{' '}
                                {receipt.content_digest}
                            </p>
                            <p className="mt-2">
                                Bukti ini mencatat pengembalian penuh atas
                                kuitansi asal; kuitansi asal tetap tersimpan dan
                                tidak ditimpa.
                            </p>
                        </footer>
                    </div>
                </article>
            </div>
        </main>
    );
}
