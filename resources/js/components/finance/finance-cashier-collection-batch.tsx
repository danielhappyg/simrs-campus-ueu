import { Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Banknote,
    CheckCircle2,
    CircleAlert,
    Clock3,
    HandCoins,
    LockKeyhole,
    Printer,
    ReceiptText,
    RefreshCcw,
    Scale,
    ShieldCheck,
    WalletCards,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent, RefObject } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type {
    FinanceCashDepositHandoffReceiptProps,
    FinanceCashierCollectionAction,
    FinanceCashierCollectionBatch,
    FinanceCashierCollectionDetailProps,
    FinanceCashierCollectionEvent,
    FinanceCashierCollectionState,
    FinanceCashierCollectionWorklistProps,
} from './cashier-collection-types';
import {
    formatFinanceDate,
    formatPrintedFinanceDate,
    formatPrintedRupiah,
    formatRupiah,
} from './finance-shared';

const statePresentation: Record<
    FinanceCashierCollectionState,
    { label: string; className: string; icon: typeof Clock3 }
> = {
    OPEN: {
        label: 'Open',
        className: 'border-sky-300 bg-sky-50 text-sky-950',
        icon: Clock3,
    },
    RECOUNT_REQUIRED: {
        label: 'Recount required',
        className: 'border-amber-400 bg-amber-50 text-amber-950',
        icon: RefreshCcw,
    },
    AWAITING_SUPERVISOR: {
        label: 'Pending supervisor verification',
        className: 'border-violet-300 bg-violet-50 text-violet-950',
        icon: ShieldCheck,
    },
    VERIFIED: {
        label: 'Verified · not yet handed over',
        className: 'border-emerald-300 bg-emerald-50 text-emerald-950',
        icon: CheckCircle2,
    },
    HANDED_OFF: {
        label: 'Handed over · pending treasury receipt',
        className: 'border-[#7fbcb6] bg-[#e8f5f3] text-[#0b4147]',
        icon: HandCoins,
    },
};

const eventLabels: Record<FinanceCashierCollectionEvent['event_type'], string> =
    {
        CLOSE_REQUESTED: 'Cashier submitted batch closure',
        RECOUNT_SUBMITTED: 'Cashier records recount',
        CLOSE_VERIFIED: 'Supervisor verified batch closure',
    };

function operationKey(operation: string): string {
    return `cashier-collection-${operation}-${Date.now()}-${crypto.randomUUID()}`;
}

function uniqueErrors(errors: Record<string, string | undefined>): string[] {
    return Array.from(
        new Set(
            Object.values(errors).filter((value): value is string => !!value),
        ),
    );
}

function CollectionStateBadge({
    state,
}: {
    state: FinanceCashierCollectionState;
}) {
    const presentation = statePresentation[state];
    const Icon = presentation.icon;

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold ${presentation.className}`}
        >
            <Icon aria-hidden="true" className="size-3.5" />
            {presentation.label}
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
    errorRef: RefObject<HTMLDivElement | null>;
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

function ActionUnavailable({
    action,
}: {
    action: FinanceCashierCollectionAction;
}) {
    if (action.allowed || !action.denial_reason) {
        return null;
    }

    return (
        <div className="flex gap-3 rounded-lg border border-slate-300 bg-slate-50 p-4 text-sm text-slate-700">
            <LockKeyhole
                aria-hidden="true"
                className="mt-0.5 size-5 shrink-0 text-slate-500"
            />
            <div>
                <p className="font-semibold text-slate-900">
                    Action unavailable
                </p>
                <p className="mt-1">{action.denial_reason}</p>
            </div>
        </div>
    );
}

function IntegrityAlert({ batch }: { batch: FinanceCashierCollectionBatch }) {
    if (batch.integrity.status === 'OK') {
        return null;
    }

    return (
        <div
            role="alert"
            className="flex gap-3 rounded-xl border border-red-400 bg-red-50 p-5 text-red-950"
        >
            <CircleAlert
                aria-hidden="true"
                className="mt-0.5 size-6 shrink-0"
            />
            <div>
                <p className="font-semibold">Batch evidence is inconsistent</p>
                <p className="mt-1 text-sm">
                    {batch.integrity.message ??
                        'Changes are blocked until the cash evidence is reconciled.'}
                </p>
            </div>
        </div>
    );
}

function variancePresentation(value: number | null) {
    if (value === null) {
        return {
            label: 'Not yet calculated',
            amount: '—',
            className: 'border-slate-300 bg-slate-50 text-slate-700',
        };
    }

    if (value === 0) {
        return {
            label: 'Matched',
            amount: formatRupiah(0),
            className: 'border-emerald-300 bg-emerald-50 text-emerald-950',
        };
    }

    return {
        label: value > 0 ? 'Overage' : 'Shortage',
        amount: `${value > 0 ? '+' : '−'}${formatRupiah(Math.abs(value))}`,
        className: 'border-amber-400 bg-amber-50 text-amber-950',
    };
}

function ReconciliationStrip({
    batch,
}: {
    batch: FinanceCashierCollectionBatch;
}) {
    const variance = variancePresentation(batch.variance_amount);

    return (
        <section
            aria-label="Batch cash reconciliation"
            className="overflow-hidden rounded-xl border border-slate-300 bg-slate-200"
        >
            <dl className="grid gap-px sm:grid-cols-2 xl:grid-cols-5">
                <div className="bg-white p-4">
                    <dt className="text-xs font-semibold text-slate-600">
                        Gross collection
                    </dt>
                    <dd className="mt-2 font-['IBM_Plex_Mono'] text-lg font-bold text-slate-950 tabular-nums">
                        {formatRupiah(batch.gross_amount)}
                    </dd>
                </div>
                <div className="bg-white p-4">
                    <dt className="text-xs font-semibold text-slate-600">
                        Completed refunds
                    </dt>
                    <dd className="mt-2 font-['IBM_Plex_Mono'] text-lg font-bold text-slate-950 tabular-nums">
                        {formatRupiah(batch.completed_refund_amount)}
                    </dd>
                </div>
                <div className="bg-[#123b5d] p-4 text-white">
                    <dt className="text-xs font-semibold text-sky-100">
                        Expected net cash
                    </dt>
                    <dd className="mt-2 font-['IBM_Plex_Mono'] text-xl font-bold tabular-nums">
                        {formatRupiah(batch.expected_net_amount)}
                    </dd>
                </div>
                <div className="bg-white p-4">
                    <dt className="text-xs font-semibold text-slate-600">
                        Counted physical cash
                    </dt>
                    <dd className="mt-2 font-['IBM_Plex_Mono'] text-lg font-bold text-slate-950 tabular-nums">
                        {batch.counted_amount === null
                            ? '—'
                            : formatRupiah(batch.counted_amount)}
                    </dd>
                </div>
                <div className={`border-l-4 p-4 ${variance.className}`}>
                    <dt className="text-xs font-semibold">{variance.label}</dt>
                    <dd className="mt-2 font-['IBM_Plex_Mono'] text-lg font-bold tabular-nums">
                        {variance.amount}
                    </dd>
                </div>
            </dl>
        </section>
    );
}

function OpenBatchForm({ action }: { action: FinanceCashierCollectionAction }) {
    const [status, setStatus] = useState('');
    const errorRef = useRef<HTMLDivElement>(null);
    const form = useForm({
        confirm_open: false,
        idempotency_key: operationKey('open'),
    });
    const errors = useMemo(() => uniqueErrors(form.errors), [form.errors]);

    useEffect(() => {
        if (errors.length) {
            errorRef.current?.focus();
        }
    }, [errors]);

    if (!action.allowed || !action.url) {
        return <ActionUnavailable action={action} />;
    }

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setStatus('Opening cash collection batch…');
        form.post(action.url!, {
            preserveScroll: true,
            onError: () => setStatus('The batch could not be opened.'),
            onSuccess: () => setStatus('Cash collection batch opened.'),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <ErrorSummary
                errors={errors}
                heading="The batch could not be opened"
                errorRef={errorRef}
            />
            <label className="flex min-h-11 items-start gap-3 rounded-lg border border-sky-300 bg-sky-50 p-3 text-sm text-sky-950">
                <input
                    type="checkbox"
                    checked={form.data.confirm_open}
                    onChange={(event) =>
                        form.setData('confirm_open', event.target.checked)
                    }
                    className="mt-0.5 size-5 accent-[#0f5b62]"
                />
                <span>
                    Open one new batch in my name. New cash settlements will be
                    linked to this batch until closure is submitted.
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
                    disabled={form.processing || !form.data.confirm_open}
                    className="min-h-11 bg-[#0f5b62] px-5 hover:bg-[#0b4147]"
                >
                    <WalletCards aria-hidden="true" className="size-4" />
                    {form.processing ? 'Opening…' : 'Open Batch'}
                </Button>
            </div>
        </form>
    );
}

function CountedCashField({
    id,
    value,
    onChange,
}: {
    id: string;
    value: number | '';
    onChange: (value: number | '') => void;
}) {
    return (
        <div>
            <Label htmlFor={id}>Counted physical cash</Label>
            <input
                id={id}
                type="number"
                inputMode="numeric"
                min={0}
                step={1}
                required
                value={value}
                onChange={(event) =>
                    onChange(
                        event.target.value === ''
                            ? ''
                            : Number(event.target.value),
                    )
                }
                aria-describedby={`${id}-help`}
                className="mt-2 min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 font-['IBM_Plex_Mono'] text-sm text-slate-950 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
            />
            <p id={`${id}-help`} className="mt-1 text-xs text-slate-600">
                Enter the physical cash count in whole rupiah. The expected cash
                value is calculated by the system and cannot be edited.
            </p>
        </div>
    );
}

function RequestCloseForm({
    batch,
    action,
}: {
    batch: FinanceCashierCollectionBatch;
    action: FinanceCashierCollectionAction;
}) {
    const [status, setStatus] = useState('');
    const errorRef = useRef<HTMLDivElement>(null);
    const form = useForm({
        counted_amount: '' as number | '',
        expected_state_fingerprint: batch.state_fingerprint,
        confirm_close: false,
        idempotency_key: operationKey('close'),
    });
    const errors = useMemo(() => uniqueErrors(form.errors), [form.errors]);

    useEffect(() => {
        if (errors.length) {
            errorRef.current?.focus();
        }
    }, [errors]);

    if (!action.allowed || !action.url) {
        return <ActionUnavailable action={action} />;
    }

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setStatus('Freezing batch membership…');
        form.post(action.url!, {
            preserveScroll: true,
            onError: () => setStatus('Batch closure could not be submitted.'),
            onSuccess: () => setStatus('Batch closure submitted.'),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <ErrorSummary
                errors={errors}
                heading="Batch closure could not be submitted"
                errorRef={errorRef}
            />
            <CountedCashField
                id="collection-close-counted-amount"
                value={form.data.counted_amount}
                onChange={(value) => form.setData('counted_amount', value)}
            />
            <label className="flex min-h-11 items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950">
                <input
                    type="checkbox"
                    checked={form.data.confirm_close}
                    onChange={(event) =>
                        form.setData('confirm_close', event.target.checked)
                    }
                    className="mt-0.5 size-5 accent-[#0f5b62]"
                />
                <span>
                    Freeze {batch.membership_count} receipts in batch{' '}
                    <strong>{batch.batch_number}</strong>. New settlements or
                    refunds cannot be added after this stage.
                </span>
            </label>
            <ActionFooter
                status={status}
                disabled={
                    form.processing ||
                    form.data.counted_amount === '' ||
                    !form.data.confirm_close
                }
                processing={form.processing}
                label="Submit Batch Closure"
                processingLabel="Submitting…"
                icon={Scale}
            />
        </form>
    );
}

function RecountForm({
    batch,
    action,
}: {
    batch: FinanceCashierCollectionBatch;
    action: FinanceCashierCollectionAction;
}) {
    const [status, setStatus] = useState('');
    const errorRef = useRef<HTMLDivElement>(null);
    const form = useForm({
        counted_amount: '' as number | '',
        explanation: '',
        expected_state_fingerprint: batch.state_fingerprint,
        confirm_recount: false,
        idempotency_key: operationKey('recount'),
    });
    const errors = useMemo(() => uniqueErrors(form.errors), [form.errors]);

    useEffect(() => {
        if (errors.length) {
            errorRef.current?.focus();
        }
    }, [errors]);

    if (!action.allowed || !action.url) {
        return <ActionUnavailable action={action} />;
    }

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setStatus('Recording recount result…');
        form.post(action.url!, {
            preserveScroll: true,
            onError: () => setStatus('The recount could not be recorded.'),
            onSuccess: () => setStatus('Recount result recorded.'),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <ErrorSummary
                errors={errors}
                heading="The recount could not be recorded"
                errorRef={errorRef}
            />
            <CountedCashField
                id="collection-recount-amount"
                value={form.data.counted_amount}
                onChange={(value) => form.setData('counted_amount', value)}
            />
            <div>
                <Label htmlFor="collection-recount-explanation">
                    Recount notes
                </Label>
                <textarea
                    id="collection-recount-explanation"
                    minLength={8}
                    maxLength={500}
                    required
                    value={form.data.explanation}
                    onChange={(event) =>
                        form.setData('explanation', event.target.value)
                    }
                    className="mt-2 min-h-28 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                />
            </div>
            <label className="flex min-h-11 items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950">
                <input
                    type="checkbox"
                    checked={form.data.confirm_recount}
                    onChange={(event) =>
                        form.setData('confirm_recount', event.target.checked)
                    }
                    className="mt-0.5 size-5 accent-[#0f5b62]"
                />
                <span>
                    This result is a new observation. Previous notes remain
                    stored, and the cash must not be changed.
                </span>
            </label>
            <ActionFooter
                status={status}
                disabled={
                    form.processing ||
                    form.data.counted_amount === '' ||
                    form.data.explanation.trim().length < 8 ||
                    !form.data.confirm_recount
                }
                processing={form.processing}
                label="Record Recount"
                processingLabel="Recording…"
                icon={RefreshCcw}
            />
        </form>
    );
}

function ConfirmationActionForm({
    batch,
    action,
    operation,
}: {
    batch: FinanceCashierCollectionBatch;
    action: FinanceCashierCollectionAction;
    operation: 'verify' | 'handoff';
}) {
    const isVerify = operation === 'verify';
    const [status, setStatus] = useState('');
    const errorRef = useRef<HTMLDivElement>(null);
    const form = useForm({
        expected_state_fingerprint: batch.state_fingerprint,
        confirm_action: false,
        idempotency_key: operationKey(operation),
    });
    const errors = useMemo(() => uniqueErrors(form.errors), [form.errors]);

    useEffect(() => {
        if (errors.length) {
            errorRef.current?.focus();
        }
    }, [errors]);

    if (!action.allowed || !action.url) {
        return <ActionUnavailable action={action} />;
    }

    const label = isVerify ? 'Verify Batch Closure' : 'Hand Over Deposit';

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setStatus(
            isVerify
                ? 'Verifying batch-closure evidence…'
                : 'Recording handover deposit…',
        );
        form.post(action.url!, {
            preserveScroll: true,
            onError: () => setStatus(`${label} could not be completed.`),
            onSuccess: () => setStatus(`${label} recorded.`),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <ErrorSummary
                errors={errors}
                heading={`${label} could not be completed`}
                errorRef={errorRef}
            />
            <label className="flex min-h-11 items-start gap-3 rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-950">
                <input
                    type="checkbox"
                    checked={form.data.confirm_action}
                    onChange={(event) =>
                        form.setData('confirm_action', event.target.checked)
                    }
                    className="mt-0.5 size-5 accent-[#0f5b62]"
                />
                <span>
                    {isVerify ? (
                        <>
                            I am a supervisor other than the cashier and have
                            verified the exact variance of{' '}
                            <strong>{formatRupiah(0)}</strong>.
                        </>
                    ) : (
                        <>
                            Hand over exactly{' '}
                            <strong>
                                {formatRupiah(batch.expected_net_amount)}
                            </strong>{' '}
                            based on verification. This action records only an
                            internal handoff, not treasury collection.
                        </>
                    )}
                </span>
            </label>
            <ActionFooter
                status={status}
                disabled={form.processing || !form.data.confirm_action}
                processing={form.processing}
                label={label}
                processingLabel={isVerify ? 'Verifying…' : 'Recording…'}
                icon={isVerify ? ShieldCheck : HandCoins}
            />
        </form>
    );
}

function ActionFooter({
    status,
    disabled,
    processing,
    label,
    processingLabel,
    icon: Icon,
}: {
    status: string;
    disabled: boolean;
    processing: boolean;
    label: string;
    processingLabel: string;
    icon: typeof Clock3;
}) {
    return (
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
                disabled={disabled}
                className="min-h-11 bg-[#0f5b62] px-5 hover:bg-[#0b4147]"
            >
                <Icon aria-hidden="true" className="size-4" />
                {processing ? processingLabel : label}
            </Button>
        </div>
    );
}

export function FinanceCashierCollectionWorklist({
    generated_at,
    actor_role,
    batches,
    open_batch,
    read_error,
}: FinanceCashierCollectionWorklistProps) {
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
                                <Banknote
                                    aria-hidden="true"
                                    className="size-5"
                                />
                                Physical cash controls by cashier
                            </p>
                            <h1 className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold">
                                Cash Collection Batch
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm text-sky-50">
                                Freeze receipt membership, reconcile the
                                physical cash, and record the internal handover
                                without claiming treasury receipt.
                            </p>
                        </div>
                        <div className="text-right text-xs text-sky-100">
                            <p className="font-semibold">
                                {actor_role === 'cashier'
                                    ? 'Cashier desk'
                                    : 'Cashier supervisor desk'}
                            </p>
                            <p className="mt-1 font-['IBM_Plex_Mono']">
                                Updated {generated_at}
                            </p>
                        </div>
                    </div>
                </header>

                <nav
                    aria-label="Related cashier workflows"
                    className="flex flex-wrap gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm"
                >
                    {actor_role === 'cashier' ? (
                        <Link
                            href="/kasir/tagihan"
                            className="inline-flex min-h-11 items-center gap-2 rounded-md px-3 text-sm font-semibold text-[#0d5275] hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                        >
                            <ReceiptText
                                aria-hidden="true"
                                className="size-4"
                            />
                            Bill List
                        </Link>
                    ) : null}
                    <Link
                        href="/kasir/koreksi-pelunasan"
                        className="inline-flex min-h-11 items-center gap-2 rounded-md px-3 text-sm font-semibold text-[#0d5275] hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        <RefreshCcw aria-hidden="true" className="size-4" />
                        Settlement Correction
                    </Link>
                </nav>

                {read_error ? (
                    <div
                        role="alert"
                        className="rounded-xl border border-red-400 bg-red-50 p-5 text-sm text-red-950"
                    >
                        <p className="font-semibold">
                            The batch list could not be loaded completely
                        </p>
                        <p className="mt-1">{read_error}</p>
                    </div>
                ) : null}

                {actor_role === 'cashier' ? (
                    <section
                        aria-labelledby="open-collection-batch-heading"
                        className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <div className="mb-4 flex items-start gap-3">
                            <span className="rounded-lg bg-sky-50 p-2 text-sky-800">
                                <WalletCards
                                    aria-hidden="true"
                                    className="size-5"
                                />
                            </span>
                            <div>
                                <h2
                                    id="open-collection-batch-heading"
                                    className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                                >
                                    Current Cashier Batch
                                </h2>
                                <p className="mt-1 text-sm text-slate-600">
                                    Server only allows one batch open for each
                                    cashier.
                                </p>
                            </div>
                        </div>
                        <OpenBatchForm action={open_batch} />
                    </section>
                ) : null}

                <section
                    aria-labelledby="collection-worklist-heading"
                    className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
                >
                    <header className="border-b border-slate-200 p-5">
                        <h2
                            id="collection-worklist-heading"
                            className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                        >
                            Batch List
                        </h2>
                        <p className="mt-1 text-sm text-slate-600">
                            “Verified” does not mean the deposit has been handed
                            over or received treasury.
                        </p>
                    </header>
                    {batches.length ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[62rem] text-left text-sm">
                                <caption className="sr-only">
                                    List batch cash collection
                                </caption>
                                <thead className="border-b border-slate-300 bg-slate-100 text-xs font-semibold text-slate-700">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            Batch and cashier
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Status
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right"
                                        >
                                            Receipts
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right"
                                        >
                                            Cash should be
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right"
                                        >
                                            Variance
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200">
                                    {batches.map((batch) => {
                                        const variance = variancePresentation(
                                            batch.variance_amount,
                                        );

                                        return (
                                            <tr key={batch.public_id}>
                                                <td className="px-4 py-4">
                                                    <p className="font-['IBM_Plex_Mono'] font-semibold text-slate-950">
                                                        {batch.batch_number}
                                                    </p>
                                                    <p className="mt-1 text-slate-700">
                                                        {batch.cashier_name}
                                                    </p>
                                                    <p className="mt-1 text-xs text-slate-500">
                                                        Opened{' '}
                                                        {formatFinanceDate(
                                                            batch.opened_at,
                                                        )}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-4">
                                                    <CollectionStateBadge
                                                        state={batch.state}
                                                    />
                                                    {batch.integrity.status ===
                                                    'FAILED' ? (
                                                        <p className="mt-2 font-semibold text-red-700">
                                                            Integrity check
                                                            failed
                                                        </p>
                                                    ) : null}
                                                </td>
                                                <td className="px-4 py-4 text-right font-['IBM_Plex_Mono'] tabular-nums">
                                                    {batch.membership_count}
                                                </td>
                                                <td className="px-4 py-4 text-right font-['IBM_Plex_Mono'] font-semibold tabular-nums">
                                                    {formatRupiah(
                                                        batch.expected_net_amount,
                                                    )}
                                                </td>
                                                <td className="px-4 py-4 text-right">
                                                    <span
                                                        className={`inline-flex rounded-md border px-2.5 py-1 font-['IBM_Plex_Mono'] text-xs font-semibold tabular-nums ${variance.className}`}
                                                    >
                                                        {variance.amount}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-4">
                                                    <Link
                                                        href={batch.show_url}
                                                        className="inline-flex min-h-11 items-center rounded-md border border-[#0f5b62] bg-white px-4 font-semibold text-[#0f5b62] hover:bg-[#e8f5f3] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                                                    >
                                                        Open Batch
                                                    </Link>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="p-8 text-center">
                            <WalletCards
                                aria-hidden="true"
                                className="mx-auto size-9 text-slate-400"
                            />
                            <p className="mt-3 font-semibold text-slate-900">
                                No batches to display
                            </p>
                            <p className="mt-1 text-sm text-slate-600">
                                Cashier can open batch if server allows.
                                Supervisors will see batches pending
                                examination.
                            </p>
                        </div>
                    )}
                </section>
            </div>
        </main>
    );
}

function CurrentAction({
    batch,
    actions,
}: Pick<FinanceCashierCollectionDetailProps, 'batch' | 'actions'>) {
    if (batch.integrity.status === 'FAILED') {
        return (
            <ActionUnavailable
                action={{
                    allowed: false,
                    url: null,
                    denial_reason:
                        batch.integrity.message ??
                        'Batch evidence must be reconciled before the action can continue.',
                }}
            />
        );
    }

    switch (batch.state) {
        case 'OPEN':
            return (
                <RequestCloseForm
                    batch={batch}
                    action={actions.request_close}
                />
            );
        case 'RECOUNT_REQUIRED':
            return <RecountForm batch={batch} action={actions.recount} />;
        case 'AWAITING_SUPERVISOR':
            return (
                <ConfirmationActionForm
                    key="verify"
                    batch={batch}
                    action={actions.verify}
                    operation="verify"
                />
            );
        case 'VERIFIED':
            return (
                <ConfirmationActionForm
                    key="handoff"
                    batch={batch}
                    action={actions.create_handoff}
                    operation="handoff"
                />
            );
        case 'HANDED_OFF':
            return (
                <div className="rounded-lg border border-[#7fbcb6] bg-[#e8f5f3] p-4 text-sm text-[#0b4147]">
                    <p className="font-semibold">Internal handover recorded</p>
                    <p className="mt-1">
                        This batch is pending treasury receipt. No further
                        cashier or supervisor action is available at this stage.
                    </p>
                    {batch.handoff ? (
                        <Link
                            href={batch.handoff.receipt_url}
                            className="mt-3 inline-flex min-h-11 items-center gap-2 rounded-md border border-[#0f5b62] bg-white px-4 font-semibold text-[#0f5b62] hover:bg-white/70 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                        >
                            <ReceiptText
                                aria-hidden="true"
                                className="size-4"
                            />
                            View Cash Handover Receipt
                        </Link>
                    ) : null}
                </div>
            );
    }
}

export function FinanceCashierCollectionDetail({
    generated_at,
    actor_role,
    batch,
    actions,
    back_url,
    read_error,
}: FinanceCashierCollectionDetailProps) {
    return (
        <main className="min-h-screen bg-slate-50 pb-12">
            <div className="mx-auto max-w-6xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Link
                        href={back_url}
                        className="inline-flex min-h-11 items-center gap-2 rounded-md px-3 text-sm font-semibold text-[#0d5275] hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        <ArrowLeft aria-hidden="true" className="size-4" />
                        Batch List
                    </Link>
                    <p className="font-['IBM_Plex_Mono'] text-xs text-slate-500">
                        Updated {generated_at}
                    </p>
                </div>

                <header className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-5">
                        <div>
                            <p className="text-sm font-semibold text-[#0f5b62]">
                                {actor_role === 'cashier'
                                    ? 'Cashier controls'
                                    : 'Cashier supervisor review'}
                            </p>
                            <h1 className="mt-1 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold text-slate-950">
                                {batch.batch_number}
                            </h1>
                            <p className="mt-2 text-sm text-slate-600">
                                Cashier {batch.cashier_name} · opened{' '}
                                {formatFinanceDate(batch.opened_at)}
                            </p>
                        </div>
                        <CollectionStateBadge state={batch.state} />
                    </div>
                </header>

                {read_error ? (
                    <div
                        role="alert"
                        className="rounded-xl border border-red-400 bg-red-50 p-5 text-sm text-red-950"
                    >
                        <p className="font-semibold">
                            Batch details could not be loaded completely
                        </p>
                        <p className="mt-1">{read_error}</p>
                    </div>
                ) : null}
                <IntegrityAlert batch={batch} />
                <ReconciliationStrip batch={batch} />

                <div className="grid gap-5 lg:grid-cols-[1.25fr_0.75fr]">
                    <section
                        aria-labelledby="collection-members-heading"
                        className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
                    >
                        <header className="border-b border-slate-200 p-5">
                            <h2
                                id="collection-members-heading"
                                className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                            >
                                Receipts in Batch
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                {batch.membership_count} receipts linked to this
                                batch evidence.
                            </p>
                        </header>
                        {batch.members.length ? (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[38rem] text-left text-sm">
                                    <caption className="sr-only">
                                        Receipts in batch {batch.batch_number}
                                    </caption>
                                    <thead className="border-b border-slate-300 bg-slate-100 text-xs font-semibold text-slate-700">
                                        <tr>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Receipt
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Collected
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-right"
                                            >
                                                Cash
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-200">
                                        {batch.members.map((member) => (
                                            <tr key={member.public_id}>
                                                <td className="px-4 py-4 font-['IBM_Plex_Mono'] font-semibold">
                                                    {member.receipt_number}
                                                </td>
                                                <td className="px-4 py-4 text-slate-700">
                                                    {formatFinanceDate(
                                                        member.collected_at,
                                                    )}
                                                </td>
                                                <td className="px-4 py-4 text-right font-['IBM_Plex_Mono'] font-semibold tabular-nums">
                                                    {formatRupiah(
                                                        member.amount,
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <p className="p-6 text-sm text-slate-600">
                                This batch has no cash receipts.
                            </p>
                        )}
                    </section>

                    <section
                        aria-labelledby="collection-timeline-heading"
                        className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <h2
                            id="collection-timeline-heading"
                            className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                        >
                            History Controls
                        </h2>
                        {batch.events.length ? (
                            <ol className="mt-4 space-y-4">
                                {batch.events.map((event) => (
                                    <li
                                        key={event.public_id}
                                        className="border-l-2 border-[#24a69a] pl-4"
                                    >
                                        <p className="font-semibold text-slate-950">
                                            {eventLabels[event.event_type]}
                                        </p>
                                        <p className="mt-1 text-sm text-slate-700">
                                            {event.actor_name} ·{' '}
                                            {formatFinanceDate(
                                                event.occurred_at,
                                            )}
                                        </p>
                                        <p className="mt-2 font-['IBM_Plex_Mono'] text-xs text-slate-600">
                                            Count{' '}
                                            {formatRupiah(event.counted_amount)}{' '}
                                            · Variance{' '}
                                            {
                                                variancePresentation(
                                                    event.variance_amount,
                                                ).amount
                                            }
                                        </p>
                                        {event.explanation ? (
                                            <p className="mt-2 text-sm text-slate-700">
                                                {event.explanation}
                                            </p>
                                        ) : null}
                                    </li>
                                ))}
                            </ol>
                        ) : (
                            <p className="mt-3 text-sm text-slate-600">
                                The batch is still open; there is no closure or
                                verification.
                            </p>
                        )}
                    </section>
                </div>

                <section
                    aria-labelledby="collection-current-action-heading"
                    className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div className="mb-4 flex items-start gap-3">
                        <span className="rounded-lg bg-[#e8f5f3] p-2 text-[#0f5b62]">
                            <ShieldCheck
                                aria-hidden="true"
                                className="size-5"
                            />
                        </span>
                        <div>
                            <h2
                                id="collection-current-action-heading"
                                className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                            >
                                Next Action
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                Availability action and reason denial comes from
                                server.
                            </p>
                        </div>
                    </div>
                    <CurrentAction batch={batch} actions={actions} />
                </section>

                <footer className="rounded-lg border border-slate-200 bg-white p-4 text-xs text-slate-500">
                    <p className="font-['IBM_Plex_Mono'] break-all">
                        Evidence batch: {batch.public_id} ·{' '}
                        {batch.content_digest}
                    </p>
                </footer>
            </div>
        </main>
    );
}

export function FinanceCashDepositHandoffReceiptView({
    generated_at,
    receipt,
    back_url,
}: FinanceCashDepositHandoffReceiptProps) {
    return (
        <main className="min-h-screen bg-slate-100 px-4 py-6 sm:px-6 print:bg-white print:p-0">
            <div className="mx-auto max-w-3xl space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3 print:hidden">
                    <Link
                        href={back_url}
                        className="inline-flex min-h-11 items-center gap-2 rounded-md border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        <ArrowLeft aria-hidden="true" className="size-4" />
                        Back to Batch
                    </Link>
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="inline-flex min-h-11 items-center gap-2 rounded-md bg-[#0f5b62] px-4 text-sm font-semibold text-white hover:bg-[#0b4147] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        <Printer aria-hidden="true" className="size-4" />
                        Print Handover Receipt
                    </button>
                </div>

                <article
                    aria-labelledby="cash-handoff-receipt-heading"
                    className="overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-sm print:rounded-none print:border-0 print:shadow-none"
                >
                    <header className="border-b-4 border-[#24a69a] bg-[#123b5d] p-6 text-white print:border-slate-900 print:bg-white print:text-slate-950">
                        <div className="flex flex-wrap items-start justify-between gap-5">
                            <div>
                                <p className="flex items-center gap-2 text-xs font-semibold text-sky-100 print:text-slate-600">
                                    <ReceiptText
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    SIMRS Campus UEU
                                </p>
                                <h1
                                    id="cash-handoff-receipt-heading"
                                    className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold"
                                >
                                    Bukti Penyerahan Setoran
                                </h1>
                            </div>
                            <div className="text-right">
                                <p className="font-['IBM_Plex_Mono'] text-sm font-semibold">
                                    {receipt.handoff_number}
                                </p>
                                <p className="mt-1 text-xs text-sky-100 print:text-slate-600">
                                    Dicetak {generated_at}
                                </p>
                            </div>
                        </div>
                    </header>

                    <div className="space-y-6 p-6">
                        <section
                            aria-labelledby="cash-handoff-status-heading"
                            className="flex gap-3 rounded-xl border border-amber-400 bg-amber-50 p-5 text-amber-950"
                        >
                            <Clock3
                                aria-hidden="true"
                                className="mt-0.5 size-6 shrink-0"
                            />
                            <div>
                                <h2
                                    id="cash-handoff-status-heading"
                                    className="font-semibold"
                                >
                                    Menunggu penerimaan treasury
                                </h2>
                                <p className="mt-1 text-sm">
                                    Bukti ini mencatat penyerahan internal oleh
                                    kasir. Belum merupakan penerimaan treasury,
                                    rekonsiliasi bank, atau jurnal akuntansi.
                                </p>
                            </div>
                        </section>

                        <section className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p className="text-xs font-semibold text-slate-500">
                                    Batch
                                </p>
                                <p className="mt-2 font-['IBM_Plex_Mono'] font-semibold text-slate-950">
                                    {receipt.batch_number}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold text-slate-500">
                                    Jumlah kuitansi
                                </p>
                                <p className="mt-2 font-['IBM_Plex_Mono'] font-semibold text-slate-950">
                                    {receipt.membership_count}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold text-slate-500">
                                    Kasir pemilik
                                </p>
                                <p className="mt-2 font-semibold text-slate-950">
                                    {receipt.cashier_name}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold text-slate-500">
                                    Supervisor pemeriksa
                                </p>
                                <p className="mt-2 font-semibold text-slate-950">
                                    {receipt.supervisor_name}
                                </p>
                            </div>
                        </section>

                        <dl className="grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-2">
                            <div className="bg-white p-4">
                                <dt className="text-xs font-semibold text-slate-500">
                                    Penerimaan bruto
                                </dt>
                                <dd className="mt-2 font-['IBM_Plex_Mono'] font-bold tabular-nums">
                                    {formatPrintedRupiah(receipt.gross_amount)}
                                </dd>
                            </div>
                            <div className="bg-white p-4">
                                <dt className="text-xs font-semibold text-slate-500">
                                    Pengembalian selesai
                                </dt>
                                <dd className="mt-2 font-['IBM_Plex_Mono'] font-bold tabular-nums">
                                    {formatPrintedRupiah(
                                        receipt.completed_refund_amount,
                                    )}
                                </dd>
                            </div>
                            <div className="bg-[#123b5d] p-4 text-white print:bg-white print:text-slate-950">
                                <dt className="text-xs font-semibold text-sky-100 print:text-slate-500">
                                    Kas bersih terverifikasi
                                </dt>
                                <dd className="mt-2 font-['IBM_Plex_Mono'] text-xl font-bold tabular-nums">
                                    {formatPrintedRupiah(
                                        receipt.expected_net_amount,
                                    )}
                                </dd>
                            </div>
                            <div className="bg-emerald-50 p-4 text-emerald-950 print:bg-white print:text-black">
                                <dt className="text-xs font-semibold">
                                    Selisih saat verifikasi
                                </dt>
                                <dd className="mt-2 font-['IBM_Plex_Mono'] text-xl font-bold tabular-nums">
                                    {formatPrintedRupiah(
                                        receipt.variance_amount,
                                    )}
                                </dd>
                            </div>
                        </dl>

                        <section aria-labelledby="handoff-timeline-heading">
                            <h2
                                id="handoff-timeline-heading"
                                className="font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold"
                            >
                                Waktu Kendali
                            </h2>
                            <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-slate-500">Dibuka</dt>
                                    <dd className="mt-1 font-semibold">
                                        {formatPrintedFinanceDate(
                                            receipt.opened_at,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">
                                        Dibekukan
                                    </dt>
                                    <dd className="mt-1 font-semibold">
                                        {formatPrintedFinanceDate(
                                            receipt.frozen_at,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">
                                        Diverifikasi
                                    </dt>
                                    <dd className="mt-1 font-semibold">
                                        {formatPrintedFinanceDate(
                                            receipt.verified_at,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">
                                        Diserahkan
                                    </dt>
                                    <dd className="mt-1 font-semibold">
                                        {formatPrintedFinanceDate(
                                            receipt.handed_off_at,
                                        )}
                                    </dd>
                                </div>
                            </dl>
                        </section>

                        <footer className="border-t border-slate-200 pt-4 text-xs text-slate-500">
                            <p className="font-['IBM_Plex_Mono'] break-all">
                                Bukti: {receipt.public_id} ·{' '}
                                {receipt.content_digest}
                            </p>
                            <p className="mt-1 font-['IBM_Plex_Mono'] break-all">
                                Batch: {receipt.batch_content_digest}
                            </p>
                            <p className="mt-1 font-['IBM_Plex_Mono'] break-all">
                                Verifikasi: {receipt.verified_event_digest}
                            </p>
                        </footer>
                    </div>
                </article>
            </div>
        </main>
    );
}
