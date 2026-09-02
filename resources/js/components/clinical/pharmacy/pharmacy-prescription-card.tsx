import { Link } from '@inertiajs/react';
import { History, Pill, ReceiptText } from 'lucide-react';
import { formatRupiah } from './operation';
import {
    PharmacyEvidenceTime,
    PharmacyStatusChip,
    pharmacyCareSettingLabel,
} from './pharmacy-shared';
import type { PharmacyPrescriptionProjection } from './types';

export function PharmacyPrescriptionItems({
    prescription,
}: {
    prescription: PharmacyPrescriptionProjection;
}) {
    return (
        <div className="overflow-x-auto rounded-lg border border-border">
            <table className="w-full min-w-[760px] border-collapse text-left text-sm">
                <caption className="sr-only">
                    Daftar obat pada resep {prescription.public_id}
                </caption>
                <thead className="bg-muted/60 text-xs text-muted-foreground">
                    <tr>
                        <th scope="col" className="px-3 py-2 font-semibold">
                            Obat
                        </th>
                        <th scope="col" className="px-3 py-2 font-semibold">
                            Aturan penggunaan
                        </th>
                        <th scope="col" className="px-3 py-2 font-semibold">
                            Jumlah
                        </th>
                        <th scope="col" className="px-3 py-2 font-semibold">
                            Status jumlah
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">
                    {prescription.items.map((item) => (
                        <tr key={item.public_id} className="align-top">
                            <td className="px-3 py-3">
                                <p className="font-semibold">
                                    {item.medicine.generic_display_name}{' '}
                                    {item.medicine.strength_text}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {item.medicine.dosage_form} ·{' '}
                                    {item.medicine.base_issue_unit}
                                    {item.medicine.brand_display_name
                                        ? ` · ${item.medicine.brand_display_name}`
                                        : ''}
                                </p>
                            </td>
                            <td className="px-3 py-3">
                                <p>
                                    {item.dose_text} · {item.route} ·{' '}
                                    {item.frequency_text}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {item.duration_text} ·{' '}
                                    {item.clinical_instruction}
                                </p>
                            </td>
                            <td className="px-3 py-3 font-mono font-semibold tabular-nums">
                                {item.requested_quantity}{' '}
                                {item.medicine.base_issue_unit}
                            </td>
                            <td className="px-3 py-3 text-xs">
                                <p>
                                    Terverifikasi:{' '}
                                    {item.verified_quantity ?? '—'}
                                </p>
                                <p>Diserahkan: {item.handed_over_quantity}</p>
                                <p>Dikembalikan: {item.returned_quantity}</p>
                                <p className="font-semibold">
                                    Sisa: {item.remaining_quantity}
                                </p>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function PharmacyPrescriptionHistory({
    prescription,
}: {
    prescription: PharmacyPrescriptionProjection;
}) {
    return (
        <details className="rounded-lg border border-border bg-muted/20 p-3">
            <summary className="flex min-h-11 cursor-pointer items-center gap-2 py-2 font-semibold">
                <History aria-hidden="true" className="size-4 text-primary" />
                Riwayat resep ({prescription.history.length})
            </summary>
            <ol className="mt-2 space-y-2">
                {prescription.history.map((event) => (
                    <li
                        key={event.public_id}
                        className="rounded-md border border-border bg-background p-3 text-sm"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <PharmacyStatusChip state={event.state} />
                                <p className="mt-2 text-xs text-muted-foreground">
                                    Versi {event.version} ·{' '}
                                    {event.actor_name ??
                                        'Pelaksana tidak tersedia'}
                                </p>
                            </div>
                            <PharmacyEvidenceTime value={event.occurred_at} />
                        </div>
                        {event.reason ? (
                            <p className="mt-2 whitespace-pre-wrap">
                                {event.reason}
                            </p>
                        ) : null}
                    </li>
                ))}
            </ol>
        </details>
    );
}

export function PharmacyControlTotals({
    prescription,
}: {
    prescription: PharmacyPrescriptionProjection;
}) {
    const totals = prescription.control_totals;

    return (
        <section className="rounded-lg border border-border bg-card p-4">
            <h3 className="flex items-center gap-2 font-semibold">
                <ReceiptText
                    aria-hidden="true"
                    className="size-4 text-primary"
                />
                Rekonsiliasi resep
            </h3>
            <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt className="text-xs text-muted-foreground">
                        Dipesan / terverifikasi
                    </dt>
                    <dd className="font-semibold tabular-nums">
                        {totals.ordered_quantity} / {totals.verified_quantity}{' '}
                        unit
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">
                        Diserahkan / dikembalikan
                    </dt>
                    <dd className="font-semibold tabular-nums">
                        {totals.handed_over_quantity} /{' '}
                        {totals.returned_to_stock_quantity +
                            totals.quarantined_return_quantity +
                            totals.non_returnable_quantity}{' '}
                        unit
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">
                        Nilai sumber biaya bersih
                    </dt>
                    <dd className="font-semibold tabular-nums">
                        {formatRupiah(totals.net_charge_source_rupiah)}
                    </dd>
                </div>
            </dl>
        </section>
    );
}

export function PharmacyPrescriptionCard({
    prescription,
    showLink = true,
}: {
    prescription: PharmacyPrescriptionProjection;
    showLink?: boolean;
}) {
    return (
        <article className="relative overflow-hidden rounded-xl border border-border bg-card p-4 shadow-sm">
            <span
                aria-hidden="true"
                className="absolute inset-y-0 left-0 w-1.5 bg-primary"
            />
            <div className="flex flex-wrap items-start justify-between gap-3 pl-1">
                <div>
                    <p className="flex items-center gap-2 text-xs font-semibold tracking-[0.12em] text-primary uppercase">
                        <Pill aria-hidden="true" className="size-4" />
                        {
                            pharmacyCareSettingLabel[
                                prescription.encounter.care_setting
                            ]
                        }{' '}
                        · {prescription.depot.display_name}
                    </p>
                    <h3 className="mt-1 font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold">
                        {prescription.encounter.patient.full_name ??
                            'Nama pasien tidak tersedia'}
                    </h3>
                    <p className="mt-0.5 font-mono text-xs text-muted-foreground">
                        {prescription.encounter.patient.medical_record_number ??
                            '—'}{' '}
                        · {prescription.public_id}
                    </p>
                </div>
                <PharmacyStatusChip state={prescription.state} />
            </div>
            <div className="mt-4 grid gap-2 text-sm sm:grid-cols-3">
                <div>
                    <p className="text-xs text-muted-foreground">Dokter</p>
                    <p className="font-semibold">
                        {prescription.ordering_physician.name ?? '—'}
                    </p>
                </div>
                <div>
                    <p className="text-xs text-muted-foreground">Lokasi</p>
                    <p className="font-semibold">
                        {prescription.encounter.location_label}
                    </p>
                </div>
                <div>
                    <p className="text-xs text-muted-foreground">Isi resep</p>
                    <p className="font-semibold">
                        {prescription.items.length} obat ·{' '}
                        {prescription.items.reduce(
                            (total, item) => total + item.requested_quantity,
                            0,
                        )}{' '}
                        unit
                    </p>
                </div>
            </div>
            {showLink ? (
                <div className="mt-4 text-right">
                    <Link
                        href={`/apotek/resep/${prescription.public_id}`}
                        className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold text-primary hover:bg-primary/5 hover:underline"
                        aria-label={`Buka resep ${prescription.encounter.patient.full_name ?? prescription.public_id}`}
                    >
                        Buka resep →
                    </Link>
                </div>
            ) : null}
        </article>
    );
}
