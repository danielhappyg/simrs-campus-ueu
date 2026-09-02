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
import { formatFinanceDate, formatRupiah } from './finance-shared';
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
    WRONG_BILL: 'Pelunasan dicatat pada tagihan yang keliru',
    DUPLICATE_COLLECTION: 'Penerimaan tunai tercatat ganda',
    CASHIER_INPUT_CONTEXT_ERROR: 'Konteks input kasir keliru',
    OTHER_SUPERVISOR_REVIEW: 'Alasan lain yang memerlukan tinjauan supervisor',
};

export const correctionStateLabels: Record<
    FinanceCashSettlementCorrectionState,
    string
> = {
    ACTIVE: 'Pelunasan aktif',
    CORRECTION_REQUESTED: 'Menunggu tinjauan supervisor',
    REVIEW_REJECTED: 'Permintaan ditolak',
    REFUND_APPROVED: 'Pengembalian disetujui, kas belum diserahkan',
    REFUND_COMPLETED: 'Pengembalian tunai selesai',
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
    REVIEW_REJECTED: 'Supervisor menolak permintaan',
    REFUND_APPROVED: 'Supervisor menyetujui pengembalian penuh',
    REFUND_COMPLETED: 'Kas dikembalikan dan dicatat selesai',
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
        setStatus('Mengirim permintaan koreksi…');
        form.post(requestUrl, {
            preserveScroll: true,
            onError: () => setStatus('Permintaan koreksi belum terkirim.'),
            onSuccess: () => setStatus('Permintaan dikirim untuk tinjauan.'),
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
                        Ajukan Koreksi Pelunasan
                    </h3>
                    <p className="mt-1 max-w-3xl text-sm text-slate-700">
                        Permintaan tidak mengubah kuitansi. Supervisor kasir
                        akan meninjau alasan sebelum pengembalian dapat
                        disetujui.
                    </p>
                </div>
            </div>

            <form onSubmit={submit} className="mt-4 space-y-4">
                <ErrorSummary
                    errors={errors}
                    heading="Permintaan koreksi belum dapat dikirim"
                    errorRef={errorRef}
                />
                <div>
                    <Label htmlFor="finance-correction-reason">
                        Alasan koreksi
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
                        <option value="">Pilih alasan yang sesuai</option>
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
                        Penjelasan kejadian
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
                        Jelaskan apa yang keliru dan bukti yang perlu diperiksa
                        supervisor. Minimal 8 dan maksimal 500 karakter.
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
                        Saya mengonfirmasi permintaan ini untuk kuitansi{' '}
                        <strong>{settlement.receipt_number}</strong> sebesar{' '}
                        <strong>{formatRupiah(settlement.amount)}</strong>.
                        Nominal pengembalian tidak dapat diubah pada formulir
                        ini.
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
                            : 'Kirim Permintaan Koreksi'}
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
                                Kendali kasir dan supervisor
                            </p>
                            <h1 className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold">
                                Koreksi Pelunasan Tunai
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm text-sky-50">
                                Daftar permintaan, keputusan supervisor, dan
                                bukti pengembalian penuh tanpa mengubah kuitansi
                                asal.
                            </p>
                        </div>
                        <p className="font-['IBM_Plex_Mono'] text-xs text-sky-100">
                            Diperbarui {generated_at}
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
                            Daftar Perkara Koreksi
                        </h2>
                        <p className="mt-1 text-sm text-slate-600">
                            Status “disetujui” berarti kas masih harus
                            diserahkan dan dicatat selesai.
                        </p>
                    </header>
                    {cases.length ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[58rem] text-left text-sm">
                                <caption className="sr-only">
                                    Daftar perkara koreksi pelunasan tunai
                                </caption>
                                <thead className="border-b border-slate-300 bg-slate-100 text-xs tracking-wide text-slate-700 uppercase">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            Perkara dan kuitansi
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Pemohon
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Alasan
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right"
                                        >
                                            Nilai
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Tindakan
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
                                                    Buka Perkara
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
                                Belum ada perkara koreksi
                            </p>
                            <p className="mt-1 text-sm text-slate-600">
                                Permintaan dari kuitansi pelunasan akan muncul
                                di sini.
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
            label: 'Kasir mengajukan koreksi',
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
                Linimasa Bukti
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
                                    Nilai penuh {formatRupiah(entry.amount)}
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
        setStatus('Mencatat keputusan supervisor…');
        form.post(url, {
            preserveScroll: true,
            onError: () => setStatus('Keputusan belum tercatat.'),
            onSuccess: () => setStatus('Keputusan supervisor tercatat.'),
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
                        Tinjauan Supervisor Kasir
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Keputusan bersifat terminal. Persetujuan belum berarti
                        uang telah dikembalikan.
                    </p>
                </div>
            </div>
            <form onSubmit={submit} className="mt-4 space-y-4">
                <ErrorSummary
                    errors={errors}
                    heading="Keputusan belum dapat dicatat"
                    errorRef={errorRef}
                />
                <fieldset>
                    <legend className="text-sm font-semibold text-slate-900">
                        Keputusan supervisor
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
                                    Tolak permintaan
                                </strong>
                                Kuitansi asal tetap aktif sepenuhnya.
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
                                    Setujui pengembalian penuh
                                </strong>
                                Kas sebesar{' '}
                                {formatRupiah(correctionCase.amount)} masih
                                harus diserahkan dan dicatat selesai.
                            </span>
                        </label>
                    </div>
                </fieldset>
                <div>
                    <Label htmlFor="finance-correction-review-explanation">
                        Dasar keputusan
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
                        Saya telah mencocokkan kuitansi, alasan, dan bukti
                        perkara sebelum menetapkan keputusan ini.
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
                        {form.processing ? 'Mencatat…' : 'Catat Keputusan'}
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
        setStatus('Mencatat penyerahan kas…');
        form.post(url, {
            preserveScroll: true,
            onError: () => setStatus('Penyerahan kas belum tercatat.'),
            onSuccess: () => setStatus('Pengembalian tunai selesai dicatat.'),
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
                            Konfirmasi Penyerahan Kas
                        </h2>
                        <p className="mt-1 max-w-2xl text-sm text-slate-700">
                            Persetujuan sudah tercatat, tetapi pengembalian
                            belum selesai. Catat hanya setelah uang tunai
                            benar-benar diserahkan.
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
                    heading="Pengembalian belum dapat dicatat selesai"
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
                        Saya mengonfirmasi uang tunai tepat sebesar{' '}
                        <strong>{formatRupiah(correctionCase.amount)}</strong>{' '}
                        telah diserahkan kembali. Setelah dicatat, kuitansi asal
                        tidak lagi berstatus pelunasan aktif.
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
                            ? 'Mencatat…'
                            : 'Catat Pengembalian Selesai'}
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
                    Kembali ke Daftar Koreksi
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
                                Perkara Koreksi Pelunasan
                            </h1>
                            <p className="mt-2 font-['IBM_Plex_Mono'] text-sm text-sky-50">
                                Kuitansi {correctionCase.receipt_number}
                            </p>
                        </div>
                        <div className="space-y-2 text-right">
                            <CorrectionStateBadge
                                state={correctionCase.state}
                            />
                            <p className="font-['IBM_Plex_Mono'] text-xs text-sky-100">
                                Diperbarui {generated_at}
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
                                Pengembalian belum selesai
                            </p>
                            <p className="mt-1">
                                Supervisor telah menyetujui nilai penuh, tetapi
                                kas belum tercatat diserahkan kembali kepada
                                pembayar.
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
                                Ringkasan Perkara
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                Nilai diikat ke pelunasan asal dan tidak dapat
                                diedit.
                            </p>
                        </div>
                        <p className="font-['IBM_Plex_Mono'] text-2xl font-bold text-[#0b4147] tabular-nums">
                            {formatRupiah(correctionCase.amount)}
                        </p>
                    </div>
                    <dl className="mt-4 grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="bg-white p-4">
                            <dt className="text-xs font-semibold text-slate-500 uppercase">
                                Pemohon
                            </dt>
                            <dd className="mt-2 font-semibold text-slate-950">
                                {correctionCase.requesting_cashier_name}
                            </dd>
                        </div>
                        <div className="bg-white p-4">
                            <dt className="text-xs font-semibold text-slate-500 uppercase">
                                Alasan tertutup
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
                                        Perkara ditutup tanpa pengembalian
                                    </p>
                                    <p className="mt-1">
                                        Kuitansi dan pelunasan asal tetap aktif
                                        sepenuhnya.
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
                                            Pengembalian telah selesai
                                        </p>
                                        <p className="mt-1">
                                            Pelunasan asal tetap tersimpan
                                            sebagai bukti, tetapi tidak lagi
                                            menambah kas bersih terkumpul.
                                        </p>
                                    </div>
                                </div>
                                {correctionCase.refund_receipt_url ? (
                                    <Link
                                        href={correctionCase.refund_receipt_url}
                                        className="mt-4 inline-flex min-h-11 items-center rounded-md bg-[#0f5b62] px-4 font-semibold text-white hover:bg-[#0b4147] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                                    >
                                        Lihat Bukti Pengembalian
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
                        Kembali ke Perkara
                    </Link>
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="inline-flex min-h-11 items-center gap-2 rounded-md bg-[#0f5b62] px-4 text-sm font-semibold text-white hover:bg-[#0b4147] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        <Printer aria-hidden="true" className="size-4" />
                        Cetak Bukti Pengembalian
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
                                        {formatFinanceDate(
                                            receipt.completed_at,
                                        )}
                                    </p>
                                </div>
                            </div>
                            <p className="font-['IBM_Plex_Mono'] text-2xl font-bold tabular-nums">
                                {formatRupiah(receipt.amount)}
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
                                        correctionReasonLabels[
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
                                        {formatFinanceDate(
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
                                        {formatFinanceDate(receipt.approved_at)}
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
                                        {formatFinanceDate(
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
