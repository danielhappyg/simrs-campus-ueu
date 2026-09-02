import { Link, router, useForm } from '@inertiajs/react';
import {
    CalendarClock,
    CircleAlert,
    History,
    Landmark,
    Link2,
    Pencil,
    Plus,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    financeFieldClass,
    formatFinanceDate,
    formatRupiah,
} from './finance-shared';
import type {
    RadiologyTariffBinding,
    RadiologyTariffMappingProps,
    RadiologyTariffOption,
    RadiologyTariffSource,
} from './radiology-tariff-mapping-types';
import type { TariffCareSetting } from './tariff-master-types';

type MappingAction =
    | { mode: 'create'; url: string }
    | {
          mode: 'revise' | 'retire';
          url: string;
          binding: RadiologyTariffBinding;
      };

type MappingFormData = {
    radiology_master_public_id: string;
    radiology_master_version_public_id: string;
    radiology_master_version: number | '';
    radiology_master_content_digest: string;
    care_setting: TariffCareSetting | '';
    tariff_item_public_id: string;
    effective_from: string;
    expected_version: number | '';
    expected_digest: string;
    reason: string;
    confirm: boolean;
    idempotency_key: string;
};

const careSettingLabels: Record<TariffCareSetting, string> = {
    OUTPATIENT: 'Rawat jalan',
    EMERGENCY: 'IGD',
    INPATIENT: 'Rawat inap',
};

function operationKey() {
    return `radiology-tariff-${Date.now()}-${globalThis.crypto?.randomUUID?.() ?? Math.random().toString(16).slice(2)}`;
}

function digest(value: string) {
    return (
        <code className="block max-w-[24rem] font-['IBM_Plex_Mono'] text-xs break-all text-slate-600">
            {value}
        </code>
    );
}

function interval(from: string, until: string | null) {
    return `${from} — ${until ?? 'seterusnya'}`;
}

function MappingStateBadge({ state }: { state: 'ACTIVE' | 'RETIRED' }) {
    return (
        <span
            className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${
                state === 'ACTIVE'
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-950'
                    : 'border-slate-300 bg-slate-100 text-slate-700'
            }`}
        >
            {state === 'ACTIVE' ? 'Aktif' : 'Nonaktif'}
        </span>
    );
}

function MappingForm({
    action,
    sources,
    tariffs,
    onClose,
    onStatus,
}: {
    action: MappingAction;
    sources: RadiologyTariffSource[];
    tariffs: RadiologyTariffOption[];
    onClose: () => void;
    onStatus: (value: string) => void;
}) {
    const binding = action.mode === 'create' ? null : action.binding;
    const form = useForm<MappingFormData>({
        radiology_master_public_id: binding?.source.public_id ?? '',
        radiology_master_version_public_id:
            binding?.source.master_version_public_id ?? '',
        radiology_master_version: binding?.source.master_version ?? '',
        radiology_master_content_digest:
            binding?.source.master_content_digest ?? '',
        care_setting: binding?.care_setting ?? '',
        tariff_item_public_id:
            action.mode === 'revise' ? (binding?.tariff.public_id ?? '') : '',
        effective_from: '',
        expected_version: binding?.latest_head_version ?? '',
        expected_digest: binding?.latest_head_content_digest ?? '',
        reason: '',
        confirm: false,
        idempotency_key: operationKey(),
    });
    const errorRef = useRef<HTMLDivElement>(null);
    const [attempted, setAttempted] = useState(false);
    const selectedSource = sources.find(
        (source) => source.public_id === form.data.radiology_master_public_id,
    );
    const selectedTariff = tariffs.find(
        (tariff) => tariff.public_id === form.data.tariff_item_public_id,
    );
    const eligibleTariffs = tariffs.filter(
        (tariff) =>
            tariff.state === 'ACTIVE' &&
            (!form.data.care_setting ||
                tariff.care_setting === form.data.care_setting),
    );
    const errors = useMemo(
        () => Array.from(new Set(Object.values(form.errors))),
        [form.errors],
    );

    useEffect(() => {
        if (attempted && errors.length) {
            errorRef.current?.focus();
        }
    }, [attempted, errors.length]);

    const chooseSource = (publicId: string) => {
        const source = sources.find((item) => item.public_id === publicId);
        form.setData('radiology_master_public_id', publicId);
        form.setData(
            'radiology_master_version_public_id',
            source?.master_version_public_id ?? '',
        );
        form.setData('radiology_master_version', source?.master_version ?? '');
        form.setData(
            'radiology_master_content_digest',
            source?.master_content_digest ?? '',
        );
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onError: () => setAttempted(true),
            onSuccess: () => {
                onStatus(
                    action.mode === 'retire'
                        ? 'Pemetaan dijadwalkan nonaktif.'
                        : action.mode === 'create'
                          ? 'Pemetaan radiologi dibuat.'
                          : 'Versi pemetaan radiologi ditambahkan.',
                );
                onClose();
            },
        };

        if (action.mode === 'revise') {
            form.patch(action.url, options);
        } else {
            form.post(action.url, options);
        }
    };

    const title =
        action.mode === 'create'
            ? 'Buat pemetaan radiologi'
            : action.mode === 'revise'
              ? 'Tambah versi pemetaan'
              : 'Jadwalkan pemetaan nonaktif';

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4"
            role="presentation"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="radiology-mapping-form-title"
                aria-describedby="radiology-mapping-form-description"
                className="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white shadow-2xl"
            >
                <header className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white p-5">
                    <div>
                        <h2
                            id="radiology-mapping-form-title"
                            className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold text-slate-950"
                        >
                            {title}
                        </h2>
                        <p
                            id="radiology-mapping-form-description"
                            className="mt-1 text-sm text-slate-600"
                        >
                            Pilihan ini berlaku mulai tanggal yang ditetapkan.
                            Riwayat terdahulu tidak diubah.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                        aria-label="Tutup formulir pemetaan"
                    >
                        <X aria-hidden="true" className="size-5" />
                    </button>
                </header>

                <form onSubmit={submit} className="space-y-5 p-5">
                    {errors.length ? (
                        <div
                            ref={errorRef}
                            tabIndex={-1}
                            role="alert"
                            className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-950 outline-none focus:ring-2 focus:ring-red-600"
                        >
                            <p className="font-semibold">
                                Pemetaan belum dapat disimpan
                            </p>
                            <ul className="mt-1 list-inside list-disc">
                                {errors.map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        </div>
                    ) : null}

                    {action.mode === 'create' ? (
                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <Label htmlFor="mapping-radiology-source">
                                    Pemeriksaan dan versi master
                                </Label>
                                <select
                                    id="mapping-radiology-source"
                                    className={financeFieldClass}
                                    value={form.data.radiology_master_public_id}
                                    onChange={(event) =>
                                        chooseSource(event.target.value)
                                    }
                                    required
                                >
                                    <option value="">Pilih pemeriksaan</option>
                                    {sources
                                        .filter(
                                            (source) =>
                                                source.state === 'ACTIVE',
                                        )
                                        .map((source) => (
                                            <option
                                                key={
                                                    source.master_version_public_id
                                                }
                                                value={source.public_id}
                                            >
                                                {source.code} · v
                                                {source.master_version} ·{' '}
                                                {source.display_name}
                                            </option>
                                        ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="mapping-care-setting">
                                    Jenis layanan
                                </Label>
                                <select
                                    id="mapping-care-setting"
                                    className={financeFieldClass}
                                    value={form.data.care_setting}
                                    onChange={(event) => {
                                        form.setData(
                                            'care_setting',
                                            event.target
                                                .value as TariffCareSetting,
                                        );
                                        form.setData(
                                            'tariff_item_public_id',
                                            '',
                                        );
                                    }}
                                    required
                                >
                                    <option value="">Pilih layanan</option>
                                    {Object.entries(careSettingLabels).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </div>
                        </div>
                    ) : (
                        <div className="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm">
                            <p className="font-semibold text-slate-950">
                                {binding?.source.code} · v
                                {binding?.source.master_version} ·{' '}
                                {careSettingLabels[binding!.care_setting]}
                            </p>
                            <p className="mt-1 text-slate-700">
                                {binding?.source.display_name}
                            </p>
                        </div>
                    )}

                    {selectedSource ? (
                        <dl className="grid gap-3 rounded-lg border border-slate-200 p-4 text-sm md:grid-cols-2">
                            <div>
                                <dt className="text-slate-500">
                                    ID versi master
                                </dt>
                                <dd className="mt-1 font-['IBM_Plex_Mono'] text-xs text-slate-950">
                                    {selectedSource.master_version_public_id}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">
                                    Digest master tepat
                                </dt>
                                <dd className="mt-1">
                                    {digest(
                                        selectedSource.master_content_digest,
                                    )}
                                </dd>
                            </div>
                        </dl>
                    ) : null}

                    {action.mode !== 'retire' ? (
                        <div>
                            <Label htmlFor="mapping-tariff">
                                Tarif radiologi
                            </Label>
                            <select
                                id="mapping-tariff"
                                className={financeFieldClass}
                                value={form.data.tariff_item_public_id}
                                onChange={(event) =>
                                    form.setData(
                                        'tariff_item_public_id',
                                        event.target.value,
                                    )
                                }
                                required
                            >
                                <option value="">
                                    Pilih tarif secara sadar
                                </option>
                                {eligibleTariffs.map((tariff) => (
                                    <option
                                        key={tariff.version_public_id}
                                        value={tariff.public_id}
                                    >
                                        {tariff.code} · v{tariff.version} ·{' '}
                                        {tariff.display_name}
                                    </option>
                                ))}
                            </select>
                            <p className="mt-1 text-xs text-slate-600">
                                Tidak ada tarif atau nilai yang dipilih
                                otomatis.
                            </p>
                        </div>
                    ) : null}

                    {selectedTariff && action.mode !== 'retire' ? (
                        <div
                            aria-label="Provenans tarif terpilih"
                            className="grid gap-3 rounded-lg border border-[#7fbcb6] bg-[#e8f5f3] p-4 text-sm md:grid-cols-2"
                        >
                            <div>
                                <p className="font-semibold text-[#0b4147]">
                                    {selectedTariff.code} · v
                                    {selectedTariff.version}
                                </p>
                                <p className="mt-1 text-slate-700">
                                    {selectedTariff.display_name}
                                </p>
                                <p className="mt-2 font-['IBM_Plex_Mono'] text-lg font-bold text-slate-950">
                                    {formatRupiah(selectedTariff.amount_rupiah)}
                                </p>
                            </div>
                            <dl className="space-y-2">
                                <div>
                                    <dt className="text-slate-500">
                                        ID versi tarif
                                    </dt>
                                    <dd className="font-['IBM_Plex_Mono'] text-xs">
                                        {selectedTariff.version_public_id}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">
                                        Digest tarif tepat
                                    </dt>
                                    <dd>
                                        {digest(selectedTariff.content_digest)}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    ) : null}

                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <Label htmlFor="mapping-effective-from">
                                {action.mode === 'retire'
                                    ? 'Nonaktif mulai'
                                    : 'Berlaku mulai'}
                            </Label>
                            <input
                                id="mapping-effective-from"
                                type="date"
                                className={financeFieldClass}
                                value={form.data.effective_from}
                                onChange={(event) =>
                                    form.setData(
                                        'effective_from',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                            <p className="mt-1 text-xs text-slate-600">
                                Gunakan tanggal mendatang untuk menjadwalkan
                                perubahan tanpa menulis ulang riwayat.
                            </p>
                        </div>
                        <div>
                            <Label htmlFor="mapping-reason">Alasan</Label>
                            <textarea
                                id="mapping-reason"
                                className={financeFieldClass}
                                rows={3}
                                minLength={3}
                                maxLength={500}
                                value={form.data.reason}
                                onChange={(event) =>
                                    form.setData('reason', event.target.value)
                                }
                                required
                            />
                        </div>
                    </div>

                    {binding ? (
                        <dl className="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm md:grid-cols-2">
                            <div>
                                <dt className="text-slate-500">
                                    Versi kepala yang diharapkan
                                </dt>
                                <dd className="mt-1 font-['IBM_Plex_Mono'] font-semibold">
                                    v{form.data.expected_version}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">
                                    Digest kepala yang diharapkan
                                </dt>
                                <dd className="mt-1">
                                    {digest(form.data.expected_digest)}
                                </dd>
                            </div>
                        </dl>
                    ) : null}

                    <label className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-slate-300 p-3 text-sm text-slate-800 focus-within:ring-2 focus-within:ring-[#1b75bc]">
                        <input
                            type="checkbox"
                            checked={form.data.confirm}
                            onChange={(event) =>
                                form.setData('confirm', event.target.checked)
                            }
                            className="mt-0.5 size-5 accent-[#0f5b62]"
                        />
                        <span>
                            Saya mengonfirmasi versi master, jenis layanan,
                            tarif, dan tanggal berlaku yang ditampilkan.
                        </span>
                    </label>

                    <footer className="flex flex-wrap justify-end gap-3 border-t border-slate-200 pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            variant={
                                action.mode === 'retire'
                                    ? 'destructive'
                                    : 'default'
                            }
                            className="min-h-11"
                            disabled={!form.data.confirm || form.processing}
                        >
                            {form.processing
                                ? 'Menyimpan…'
                                : action.mode === 'retire'
                                  ? 'Jadwalkan nonaktif'
                                  : 'Simpan pemetaan'}
                        </Button>
                    </footer>
                </form>
            </section>
        </div>
    );
}

export function RadiologyTariffMappingWorkspace(
    props: RadiologyTariffMappingProps,
) {
    const [action, setAction] = useState<MappingAction | null>(null);
    const [status, setStatus] = useState('');
    const canCreate =
        props.permissions.can_manage && props.commands.create_url !== null;

    return (
        <main className="min-h-screen bg-slate-50 pb-12">
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                <nav aria-label="Jalur halaman" className="text-sm">
                    <ol className="flex flex-wrap items-center gap-2 text-slate-600">
                        <li>Manajemen Data</li>
                        <li aria-hidden="true">/</li>
                        <li>
                            <Link
                                href="/manajemen-data/tarif-komponen-biaya"
                                className="inline-flex min-h-11 items-center rounded-md px-2 font-semibold text-[#0d5275] hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                            >
                                Tarif &amp; Komponen Biaya
                            </Link>
                        </li>
                        <li aria-hidden="true">/</li>
                        <li aria-current="page">Pemetaan Radiologi</li>
                    </ol>
                </nav>

                <header className="relative overflow-hidden rounded-2xl bg-[#123b5d] p-6 text-white shadow-sm">
                    <span
                        aria-hidden="true"
                        className="absolute inset-y-0 left-0 w-2 bg-[#24a69a]"
                    />
                    <div className="relative flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="flex items-center gap-2 text-xs font-semibold tracking-[0.14em] text-sky-100 uppercase">
                                <Landmark
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                Pengendalian sumber biaya
                            </p>
                            <h1 className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold">
                                Pemetaan Radiologi
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm text-sky-50">
                                Hubungkan satu versi master pemeriksaan dan
                                jenis layanan ke satu tarif radiologi yang
                                berlaku pada tanggal layanan.
                            </p>
                        </div>
                        <span className="rounded-md bg-white/10 px-3 py-2 text-sm font-semibold">
                            {props.permissions.can_manage
                                ? 'Pengelola Tarif'
                                : 'Akses lihat-saja'}
                        </span>
                    </div>
                </header>

                <div
                    role="status"
                    aria-live="polite"
                    className={
                        status
                            ? 'rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-950'
                            : 'sr-only'
                    }
                >
                    {status}
                </div>

                {props.read_error ? (
                    <div
                        role="alert"
                        className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-950"
                    >
                        <p className="font-semibold">
                            Pemetaan radiologi belum dapat dibaca.
                        </p>
                        <p className="mt-1">{props.read_error}</p>
                    </div>
                ) : null}

                <section
                    aria-labelledby="mapping-control-heading"
                    className="grid gap-4 rounded-xl border border-[#7fbcb6] bg-white p-5 shadow-sm lg:grid-cols-[1fr_auto]"
                >
                    <div>
                        <h2
                            id="mapping-control-heading"
                            className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold text-slate-950"
                        >
                            Peta berlaku pada {props.as_of_date}
                        </h2>
                        <dl className="mt-3 grid gap-3 text-sm md:grid-cols-2">
                            <div>
                                <dt className="text-slate-500">
                                    Versi proyeksi master sumber
                                </dt>
                                <dd className="mt-1 font-['IBM_Plex_Mono'] font-semibold text-slate-950">
                                    {props.source_master_version}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">
                                    Digest proyeksi master sumber
                                </dt>
                                <dd className="mt-1">
                                    {digest(props.source_master_content_digest)}
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <label className="flex min-h-11 items-center gap-2 text-sm font-medium text-slate-700">
                            <CalendarClock
                                aria-hidden="true"
                                className="size-4"
                            />
                            <span className="sr-only">Tanggal berlaku</span>
                            <input
                                type="date"
                                className={`${financeFieldClass} w-auto`}
                                value={props.as_of_date}
                                onChange={(event) =>
                                    router.get(
                                        '/manajemen-data/tarif-komponen-biaya/pemetaan-radiologi',
                                        { as_of_date: event.target.value },
                                        { preserveState: true },
                                    )
                                }
                            />
                        </label>
                        {canCreate ? (
                            <Button
                                type="button"
                                className="min-h-11 bg-[#0f5b62] hover:bg-[#0b4147]"
                                onClick={() =>
                                    setAction({
                                        mode: 'create',
                                        url: props.commands.create_url!,
                                    })
                                }
                            >
                                <Plus aria-hidden="true" /> Buat pemetaan
                            </Button>
                        ) : null}
                    </div>
                </section>

                {props.history ? (
                    <section
                        aria-labelledby="mapping-history-heading"
                        className="rounded-xl border border-[#7fbcb6] bg-white shadow-sm"
                    >
                        <header className="flex flex-wrap items-start justify-between gap-3 border-b border-[#7fbcb6] bg-[#e8f5f3] p-4">
                            <div>
                                <h2
                                    id="mapping-history-heading"
                                    className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold text-[#0b4147]"
                                >
                                    Riwayat pemetaan tetap
                                </h2>
                                <p className="mt-1 text-sm text-slate-700">
                                    {props.history.source.code} · v
                                    {props.history.source.master_version} ·{' '}
                                    {
                                        careSettingLabels[
                                            props.history.care_setting
                                        ]
                                    }
                                </p>
                            </div>
                            <Link
                                href="/manajemen-data/tarif-komponen-biaya/pemetaan-radiologi"
                                className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold text-[#0d5275] hover:bg-white focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                            >
                                Tutup riwayat
                            </Link>
                        </header>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[66rem] text-left text-sm">
                                <caption className="sr-only">
                                    Riwayat versi pemetaan radiologi tetap
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
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Periode berlaku
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Digest
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Alasan
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
                                                className="px-4 py-4 font-['IBM_Plex_Mono']"
                                            >
                                                v{version.version}
                                            </th>
                                            <td className="px-4 py-4">
                                                <span className="font-semibold">
                                                    {version.tariff.code} · v
                                                    {version.tariff.version}
                                                </span>
                                                <span className="mt-1 block text-slate-600">
                                                    {
                                                        version.tariff
                                                            .display_name
                                                    }
                                                </span>
                                                <span className="mt-1 block font-['IBM_Plex_Mono'] text-xs">
                                                    {formatRupiah(
                                                        version.tariff
                                                            .amount_rupiah,
                                                    )}
                                                </span>
                                            </td>
                                            <td className="px-4 py-4">
                                                <MappingStateBadge
                                                    state={version.state}
                                                />
                                            </td>
                                            <td className="px-4 py-4">
                                                {interval(
                                                    version.effective_from,
                                                    version.effective_until,
                                                )}
                                            </td>
                                            <td className="px-4 py-4">
                                                {digest(version.content_digest)}
                                            </td>
                                            <td className="px-4 py-4">
                                                <p>{version.reason}</p>
                                                <p className="mt-1 text-xs text-slate-500">
                                                    {version.authored_by} ·{' '}
                                                    {formatFinanceDate(
                                                        version.authored_at,
                                                    )}
                                                </p>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ) : null}

                <section aria-labelledby="mapping-list-heading">
                    <div className="mb-3 flex items-end justify-between gap-3">
                        <div>
                            <h2
                                id="mapping-list-heading"
                                className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold text-slate-950"
                            >
                                Pemetaan efektif
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                Setiap baris mengikat satu versi master dan satu
                                jenis layanan tanpa pencocokan kode otomatis.
                            </p>
                        </div>
                    </div>
                    {props.mappings.length ? (
                        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                            <table className="w-full min-w-[78rem] text-left text-sm">
                                <caption className="sr-only">
                                    Pemetaan tarif pemeriksaan radiologi
                                </caption>
                                <thead className="border-b border-slate-300 bg-slate-100 text-xs tracking-wide text-slate-700 uppercase">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            Master radiologi
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Digest master
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Layanan
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Tarif
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Periode
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            <span className="sr-only">
                                                Tindakan
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200">
                                    {props.mappings.map((mapping) => (
                                        <tr
                                            key={`${mapping.public_id}:${mapping.version}`}
                                            className="align-top"
                                        >
                                            <th
                                                scope="row"
                                                className="px-4 py-4"
                                            >
                                                <span className="font-semibold text-slate-950">
                                                    {mapping.source.code} · v
                                                    {
                                                        mapping.source
                                                            .master_version
                                                    }
                                                </span>
                                                <span className="mt-1 block text-slate-600">
                                                    {
                                                        mapping.source
                                                            .display_name
                                                    }
                                                </span>
                                                <span className="mt-1 block font-['IBM_Plex_Mono'] text-xs text-slate-500">
                                                    {
                                                        mapping.source
                                                            .master_version_public_id
                                                    }
                                                </span>
                                            </th>
                                            <td className="px-4 py-4">
                                                {digest(
                                                    mapping.source
                                                        .master_content_digest,
                                                )}
                                            </td>
                                            <td className="px-4 py-4">
                                                {
                                                    careSettingLabels[
                                                        mapping.care_setting
                                                    ]
                                                }
                                            </td>
                                            <td className="px-4 py-4">
                                                <span className="font-semibold text-slate-950">
                                                    {mapping.tariff.code} · v
                                                    {mapping.tariff.version}
                                                </span>
                                                <span className="mt-1 block text-slate-600">
                                                    {
                                                        mapping.tariff
                                                            .display_name
                                                    }
                                                </span>
                                                <span className="mt-1 block font-['IBM_Plex_Mono'] font-semibold">
                                                    {formatRupiah(
                                                        mapping.tariff
                                                            .amount_rupiah,
                                                    )}
                                                </span>
                                                <span className="mt-1 block">
                                                    {digest(
                                                        mapping.tariff
                                                            .content_digest,
                                                    )}
                                                </span>
                                            </td>
                                            <td className="px-4 py-4">
                                                {interval(
                                                    mapping.effective_from,
                                                    mapping.effective_until,
                                                )}
                                            </td>
                                            <td className="px-4 py-4">
                                                <MappingStateBadge
                                                    state={mapping.state}
                                                />
                                                <p className="mt-2 font-['IBM_Plex_Mono'] text-xs text-slate-500">
                                                    kepala v
                                                    {
                                                        mapping.latest_head_version
                                                    }
                                                </p>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex min-w-max gap-1">
                                                    <Link
                                                        href={
                                                            mapping.actions
                                                                .history_url
                                                        }
                                                        aria-label={`Buka riwayat pemetaan ${mapping.source.code} ${careSettingLabels[mapping.care_setting]}`}
                                                        className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md text-[#0d5275] hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                                                    >
                                                        <History
                                                            aria-hidden="true"
                                                            className="size-4"
                                                        />
                                                    </Link>
                                                    {props.permissions
                                                        .can_manage &&
                                                    mapping.latest_head_state ===
                                                        'ACTIVE' &&
                                                    mapping.actions
                                                        .revise_url ? (
                                                        <button
                                                            type="button"
                                                            aria-label={`Tambah versi pemetaan ${mapping.source.code} ${careSettingLabels[mapping.care_setting]}`}
                                                            onClick={() =>
                                                                setAction({
                                                                    mode: 'revise',
                                                                    url: mapping
                                                                        .actions
                                                                        .revise_url!,
                                                                    binding:
                                                                        mapping,
                                                                })
                                                            }
                                                            className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md text-[#0d5275] hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                                                        >
                                                            <Pencil
                                                                aria-hidden="true"
                                                                className="size-4"
                                                            />
                                                        </button>
                                                    ) : null}
                                                    {props.permissions
                                                        .can_manage &&
                                                    mapping.latest_head_state ===
                                                        'ACTIVE' &&
                                                    mapping.actions
                                                        .retire_url ? (
                                                        <button
                                                            type="button"
                                                            aria-label={`Nonaktifkan pemetaan ${mapping.source.code} ${careSettingLabels[mapping.care_setting]}`}
                                                            onClick={() =>
                                                                setAction({
                                                                    mode: 'retire',
                                                                    url: mapping
                                                                        .actions
                                                                        .retire_url!,
                                                                    binding:
                                                                        mapping,
                                                                })
                                                            }
                                                            className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md text-red-700 hover:bg-red-50 focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:outline-none"
                                                        >
                                                            <X
                                                                aria-hidden="true"
                                                                className="size-4"
                                                            />
                                                        </button>
                                                    ) : null}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
                            <Link2
                                aria-hidden="true"
                                className="mx-auto size-8 text-slate-400"
                            />
                            <p className="mt-3 font-semibold text-slate-950">
                                Belum ada pemetaan tarif radiologi yang
                                dikonfigurasi secara sengaja.
                            </p>
                            <p className="mt-1 text-sm text-slate-600">
                                Pilih versi master, jenis layanan, tarif, dan
                                tanggal berlaku saat keputusan pemetaan
                                tersedia.
                            </p>
                        </div>
                    )}
                </section>

                <section
                    aria-labelledby="mapping-gap-heading"
                    className="rounded-xl border border-amber-300 bg-white shadow-sm"
                >
                    <header className="flex items-start gap-3 border-b border-amber-200 bg-amber-50 p-4">
                        <CircleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-5 text-amber-800"
                        />
                        <div>
                            <h2
                                id="mapping-gap-heading"
                                className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold text-amber-950"
                            >
                                Celah pemetaan
                            </h2>
                            <p className="mt-1 text-sm text-amber-900">
                                Celah tetap terlihat dan tidak pernah diberi
                                nilai perkiraan.
                            </p>
                        </div>
                    </header>
                    {props.gaps.length ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[58rem] text-left text-sm">
                                <caption className="sr-only">
                                    Versi master radiologi tanpa pemetaan
                                    efektif
                                </caption>
                                <thead className="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-700 uppercase">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            Master radiologi
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Layanan
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Status tertutup
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Keterangan
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200">
                                    {props.gaps.map((gap) => (
                                        <tr
                                            key={`${gap.source.master_version_public_id}:${gap.care_setting}`}
                                            className="align-top"
                                        >
                                            <th
                                                scope="row"
                                                className="px-4 py-4"
                                            >
                                                <span className="font-semibold">
                                                    {gap.source.code} · v
                                                    {gap.source.master_version}
                                                </span>
                                                <span className="mt-1 block text-slate-600">
                                                    {gap.source.display_name}
                                                </span>
                                            </th>
                                            <td className="px-4 py-4">
                                                {
                                                    careSettingLabels[
                                                        gap.care_setting
                                                    ]
                                                }
                                            </td>
                                            <td className="px-4 py-4">
                                                <span className="inline-flex rounded-full border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-950">
                                                    {gap.reason_label}
                                                </span>
                                            </td>
                                            <td className="px-4 py-4 text-slate-700">
                                                {gap.detail}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <p className="p-6 text-sm text-slate-700">
                            Tidak ada celah pemetaan pada tanggal yang dipilih.
                        </p>
                    )}
                </section>
            </div>

            {action ? (
                <MappingForm
                    key={`${action.mode}:${action.url}`}
                    action={action}
                    sources={props.sources}
                    tariffs={props.tariff_options}
                    onClose={() => setAction(null)}
                    onStatus={setStatus}
                />
            ) : null}
        </main>
    );
}
