import { Link, useForm } from '@inertiajs/react';
import {
    BedDouble,
    CircleAlert,
    History,
    Plus,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type {
    AccommodationTariffBinding,
    AccommodationTariffMappingProps,
    AccommodationTariffSource,
} from './accommodation-tariff-mapping-types';
import {
    financeFieldClass,
    formatFinanceDate,
    formatRupiah,
} from './finance-shared';

type MappingAction =
    | { kind: 'create' }
    | { kind: 'revise'; binding: AccommodationTariffBinding }
    | { kind: 'retire'; binding: AccommodationTariffBinding };

function newIdempotencyKey(): string {
    return `accommodation-tariff-${Date.now()}-${crypto.randomUUID()}`;
}

function SourceIdentity({ source }: { source: AccommodationTariffSource }) {
    return (
        <>
            <span className="block font-semibold text-slate-950">
                {source.display_name}
            </span>
            <span className="mt-1 block text-xs text-slate-600">
                {source.ward_display_name} · {source.room_label} ·{' '}
                {source.service_class_label}
            </span>
            <span className="mt-1 block font-['IBM_Plex_Mono'] text-xs text-slate-500">
                {source.code} · bed {source.public_id}
            </span>
        </>
    );
}

function BedEvidence({ source }: { source: AccommodationTariffSource }) {
    return (
        <details className="mt-2 rounded-md border border-slate-200 bg-slate-50">
            <summary className="flex min-h-11 cursor-pointer items-center px-3 text-xs font-semibold text-[#0d5275] outline-none focus-visible:ring-2 focus-visible:ring-[#1b75bc]">
                Bukti versi tempat tidur
            </summary>
            <dl className="grid gap-2 border-t border-slate-200 p-3 text-xs sm:grid-cols-2">
                <div>
                    <dt className="text-slate-500">Bangsal</dt>
                    <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                        {source.ward_code} · {source.ward_display_name}
                    </dd>
                </div>
                <div>
                    <dt className="text-slate-500">Versi tempat tidur</dt>
                    <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                        v{source.bed_version} · {source.bed_version_public_id}
                    </dd>
                </div>
                <div className="sm:col-span-2">
                    <dt className="text-slate-500">Digest tepat</dt>
                    <dd className="mt-1 font-['IBM_Plex_Mono'] break-all">
                        {source.bed_content_digest}
                    </dd>
                </div>
            </dl>
        </details>
    );
}

function MappingForm({
    action,
    props,
    onClose,
}: {
    action: MappingAction;
    props: AccommodationTariffMappingProps;
    onClose: () => void;
}) {
    const isCreate = action.kind === 'create';
    const isRetire = action.kind === 'retire';
    const binding = isCreate ? null : action.binding;
    const errorRef = useRef<HTMLDivElement>(null);
    const [status, setStatus] = useState<string | null>(null);
    const form = useForm({
        inpatient_bed_public_id: isCreate ? '' : binding!.source.public_id,
        inpatient_bed_version_public_id: isCreate
            ? ''
            : binding!.source.bed_version_public_id,
        inpatient_bed_version: isCreate ? 0 : binding!.source.bed_version,
        inpatient_bed_content_digest: isCreate
            ? ''
            : binding!.source.bed_content_digest,
        tariff_item_public_id: isRetire
            ? ''
            : (binding?.tariff.public_id ?? ''),
        effective_from: '',
        reason: '',
        expected_version: isCreate ? 0 : binding!.latest_head_version,
        expected_digest: isCreate ? '' : binding!.latest_head_content_digest,
        confirm: false,
        idempotency_key: newIdempotencyKey(),
    });
    const selectedSource = props.sources.find(
        (source) => source.public_id === form.data.inpatient_bed_public_id,
    );
    const selectedTariff = props.tariff_options.find(
        (tariff) => tariff.public_id === form.data.tariff_item_public_id,
    );
    const errors = Object.values(form.errors).filter(Boolean);

    useEffect(() => {
        if (errors.length) {
            errorRef.current?.focus();
        }
    }, [errors.length]);

    const setSource = (publicId: string) => {
        const source = props.sources.find(
            (item) => item.public_id === publicId,
        );
        form.setData('inpatient_bed_public_id', publicId);
        form.setData(
            'inpatient_bed_version_public_id',
            source?.bed_version_public_id ?? '',
        );
        form.setData('inpatient_bed_version', source?.bed_version ?? 0);
        form.setData(
            'inpatient_bed_content_digest',
            source?.bed_content_digest ?? '',
        );
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setStatus(null);

        const onSuccess = () => {
            setStatus(
                isRetire
                    ? 'Pemetaan akomodasi dijadwalkan nonaktif.'
                    : isCreate
                      ? 'Pemetaan akomodasi dibuat.'
                      : 'Versi pemetaan akomodasi disimpan.',
            );
        };
        const options = { preserveScroll: true, onSuccess };

        if (isCreate && props.commands.create_url) {
            form.post(props.commands.create_url, options);
        } else if (isRetire && binding?.actions.retire_url) {
            form.post(binding.actions.retire_url, options);
        } else if (binding?.actions.revise_url) {
            form.patch(binding.actions.revise_url, options);
        }
    };

    return (
        <section
            aria-labelledby="accommodation-form-heading"
            className="rounded-xl border border-[#7fbcb6] bg-white shadow-sm"
        >
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-[#c7e1dc] bg-[#f1faf8] px-5 py-4">
                <div>
                    <h2
                        id="accommodation-form-heading"
                        className="text-lg font-semibold text-[#0b4147]"
                    >
                        {isRetire
                            ? 'Jadwalkan nonaktif pemetaan'
                            : isCreate
                              ? 'Buat pemetaan akomodasi'
                              : 'Tambah versi pemetaan'}
                    </h2>
                    <p className="mt-1 max-w-3xl text-sm text-slate-700">
                        Pemetaan selalu mengunci identitas dan digest versi
                        tempat tidur yang tepat. Nama kamar atau kelas tidak
                        pernah dipakai sebagai pengganti bukti tersebut.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    onClick={onClose}
                >
                    Tutup formulir
                </Button>
            </div>

            <form className="grid gap-5 p-5 md:grid-cols-2" onSubmit={submit}>
                {errors.length ? (
                    <div
                        ref={errorRef}
                        tabIndex={-1}
                        role="alert"
                        className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-950 outline-none md:col-span-2"
                    >
                        <p className="font-semibold">
                            Pemetaan belum dapat disimpan
                        </p>
                        <ul className="mt-1 list-disc pl-5">
                            {errors.map((error) => (
                                <li key={error}>{error}</li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {isCreate ? (
                    <div className="grid gap-1.5 md:col-span-2">
                        <Label htmlFor="accommodation-bed">
                            Tempat tidur dan versi master
                        </Label>
                        <select
                            id="accommodation-bed"
                            value={form.data.inpatient_bed_public_id}
                            onChange={(event) => setSource(event.target.value)}
                            className={financeFieldClass}
                        >
                            <option value="">Pilih tempat tidur tepat</option>
                            {props.sources.map((source) => (
                                <option
                                    key={source.public_id}
                                    value={source.public_id}
                                >
                                    {source.code} · {source.display_name} · v
                                    {source.bed_version}
                                </option>
                            ))}
                        </select>
                        {selectedSource ? (
                            <BedEvidence source={selectedSource} />
                        ) : null}
                    </div>
                ) : (
                    <div className="md:col-span-2">
                        <Label>Tempat tidur yang dipetakan</Label>
                        <div className="mt-1 rounded-md border border-slate-300 bg-slate-50 p-3">
                            <SourceIdentity source={binding!.source} />
                            <BedEvidence source={binding!.source} />
                        </div>
                    </div>
                )}

                {!isRetire ? (
                    <div className="grid gap-1.5 md:col-span-2">
                        <Label htmlFor="accommodation-tariff">
                            Tarif akomodasi
                        </Label>
                        <select
                            id="accommodation-tariff"
                            value={form.data.tariff_item_public_id}
                            onChange={(event) =>
                                form.setData(
                                    'tariff_item_public_id',
                                    event.target.value,
                                )
                            }
                            className={financeFieldClass}
                        >
                            <option value="">
                                Pilih tarif yang telah dikelola
                            </option>
                            {props.tariff_options.map((tariff) => (
                                <option
                                    key={tariff.public_id}
                                    value={tariff.public_id}
                                >
                                    {tariff.code} · {tariff.display_name} · v
                                    {tariff.version} ·{' '}
                                    {formatRupiah(tariff.amount_rupiah)}
                                </option>
                            ))}
                        </select>
                        {selectedTariff ? (
                            <p className="text-sm text-slate-600">
                                Berlaku di master sejak{' '}
                                {selectedTariff.effective_from}. Nilai dipakai
                                hanya jika versi tarif ini tepat dan efektif
                                pada tanggal layanan.
                            </p>
                        ) : (
                            <p className="text-sm text-slate-600">
                                Tidak ada tarif atau nilai yang diusulkan
                                sistem. Pilih hanya tarif yang telah
                                dikonfigurasi Pengelola Tarif.
                            </p>
                        )}
                    </div>
                ) : null}

                <div className="grid gap-1.5">
                    <Label htmlFor="accommodation-effective">
                        {isRetire ? 'Nonaktif mulai' : 'Berlaku mulai'}
                    </Label>
                    <input
                        id="accommodation-effective"
                        type="date"
                        value={form.data.effective_from}
                        onChange={(event) =>
                            form.setData('effective_from', event.target.value)
                        }
                        className={financeFieldClass}
                    />
                    <p className="text-xs text-slate-600">
                        Tanggal harus prospektif dan tidak boleh menulis ulang
                        hari layanan lampau.
                    </p>
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="accommodation-reason">Alasan</Label>
                    <textarea
                        id="accommodation-reason"
                        value={form.data.reason}
                        onChange={(event) =>
                            form.setData('reason', event.target.value)
                        }
                        className={`${financeFieldClass} min-h-24 resize-y`}
                    />
                </div>

                {!isCreate ? (
                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700 md:col-span-2">
                        Versi kepala yang diharapkan: v
                        {binding!.latest_head_version} ·{' '}
                        <span className="font-['IBM_Plex_Mono'] break-all">
                            {binding!.latest_head_content_digest}
                        </span>
                    </div>
                ) : null}

                <label className="flex min-h-11 items-start gap-3 rounded-md border border-slate-200 p-3 text-sm text-slate-800 md:col-span-2">
                    <input
                        type="checkbox"
                        checked={form.data.confirm}
                        onChange={(event) =>
                            form.setData('confirm', event.target.checked)
                        }
                        className="mt-0.5 size-4 rounded border-slate-400 text-[#0f5b62] focus:ring-[#1b75bc]"
                    />
                    <span>
                        Saya mengonfirmasi versi tempat tidur tepat, tarif yang
                        dipilih, tanggal efektif, dan alasan perubahan ini.
                    </span>
                </label>

                <div className="flex flex-wrap items-center gap-3 md:col-span-2">
                    <Button
                        type="submit"
                        className="min-h-11 bg-[#0f5b62] hover:bg-[#0b4147]"
                        disabled={form.processing || !form.data.confirm}
                    >
                        {isRetire ? 'Jadwalkan nonaktif' : 'Simpan pemetaan'}
                    </Button>
                    {status ? (
                        <p
                            role="status"
                            className="text-sm font-medium text-emerald-800"
                        >
                            {status}
                        </p>
                    ) : null}
                </div>
            </form>
        </section>
    );
}

export function AccommodationTariffMappingWorkspace(
    props: AccommodationTariffMappingProps,
) {
    const [action, setAction] = useState<MappingAction | null>(null);
    const [query, setQuery] = useState('');
    const visibleMappings = useMemo(() => {
        const normalized = query.trim().toLocaleLowerCase('id-ID');

        if (!normalized) {
            return props.mappings;
        }

        return props.mappings.filter((mapping) =>
            [
                mapping.source.code,
                mapping.source.display_name,
                mapping.source.ward_display_name,
                mapping.source.room_label,
                mapping.tariff.code,
                mapping.tariff.display_name,
            ].some((value) =>
                value.toLocaleLowerCase('id-ID').includes(normalized),
            ),
        );
    }, [props.mappings, query]);

    return (
        <main className="min-h-full bg-[#f8fbfb] px-4 py-6 sm:px-6 lg:px-8">
            <div className="mx-auto grid max-w-7xl gap-6">
                <header className="rounded-xl border border-[#7fbcb6] bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div className="flex items-center gap-2 text-[#0f5b62]">
                                <BedDouble
                                    aria-hidden="true"
                                    className="size-5"
                                />
                                <span className="text-sm font-semibold">
                                    Rawat inap · akomodasi
                                </span>
                            </div>
                            <h1 className="mt-2 text-2xl font-semibold tracking-tight text-[#0b4147]">
                                Pemetaan Akomodasi Rawat Inap
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-700">
                                Hubungkan tarif yang dikelola dengan versi
                                tempat tidur yang tepat. Satu hari okupansi yang
                                tertutup memakai satu unit, tanpa prorata dan
                                tanpa inferensi harga.
                            </p>
                        </div>
                        {props.permissions.can_manage &&
                        props.commands.create_url ? (
                            <Button
                                type="button"
                                className="min-h-11 bg-[#0f5b62] hover:bg-[#0b4147]"
                                onClick={() => setAction({ kind: 'create' })}
                            >
                                <Plus aria-hidden="true" className="size-4" />
                                Buat pemetaan
                            </Button>
                        ) : (
                            <span className="inline-flex min-h-11 items-center gap-2 rounded-md border border-slate-300 bg-slate-50 px-3 text-sm font-medium text-slate-700">
                                <ShieldCheck
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                Akses lihat-saja
                            </span>
                        )}
                    </div>
                    <dl className="mt-5 grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-3">
                        <div className="bg-white p-3">
                            <dt className="text-xs font-semibold text-slate-500">
                                Tanggal proyeksi
                            </dt>
                            <dd className="mt-1 font-['IBM_Plex_Mono'] text-sm text-slate-950">
                                {props.as_of_date}
                            </dd>
                        </div>
                        <div className="bg-white p-3">
                            <dt className="text-xs font-semibold text-slate-500">
                                Master tempat tidur
                            </dt>
                            <dd className="mt-1 font-['IBM_Plex_Mono'] text-sm text-slate-950">
                                {props.source_master_version}
                            </dd>
                        </div>
                        <div className="bg-white p-3">
                            <dt className="text-xs font-semibold text-slate-500">
                                Digest master
                            </dt>
                            <dd className="mt-1 font-['IBM_Plex_Mono'] text-xs break-all text-slate-950">
                                {props.source_master_content_digest}
                            </dd>
                        </div>
                    </dl>
                </header>

                {props.read_error ? (
                    <div
                        role="alert"
                        className="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-950"
                    >
                        <div className="flex gap-2">
                            <CircleAlert
                                aria-hidden="true"
                                className="mt-0.5 size-5 shrink-0"
                            />
                            <p>{props.read_error}</p>
                        </div>
                    </div>
                ) : null}

                <section
                    aria-labelledby="occupancy-rule-heading"
                    className="rounded-xl border border-[#b7d7f0] bg-[#f4faff] p-5"
                >
                    <h2
                        id="occupancy-rule-heading"
                        className="font-semibold text-[#0d5275]"
                    >
                        Hanya hari okupansi tertutup
                    </h2>
                    <p className="mt-1 text-sm leading-6 text-slate-700">
                        {props.source_trigger.label} Tidak ada biaya dari
                        admisi, tempat tidur saat ini, sensus, waktu berjalan,
                        atau tindakan kasir. Riwayat lokasi yang tidak lengkap
                        tetap menjadi gap dan tidak diberi nilai.
                    </p>
                </section>

                {action ? (
                    <MappingForm
                        action={action}
                        props={props}
                        onClose={() => setAction(null)}
                    />
                ) : null}

                <section
                    aria-labelledby="accommodation-mapping-heading"
                    className="rounded-xl border border-[#7fbcb6] bg-white shadow-sm"
                >
                    <div className="flex flex-wrap items-end justify-between gap-4 border-b border-[#c7e1dc] px-5 py-4">
                        <div>
                            <h2
                                id="accommodation-mapping-heading"
                                className="text-lg font-semibold text-[#0b4147]"
                            >
                                Pemetaan yang berlaku
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                Setiap pemetaan merujuk versi tempat tidur dan
                                tarif yang dapat ditelusuri.
                            </p>
                        </div>
                        <label className="grid gap-1 text-sm font-medium text-slate-700">
                            Cari pemetaan
                            <input
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                className={`${financeFieldClass} min-w-64`}
                                placeholder="Kode tempat tidur atau tarif"
                            />
                        </label>
                    </div>
                    {visibleMappings.length ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[70rem] text-left text-sm">
                                <caption className="sr-only">
                                    Pemetaan tarif akomodasi yang berlaku
                                </caption>
                                <thead className="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-700 uppercase">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            Tempat tidur tepat
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Tarif efektif
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Periode
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            <span className="sr-only">
                                                Aksi
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200">
                                    {visibleMappings.map((mapping) => (
                                        <tr
                                            key={mapping.public_id}
                                            className="align-top"
                                        >
                                            <th
                                                scope="row"
                                                className="px-4 py-4 font-normal"
                                            >
                                                <SourceIdentity
                                                    source={mapping.source}
                                                />
                                                <BedEvidence
                                                    source={mapping.source}
                                                />
                                            </th>
                                            <td className="px-4 py-4">
                                                <span className="block font-semibold text-slate-950">
                                                    {
                                                        mapping.tariff
                                                            .display_name
                                                    }
                                                </span>
                                                <span className="mt-1 block font-['IBM_Plex_Mono'] text-xs text-slate-500">
                                                    {mapping.tariff.code} · v
                                                    {mapping.tariff.version}
                                                </span>
                                                <span className="mt-1 block font-semibold text-slate-800">
                                                    {formatRupiah(
                                                        mapping.tariff
                                                            .amount_rupiah,
                                                    )}{' '}
                                                    / hari
                                                </span>
                                            </td>
                                            <td className="px-4 py-4 font-['IBM_Plex_Mono'] text-slate-700">
                                                {mapping.effective_from} —{' '}
                                                {mapping.effective_until ??
                                                    'berjalan'}
                                            </td>
                                            <td className="px-4 py-4">
                                                <span className="inline-flex rounded-full border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-950">
                                                    {mapping.state === 'ACTIVE'
                                                        ? 'Aktif'
                                                        : 'Nonaktif'}
                                                </span>
                                            </td>
                                            <td className="px-4 py-4">
                                                <div className="flex flex-wrap gap-2">
                                                    <Link
                                                        href={
                                                            mapping.actions
                                                                .history_url
                                                        }
                                                        className="inline-flex min-h-11 items-center gap-2 rounded-md px-3 text-sm font-semibold text-[#0d5275] outline-none hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc]"
                                                    >
                                                        <History
                                                            aria-hidden="true"
                                                            className="size-4"
                                                        />
                                                        Riwayat
                                                    </Link>
                                                    {props.permissions
                                                        .can_manage &&
                                                    mapping.actions
                                                        .revise_url ? (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            className="min-h-11"
                                                            onClick={() =>
                                                                setAction({
                                                                    kind: 'revise',
                                                                    binding:
                                                                        mapping,
                                                                })
                                                            }
                                                        >
                                                            Tambah versi
                                                        </Button>
                                                    ) : null}
                                                    {props.permissions
                                                        .can_manage &&
                                                    mapping.actions
                                                        .retire_url ? (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            className="min-h-11"
                                                            onClick={() =>
                                                                setAction({
                                                                    kind: 'retire',
                                                                    binding:
                                                                        mapping,
                                                                })
                                                            }
                                                        >
                                                            Nonaktifkan
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="p-8 text-center">
                            <p className="font-semibold text-slate-950">
                                Belum ada pemetaan tarif akomodasi yang
                                dikonfigurasi secara sengaja.
                            </p>
                            <p className="mt-1 text-sm text-slate-600">
                                Tidak ada harga bawaan untuk kamar atau kelas
                                layanan.
                            </p>
                        </div>
                    )}
                </section>

                <section
                    aria-labelledby="accommodation-gap-heading"
                    className="rounded-xl border border-amber-300 bg-white shadow-sm"
                >
                    <div className="border-b border-amber-200 bg-amber-50 px-5 py-4">
                        <h2
                            id="accommodation-gap-heading"
                            className="text-lg font-semibold text-amber-950"
                        >
                            Gap kesiapan akomodasi
                        </h2>
                        <p className="mt-1 text-sm text-amber-900">
                            Gap tidak menghasilkan harga, sumber biaya, atau
                            jalan pintas penerbitan tagihan.
                        </p>
                    </div>
                    {props.gaps.length ? (
                        <ul
                            className="divide-y divide-slate-200"
                            aria-label="Gap pemetaan akomodasi"
                        >
                            {props.gaps.map((gap) => (
                                <li
                                    key={`${gap.source.public_id}-${gap.reason_code}`}
                                    className="p-5"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <SourceIdentity
                                                source={gap.source}
                                            />
                                            <BedEvidence source={gap.source} />
                                        </div>
                                        <span className="rounded-full border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-950">
                                            {gap.reason_label}
                                        </span>
                                    </div>
                                    <p className="mt-3 text-sm text-slate-700">
                                        {gap.detail}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="p-5 text-sm text-slate-600">
                            Tidak ada gap pemetaan pada proyeksi ini.
                        </p>
                    )}
                </section>

                {props.history ? (
                    <section
                        aria-labelledby="accommodation-history-heading"
                        className="rounded-xl border border-[#7fbcb6] bg-white shadow-sm"
                    >
                        <div className="border-b border-[#c7e1dc] px-5 py-4">
                            <h2
                                id="accommodation-history-heading"
                                className="text-lg font-semibold text-[#0b4147]"
                            >
                                Riwayat versi pemetaan tetap
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                {props.history.source.code} ·{' '}
                                {props.history.source.display_name}
                            </p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[56rem] text-left text-sm">
                                <caption className="sr-only">
                                    Riwayat versi pemetaan akomodasi tetap
                                </caption>
                                <thead className="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-700 uppercase">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            Versi
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Tarif
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Periode
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Alasan dan bukti
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Ditulis
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200">
                                    {props.history.versions.map((version) => (
                                        <tr
                                            key={version.public_id}
                                            className="align-top"
                                        >
                                            <th
                                                scope="row"
                                                className="px-4 py-4 font-['IBM_Plex_Mono'] font-normal"
                                            >
                                                v{version.version}
                                            </th>
                                            <td className="px-4 py-4">
                                                <span className="block font-medium text-slate-950">
                                                    {
                                                        version.tariff
                                                            .display_name
                                                    }
                                                </span>
                                                <span className="mt-1 block text-xs text-slate-600">
                                                    {version.tariff.code} ·{' '}
                                                    {formatRupiah(
                                                        version.tariff
                                                            .amount_rupiah,
                                                    )}
                                                </span>
                                            </td>
                                            <td className="px-4 py-4 font-['IBM_Plex_Mono'] text-xs text-slate-700">
                                                {version.effective_from} —{' '}
                                                {version.effective_until ??
                                                    'berjalan'}
                                            </td>
                                            <td className="px-4 py-4">
                                                <p className="text-slate-700">
                                                    {version.reason}
                                                </p>
                                                <p className="mt-2 font-['IBM_Plex_Mono'] text-xs break-all text-slate-500">
                                                    {version.content_digest}
                                                </p>
                                            </td>
                                            <td className="px-4 py-4 text-slate-700">
                                                {version.authored_by}
                                                <span className="mt-1 block text-xs">
                                                    {formatFinanceDate(
                                                        version.authored_at,
                                                    )}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ) : null}
            </div>
        </main>
    );
}
