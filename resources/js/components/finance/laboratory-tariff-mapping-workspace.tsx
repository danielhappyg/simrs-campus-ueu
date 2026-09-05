import { Link, router, useForm } from '@inertiajs/react';
import {
    CalendarClock,
    CircleAlert,
    FlaskConical,
    History,
    Link2,
    Pencil,
    Plus,
    ShieldCheck,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    financeFieldClass,
    formatFinanceDate,
    formatRupiah,
} from './finance-shared';
import type {
    LaboratoryTariffBinding,
    LaboratoryTariffMappingProps,
    LaboratoryTariffOption,
    LaboratoryTariffSource,
} from './laboratory-tariff-mapping-types';
import type { TariffCareSetting } from './tariff-master-types';

type MappingAction =
    | { mode: 'create'; url: string }
    | {
          mode: 'revise' | 'retire';
          url: string;
          binding: LaboratoryTariffBinding;
      };

type FormData = {
    laboratory_master_public_id: string;
    laboratory_master_version_public_id: string;
    laboratory_master_version: number | '';
    laboratory_master_content_digest: string;
    care_setting: TariffCareSetting | '';
    tariff_item_public_id: string;
    effective_from: string;
    expected_version: number | '';
    expected_digest: string;
    reason: string;
    confirm: boolean;
    idempotency_key: string;
};

const careLabels: Record<TariffCareSetting, string> = {
    OUTPATIENT: 'Outpatient',
    EMERGENCY: 'IGD',
    INPATIENT: 'Inpatient',
};

function operationKey() {
    return `laboratory-tariff-${Date.now()}-${globalThis.crypto?.randomUUID?.() ?? Math.random().toString(16).slice(2)}`;
}

function Digest({ value }: { value: string }) {
    return (
        <code className="block max-w-[24rem] font-['IBM_Plex_Mono'] text-xs break-all text-slate-600">
            {value}
        </code>
    );
}

function StateBadge({ state }: { state: 'ACTIVE' | 'RETIRED' }) {
    return (
        <span
            className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${
                state === 'ACTIVE'
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-950'
                    : 'border-slate-300 bg-slate-100 text-slate-700'
            }`}
        >
            {state === 'ACTIVE' ? 'Active' : 'Inactive'}
        </span>
    );
}

function SourceIdentity({ source }: { source: LaboratoryTariffSource }) {
    return (
        <div>
            <p className="font-semibold text-slate-950">
                {source.code} · v{source.master_version}
            </p>
            <p className="mt-1 text-slate-700">{source.display_name}</p>
            <p className="mt-1 text-xs text-slate-600">
                {source.specimen_type} · {source.component_count} component
                result
            </p>
            <p className="mt-1 font-['IBM_Plex_Mono'] text-xs text-slate-500">
                {source.master_version_public_id}
            </p>
        </div>
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
    sources: LaboratoryTariffSource[];
    tariffs: LaboratoryTariffOption[];
    onClose: () => void;
    onStatus: (value: string) => void;
}) {
    const binding = action.mode === 'create' ? null : action.binding;
    const form = useForm<FormData>({
        laboratory_master_public_id: binding?.source.public_id ?? '',
        laboratory_master_version_public_id:
            binding?.source.master_version_public_id ?? '',
        laboratory_master_version: binding?.source.master_version ?? '',
        laboratory_master_content_digest:
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
    const [attempted, setAttempted] = useState(false);
    const errorRef = useRef<HTMLDivElement>(null);
    const errors = useMemo(
        () => Array.from(new Set(Object.values(form.errors))),
        [form.errors],
    );
    const selectedSource = sources.find(
        (source) => source.public_id === form.data.laboratory_master_public_id,
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

    useEffect(() => {
        if (attempted && errors.length) {
            errorRef.current?.focus();
        }
    }, [attempted, errors.length]);

    const chooseSource = (publicId: string) => {
        const source = sources.find((item) => item.public_id === publicId);
        form.setData('laboratory_master_public_id', publicId);
        form.setData(
            'laboratory_master_version_public_id',
            source?.master_version_public_id ?? '',
        );
        form.setData('laboratory_master_version', source?.master_version ?? '');
        form.setData(
            'laboratory_master_content_digest',
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
                        ? 'Laboratory mapping scheduled for deactivation.'
                        : action.mode === 'revise'
                          ? 'Laboratory mapping version added.'
                          : 'Laboratory mapping created.',
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

    const closeOnEscape = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Escape') {
            onClose();
        }
    };

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4"
            role="presentation"
            onKeyDown={closeOnEscape}
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="laboratory-mapping-form-title"
                aria-describedby="laboratory-mapping-form-description"
                className="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white shadow-2xl"
            >
                <header className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white p-5">
                    <div>
                        <h2
                            id="laboratory-mapping-form-title"
                            className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                        >
                            {action.mode === 'create'
                                ? 'Create laboratory mapping'
                                : action.mode === 'revise'
                                  ? 'Add mapping version'
                                  : 'Schedule mapping deactivation'}
                        </h2>
                        <p
                            id="laboratory-mapping-form-description"
                            className="mt-1 text-sm text-slate-600"
                        >
                            The value applies only to the source result with
                            status VERIFIED on date service. History not
                            changed.
                        </p>
                    </div>
                    <button
                        autoFocus
                        type="button"
                        onClick={onClose}
                        aria-label="Close mapping form"
                        className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
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
                                The mapping could not be saved
                            </p>
                            <ul className="mt-1 list-inside list-disc">
                                {errors.map((error) => (
                                    <li key={error}>{error}</li>
                                ))}
                            </ul>
                        </div>
                    ) : null}

                    {action.mode === 'create' ? (
                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <Label htmlFor="laboratory-mapping-source">
                                    Examination and master version
                                </Label>
                                <select
                                    id="laboratory-mapping-source"
                                    className={financeFieldClass}
                                    value={
                                        form.data.laboratory_master_public_id
                                    }
                                    onChange={(event) =>
                                        chooseSource(event.target.value)
                                    }
                                    required
                                >
                                    <option value="">Select examination</option>
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
                                <Label htmlFor="laboratory-mapping-care">
                                    Service type
                                </Label>
                                <select
                                    id="laboratory-mapping-care"
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
                                    <option value="">Select service</option>
                                    {Object.entries(careLabels).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </div>
                        </div>
                    ) : binding ? (
                        <div className="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm">
                            <SourceIdentity source={binding.source} />
                            <p className="mt-2 font-semibold text-[#0d5275]">
                                {careLabels[binding.care_setting]}
                            </p>
                        </div>
                    ) : null}

                    {selectedSource ? (
                        <div className="grid gap-4 rounded-lg border border-slate-200 p-4 text-sm md:grid-cols-2">
                            <SourceIdentity source={selectedSource} />
                            <div>
                                <p className="text-slate-500">
                                    Exact master digest
                                </p>
                                <div className="mt-1">
                                    <Digest
                                        value={
                                            selectedSource.master_content_digest
                                        }
                                    />
                                </div>
                            </div>
                        </div>
                    ) : null}

                    {action.mode !== 'retire' ? (
                        <div>
                            <Label htmlFor="laboratory-mapping-tariff">
                                Laboratory tariff
                            </Label>
                            <select
                                id="laboratory-mapping-tariff"
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
                                    Select a tariff explicitly
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
                                No tariff or value is selected automatically.
                            </p>
                        </div>
                    ) : null}

                    {selectedTariff && action.mode !== 'retire' ? (
                        <div
                            aria-label="Selected tariff provenance"
                            className="grid gap-4 rounded-lg border border-[#7fbcb6] bg-[#e8f5f3] p-4 text-sm md:grid-cols-2"
                        >
                            <div>
                                <p className="font-semibold text-[#0b4147]">
                                    {selectedTariff.code} · v
                                    {selectedTariff.version}
                                </p>
                                <p className="mt-1">
                                    {selectedTariff.display_name}
                                </p>
                                <p className="mt-2 font-['IBM_Plex_Mono'] text-lg font-bold">
                                    {formatRupiah(selectedTariff.amount_rupiah)}
                                </p>
                            </div>
                            <div>
                                <p className="text-slate-500">
                                    Tariff-version ID and digest
                                </p>
                                <p className="mt-1 font-['IBM_Plex_Mono'] text-xs break-all">
                                    {selectedTariff.version_public_id}
                                </p>
                                <div className="mt-2">
                                    <Digest
                                        value={selectedTariff.content_digest}
                                    />
                                </div>
                            </div>
                        </div>
                    ) : null}

                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <Label htmlFor="laboratory-mapping-effective">
                                {action.mode === 'retire'
                                    ? 'Inactive from'
                                    : 'Effective from'}
                            </Label>
                            <input
                                id="laboratory-mapping-effective"
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
                                Use a future date to schedule the change without
                                rewriting previous versions.
                            </p>
                        </div>
                        <div>
                            <Label htmlFor="laboratory-mapping-reason">
                                Reason
                            </Label>
                            <textarea
                                id="laboratory-mapping-reason"
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
                                    Expected head version
                                </dt>
                                <dd className="mt-1 font-['IBM_Plex_Mono'] font-semibold">
                                    v{form.data.expected_version}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">
                                    Expected head digest
                                </dt>
                                <dd className="mt-1">
                                    <Digest value={form.data.expected_digest} />
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
                            I confirm the master version, service type, tariff,
                            and displayed effective date.
                        </span>
                    </label>

                    <footer className="flex flex-wrap justify-end gap-3 border-t border-slate-200 pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={onClose}
                        >
                            Cancel
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
                                ? 'Saving…'
                                : action.mode === 'retire'
                                  ? 'Schedule deactivation'
                                  : 'Save mapping'}
                        </Button>
                    </footer>
                </form>
            </section>
        </div>
    );
}

export function LaboratoryTariffMappingWorkspace(
    props: LaboratoryTariffMappingProps,
) {
    const [action, setAction] = useState<MappingAction | null>(null);
    const [status, setStatus] = useState('');
    const canCreate =
        props.permissions.can_manage && props.commands.create_url !== null;

    return (
        <main className="min-h-screen bg-slate-50 pb-12">
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                <nav aria-label="Breadcrumb" className="text-sm">
                    <ol className="flex flex-wrap items-center gap-2 text-slate-600">
                        <li>Data Management</li>
                        <li aria-hidden="true">/</li>
                        <li>
                            <Link
                                href="/manajemen-data/tarif-komponen-biaya"
                                className="inline-flex min-h-11 items-center rounded-md px-2 font-semibold text-[#0d5275] hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                            >
                                Tariffs &amp; Charge Components
                            </Link>
                        </li>
                        <li aria-hidden="true">/</li>
                        <li aria-current="page">Laboratory Mapping</li>
                    </ol>
                </nav>

                <header className="relative overflow-hidden rounded-2xl bg-[#123b5d] p-6 text-white shadow-sm">
                    <span
                        aria-hidden="true"
                        className="absolute inset-y-0 left-0 w-2 bg-[#24a69a]"
                    />
                    <div className="relative flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="flex items-center gap-2 text-xs font-semibold text-sky-100">
                                <FlaskConical
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                Source-charge controls use the verified result
                            </p>
                            <h1 className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold">
                                Laboratory Mapping
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm text-sky-50">
                                Link one version master examination and type
                                service to one laboratory tariff that effective
                                on the verified source-result date.
                            </p>
                        </div>
                        <span className="rounded-md bg-white/10 px-3 py-2 text-sm font-semibold">
                            {props.permissions.can_manage
                                ? 'Tariff Manager'
                                : 'Read-only access'}
                        </span>
                    </div>
                </header>

                <aside
                    aria-label="Laboratory source-charge rules"
                    className="grid gap-4 rounded-xl border border-[#7fbcb6] bg-white p-5 shadow-sm md:grid-cols-[auto_1fr]"
                >
                    <span className="grid size-11 place-items-center rounded-lg bg-[#e8f5f3] text-[#0f5b62]">
                        <ShieldCheck aria-hidden="true" className="size-5" />
                    </span>
                    <div>
                        <p className="font-semibold text-slate-950">
                            VERIFIED source results only
                        </p>
                        <p className="mt-1 text-sm text-slate-700">
                            {props.source_trigger.label} Draft, amendemen,
                            communication result critical, and acknowledgement
                            physician does not create a new source charge.
                        </p>
                    </div>
                </aside>

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
                            Laboratory mappings could not be loaded.
                        </p>
                        <p className="mt-1">{props.read_error}</p>
                    </div>
                ) : null}

                <section
                    aria-labelledby="laboratory-mapping-control"
                    className="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[1fr_auto]"
                >
                    <div>
                        <h2
                            id="laboratory-mapping-control"
                            className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                        >
                            Mappings effective on {props.as_of_date}
                        </h2>
                        <dl className="mt-3 grid gap-3 text-sm md:grid-cols-2">
                            <div>
                                <dt className="text-slate-500">
                                    Version projection master
                                </dt>
                                <dd className="mt-1 font-['IBM_Plex_Mono'] font-semibold">
                                    {props.source_master_version}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">
                                    Digest projection master
                                </dt>
                                <dd className="mt-1">
                                    <Digest
                                        value={
                                            props.source_master_content_digest
                                        }
                                    />
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
                            <span className="sr-only">Effective date</span>
                            <input
                                type="date"
                                className={`${financeFieldClass} w-auto`}
                                value={props.as_of_date}
                                onChange={(event) =>
                                    router.get(
                                        '/manajemen-data/tarif-komponen-biaya/pemetaan-laboratorium',
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
                                <Plus aria-hidden="true" /> Create mapping
                            </Button>
                        ) : null}
                    </div>
                </section>

                {props.history ? (
                    <section
                        aria-labelledby="laboratory-mapping-history"
                        className="rounded-xl border border-[#7fbcb6] bg-white shadow-sm"
                    >
                        <header className="flex flex-wrap items-start justify-between gap-3 border-b border-[#7fbcb6] bg-[#e8f5f3] p-4">
                            <div>
                                <h2
                                    id="laboratory-mapping-history"
                                    className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold text-[#0b4147]"
                                >
                                    Immutable mapping history
                                </h2>
                                <div className="mt-2 text-sm">
                                    <SourceIdentity
                                        source={props.history.source}
                                    />
                                </div>
                            </div>
                            <Link
                                href="/manajemen-data/tarif-komponen-biaya/pemetaan-laboratorium"
                                className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold text-[#0d5275] hover:bg-white focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                            >
                                Close history
                            </Link>
                        </header>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[64rem] text-left text-sm">
                                <caption className="sr-only">
                                    Immutable laboratory mapping version history
                                </caption>
                                <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-700 uppercase">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            Version
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Tariff
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Period
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Digest
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Reason
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
                                                <p className="font-semibold">
                                                    {version.tariff.code} · v
                                                    {version.tariff.version}
                                                </p>
                                                <p className="mt-1 text-slate-600">
                                                    {
                                                        version.tariff
                                                            .display_name
                                                    }
                                                </p>
                                                <p className="mt-1 font-['IBM_Plex_Mono'] text-xs">
                                                    {formatRupiah(
                                                        version.tariff
                                                            .amount_rupiah,
                                                    )}
                                                </p>
                                            </td>
                                            <td className="px-4 py-4">
                                                <StateBadge
                                                    state={version.state}
                                                />
                                            </td>
                                            <td className="px-4 py-4">
                                                {version.effective_from} —{' '}
                                                {version.effective_until ??
                                                    'onward'}
                                            </td>
                                            <td className="px-4 py-4">
                                                <Digest
                                                    value={
                                                        version.content_digest
                                                    }
                                                />
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

                <section aria-labelledby="laboratory-mapping-list">
                    <h2
                        id="laboratory-mapping-list"
                        className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                    >
                        Mapping effective
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Each line mengikat one version master and type service
                        without matching code, specimen, or component secara
                        automatically.
                    </p>
                    {props.mappings.length ? (
                        <div className="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                            <table className="w-full min-w-[78rem] text-left text-sm">
                                <caption className="sr-only">
                                    Mapping tariff examination laboratory
                                </caption>
                                <thead className="border-b border-slate-300 bg-slate-100 text-xs text-slate-700 uppercase">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            Master laboratory
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Digest master
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Service
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Tariff
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Period
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            <span className="sr-only">
                                                Action
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
                                                <SourceIdentity
                                                    source={mapping.source}
                                                />
                                            </th>
                                            <td className="px-4 py-4">
                                                <Digest
                                                    value={
                                                        mapping.source
                                                            .master_content_digest
                                                    }
                                                />
                                            </td>
                                            <td className="px-4 py-4">
                                                {
                                                    careLabels[
                                                        mapping.care_setting
                                                    ]
                                                }
                                            </td>
                                            <td className="px-4 py-4">
                                                <p className="font-semibold">
                                                    {mapping.tariff.code} · v
                                                    {mapping.tariff.version}
                                                </p>
                                                <p className="mt-1 text-slate-600">
                                                    {
                                                        mapping.tariff
                                                            .display_name
                                                    }
                                                </p>
                                                <p className="mt-1 font-['IBM_Plex_Mono'] font-semibold">
                                                    {formatRupiah(
                                                        mapping.tariff
                                                            .amount_rupiah,
                                                    )}
                                                </p>
                                                <div className="mt-1">
                                                    <Digest
                                                        value={
                                                            mapping.tariff
                                                                .content_digest
                                                        }
                                                    />
                                                </div>
                                            </td>
                                            <td className="px-4 py-4">
                                                {mapping.effective_from} —{' '}
                                                {mapping.effective_until ??
                                                    'onward'}
                                            </td>
                                            <td className="px-4 py-4">
                                                <StateBadge
                                                    state={mapping.state}
                                                />
                                                <p className="mt-2 font-['IBM_Plex_Mono'] text-xs text-slate-500">
                                                    head v
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
                                                        aria-label={`Open history mapping ${mapping.source.code} ${careLabels[mapping.care_setting]}`}
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
                                                            aria-label={`Add mapping version ${mapping.source.code} ${careLabels[mapping.care_setting]}`}
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
                                                            aria-label={`Deactivate mapping ${mapping.source.code} ${careLabels[mapping.care_setting]}`}
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
                        <div className="mt-3 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
                            <Link2
                                aria-hidden="true"
                                className="mx-auto size-8 text-slate-400"
                            />
                            <p className="mt-3 font-semibold text-slate-950">
                                No laboratory tariff mappings have been
                                configured deliberately.
                            </p>
                            <p className="mt-1 text-sm text-slate-600">
                                Select version master, type service, tariff, and
                                effective date when a mapping decision is
                                available.
                            </p>
                        </div>
                    )}
                </section>

                <section
                    aria-labelledby="laboratory-mapping-gaps"
                    className="rounded-xl border border-amber-300 bg-white shadow-sm"
                >
                    <header className="flex items-start gap-3 border-b border-amber-200 bg-amber-50 p-4">
                        <CircleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-5 text-amber-800"
                        />
                        <div>
                            <h2
                                id="laboratory-mapping-gaps"
                                className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold text-amber-950"
                            >
                                Gap mapping
                            </h2>
                            <p className="mt-1 text-sm text-amber-900">
                                Gaps remain visible and are never assigned a
                                value estimated.
                            </p>
                        </div>
                    </header>
                    {props.gaps.length ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[62rem] text-left text-sm">
                                <caption className="sr-only">
                                    Version master laboratory without mapping
                                    effective
                                </caption>
                                <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-700 uppercase">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            Master laboratory
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Service
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Blocking status
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Details
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
                                                <SourceIdentity
                                                    source={gap.source}
                                                />
                                            </th>
                                            <td className="px-4 py-4">
                                                {careLabels[gap.care_setting]}
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
                            No mapping gaps on the selected date.
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
