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
    priority: boolean;
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
    scopeLabel: string;
    accent: string;
    icon: ComponentType<SVGProps<SVGSVGElement>>;
};

const services: readonly ServiceDefinition[] = [
    {
        key: 'rawat_jalan',
        shortLabel: 'OPD',
        label: 'Outpatient',
        scopeLabel: 'Active and registered today',
        accent: 'bg-[#1b75bc]',
        icon: Activity,
    },
    {
        key: 'igd',
        shortLabel: 'ED',
        label: 'Emergency Department',
        scopeLabel: 'Active and registered today',
        accent: 'bg-[#f26a1b]',
        icon: HeartPulse,
    },
    {
        key: 'rawat_inap',
        shortLabel: 'IPD',
        label: 'Inpatient',
        scopeLabel: 'All currently active episodes',
        accent: 'bg-[#0f766e]',
        icon: Building2,
    },
] as const;

const statusChips = [
    { key: 'registered', label: 'Registered' },
    { key: 'in_examination', label: 'In care' },
    { key: 'ready_for_rm', label: 'Ready for records review' },
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

const queueCopy: Record<string, Pick<DeskQueue, 'label' | 'hint'>> = {
    'queue.in_exam.rj': {
        label: 'Outpatient examination',
        hint: 'Clinic visits currently being seen.',
    },
    'queue.occupancy': {
        label: 'Bed census',
        hint: 'Occupied beds in managed wards.',
    },
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Home',
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
            <Head title="Work desk" />

            <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 md:px-6 md:py-8">
                <header className="grid gap-4 border-b border-[#dbe5ee] pb-5 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                    <div className="space-y-1.5">
                        <h1 className="max-w-3xl font-display text-3xl font-semibold tracking-tight text-[#0f2942] md:text-4xl">
                            Today’s work desk
                        </h1>
                        <p className="max-w-2xl text-base leading-relaxed text-[#52677b]">
                            Today’s service summary and work queues.
                        </p>
                    </div>
                    <p className="inline-flex w-fit items-center gap-2 rounded-full border border-[#b8d7ee] bg-[#eef7fd] px-3 py-1.5 text-xs font-semibold text-[#123b63]">
                        <span
                            aria-hidden="true"
                            className="size-2 rounded-full bg-[#179c78]"
                        />
                        Operational data
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

                <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1.15fr)_minmax(22rem,0.85fr)]">
                    <section aria-labelledby="service-flow-heading">
                        <div className="mb-3">
                            <h2
                                id="service-flow-heading"
                                className="text-xl font-semibold text-[#0f2942]"
                            >
                                Care flow
                            </h2>
                            <p className="mt-1 text-sm text-[#64788a]">
                                Outpatient and emergency visits today; inpatient
                                covers all currently active episodes.
                            </p>
                        </div>

                        {!census.available ? (
                            <div
                                role="alert"
                                className="mb-3 flex items-start gap-3 rounded-xl border border-[#fed7aa] bg-[#fff7ed] px-4 py-3 text-sm text-[#9a3412]"
                            >
                                <CircleAlert
                                    aria-hidden="true"
                                    className="mt-0.5 size-5 shrink-0"
                                />
                                <span>{census.read_error}</span>
                            </div>
                        ) : null}

                        <div className="overflow-hidden rounded-xl border border-[#dbe5ee] bg-white shadow-[0_8px_24px_rgba(15,41,66,0.04)]">
                            {services.map((service) => {
                                const Icon = service.icon;
                                const setting = census.by_setting[service.key];

                                return (
                                    <article
                                        key={service.key}
                                        className="relative grid gap-3 border-b border-[#e8eef3] px-4 py-3.5 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center md:px-5"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={`absolute inset-y-0 left-0 w-1 ${service.accent}`}
                                        />
                                        <div className="flex min-w-0 items-center gap-3">
                                            <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-[#eef5fa] text-[#123b63]">
                                                <Icon
                                                    aria-hidden="true"
                                                    className="size-5"
                                                />
                                            </span>
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-baseline gap-x-2">
                                                    <span className="text-xs font-semibold text-[#1b75bc]">
                                                        {service.shortLabel}
                                                    </span>
                                                    <h3 className="font-semibold text-[#0f2942]">
                                                        {service.label}
                                                    </h3>
                                                </div>
                                                <p className="mt-0.5 text-xs text-[#64788a]">
                                                    {service.scopeLabel}
                                                </p>
                                            </div>
                                        </div>

                                        <p className="font-display text-3xl leading-none font-semibold text-[#0f2942] tabular-nums sm:text-right">
                                            {formatCount(setting.total_active)}
                                            <span className="ml-1.5 text-xs font-normal text-[#64788a]">
                                                active
                                            </span>
                                        </p>

                                        <dl className="grid grid-cols-3 gap-2 sm:col-span-2">
                                            {statusChips.map((chip) => (
                                                <div
                                                    key={chip.key}
                                                    className="flex items-center justify-between gap-2 rounded-md bg-[#f6f9fb] px-2.5 py-2"
                                                >
                                                    <dt className="truncate text-xs text-[#64788a]">
                                                        {chip.label}
                                                    </dt>
                                                    <dd className="text-sm font-semibold text-[#0f2942] tabular-nums">
                                                        {formatCount(
                                                            setting[chip.key],
                                                        )}
                                                    </dd>
                                                </div>
                                            ))}
                                        </dl>
                                    </article>
                                );
                            })}
                        </div>
                    </section>

                    <section aria-labelledby="queues-heading">
                        <div className="mb-3 flex items-center gap-3">
                            <span className="grid size-9 place-items-center rounded-lg bg-[#e8f3fb] text-[#1b75bc]">
                                <ClipboardList
                                    aria-hidden="true"
                                    className="size-4.5"
                                />
                            </span>
                            <div>
                                <h2
                                    id="queues-heading"
                                    className="text-xl font-semibold text-[#0f2942]"
                                >
                                    Work queues
                                </h2>
                                <p className="mt-0.5 text-sm text-[#64788a]">
                                    Prioritized for your role.
                                </p>
                            </div>
                        </div>

                        {queues.length === 0 ? (
                            <p className="rounded-xl border border-[#e2e8f0] bg-white px-4 py-5 text-sm text-[#64788a]">
                                No work queues are available for this account.
                            </p>
                        ) : (
                            <ul className="overflow-hidden rounded-xl border border-[#dbe5ee] bg-white shadow-[0_8px_24px_rgba(15,41,66,0.04)]">
                                {queues.map((queue) => {
                                    const displayQueue =
                                        queueCopy[queue.id] ?? queue;
                                    const QueueIcon =
                                        queueIcons[queue.id] ?? ClipboardList;

                                    return (
                                        <li
                                            key={queue.id}
                                            className="border-b border-[#e8eef3] last:border-b-0"
                                        >
                                            <Link
                                                href={queue.href}
                                                aria-label={`${displayQueue.label}: ${formatCount(queue.count)}`}
                                                className={`group grid min-h-20 grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 px-3.5 py-3 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none focus-visible:ring-inset ${
                                                    queue.priority
                                                        ? 'bg-[#123b63] text-white hover:bg-[#0f3152]'
                                                        : 'bg-white text-[#0f2942] hover:bg-[#f6f9fb]'
                                                }`}
                                            >
                                                <span
                                                    className={`grid size-11 place-items-center rounded-lg text-lg font-semibold tabular-nums ${
                                                        queue.priority
                                                            ? 'bg-white/12 text-white ring-1 ring-white/20'
                                                            : toneClass(
                                                                  queue.tone,
                                                              )
                                                    }`}
                                                >
                                                    {formatCount(queue.count)}
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="flex flex-wrap items-center gap-2">
                                                        <span className="text-sm font-semibold">
                                                            {displayQueue.label}
                                                        </span>
                                                        {queue.priority ? (
                                                            <span className="rounded-full bg-white/14 px-2 py-0.5 text-[0.65rem] font-semibold text-white">
                                                                Main desk
                                                            </span>
                                                        ) : null}
                                                    </span>
                                                    <span
                                                        className={`mt-0.5 block truncate text-xs ${
                                                            queue.priority
                                                                ? 'text-[#d7e7f3]'
                                                                : 'text-[#64788a]'
                                                        }`}
                                                    >
                                                        {displayQueue.hint}
                                                    </span>
                                                </span>
                                                <span
                                                    className={`grid size-8 place-items-center rounded-lg ${
                                                        queue.priority
                                                            ? 'bg-white/10 text-white'
                                                            : 'bg-[#f1f5f9] text-[#123b63]'
                                                    }`}
                                                >
                                                    <QueueIcon
                                                        aria-hidden="true"
                                                        className="size-4"
                                                    />
                                                    <span className="sr-only">
                                                        Open desk
                                                    </span>
                                                </span>
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </section>
                </div>

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
                                    <h2
                                        id="occupancy-heading"
                                        className="text-xl font-semibold text-[#0f2942]"
                                    >
                                        Inpatient occupancy
                                    </h2>
                                    <p className="mt-0.5 text-sm text-[#64788a]">
                                        Ward capacity managed by the system.
                                    </p>
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
                                            Active wards
                                        </dt>
                                        <dd className="mt-1 text-2xl font-semibold text-[#0f2942] tabular-nums">
                                            {occupancy.totals.active_wards}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-[#64788a]">
                                            Active beds
                                        </dt>
                                        <dd className="mt-1 text-2xl font-semibold text-[#0f2942] tabular-nums">
                                            {occupancy.totals.active_beds}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-[#64788a]">
                                            Occupied
                                        </dt>
                                        <dd className="mt-1 text-2xl font-semibold text-[#b45309] tabular-nums">
                                            {occupancy.totals.occupied_beds}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-[#64788a]">
                                            Available
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
                            Module shortcuts
                        </h2>
                        <nav
                            aria-label="Home module shortcuts"
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
