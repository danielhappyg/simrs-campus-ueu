import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    BedDouble,
    Building2,
    CircleAlert,
    ClipboardList,
    FileSearch,
    HeartPulse,
    Stethoscope,
} from 'lucide-react';
import type { ComponentType, SVGProps } from 'react';
import type { BreadcrumbItem } from '@/types';

type ServiceSetting = 'rawat_jalan' | 'igd' | 'rawat_inap';

type SettingCensus = {
    registered: number | null;
    in_examination: number | null;
    ready_for_rm: number | null;
    total_active: number | null;
};

type CensusOverview = {
    available: boolean;
    by_setting: Record<ServiceSetting, SettingCensus>;
    read_error: string | null;
};

type OccupancyOverview = {
    available: boolean;
    totals: {
        active_wards: number;
        active_beds: number;
        occupied_beds: number;
        available_beds: number;
    };
    read_error: string | null;
} | null;

type QueueTone = 'navy' | 'blue' | 'teal' | 'orange' | 'slate';

type DeskQueue = {
    id: string;
    label: string;
    hint: string;
    count: number | null;
    href: string;
    tone: QueueTone;
};

type OperationalAction = {
    setting: ServiceSetting | 'occupancy';
    kind: string;
    label: string;
    href: string;
};

type Props = {
    census: CensusOverview;
    queues: DeskQueue[];
    occupancy: OccupancyOverview;
    actions: OperationalAction[];
};

type ServiceDefinition = {
    key: ServiceSetting;
    shortLabel: string;
    label: string;
    accent: string;
    icon: ComponentType<SVGProps<SVGSVGElement>>;
};

const services: readonly ServiceDefinition[] = [
    {
        key: 'rawat_jalan',
        shortLabel: 'RJ',
        label: 'Rawat Jalan',
        accent: 'bg-[#1b75bc]',
        icon: Activity,
    },
    {
        key: 'igd',
        shortLabel: 'IGD',
        label: 'Instalasi Gawat Darurat',
        accent: 'bg-[#f26a1b]',
        icon: HeartPulse,
    },
    {
        key: 'rawat_inap',
        shortLabel: 'RI',
        label: 'Rawat Inap',
        accent: 'bg-[#0f766e]',
        icon: Building2,
    },
] as const;

const statusChips = [
    { key: 'registered', label: 'Terdaftar' },
    { key: 'in_examination', label: 'Pemeriksaan' },
    { key: 'ready_for_rm', label: 'Siap RM' },
] as const;

const tonePlate: Record<QueueTone, string> = {
    navy: 'bg-[#e8eef5] text-[#123b63]',
    blue: 'bg-[#e8f3fb] text-[#1b75bc]',
    teal: 'bg-[#e7f5f3] text-[#0f766e]',
    orange: 'bg-[#fdeee3] text-[#c2410c]',
    slate: 'bg-[#f1f5f9] text-[#475569]',
};

const queueIcons: Record<string, ComponentType<SVGProps<SVGSVGElement>>> = {
    'queue.registered.rj': ClipboardList,
    'queue.in_exam.rj': Stethoscope,
    'queue.ready_rm.rj': FileSearch,
    'queue.in_exam.igd': HeartPulse,
    'queue.in_exam.ri': Building2,
    'queue.ready_rm.ri': FileSearch,
    'queue.occupancy': BedDouble,
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Beranda',
        href: '/',
    },
];

function formatCount(value: number | null): string {
    return value === null ? '—' : String(value);
}

function toneClass(tone: QueueTone): string {
    switch (tone) {
        case 'navy':
        case 'blue':
        case 'teal':
        case 'orange':
        case 'slate':
            return tonePlate[tone];
        default: {
            const exhaustive: never = tone;

            return exhaustive;
        }
    }
}

function ActionLink({ action }: { action: OperationalAction }) {
    return (
        <Link
            href={action.href}
            className="group inline-flex min-h-11 items-center justify-between gap-3 rounded-lg border border-[#dbe5ee] bg-white px-3.5 py-2.5 text-sm font-semibold text-[#123b63] transition-colors hover:border-[#1b75bc]/50 hover:bg-[#f4f9fd] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:ring-offset-2 focus-visible:outline-none"
        >
            {action.label}
            <ArrowRight
                aria-hidden="true"
                className="size-4 shrink-0 text-[#1b75bc] transition-transform group-hover:translate-x-0.5"
            />
        </Link>
    );
}

export default function RebuildHome({
    census,
    queues,
    occupancy,
    actions,
}: Props) {
    const { flash } = usePage().props;
    const queueHrefs = new Set(queues.map((queue) => queue.href));
    const moduleActions = actions.filter(
        (action) => !queueHrefs.has(action.href),
    );

    return (
        <>
            <Head title="Meja Kerja" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 px-4 py-8 md:px-6 md:py-10">
                <header className="grid gap-4 border-b border-[#dbe5ee] pb-7 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                    <div className="space-y-2">
                        <p className="font-mono text-xs font-semibold tracking-[0.16em] text-[#1b75bc] uppercase">
                            Operasional hari ini
                        </p>
                        <h1 className="max-w-3xl font-display text-3xl font-semibold tracking-tight text-[#0f2942] md:text-4xl">
                            Meja kerja hari ini
                        </h1>
                        <p className="max-w-2xl text-base leading-relaxed text-[#52677b]">
                            Ringkasan layanan dan antrian kerja hari ini.
                        </p>
                    </div>
                    <p className="inline-flex w-fit items-center gap-2 rounded-full border border-[#b8d7ee] bg-[#eef7fd] px-3 py-1.5 text-xs font-semibold text-[#123b63]">
                        <span
                            aria-hidden="true"
                            className="size-2 rounded-full bg-[#179c78]"
                        />
                        Sensus harian
                    </p>
                </header>

                {flash?.error ? (
                    <div
                        role="alert"
                        className="rounded-xl border border-[#fecaca] bg-[#fef2f2] px-4 py-3 text-sm text-[#991b1b]"
                    >
                        {flash.error}
                    </div>
                ) : null}

                {flash?.success ? (
                    <div
                        role="status"
                        className="rounded-xl border border-[#a7f3d0] bg-[#ecfdf5] px-4 py-3 text-sm text-[#065f46]"
                    >
                        {flash.success}
                    </div>
                ) : null}

                <section aria-labelledby="service-flow-heading">
                    <div className="mb-4">
                        <h2
                            id="service-flow-heading"
                            className="text-xl font-semibold text-[#0f2942]"
                        >
                            Arus layanan
                        </h2>
                        <p className="mt-1 text-sm text-[#64788a]">
                            Status kunjungan aktif yang didaftarkan hari ini.
                        </p>
                    </div>

                    {!census.available ? (
                        <div
                            role="alert"
                            className="mb-4 flex items-start gap-3 rounded-xl border border-[#fed7aa] bg-[#fff7ed] px-4 py-3 text-sm text-[#9a3412]"
                        >
                            <CircleAlert
                                aria-hidden="true"
                                className="mt-0.5 size-5 shrink-0"
                            />
                            <span>{census.read_error}</span>
                        </div>
                    ) : null}

                    <div className="grid gap-4 lg:grid-cols-3">
                        {services.map((service) => {
                            const Icon = service.icon;
                            const setting = census.by_setting[service.key];

                            return (
                                <article
                                    key={service.key}
                                    className="flex min-h-56 flex-col overflow-hidden rounded-xl border border-[#dbe5ee] bg-white shadow-[0_10px_30px_rgba(15,41,66,0.05)]"
                                >
                                    <div
                                        aria-hidden="true"
                                        className={`h-1 ${service.accent}`}
                                    />
                                    <div className="flex flex-1 flex-col p-5">
                                        <div className="flex items-start justify-between gap-4">
                                            <div>
                                                <p className="font-mono text-xs font-bold tracking-[0.12em] text-[#64788a] uppercase">
                                                    {service.shortLabel}
                                                </p>
                                                <h3 className="mt-1 text-lg font-semibold text-[#0f2942]">
                                                    {service.label}
                                                </h3>
                                            </div>
                                            <span className="grid size-10 place-items-center rounded-lg bg-[#eef5fa] text-[#123b63]">
                                                <Icon
                                                    aria-hidden="true"
                                                    className="size-5"
                                                />
                                            </span>
                                        </div>

                                        <p className="mt-5 font-display text-5xl leading-none font-semibold text-[#0f2942] tabular-nums">
                                            {formatCount(setting.total_active)}
                                        </p>
                                        <p className="mt-2 text-sm text-[#64788a]">
                                            Kunjungan aktif hari ini
                                        </p>

                                        <ul className="mt-5 flex flex-wrap gap-2 border-t border-[#e8eef3] pt-4">
                                            {statusChips.map((chip) => (
                                                <li
                                                    key={chip.key}
                                                    className="inline-flex items-center gap-1.5 rounded-full border border-[#e2e8f0] bg-[#f8fafc] px-2.5 py-1"
                                                >
                                                    <span className="text-[0.65rem] tracking-wide text-[#64788a] uppercase">
                                                        {chip.label}
                                                    </span>
                                                    <span className="text-sm font-semibold text-[#0f2942] tabular-nums">
                                                        {formatCount(
                                                            setting[chip.key],
                                                        )}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                </article>
                            );
                        })}
                    </div>
                </section>

                <section aria-labelledby="queues-heading">
                    <div className="mb-4 flex items-center gap-3">
                        <span className="grid size-10 place-items-center rounded-lg bg-[#e8f3fb] text-[#1b75bc]">
                            <ClipboardList
                                aria-hidden="true"
                                className="size-5"
                            />
                        </span>
                        <div>
                            <h2
                                id="queues-heading"
                                className="text-xl font-semibold text-[#0f2942]"
                            >
                                Antrian kerja
                            </h2>
                            <p className="mt-1 text-sm text-[#64788a]">
                                Meja yang dapat Anda buka dari status hari ini.
                            </p>
                        </div>
                    </div>

                    {queues.length === 0 ? (
                        <p className="rounded-xl border border-[#e2e8f0] bg-white px-4 py-5 text-sm text-[#64788a]">
                            Tidak ada antrian kerja untuk akses akun ini.
                        </p>
                    ) : (
                        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {queues.map((queue) => {
                                const QueueIcon =
                                    queueIcons[queue.id] ?? ClipboardList;

                                return (
                                    <li key={queue.id}>
                                        <Link
                                            href={queue.href}
                                            aria-label={`${queue.label}: ${formatCount(queue.count)}`}
                                            className="group flex h-full flex-col gap-3 rounded-xl border border-[#e2e8f0] bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-[transform,border-color,background-color] duration-200 ease-out hover:-translate-y-0.5 hover:border-[#1b75bc]/55 hover:bg-[#f8fbff] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:ring-offset-2 focus-visible:outline-none"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <span
                                                    className={`grid size-11 place-items-center rounded-2xl text-lg font-semibold tabular-nums ${toneClass(queue.tone)}`}
                                                >
                                                    {formatCount(queue.count)}
                                                </span>
                                                <span className="grid size-8 place-items-center rounded-lg bg-[#f1f5f9] text-[#123b63]">
                                                    <QueueIcon
                                                        aria-hidden="true"
                                                        className="size-4"
                                                    />
                                                </span>
                                            </div>
                                            <div>
                                                <p className="text-sm font-semibold text-[#0f2942]">
                                                    {queue.label}
                                                </p>
                                                <p className="mt-1 text-xs leading-relaxed text-[#64788a]">
                                                    {queue.hint}
                                                </p>
                                            </div>
                                            <span className="mt-auto inline-flex items-center gap-1 text-xs font-semibold text-[#1b75bc]">
                                                Buka meja
                                                <ArrowRight
                                                    aria-hidden="true"
                                                    className="size-3.5 transition-transform group-hover:translate-x-0.5"
                                                />
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>

                {occupancy ? (
                    <section
                        aria-labelledby="occupancy-heading"
                        className="overflow-hidden rounded-xl border border-[#b9d7cf] bg-[#f4fbf8]"
                    >
                        <div className="p-5 md:p-6">
                            <div className="flex items-center gap-3">
                                <span className="grid size-10 place-items-center rounded-lg bg-[#dff3eb] text-[#0f766e]">
                                    <BedDouble
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                </span>
                                <div>
                                    <p className="font-mono text-xs font-semibold tracking-[0.12em] text-[#0f766e] uppercase">
                                        Kapasitas terkelola
                                    </p>
                                    <h2
                                        id="occupancy-heading"
                                        className="text-xl font-semibold text-[#0f2942]"
                                    >
                                        Hunian rawat inap
                                    </h2>
                                </div>
                            </div>

                            {!occupancy.available ? (
                                <div
                                    role="alert"
                                    className="mt-4 flex items-start gap-3 rounded-lg border border-[#fed7aa] bg-white px-4 py-3 text-sm text-[#9a3412]"
                                >
                                    <CircleAlert
                                        aria-hidden="true"
                                        className="mt-0.5 size-5 shrink-0"
                                    />
                                    <span>{occupancy.read_error}</span>
                                </div>
                            ) : (
                                <dl className="mt-5 grid grid-cols-2 gap-x-5 gap-y-4 sm:grid-cols-4">
                                    <div>
                                        <dt className="text-xs text-[#64788a]">
                                            Bangsal aktif
                                        </dt>
                                        <dd className="mt-1 text-2xl font-semibold text-[#0f2942] tabular-nums">
                                            {occupancy.totals.active_wards}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-[#64788a]">
                                            Tempat tidur aktif
                                        </dt>
                                        <dd className="mt-1 text-2xl font-semibold text-[#0f2942] tabular-nums">
                                            {occupancy.totals.active_beds}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-[#64788a]">
                                            Terisi
                                        </dt>
                                        <dd className="mt-1 text-2xl font-semibold text-[#b45309] tabular-nums">
                                            {occupancy.totals.occupied_beds}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-[#64788a]">
                                            Tersedia
                                        </dt>
                                        <dd className="mt-1 text-2xl font-semibold text-[#0f766e] tabular-nums">
                                            {occupancy.totals.available_beds}
                                        </dd>
                                    </div>
                                </dl>
                            )}
                        </div>
                    </section>
                ) : null}

                {moduleActions.length > 0 ? (
                    <section aria-labelledby="modules-heading">
                        <h2
                            id="modules-heading"
                            className="mb-3 text-xl font-semibold text-[#0f2942]"
                        >
                            Pintasan modul
                        </h2>
                        <nav
                            aria-label="Pintasan modul beranda"
                            className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
                        >
                            {moduleActions.map((action) => (
                                <ActionLink
                                    key={`${action.setting}-${action.kind}`}
                                    action={action}
                                />
                            ))}
                        </nav>
                    </section>
                ) : null}
            </div>
        </>
    );
}

RebuildHome.layout = {
    breadcrumbs,
};
