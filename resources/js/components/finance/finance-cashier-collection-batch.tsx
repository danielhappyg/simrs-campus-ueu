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
import { formatFinanceDate, formatRupiah } from './finance-shared';

const statePresentation: Record<
    FinanceCashierCollectionState,
    { label: string; className: string; icon: typeof Clock3 }
> = {
    OPEN: {
        label: 'Terbuka',
        className: 'border-sky-300 bg-sky-50 text-sky-950',
        icon: Clock3,
    },
    RECOUNT_REQUIRED: {
        label: 'Hitung ulang diperlukan',
        className: 'border-amber-400 bg-amber-50 text-amber-950',
        icon: RefreshCcw,
    },
    AWAITING_SUPERVISOR: {
        label: 'Menunggu verifikasi supervisor',
        className: 'border-violet-300 bg-violet-50 text-violet-950',
        icon: ShieldCheck,
    },
    VERIFIED: {
        label: 'Terverifikasi · belum diserahkan',
        className: 'border-emerald-300 bg-emerald-50 text-emerald-950',
        icon: CheckCircle2,
    },
    HANDED_OFF: {
        label: 'Diserahkan · menunggu treasury',
        className: 'border-[#7fbcb6] bg-[#e8f5f3] text-[#0b4147]',
        icon: HandCoins,
    },
};

const eventLabels: Record<FinanceCashierCollectionEvent['event_type'], string> =
    {
        CLOSE_REQUESTED: 'Kasir mengajukan tutup batch',
        RECOUNT_SUBMITTED: 'Kasir mencatat hitung ulang',
        CLOSE_VERIFIED: 'Supervisor memverifikasi tutup batch',
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
                    Tindakan belum tersedia
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
                <p className="font-semibold">Bukti batch tidak konsisten</p>
                <p className="mt-1 text-sm">
                    {batch.integrity.message ??
                        'Perubahan dihentikan sampai bukti kas direkonsiliasi.'}
                </p>
            </div>
        </div>
    );
}

function variancePresentation(value: number | null) {
    if (value === null) {
        return {
            label: 'Belum dihitung',
            amount: '—',
            className: 'border-slate-300 bg-slate-50 text-slate-700',
        };
    }

    if (value === 0) {
        return {
            label: 'Cocok',
            amount: formatRupiah(0),
            className: 'border-emerald-300 bg-emerald-50 text-emerald-950',
        };
    }

    return {
        label: value > 0 ? 'Selisih lebih' : 'Selisih kurang',
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
            aria-label="Rekonsiliasi kas batch"
            className="overflow-hidden rounded-xl border border-slate-300 bg-slate-200"
        >
            <dl className="grid gap-px sm:grid-cols-2 xl:grid-cols-5">
                <div className="bg-white p-4">
                    <dt className="text-xs font-semibold text-slate-600">
                        Penerimaan bruto
                    </dt>
                    <dd className="mt-2 font-['IBM_Plex_Mono'] text-lg font-bold text-slate-950 tabular-nums">
                        {formatRupiah(batch.gross_amount)}
                    </dd>
                </div>
                <div className="bg-white p-4">
                    <dt className="text-xs font-semibold text-slate-600">
                        Pengembalian selesai
                    </dt>
                    <dd className="mt-2 font-['IBM_Plex_Mono'] text-lg font-bold text-slate-950 tabular-nums">
                        {formatRupiah(batch.completed_refund_amount)}
                    </dd>
                </div>
                <div className="bg-[#123b5d] p-4 text-white">
                    <dt className="text-xs font-semibold text-sky-100">
                        Kas bersih seharusnya
                    </dt>
                    <dd className="mt-2 font-['IBM_Plex_Mono'] text-xl font-bold tabular-nums">
                        {formatRupiah(batch.expected_net_amount)}
                    </dd>
                </div>
                <div className="bg-white p-4">
                    <dt className="text-xs font-semibold text-slate-600">
                        Kas fisik terhitung
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
        setStatus('Membuka batch penerimaan kas…');
        form.post(action.url!, {
            preserveScroll: true,
            onError: () => setStatus('Batch belum dapat dibuka.'),
            onSuccess: () => setStatus('Batch penerimaan kas dibuka.'),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <ErrorSummary
                errors={errors}
                heading="Batch belum dapat dibuka"
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
                    Buka satu batch baru atas nama saya. Pelunasan tunai baru
                    akan terikat ke batch ini sampai tutup batch diajukan.
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
                    {form.processing ? 'Membuka…' : 'Buka Batch'}
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
            <Label htmlFor={id}>Kas fisik terhitung</Label>
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
                Masukkan hasil hitung fisik dalam rupiah bulat. Nilai kas
                seharusnya dihitung oleh sistem dan tidak dapat diedit.
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
        setStatus('Membekukan keanggotaan batch…');
        form.post(action.url!, {
            preserveScroll: true,
            onError: () => setStatus('Tutup batch belum dapat diajukan.'),
            onSuccess: () => setStatus('Tutup batch diajukan.'),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <ErrorSummary
                errors={errors}
                heading="Tutup batch belum dapat diajukan"
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
                    Bekukan {batch.membership_count} kuitansi dalam batch{' '}
                    <strong>{batch.batch_number}</strong>. Pelunasan atau
                    pengembalian baru tidak dapat masuk setelah tahap ini.
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
                label="Ajukan Tutup Batch"
                processingLabel="Mengajukan…"
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
        setStatus('Mencatat hasil hitung ulang…');
        form.post(action.url!, {
            preserveScroll: true,
            onError: () => setStatus('Hitung ulang belum dapat dicatat.'),
            onSuccess: () => setStatus('Hasil hitung ulang dicatat.'),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <ErrorSummary
                errors={errors}
                heading="Hitung ulang belum dapat dicatat"
                errorRef={errorRef}
            />
            <CountedCashField
                id="collection-recount-amount"
                value={form.data.counted_amount}
                onChange={(value) => form.setData('counted_amount', value)}
            />
            <div>
                <Label htmlFor="collection-recount-explanation">
                    Catatan hitung ulang
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
                    Hasil ini adalah pengamatan baru. Catatan sebelumnya tetap
                    tersimpan dan kas seharusnya tidak berubah.
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
                label="Catat Hitung Ulang"
                processingLabel="Mencatat…"
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

    const label = isVerify ? 'Verifikasi Tutup Batch' : 'Serahkan Setoran';

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setStatus(
            isVerify
                ? 'Memverifikasi bukti tutup batch…'
                : 'Mencatat penyerahan setoran…',
        );
        form.post(action.url!, {
            preserveScroll: true,
            onError: () => setStatus(`${label} belum dapat diselesaikan.`),
            onSuccess: () => setStatus(`${label} dicatat.`),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <ErrorSummary
                errors={errors}
                heading={`${label} belum dapat diselesaikan`}
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
                            Saya adalah supervisor yang berbeda dari kasir dan
                            telah memeriksa selisih tepat{' '}
                            <strong>{formatRupiah(0)}</strong>.
                        </>
                    ) : (
                        <>
                            Serahkan tepat{' '}
                            <strong>
                                {formatRupiah(batch.expected_net_amount)}
                            </strong>{' '}
                            berdasarkan verifikasi. Tindakan ini hanya mencatat
                            handoff internal, bukan penerimaan treasury.
                        </>
                    )}
                </span>
            </label>
            <ActionFooter
                status={status}
                disabled={form.processing || !form.data.confirm_action}
                processing={form.processing}
                label={label}
                processingLabel={isVerify ? 'Memverifikasi…' : 'Mencatat…'}
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
                                Kendali kas fisik per kasir
                            </p>
                            <h1 className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold">
                                Batch Penerimaan Kas
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm text-sky-50">
                                Bekukan kuitansi, cocokkan kas fisik, lalu catat
                                penyerahan internal tanpa mengklaim penerimaan
                                treasury.
                            </p>
                        </div>
                        <div className="text-right text-xs text-sky-100">
                            <p className="font-semibold">
                                {actor_role === 'cashier'
                                    ? 'Meja kasir'
                                    : 'Meja supervisor kasir'}
                            </p>
                            <p className="mt-1 font-['IBM_Plex_Mono']">
                                Diperbarui {generated_at}
                            </p>
                        </div>
                    </div>
                </header>

                <nav
                    aria-label="Alur kerja kasir terkait"
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
                            Daftar Tagihan
                        </Link>
                    ) : null}
                    <Link
                        href="/kasir/koreksi-pelunasan"
                        className="inline-flex min-h-11 items-center gap-2 rounded-md px-3 text-sm font-semibold text-[#0d5275] hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        <RefreshCcw aria-hidden="true" className="size-4" />
                        Koreksi Pelunasan
                    </Link>
                </nav>

                {read_error ? (
                    <div
                        role="alert"
                        className="rounded-xl border border-red-400 bg-red-50 p-5 text-sm text-red-950"
                    >
                        <p className="font-semibold">
                            Daftar batch belum dapat dimuat lengkap
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
                                    Batch Kasir Saat Ini
                                </h2>
                                <p className="mt-1 text-sm text-slate-600">
                                    Server hanya mengizinkan satu batch terbuka
                                    untuk setiap kasir.
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
                            Daftar Batch
                        </h2>
                        <p className="mt-1 text-sm text-slate-600">
                            “Terverifikasi” belum berarti setoran telah
                            diserahkan atau diterima treasury.
                        </p>
                    </header>
                    {batches.length ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[62rem] text-left text-sm">
                                <caption className="sr-only">
                                    Daftar batch penerimaan kas
                                </caption>
                                <thead className="border-b border-slate-300 bg-slate-100 text-xs font-semibold text-slate-700">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            Batch dan kasir
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Status
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right"
                                        >
                                            Kuitansi
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right"
                                        >
                                            Kas seharusnya
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right"
                                        >
                                            Selisih
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Tindakan
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
                                                        Dibuka{' '}
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
                                                            Integritas gagal
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
                                                        Buka Batch
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
                                Belum ada batch yang dapat ditampilkan
                            </p>
                            <p className="mt-1 text-sm text-slate-600">
                                Kasir dapat membuka batch jika server
                                mengizinkan. Supervisor akan melihat batch yang
                                menunggu pemeriksaan.
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
                        'Bukti batch harus direkonsiliasi sebelum tindakan dilanjutkan.',
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
                    <p className="font-semibold">
                        Penyerahan internal tercatat
                    </p>
                    <p className="mt-1">
                        Batch menunggu penerimaan treasury. Tidak ada tindakan
                        kasir atau supervisor lanjutan dalam tahap ini.
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
                            Lihat Bukti Penyerahan
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
                        Daftar Batch
                    </Link>
                    <p className="font-['IBM_Plex_Mono'] text-xs text-slate-500">
                        Diperbarui {generated_at}
                    </p>
                </div>

                <header className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-5">
                        <div>
                            <p className="text-sm font-semibold text-[#0f5b62]">
                                {actor_role === 'cashier'
                                    ? 'Kendali kasir'
                                    : 'Tinjauan supervisor kasir'}
                            </p>
                            <h1 className="mt-1 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold text-slate-950">
                                {batch.batch_number}
                            </h1>
                            <p className="mt-2 text-sm text-slate-600">
                                Kasir {batch.cashier_name} · dibuka{' '}
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
                            Detail batch belum dapat dimuat lengkap
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
                                Kuitansi dalam Batch
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                {batch.membership_count} kuitansi terikat pada
                                bukti batch ini.
                            </p>
                        </header>
                        {batch.members.length ? (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[38rem] text-left text-sm">
                                    <caption className="sr-only">
                                        Kuitansi anggota batch{' '}
                                        {batch.batch_number}
                                    </caption>
                                    <thead className="border-b border-slate-300 bg-slate-100 text-xs font-semibold text-slate-700">
                                        <tr>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Kuitansi
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Dikumpulkan
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-right"
                                            >
                                                Tunai
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
                                Belum ada kuitansi tunai dalam batch ini.
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
                            Riwayat Kendali
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
                                            Hitung{' '}
                                            {formatRupiah(event.counted_amount)}{' '}
                                            · Selisih{' '}
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
                                Batch masih terbuka; belum ada peristiwa tutup
                                atau verifikasi.
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
                                Tindakan Berikutnya
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                Ketersediaan tindakan dan alasan penolakan
                                berasal dari server.
                            </p>
                        </div>
                    </div>
                    <CurrentAction batch={batch} actions={actions} />
                </section>

                <footer className="rounded-lg border border-slate-200 bg-white p-4 text-xs text-slate-500">
                    <p className="font-['IBM_Plex_Mono'] break-all">
                        Bukti batch: {batch.public_id} · {batch.content_digest}
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
                        Kembali ke Batch
                    </Link>
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="inline-flex min-h-11 items-center gap-2 rounded-md bg-[#0f5b62] px-4 text-sm font-semibold text-white hover:bg-[#0b4147] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        <Printer aria-hidden="true" className="size-4" />
                        Cetak Bukti Penyerahan
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
                                    {formatRupiah(receipt.gross_amount)}
                                </dd>
                            </div>
                            <div className="bg-white p-4">
                                <dt className="text-xs font-semibold text-slate-500">
                                    Pengembalian selesai
                                </dt>
                                <dd className="mt-2 font-['IBM_Plex_Mono'] font-bold tabular-nums">
                                    {formatRupiah(
                                        receipt.completed_refund_amount,
                                    )}
                                </dd>
                            </div>
                            <div className="bg-[#123b5d] p-4 text-white print:bg-white print:text-slate-950">
                                <dt className="text-xs font-semibold text-sky-100 print:text-slate-500">
                                    Kas bersih terverifikasi
                                </dt>
                                <dd className="mt-2 font-['IBM_Plex_Mono'] text-xl font-bold tabular-nums">
                                    {formatRupiah(receipt.expected_net_amount)}
                                </dd>
                            </div>
                            <div className="bg-emerald-50 p-4 text-emerald-950 print:bg-white print:text-black">
                                <dt className="text-xs font-semibold">
                                    Selisih saat verifikasi
                                </dt>
                                <dd className="mt-2 font-['IBM_Plex_Mono'] text-xl font-bold tabular-nums">
                                    {formatRupiah(receipt.variance_amount)}
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
                                        {formatFinanceDate(receipt.opened_at)}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">
                                        Dibekukan
                                    </dt>
                                    <dd className="mt-1 font-semibold">
                                        {formatFinanceDate(receipt.frozen_at)}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">
                                        Diverifikasi
                                    </dt>
                                    <dd className="mt-1 font-semibold">
                                        {formatFinanceDate(receipt.verified_at)}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">
                                        Diserahkan
                                    </dt>
                                    <dd className="mt-1 font-semibold">
                                        {formatFinanceDate(
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
