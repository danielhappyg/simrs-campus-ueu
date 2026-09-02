import { Link, useForm } from '@inertiajs/react';
import {
    Banknote,
    CheckCircle2,
    CircleAlert,
    FileCheck2,
    History,
    ReceiptText,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    correctionStateLabels,
    FinanceSettlementCorrectionRequestPanel,
} from './finance-cash-settlement-correction';
import {
    careSettingLabels,
    FinanceBackLink,
    FinanceCoverageRail,
    financeReadinessLabels,
    financeFieldClass,
    financeSourceDomainLabels,
    FinanceStateBadge,
    formatFinanceDate,
    formatRupiah,
} from './finance-shared';
import type {
    FinanceAccommodationTariffProvenance,
    FinanceBillDetailProps,
    FinanceCashSettlementContext,
    FinanceSourceLine,
    FinanceSourceReadiness,
} from './types';

function newIdempotencyKey(): string {
    return `finance-issue-${Date.now()}-${crypto.randomUUID()}`;
}

function newSettlementIdempotencyKey(): string {
    return `finance-settlement-${Date.now()}-${crypto.randomUUID()}`;
}

function AccommodationTariffProvenance({
    provenance,
}: {
    provenance: FinanceAccommodationTariffProvenance;
}) {
    return (
        <dl className="grid gap-2 border-t border-slate-200 p-3 text-xs md:grid-cols-2">
            <div>
                <dt className="text-slate-500">Hari okupansi</dt>
                <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                    {provenance.service_date} · {provenance.pricing_unit}
                    <span className="mt-1 block">
                        Jangkar:{' '}
                        {formatFinanceDate(provenance.occupancy_anchor_at)}
                    </span>
                </dd>
            </div>
            <div>
                <dt className="text-slate-500">Tempat tidur tepat</dt>
                <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                    {provenance.bed_code} · v{provenance.inpatient_bed_version}
                    <span className="mt-1 block">
                        {provenance.inpatient_bed_version_public_id}
                    </span>
                    <span className="mt-1 block">
                        Digest: {provenance.inpatient_bed_content_digest}
                    </span>
                </dd>
            </div>
            <div>
                <dt className="text-slate-500">Penempatan dan interval</dt>
                <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                    {provenance.ward_code} · {provenance.room_label} ·{' '}
                    {provenance.service_class}
                    <span className="mt-1 block">
                        {formatFinanceDate(provenance.interval_start_at)} —{' '}
                        {formatFinanceDate(provenance.interval_end_at)}
                    </span>
                    <span className="mt-1 block">
                        Penutup {provenance.closing_type}:{' '}
                        {provenance.closing_public_id}
                    </span>
                </dd>
            </div>
            <div>
                <dt className="text-slate-500">Pemetaan</dt>
                <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                    {provenance.binding_public_id} · v
                    {provenance.binding_version}
                    <span className="mt-1 block">
                        {provenance.binding_version_public_id}
                    </span>
                    <span className="mt-1 block">
                        Digest: {provenance.binding_content_digest}
                    </span>
                </dd>
            </div>
            <div>
                <dt className="text-slate-500">Tarif dan komponen biaya</dt>
                <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                    {provenance.tariff_item_code}
                    <span className="mt-1 block">
                        {provenance.component_code}
                    </span>
                    <span className="mt-1 block">
                        Berlaku mulai: {provenance.effective_from}
                    </span>
                </dd>
            </div>
            <div>
                <dt className="text-slate-500">Digest tarif tepat</dt>
                <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                    {provenance.tariff_content_digest}
                </dd>
            </div>
        </dl>
    );
}

function SourceTable({
    lines,
    caption,
}: {
    lines: FinanceSourceLine[];
    caption: string;
}) {
    return (
        <div className="overflow-x-auto rounded-lg border border-slate-200">
            <table className="w-full min-w-[56rem] border-collapse text-left text-sm">
                <caption className="sr-only">{caption}</caption>
                <thead className="border-b border-slate-300 bg-slate-100 text-xs tracking-wide text-slate-700 uppercase">
                    <tr>
                        <th scope="col" className="px-4 py-3">
                            Sumber biaya
                        </th>
                        <th scope="col" className="px-4 py-3">
                            Uraian
                        </th>
                        <th scope="col" className="px-4 py-3 text-right">
                            Jumlah
                        </th>
                        <th scope="col" className="px-4 py-3 text-right">
                            Nilai satuan
                        </th>
                        <th scope="col" className="px-4 py-3 text-right">
                            Nilai baris
                        </th>
                        <th scope="col" className="px-4 py-3">
                            Waktu sumber
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-200 bg-white">
                    {lines.map((line) => (
                        <tr key={line.public_id} className="align-top">
                            <th scope="row" className="px-4 py-3 font-normal">
                                <span
                                    className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold ${
                                        line.event_type === 'CHARGE'
                                            ? 'border-sky-300 bg-sky-50 text-sky-900'
                                            : 'border-orange-300 bg-orange-50 text-orange-950'
                                    }`}
                                >
                                    {line.source_domain === 'ACCOMMODATION'
                                        ? 'Hari okupansi akomodasi'
                                        : line.source_domain === 'RADIOLOGY'
                                          ? 'Pemeriksaan radiologi'
                                          : line.source_domain === 'LABORATORY'
                                            ? 'Hasil asli laboratorium VERIFIED'
                                            : line.event_type === 'CHARGE'
                                              ? 'Penyerahan obat'
                                              : 'Retur obat'}
                                </span>
                                <span className="mt-1 block text-xs font-semibold text-slate-700">
                                    {
                                        financeSourceDomainLabels[
                                            line.source_domain
                                        ]
                                    }
                                </span>
                                <span className="mt-1 block font-['IBM_Plex_Mono'] text-xs text-slate-500">
                                    {line.source_reference}
                                </span>
                            </th>
                            <td className="px-4 py-3 text-slate-800">
                                {line.description}
                                {line.tariff_provenance ? (
                                    <details className="mt-2 rounded-md border border-slate-200 bg-slate-50">
                                        <summary className="flex min-h-11 cursor-pointer items-center px-3 text-xs font-semibold text-[#0d5275] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none">
                                            Provenans tarif
                                        </summary>
                                        {line.source_domain ===
                                        'ACCOMMODATION' ? (
                                            <AccommodationTariffProvenance
                                                provenance={
                                                    line.tariff_provenance
                                                }
                                            />
                                        ) : (
                                            <dl className="grid gap-2 border-t border-slate-200 p-3 text-xs md:grid-cols-2">
                                                <div>
                                                    <dt className="text-slate-500">
                                                        {line.source_domain ===
                                                        'LABORATORY'
                                                            ? 'Master laboratorium'
                                                            : 'Master radiologi'}
                                                    </dt>
                                                    <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                                                        {line.source_domain ===
                                                        'LABORATORY'
                                                            ? line
                                                                  .tariff_provenance
                                                                  .laboratory_master_code
                                                            : line
                                                                  .tariff_provenance
                                                                  .radiology_master_code}{' '}
                                                        · v
                                                        {line.source_domain ===
                                                        'LABORATORY'
                                                            ? line
                                                                  .tariff_provenance
                                                                  .laboratory_master_version
                                                            : line
                                                                  .tariff_provenance
                                                                  .radiology_master_version}
                                                        <span className="mt-1 block">
                                                            {line.source_domain ===
                                                            'LABORATORY'
                                                                ? line
                                                                      .tariff_provenance
                                                                      .laboratory_master_version_public_id
                                                                : line
                                                                      .tariff_provenance
                                                                      .radiology_master_version_public_id}
                                                        </span>
                                                        <span className="mt-1 block">
                                                            Digest:{' '}
                                                            {line.source_domain ===
                                                            'LABORATORY'
                                                                ? line
                                                                      .tariff_provenance
                                                                      .laboratory_master_content_digest
                                                                : line
                                                                      .tariff_provenance
                                                                      .radiology_master_content_digest}
                                                        </span>
                                                    </dd>
                                                </div>
                                                {line.source_domain ===
                                                'LABORATORY' ? (
                                                    <div>
                                                        <dt className="text-slate-500">
                                                            Hasil asli VERIFIED
                                                        </dt>
                                                        <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                                                            {
                                                                line
                                                                    .tariff_provenance
                                                                    .laboratory_result_public_id
                                                            }{' '}
                                                            · v
                                                            {
                                                                line
                                                                    .tariff_provenance
                                                                    .laboratory_result_version
                                                            }
                                                            <span className="mt-1 block">
                                                                Diverifikasi:{' '}
                                                                {formatFinanceDate(
                                                                    line
                                                                        .tariff_provenance
                                                                        .laboratory_verified_at,
                                                                )}
                                                            </span>
                                                            <span className="mt-1 block">
                                                                Digest:{' '}
                                                                {
                                                                    line
                                                                        .tariff_provenance
                                                                        .laboratory_result_content_digest
                                                                }
                                                            </span>
                                                        </dd>
                                                    </div>
                                                ) : null}
                                                {line.source_domain ===
                                                'LABORATORY' ? (
                                                    <div>
                                                        <dt className="text-slate-500">
                                                            Spesimen
                                                        </dt>
                                                        <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                                                            {
                                                                line
                                                                    .tariff_provenance
                                                                    .laboratory_specimen_public_id
                                                            }
                                                        </dd>
                                                    </div>
                                                ) : null}
                                                <div>
                                                    <dt className="text-slate-500">
                                                        Pemetaan
                                                    </dt>
                                                    <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                                                        {
                                                            line
                                                                .tariff_provenance
                                                                .binding_public_id
                                                        }{' '}
                                                        · v
                                                        {
                                                            line
                                                                .tariff_provenance
                                                                .binding_version
                                                        }
                                                        <span className="mt-1 block">
                                                            {
                                                                line
                                                                    .tariff_provenance
                                                                    .binding_version_public_id
                                                            }
                                                        </span>
                                                        <span className="mt-1 block">
                                                            Digest:{' '}
                                                            {
                                                                line
                                                                    .tariff_provenance
                                                                    .binding_content_digest
                                                            }
                                                        </span>
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-slate-500">
                                                        Tarif
                                                    </dt>
                                                    <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                                                        {
                                                            line
                                                                .tariff_provenance
                                                                .tariff_code
                                                        }{' '}
                                                        · v
                                                        {
                                                            line
                                                                .tariff_provenance
                                                                .tariff_item_version
                                                        }
                                                        <span className="mt-1 block">
                                                            {
                                                                line
                                                                    .tariff_provenance
                                                                    .tariff_item_version_public_id
                                                            }
                                                        </span>
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-slate-500">
                                                        Komponen biaya
                                                    </dt>
                                                    <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                                                        {
                                                            line
                                                                .tariff_provenance
                                                                .component_code
                                                        }
                                                        <span className="mt-1 block">
                                                            {
                                                                line
                                                                    .tariff_provenance
                                                                    .component_public_id
                                                            }
                                                        </span>
                                                        <span className="mt-1 block">
                                                            Digest:{' '}
                                                            {
                                                                line
                                                                    .tariff_provenance
                                                                    .component_content_digest
                                                            }
                                                        </span>
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-slate-500">
                                                        Masa berlaku tarif
                                                    </dt>
                                                    <dd className="mt-1 font-['IBM_Plex_Mono']">
                                                        Tanggal layanan:{' '}
                                                        {
                                                            line
                                                                .tariff_provenance
                                                                .service_date
                                                        }
                                                        <span className="mt-1 block">
                                                            Berlaku mulai:{' '}
                                                            {
                                                                line
                                                                    .tariff_provenance
                                                                    .effective_from
                                                            }
                                                        </span>
                                                    </dd>
                                                </div>
                                                <div className="md:col-span-2">
                                                    <dt className="text-slate-500">
                                                        Digest tarif tepat
                                                    </dt>
                                                    <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                                                        {
                                                            line
                                                                .tariff_provenance
                                                                .tariff_content_digest
                                                        }
                                                    </dd>
                                                </div>
                                            </dl>
                                        )}
                                    </details>
                                ) : null}
                            </td>
                            <td className="px-4 py-3 text-right font-['IBM_Plex_Mono'] tabular-nums">
                                {line.quantity}
                            </td>
                            <td className="px-4 py-3 text-right font-['IBM_Plex_Mono'] tabular-nums">
                                {formatRupiah(line.unit_amount)}
                            </td>
                            <td className="px-4 py-3 text-right font-['IBM_Plex_Mono'] font-semibold tabular-nums">
                                {formatRupiah(line.signed_amount)}
                            </td>
                            <td className="px-4 py-3 text-slate-600">
                                {formatFinanceDate(line.occurred_at)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ControlTotals({
    gross,
    reversal,
    net,
}: {
    gross: number;
    reversal: number;
    net: number;
}) {
    return (
        <dl className="grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-3">
            <div className="bg-white p-4">
                <dt className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                    Biaya tercatat
                </dt>
                <dd className="mt-2 font-['IBM_Plex_Mono'] text-lg font-semibold text-slate-950 tabular-nums">
                    {formatRupiah(gross)}
                </dd>
            </div>
            <div className="bg-white p-4">
                <dt className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                    Retur
                </dt>
                <dd className="mt-2 font-['IBM_Plex_Mono'] text-lg font-semibold text-orange-800 tabular-nums">
                    {formatRupiah(reversal)}
                </dd>
            </div>
            <div className="bg-[#e8f5f3] p-4">
                <dt className="text-xs font-semibold tracking-wide text-[#0f5b62] uppercase">
                    Neto sumber tercakup
                </dt>
                <dd className="mt-2 font-['IBM_Plex_Mono'] text-xl font-bold text-[#0b4147] tabular-nums">
                    {formatRupiah(net)}
                </dd>
            </div>
        </dl>
    );
}

function IssueVersionPanel({
    url,
    fingerprint,
    version,
}: {
    url: string;
    fingerprint: string;
    version: number;
}) {
    const [status, setStatus] = useState('');
    const errorRef = useRef<HTMLDivElement>(null);
    const form = useForm({
        expected_fingerprint: fingerprint,
        issue_reason: '',
        confirm_issue: false,
        idempotency_key: newIdempotencyKey(),
    });
    const errorMessages = useMemo(
        () => Array.from(new Set(Object.values(form.errors))),
        [form.errors],
    );

    useEffect(() => {
        if (errorMessages.length) {
            errorRef.current?.focus();
        }
    }, [errorMessages]);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setStatus('Menerbitkan versi tagihan…');
        form.post(url, {
            preserveScroll: true,
            onError: () => setStatus('Versi belum diterbitkan.'),
            onSuccess: () => {
                setStatus(`Versi ${version + 1} diterbitkan.`);
                form.setData('confirm_issue', false);
                form.reset('issue_reason');
                form.setData('idempotency_key', newIdempotencyKey());
            },
        });
    };

    return (
        <section
            aria-labelledby="finance-issue-heading"
            className="rounded-xl border border-[#7fbcb6] bg-white p-5 shadow-sm"
        >
            <div className="flex items-start gap-3">
                <span className="rounded-lg bg-[#e8f5f3] p-2 text-[#0f5b62]">
                    <FileCheck2 aria-hidden="true" className="size-5" />
                </span>
                <div>
                    <h2
                        id="finance-issue-heading"
                        className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                    >
                        Terbitkan Versi Tagihan
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Versi baru mengunci sumber biaya yang tersedia saat
                        penerbitan. Riwayat sebelumnya tetap utuh.
                    </p>
                </div>
            </div>

            {errorMessages.length ? (
                <div
                    ref={errorRef}
                    tabIndex={-1}
                    role="alert"
                    aria-labelledby="finance-error-heading"
                    className="mt-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-950 outline-none focus:ring-2 focus:ring-red-600"
                >
                    <p id="finance-error-heading" className="font-semibold">
                        Versi tagihan belum dapat diterbitkan
                    </p>
                    <ul className="mt-1 list-inside list-disc">
                        {errorMessages.map((message) => (
                            <li key={message}>{message}</li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <form onSubmit={submit} className="mt-5 space-y-4">
                <div>
                    <Label htmlFor="finance-issue-reason">
                        Alasan penerbitan
                    </Label>
                    <textarea
                        id="finance-issue-reason"
                        value={form.data.issue_reason}
                        onChange={(event) =>
                            form.setData('issue_reason', event.target.value)
                        }
                        required
                        minLength={3}
                        maxLength={500}
                        rows={3}
                        className={financeFieldClass}
                        aria-invalid={Boolean(form.errors.issue_reason)}
                    />
                </div>

                <label className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-slate-300 p-3 text-sm text-slate-800 focus-within:ring-2 focus-within:ring-[#1b75bc]">
                    <input
                        type="checkbox"
                        checked={form.data.confirm_issue}
                        onChange={(event) =>
                            form.setData('confirm_issue', event.target.checked)
                        }
                        className="mt-0.5 size-5 accent-[#0f5b62]"
                    />
                    <span>
                        Saya mengonfirmasi bahwa versi ini hanya memuat sumber
                        Apotek, Radiologi, Laboratorium, dan Akomodasi yang
                        tercakup serta telah direkonsiliasi, bukan bukti
                        pembayaran atau tagihan lengkap seluruh layanan rumah
                        sakit.
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
                        disabled={!form.data.confirm_issue || form.processing}
                        className="min-h-11 bg-[#0f5b62] px-5 hover:bg-[#0b4147]"
                    >
                        {form.processing
                            ? 'Menerbitkan…'
                            : `Terbitkan versi ${version + 1}`}
                    </Button>
                </div>
            </form>
        </section>
    );
}

function CashSettlementPanel({
    settlement,
    url,
    canRequestCorrection,
    correctionRequestUrl,
}: {
    settlement: FinanceCashSettlementContext;
    url: string | null;
    canRequestCorrection: boolean;
    correctionRequestUrl: string | null;
}) {
    const [status, setStatus] = useState('');
    const errorRef = useRef<HTMLDivElement>(null);
    const form = useForm({
        expected_bill_fingerprint: settlement.bill_fingerprint,
        expected_bill_version_content_digest:
            settlement.bill_version_content_digest ?? '',
        confirm_settlement: false,
        idempotency_key: newSettlementIdempotencyKey(),
    });
    const errorMessages = useMemo(
        () => Array.from(new Set(Object.values(form.errors))),
        [form.errors],
    );

    useEffect(() => {
        if (errorMessages.length) {
            errorRef.current?.focus();
        }
    }, [errorMessages]);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!url) {
            return;
        }

        setStatus('Mencatat pelunasan tunai…');
        form.post(url, {
            preserveScroll: true,
            onError: () => setStatus('Pelunasan belum dicatat.'),
            onSuccess: () => {
                setStatus('Pelunasan tunai dicatat.');
                form.setData('confirm_settlement', false);
                form.setData('idempotency_key', newSettlementIdempotencyKey());
            },
        });
    };

    if (settlement.settlement) {
        const correctionState = settlement.settlement.correction_state;
        const isActive = correctionState === 'ACTIVE';
        const isCompleted = correctionState === 'REFUND_COMPLETED';
        const heading =
            correctionState === 'ACTIVE'
                ? 'Tagihan Lunas'
                : correctionState === 'CORRECTION_REQUESTED'
                  ? 'Koreksi Menunggu Tinjauan'
                  : correctionState === 'REVIEW_REJECTED'
                    ? 'Permintaan Koreksi Ditolak'
                    : correctionState === 'REFUND_APPROVED'
                      ? 'Pengembalian Disetujui'
                      : 'Pelunasan Telah Dikoreksi';
        const explanation =
            correctionState === 'ACTIVE'
                ? `Pelunasan tunai dicatat pada versi tagihan ${settlement.bill_version}.`
                : correctionState === 'CORRECTION_REQUESTED'
                  ? 'Permintaan sedang ditinjau. Pelunasan asal masih aktif dan tetap dihitung sebagai kas terkumpul.'
                  : correctionState === 'REVIEW_REJECTED'
                    ? 'Supervisor menolak permintaan. Pelunasan dan kuitansi asal tetap aktif.'
                    : correctionState === 'REFUND_APPROVED'
                      ? 'Pengembalian penuh telah disetujui, tetapi uang tunai belum tercatat diserahkan kembali.'
                      : 'Pengembalian tunai telah selesai. Pelunasan asal tetap tersimpan sebagai bukti dan tidak lagi aktif.';

        return (
            <>
                <section
                    aria-labelledby="finance-settlement-heading"
                    className={`rounded-xl border bg-white p-5 shadow-sm ${
                        isActive
                            ? 'border-emerald-300'
                            : isCompleted
                              ? 'border-[#7fbcb6]'
                              : 'border-amber-300'
                    }`}
                >
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-3">
                            <span
                                className={`rounded-lg p-2 ${
                                    isActive
                                        ? 'bg-emerald-50 text-emerald-800'
                                        : isCompleted
                                          ? 'bg-[#e8f5f3] text-[#0f5b62]'
                                          : 'bg-amber-50 text-amber-900'
                                }`}
                            >
                                {isActive || isCompleted ? (
                                    <CheckCircle2
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                ) : (
                                    <CircleAlert
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                )}
                            </span>
                            <div>
                                <h2
                                    id="finance-settlement-heading"
                                    className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold text-slate-950"
                                >
                                    {heading}
                                </h2>
                                <p className="mt-1 text-sm text-slate-700">
                                    {explanation}
                                </p>
                            </div>
                        </div>
                        <p className="font-['IBM_Plex_Mono'] text-xl font-bold text-slate-950 tabular-nums">
                            {formatRupiah(settlement.settlement.amount)}
                        </p>
                    </div>
                    <dl className="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-slate-600">Nomor kuitansi</dt>
                            <dd className="mt-1 font-['IBM_Plex_Mono'] font-semibold text-slate-950">
                                {settlement.settlement.receipt_number}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-600">Waktu pelunasan</dt>
                            <dd className="mt-1 font-semibold text-slate-950">
                                {formatFinanceDate(
                                    settlement.settlement.settled_at,
                                )}
                            </dd>
                        </div>
                    </dl>
                    <Link
                        href={settlement.settlement.receipt_url}
                        className="mt-4 inline-flex min-h-11 items-center justify-center rounded-md bg-[#0f5b62] px-5 text-sm font-semibold text-white hover:bg-[#0b4147] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        Lihat Kuitansi
                    </Link>
                    {settlement.settlement.correction_url ? (
                        <Link
                            href={settlement.settlement.correction_url}
                            className="mt-4 ml-3 inline-flex min-h-11 items-center justify-center rounded-md border border-[#0f5b62] bg-white px-5 text-sm font-semibold text-[#0f5b62] hover:bg-[#e8f5f3] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                        >
                            {correctionStateLabels[correctionState]}
                        </Link>
                    ) : null}
                    {isActive &&
                    canRequestCorrection &&
                    correctionRequestUrl ? (
                        <FinanceSettlementCorrectionRequestPanel
                            settlement={settlement.settlement}
                            requestUrl={correctionRequestUrl}
                        />
                    ) : null}
                </section>

                {settlement.settlement_available &&
                url &&
                settlement.amount !== null ? (
                    <section
                        aria-labelledby="finance-restored-balance-heading"
                        className="rounded-xl border-2 border-[#7fbcb6] bg-white p-5 shadow-sm"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex items-start gap-3">
                                <span className="rounded-lg bg-[#e8f5f3] p-2 text-[#0f5b62]">
                                    <Banknote
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                </span>
                                <div>
                                    <h2
                                        id="finance-restored-balance-heading"
                                        className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                                    >
                                        Sisa Tagihan yang Dapat Dilunasi
                                    </h2>
                                    <p className="mt-1 max-w-3xl text-sm text-slate-700">
                                        Kuitansi aktif di atas tetap menjadi
                                        bukti penerimaannya sendiri dan tidak
                                        mencakup sisa tagihan ini. Nilai berikut
                                        dihitung dari kas bersih setelah
                                        pengembalian terdahulu.
                                    </p>
                                </div>
                            </div>
                            <div className="text-right">
                                <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                    Sisa tepat
                                </p>
                                <p className="mt-1 font-['IBM_Plex_Mono'] text-2xl font-bold text-[#0b4147] tabular-nums">
                                    {formatRupiah(settlement.amount)}
                                </p>
                            </div>
                        </div>

                        {errorMessages.length ? (
                            <div
                                ref={errorRef}
                                tabIndex={-1}
                                role="alert"
                                className="mt-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-950 outline-none focus:ring-2 focus:ring-red-600"
                            >
                                <p className="font-semibold">
                                    Sisa tagihan belum dapat dilunasi
                                </p>
                                <ul className="mt-1 list-inside list-disc">
                                    {errorMessages.map((message) => (
                                        <li key={message}>{message}</li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}

                        <form onSubmit={submit} className="mt-5 space-y-4">
                            <label className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-slate-300 p-3 text-sm text-slate-800 focus-within:ring-2 focus-within:ring-[#1b75bc]">
                                <input
                                    type="checkbox"
                                    checked={form.data.confirm_settlement}
                                    onChange={(event) =>
                                        form.setData(
                                            'confirm_settlement',
                                            event.target.checked,
                                        )
                                    }
                                    className="mt-0.5 size-5 accent-[#0f5b62]"
                                />
                                <span>
                                    Saya mengonfirmasi penerimaan tunai baru
                                    tepat sebesar{' '}
                                    <strong>
                                        {formatRupiah(settlement.amount)}
                                    </strong>{' '}
                                    untuk sisa versi tagihan{' '}
                                    {settlement.bill_version}.
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
                                        !form.data.confirm_settlement ||
                                        form.processing
                                    }
                                    className="min-h-11 bg-[#0f5b62] px-5 hover:bg-[#0b4147]"
                                >
                                    {form.processing
                                        ? 'Mencatat…'
                                        : 'Catat Pelunasan Sisa Tunai'}
                                </Button>
                            </div>
                        </form>
                    </section>
                ) : null}
            </>
        );
    }

    if (
        !settlement.settlement_available ||
        !url ||
        settlement.amount === null
    ) {
        return null;
    }

    return (
        <section
            aria-labelledby="finance-settlement-heading"
            className="rounded-xl border border-[#7fbcb6] bg-white p-5 shadow-sm"
        >
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                    <span className="rounded-lg bg-[#e8f5f3] p-2 text-[#0f5b62]">
                        <Banknote aria-hidden="true" className="size-5" />
                    </span>
                    <div>
                        <h2
                            id="finance-settlement-heading"
                            className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                        >
                            Pelunasan Tunai
                        </h2>
                        <p className="mt-1 text-sm text-slate-600">
                            Nilai diambil langsung dari versi tagihan terbit dan
                            tidak dapat diubah pada langkah ini.
                        </p>
                    </div>
                </div>
                <div className="text-right">
                    <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                        Jumlah dibayar
                    </p>
                    <p className="mt-1 font-['IBM_Plex_Mono'] text-2xl font-bold text-[#0b4147] tabular-nums">
                        {formatRupiah(settlement.amount)}
                    </p>
                </div>
            </div>

            {errorMessages.length ? (
                <div
                    ref={errorRef}
                    tabIndex={-1}
                    role="alert"
                    aria-labelledby="finance-settlement-error-heading"
                    className="mt-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-950 outline-none focus:ring-2 focus:ring-red-600"
                >
                    <p
                        id="finance-settlement-error-heading"
                        className="font-semibold"
                    >
                        Pelunasan belum dapat dicatat
                    </p>
                    <ul className="mt-1 list-inside list-disc">
                        {errorMessages.map((message) => (
                            <li key={message}>{message}</li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <form onSubmit={submit} className="mt-5 space-y-4">
                <label className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-slate-300 p-3 text-sm text-slate-800 focus-within:ring-2 focus-within:ring-[#1b75bc]">
                    <input
                        type="checkbox"
                        checked={form.data.confirm_settlement}
                        onChange={(event) =>
                            form.setData(
                                'confirm_settlement',
                                event.target.checked,
                            )
                        }
                        className="mt-0.5 size-5 accent-[#0f5b62]"
                    />
                    <span>
                        Saya mengonfirmasi penerimaan tunai tepat sebesar{' '}
                        <strong>{formatRupiah(settlement.amount)}</strong> untuk
                        versi tagihan {settlement.bill_version}.
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
                            !form.data.confirm_settlement || form.processing
                        }
                        className="min-h-11 bg-[#0f5b62] px-5 hover:bg-[#0b4147]"
                    >
                        {form.processing
                            ? 'Mencatat…'
                            : 'Catat Pelunasan Tunai'}
                    </Button>
                </div>
            </form>
        </section>
    );
}

function SourceReadinessPanel({
    readiness,
}: {
    readiness: FinanceSourceReadiness;
}) {
    return (
        <section
            aria-labelledby="finance-readiness-heading"
            className="rounded-xl border border-[#7fbcb6] bg-white shadow-sm"
        >
            <header className="flex flex-wrap items-start justify-between gap-4 border-b border-[#7fbcb6] bg-[#e8f5f3] p-5">
                <div>
                    <h2
                        id="finance-readiness-heading"
                        className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold text-[#0b4147]"
                    >
                        Kesiapan Sumber Biaya
                    </h2>
                    <p className="mt-1 text-sm text-slate-700">
                        Status pemeriksaan Radiologi yang sudah dilakukan dan
                        hasil asli Laboratorium berstatus VERIFIED pada episode
                        ini.
                    </p>
                </div>
                <dl className="grid grid-cols-2 gap-2 text-center text-sm">
                    <div className="rounded-md border border-emerald-300 bg-white px-3 py-2">
                        <dt className="text-xs text-slate-600">
                            Terselesaikan
                        </dt>
                        <dd className="font-['IBM_Plex_Mono'] text-lg font-bold text-emerald-900">
                            {readiness.resolved_count}
                        </dd>
                    </div>
                    <div className="rounded-md border border-amber-300 bg-white px-3 py-2">
                        <dt className="text-xs text-slate-600">
                            Belum terselesaikan
                        </dt>
                        <dd className="font-['IBM_Plex_Mono'] text-lg font-bold text-amber-950">
                            {readiness.unresolved_count}
                        </dd>
                    </div>
                </dl>
            </header>

            {readiness.issue_blocked ? (
                <div
                    role="status"
                    className="m-4 flex items-start gap-3 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-950"
                >
                    <CircleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-5 shrink-0"
                    />
                    <div>
                        <p className="font-semibold">
                            Penerbitan versi tagihan tertahan
                        </p>
                        <p className="mt-1">
                            {readiness.issue_blocker ??
                                'Selesaikan seluruh sumber diagnostik yang belum terselesaikan, lalu periksa kembali kesiapan.'}
                        </p>
                    </div>
                </div>
            ) : null}

            {readiness.items.length ? (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[52rem] text-left text-sm">
                        <caption className="sr-only">
                            Kesiapan sumber biaya Radiologi, Laboratorium, dan
                            Akomodasi per layanan
                        </caption>
                        <thead className="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-700 uppercase">
                            <tr>
                                <th scope="col" className="px-4 py-3">
                                    Pemeriksaan
                                </th>
                                <th scope="col" className="px-4 py-3">
                                    Waktu layanan
                                </th>
                                <th scope="col" className="px-4 py-3">
                                    Status
                                </th>
                                <th scope="col" className="px-4 py-3">
                                    Keterangan
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200">
                            {readiness.items.map((item) => (
                                <tr key={item.public_id} className="align-top">
                                    <th
                                        scope="row"
                                        className="px-4 py-4 font-normal"
                                    >
                                        <span className="font-semibold text-slate-950">
                                            {item.description}
                                        </span>
                                        <span className="mt-1 block font-['IBM_Plex_Mono'] text-xs text-slate-500">
                                            {item.source_reference}
                                        </span>
                                    </th>
                                    <td className="px-4 py-4 text-slate-700">
                                        {formatFinanceDate(item.service_at)}
                                    </td>
                                    <td className="px-4 py-4">
                                        <span
                                            className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${
                                                item.state ===
                                                    'TERSINKRONISASI' ||
                                                item.state ===
                                                    'SIAP_DISINKRONKAN'
                                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-950'
                                                    : 'border-amber-300 bg-amber-50 text-amber-950'
                                            }`}
                                        >
                                            {item.state_label ||
                                                financeReadinessLabels[
                                                    item.state
                                                ]}
                                        </span>
                                    </td>
                                    <td className="px-4 py-4 text-slate-700">
                                        {item.detail}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <p className="p-5 text-sm text-slate-600">
                    Belum ada pemeriksaan Radiologi, hasil asli Laboratorium
                    berstatus VERIFIED, atau hari Akomodasi tertutup pada
                    episode ini.
                </p>
            )}
        </section>
    );
}

export function FinanceBillDetail({
    bill,
    coverage,
    settlement,
    permissions,
    commands,
    generated_at,
    read_error,
}: FinanceBillDetailProps) {
    const currentVersion = bill.versions.at(-1);

    return (
        <main className="min-h-screen bg-slate-50 pb-12">
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                <FinanceBackLink />

                <header className="relative overflow-hidden rounded-2xl bg-[#123b5d] p-6 text-white shadow-sm">
                    <span
                        aria-hidden="true"
                        className="absolute inset-y-0 left-0 w-2 bg-[#24a69a]"
                    />
                    <div className="relative flex flex-wrap items-start justify-between gap-5">
                        <div>
                            <p className="flex items-center gap-2 text-xs font-semibold tracking-[0.14em] text-sky-100 uppercase">
                                <ReceiptText
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                Detail Tagihan Pasien
                            </p>
                            <h1 className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold">
                                {bill.encounter.patient.full_name}
                            </h1>
                            <p className="mt-2 font-['IBM_Plex_Mono'] text-sm text-sky-50">
                                {bill.encounter.encounter_number} ·{' '}
                                {bill.encounter.patient.medical_record_number} ·{' '}
                                {bill.bill_number}
                            </p>
                        </div>
                        <div className="space-y-2 text-right">
                            <FinanceStateBadge state={bill.state} />
                            <p className="font-['IBM_Plex_Mono'] text-xs text-sky-100">
                                Diperbarui {generated_at}
                            </p>
                        </div>
                    </div>
                </header>

                <FinanceCoverageRail
                    label={coverage.label}
                    excludedLabel={coverage.excluded_label}
                    domains={coverage.domains}
                />

                {read_error ? (
                    <div
                        role="alert"
                        className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-950"
                    >
                        <p className="font-semibold">
                            Detail tagihan belum dapat dibaca.
                        </p>
                        <p className="mt-1">{read_error}</p>
                    </div>
                ) : null}

                <section
                    aria-labelledby="finance-episode-heading"
                    className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <h2
                        id="finance-episode-heading"
                        className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                    >
                        Episode layanan
                    </h2>
                    <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt className="text-slate-500">Layanan</dt>
                            <dd className="mt-1 font-semibold text-slate-950">
                                {careSettingLabels[bill.encounter.care_setting]}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Lokasi</dt>
                            <dd className="mt-1 font-semibold text-slate-950">
                                {bill.encounter.service_location}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Sumber tercatat</dt>
                            <dd className="mt-1 font-['IBM_Plex_Mono'] font-semibold text-slate-950">
                                {bill.current_source_event_count} baris
                            </dd>
                        </div>
                    </dl>
                </section>

                <SourceReadinessPanel readiness={bill.source_readiness} />

                <section
                    aria-labelledby="finance-source-heading"
                    className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div className="flex items-start gap-3">
                        <span className="rounded-lg bg-sky-50 p-2 text-[#0d5275]">
                            <ShieldCheck
                                aria-hidden="true"
                                className="size-5"
                            />
                        </span>
                        <div>
                            <h2
                                id="finance-source-heading"
                                className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                            >
                                Sumber Biaya
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                Sumber Apotek, Radiologi, Laboratorium, dan
                                Akomodasi yang telah direkonsiliasi untuk versi
                                berikutnya.
                            </p>
                        </div>
                    </div>
                    <ControlTotals
                        gross={bill.totals.gross_amount}
                        reversal={bill.totals.reversal_amount}
                        net={bill.totals.net_amount}
                    />
                    <SourceTable
                        lines={bill.current_sources}
                        caption="Sumber biaya Apotek, Radiologi, Laboratorium, dan Akomodasi terkini"
                    />
                </section>

                {permissions.can_issue &&
                commands.issue_url &&
                !bill.source_readiness.issue_blocked ? (
                    <IssueVersionPanel
                        url={commands.issue_url}
                        fingerprint={bill.fingerprint}
                        version={bill.current_version}
                    />
                ) : null}

                <CashSettlementPanel
                    settlement={settlement}
                    url={
                        permissions.can_settle ? commands.settlement_url : null
                    }
                    canRequestCorrection={permissions.can_request_correction}
                    correctionRequestUrl={commands.correction_request_url}
                />

                <section
                    aria-labelledby="finance-history-heading"
                    className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div className="flex items-start gap-3">
                        <span className="rounded-lg bg-slate-100 p-2 text-slate-700">
                            <History aria-hidden="true" className="size-5" />
                        </span>
                        <div>
                            <h2
                                id="finance-history-heading"
                                className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                            >
                                Riwayat Versi
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                Setiap versi dan barisnya tetap dapat
                                ditelusuri.
                            </p>
                        </div>
                    </div>

                    {bill.versions.length ? (
                        <div className="space-y-4">
                            {[...bill.versions].reverse().map((version) => (
                                <article
                                    key={version.public_id}
                                    className="rounded-lg border border-slate-300"
                                >
                                    <header className="grid gap-3 border-b border-slate-200 bg-slate-100 p-4 md:grid-cols-[1fr_auto]">
                                        <div>
                                            <h3 className="font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold">
                                                Versi {version.version}
                                                {version.public_id ===
                                                currentVersion?.public_id
                                                    ? ' · Terkini'
                                                    : ''}
                                            </h3>
                                            <p className="mt-1 text-sm text-slate-600">
                                                {version.issue_reason}
                                            </p>
                                            <p className="mt-1 text-xs font-semibold text-[#0f5b62]">
                                                Cakupan saat diterbitkan:{' '}
                                                {version.coverage_label}
                                            </p>
                                            <p className="mt-1 text-xs text-slate-500">
                                                Diterbitkan oleh akun Kasir #
                                                {version.issued_by_user_id} ·{' '}
                                                {formatFinanceDate(
                                                    version.issued_at,
                                                )}
                                            </p>
                                        </div>
                                        <p className="font-['IBM_Plex_Mono'] text-lg font-bold text-[#0b4147] tabular-nums">
                                            {formatRupiah(version.net_amount)}
                                        </p>
                                    </header>
                                    <div className="space-y-4 p-4">
                                        <ControlTotals
                                            gross={version.gross_amount}
                                            reversal={version.reversal_amount}
                                            net={version.net_amount}
                                        />
                                        <SourceTable
                                            lines={version.lines}
                                            caption={`Baris sumber biaya versi ${version.version}`}
                                        />
                                    </div>
                                </article>
                            ))}
                        </div>
                    ) : (
                        <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-600">
                            Belum ada versi yang diterbitkan.
                        </div>
                    )}
                </section>
            </div>
        </main>
    );
}
