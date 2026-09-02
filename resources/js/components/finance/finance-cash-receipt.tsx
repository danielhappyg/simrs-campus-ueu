import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    CircleAlert,
    Printer,
    ReceiptText,
} from 'lucide-react';
import { correctionStateLabels } from './finance-cash-settlement-correction';
import {
    careSettingLabels,
    financeSourceDomainLabels,
    formatFinanceDate,
    formatRupiah,
} from './finance-shared';
import type { FinanceCashReceiptProps } from './types';

export function FinanceCashReceiptView({
    receipt,
    back_url,
    generated_at,
}: FinanceCashReceiptProps) {
    const isCompleted = receipt.correction_state === 'REFUND_COMPLETED';
    const isPending =
        receipt.correction_state === 'CORRECTION_REQUESTED' ||
        receipt.correction_state === 'REFUND_APPROVED';
    const receiptStatus = isCompleted
        ? 'Dikoreksi · tunai dikembalikan'
        : receipt.correction_state === 'CORRECTION_REQUESTED'
          ? 'Koreksi diminta · pelunasan masih aktif'
          : receipt.correction_state === 'REFUND_APPROVED'
            ? 'Pengembalian disetujui · kas belum diserahkan'
            : receipt.correction_state === 'REVIEW_REJECTED'
              ? 'Lunas · permintaan koreksi ditolak'
              : 'Lunas · Tunai';

    return (
        <main className="min-h-screen bg-slate-100 px-4 py-6 sm:px-6 print:bg-white print:p-0">
            <div className="mx-auto max-w-3xl space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3 print:hidden">
                    <Link
                        href={back_url}
                        className="inline-flex min-h-11 items-center gap-2 rounded-md border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        <ArrowLeft aria-hidden="true" className="size-4" />
                        Kembali ke Tagihan
                    </Link>
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="inline-flex min-h-11 items-center gap-2 rounded-md bg-[#0f5b62] px-4 text-sm font-semibold text-white hover:bg-[#0b4147] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        <Printer aria-hidden="true" className="size-4" />
                        Cetak Kuitansi
                    </button>
                </div>

                <article
                    aria-labelledby="cash-receipt-heading"
                    className="overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-sm print:rounded-none print:border-0 print:shadow-none"
                >
                    <header className="border-b-4 border-[#24a69a] bg-[#123b5d] p-6 text-white print:border-slate-900 print:bg-white print:text-slate-950">
                        <div className="flex flex-wrap items-start justify-between gap-5">
                            <div>
                                <p className="flex items-center gap-2 text-xs font-semibold tracking-[0.14em] text-sky-100 uppercase print:text-slate-600">
                                    <ReceiptText
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    SIMRS Campus UEU
                                </p>
                                <h1
                                    id="cash-receipt-heading"
                                    className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold"
                                >
                                    Kuitansi Pelunasan
                                </h1>
                            </div>
                            <div className="text-right">
                                <p className="font-['IBM_Plex_Mono'] text-sm font-semibold">
                                    {receipt.receipt_number}
                                </p>
                                <p className="mt-1 text-xs text-sky-100 print:text-slate-600">
                                    Dicetak {generated_at}
                                </p>
                            </div>
                        </div>
                    </header>

                    <div className="space-y-6 p-6">
                        <section aria-labelledby="receipt-status-heading">
                            <div
                                className={`flex flex-wrap items-center justify-between gap-4 rounded-xl border p-5 ${
                                    isCompleted
                                        ? 'border-[#7fbcb6] bg-[#e8f5f3] text-[#0b4147]'
                                        : isPending
                                          ? 'border-amber-400 bg-amber-50 text-amber-950'
                                          : 'border-emerald-300 bg-emerald-50 text-emerald-950'
                                }`}
                            >
                                <div className="flex items-center gap-3">
                                    {isPending ? (
                                        <CircleAlert
                                            aria-hidden="true"
                                            className="size-6"
                                        />
                                    ) : (
                                        <CheckCircle2
                                            aria-hidden="true"
                                            className="size-6"
                                        />
                                    )}
                                    <div>
                                        <h2
                                            id="receipt-status-heading"
                                            className="font-semibold"
                                        >
                                            {receiptStatus}
                                        </h2>
                                        <p className="mt-1 text-sm">
                                            {formatFinanceDate(
                                                receipt.settled_at,
                                            )}
                                        </p>
                                    </div>
                                </div>
                                <p className="font-['IBM_Plex_Mono'] text-2xl font-bold tabular-nums">
                                    {formatRupiah(receipt.amount)}
                                </p>
                            </div>
                            {receipt.correction_url ? (
                                <div className="mt-3 rounded-lg border border-slate-300 bg-white p-4 text-sm text-slate-800 print:border-slate-500">
                                    <p className="font-semibold">
                                        {
                                            correctionStateLabels[
                                                receipt.correction_state
                                            ]
                                        }
                                    </p>
                                    <p className="mt-1">
                                        {isCompleted
                                            ? 'Kuitansi asal ini tetap tersimpan sebagai bukti, tetapi tidak lagi berstatus pelunasan aktif.'
                                            : 'Buka perkara untuk melihat alasan, keputusan, dan status penyerahan kas.'}
                                    </p>
                                    <Link
                                        href={receipt.correction_url}
                                        className="mt-3 inline-flex min-h-11 items-center rounded-md border border-[#0f5b62] bg-white px-4 font-semibold text-[#0f5b62] hover:bg-[#e8f5f3] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none print:hidden"
                                    >
                                        Lihat Bukti Koreksi
                                    </Link>
                                </div>
                            ) : null}
                        </section>

                        <section
                            aria-labelledby="receipt-patient-heading"
                            className="grid gap-5 sm:grid-cols-2"
                        >
                            <div>
                                <h2
                                    id="receipt-patient-heading"
                                    className="text-xs font-semibold tracking-wide text-slate-500 uppercase"
                                >
                                    Pasien
                                </h2>
                                <p className="mt-2 text-lg font-semibold text-slate-950">
                                    {receipt.patient_name}
                                </p>
                                <p className="mt-1 font-['IBM_Plex_Mono'] text-sm text-slate-600">
                                    {receipt.medical_record_number}
                                </p>
                            </div>
                            <div>
                                <h2 className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                    Episode layanan
                                </h2>
                                <p className="mt-2 font-semibold text-slate-950">
                                    {careSettingLabels[receipt.care_setting]}
                                </p>
                                <p className="mt-1 font-['IBM_Plex_Mono'] text-sm break-all text-slate-600">
                                    {receipt.encounter_public_id}
                                </p>
                            </div>
                        </section>

                        <dl className="grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-3">
                            <div className="bg-white p-4">
                                <dt className="text-xs font-semibold text-slate-500 uppercase">
                                    Nomor tagihan
                                </dt>
                                <dd className="mt-2 font-['IBM_Plex_Mono'] font-semibold text-slate-950">
                                    {receipt.bill_number}
                                </dd>
                            </div>
                            <div className="bg-white p-4">
                                <dt className="text-xs font-semibold text-slate-500 uppercase">
                                    Versi tagihan
                                </dt>
                                <dd className="mt-2 font-['IBM_Plex_Mono'] font-semibold text-slate-950">
                                    Versi {receipt.bill_version}
                                </dd>
                            </div>
                            <div className="bg-white p-4">
                                <dt className="text-xs font-semibold text-slate-500 uppercase">
                                    Kasir
                                </dt>
                                <dd className="mt-2 font-semibold text-slate-950">
                                    {receipt.cashier_name}
                                </dd>
                            </div>
                        </dl>

                        <section aria-labelledby="receipt-coverage-heading">
                            <h2
                                id="receipt-coverage-heading"
                                className="text-xs font-semibold tracking-wide text-slate-500 uppercase"
                            >
                                Sumber biaya tercakup
                            </h2>
                            <ul className="mt-3 flex flex-wrap gap-2">
                                {receipt.source_domains.map((domain) => (
                                    <li
                                        key={domain}
                                        className="rounded-full border border-sky-300 bg-sky-50 px-3 py-1 text-sm font-semibold text-sky-950"
                                    >
                                        {financeSourceDomainLabels[domain]}
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-3 text-sm text-slate-700">
                                {receipt.coverage_label}
                            </p>
                            <p className="mt-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm font-medium text-amber-950">
                                Kuitansi ini melunasi hanya versi tagihan dan
                                sumber biaya yang tercakup di atas.{' '}
                                {receipt.coverage_exclusion}
                            </p>
                        </section>

                        <footer className="border-t border-slate-200 pt-4 text-xs text-slate-500">
                            <p className="font-['IBM_Plex_Mono'] break-all">
                                Bukti: {receipt.public_id} ·{' '}
                                {receipt.content_digest}
                            </p>
                        </footer>
                    </div>
                </article>
            </div>
        </main>
    );
}
