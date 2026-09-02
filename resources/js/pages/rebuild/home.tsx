import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    BedDouble,
    Building2,
    CircleAlert,
    HeartPulse,
} from 'lucide-react';
import type { ComponentType, SVGProps } from 'react';
import type { BreadcrumbItem } from '@/types';

type ServiceSetting = 'rawat_jalan' | 'igd' | 'rawat_inap';

type EncounterOverview = {
    available: boolean;
    totals: Record<ServiceSetting, number | null>;
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

type OperationalAction = {
    setting: ServiceSetting | 'occupancy';
    kind: string;
    label: string;
    href: string;
};

type Props = {
    encounters: EncounterOverview;
    occupancy: OccupancyOverview;
    actions: OperationalAction[];
};

type ServiceDefinition = {
    key: ServiceSetting;
    shortLabel: string;
    label: string;
    description: string;
    accent: string;
    icon: ComponentType<SVGProps<SVGSVGElement>>;
};

const services: readonly ServiceDefinition[] = [
    {
        key: 'rawat_jalan',
        shortLabel: 'RJ',
        label: 'Rawat Jalan',
        description: 'Kunjungan poliklinik yang masih aktif.',
        accent: 'border-t-[#1b75bc]',
        icon: Activity,
    },
    {
        key: 'igd',
        shortLabel: 'IGD',
        label: 'Instalasi Gawat Darurat',
        description: 'Kunjungan kegawatdaruratan yang masih aktif.',
        accent: 'border-t-[#c2413a]',
        icon: HeartPulse,
    },
    {
        key: 'rawat_inap',
        shortLabel: 'RI',
        label: 'Rawat Inap',
        description: 'Perawatan pasien di bangsal yang masih aktif.',
        accent: 'border-t-[#0f766e]',
        icon: Building2,
    },
] as const;

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Beranda',
        href: '/',
    },
];

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

export default function RebuildHome({ encounters, occupancy, actions }: Props) {
    const { flash } = usePage().props;
    const actionsFor = (setting: OperationalAction['setting']) =>
        actions.filter((action) => action.setting === setting);

    return (
        <>
            <Head title="Beranda Operasional" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 px-4 py-8 md:px-6 md:py-10">
                <header className="grid gap-4 border-b border-[#dbe5ee] pb-7 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                    <div className="space-y-2">
                        <p className="font-mono text-xs font-semibold tracking-[0.16em] text-[#1b75bc] uppercase">
                            Operasional hari ini
                        </p>
                        <h1 className="max-w-3xl font-display text-3xl font-semibold tracking-tight text-[#0f2942] md:text-4xl">
                            Satu pandangan untuk tiga area layanan
                        </h1>
                        <p className="max-w-2xl text-base leading-relaxed text-[#52677b]">
                            Pantau arus layanan dan buka meja kerja yang sesuai
                            dengan akses akun Anda.
                        </p>
                    </div>
                    <p className="inline-flex w-fit items-center gap-2 rounded-full border border-[#b8d7ee] bg-[#eef7fd] px-3 py-1.5 text-xs font-semibold text-[#123b63]">
                        <span
                            aria-hidden="true"
                            className="size-2 rounded-full bg-[#179c78]"
                        />
                        Ringkasan aktif
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
                    <div className="mb-4 flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <h2
                                id="service-flow-heading"
                                className="text-xl font-semibold text-[#0f2942]"
                            >
                                Arus kunjungan aktif
                            </h2>
                            <p className="mt-1 text-sm text-[#64788a]">
                                Kunjungan aktif yang didaftarkan hari ini.
                            </p>
                        </div>
                        <p className="font-mono text-xs text-[#64788a]">
                            RJ · IGD · RI
                        </p>
                    </div>

                    {!encounters.available ? (
                        <div
                            role="alert"
                            className="mb-4 flex items-start gap-3 rounded-xl border border-[#fed7aa] bg-[#fff7ed] px-4 py-3 text-sm text-[#9a3412]"
                        >
                            <CircleAlert
                                aria-hidden="true"
                                className="mt-0.5 size-5 shrink-0"
                            />
                            <span>{encounters.read_error}</span>
                        </div>
                    ) : null}

                    <div className="grid gap-4 lg:grid-cols-3">
                        {services.map((service) => {
                            const Icon = service.icon;
                            const serviceActions = actionsFor(service.key);
                            const total = encounters.totals[service.key];

                            return (
                                <article
                                    key={service.key}
                                    className={`flex min-h-64 flex-col rounded-xl border border-t-4 border-[#dbe5ee] bg-white p-5 shadow-[0_10px_30px_rgba(15,41,66,0.05)] ${service.accent}`}
                                >
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

                                    <div className="mt-5">
                                        <p className="sr-only">
                                            Jumlah kunjungan aktif
                                        </p>
                                        <p className="font-display text-5xl leading-none font-semibold text-[#0f2942] tabular-nums">
                                            {total ?? '—'}
                                        </p>
                                        <p className="mt-2 text-sm leading-relaxed text-[#64788a]">
                                            {service.description}
                                        </p>
                                    </div>

                                    {serviceActions.length > 0 ? (
                                        <nav
                                            aria-label={`Aksi ${service.label}`}
                                            className="mt-auto grid gap-2 pt-5"
                                        >
                                            {serviceActions.map((action) => (
                                                <ActionLink
                                                    key={action.kind}
                                                    action={action}
                                                />
                                            ))}
                                        </nav>
                                    ) : (
                                        <p className="mt-auto pt-5 text-xs leading-relaxed text-[#7b8d9e]">
                                            Tidak ada meja kerja untuk akses
                                            akun ini.
                                        </p>
                                    )}
                                </article>
                            );
                        })}
                    </div>
                </section>

                {occupancy ? (
                    <section
                        aria-labelledby="occupancy-heading"
                        className="overflow-hidden rounded-xl border border-[#b9d7cf] bg-[#f4fbf8]"
                    >
                        <div className="grid gap-5 p-5 md:grid-cols-[minmax(0,1fr)_auto] md:items-center md:p-6">
                            <div>
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
                                                {
                                                    occupancy.totals
                                                        .available_beds
                                                }
                                            </dd>
                                        </div>
                                    </dl>
                                )}
                            </div>

                            {actionsFor('occupancy').map((action) => (
                                <ActionLink key={action.kind} action={action} />
                            ))}
                        </div>
                    </section>
                ) : null}
            </div>
        </>
    );
}

RebuildHome.layout = {
    breadcrumbs,
};
