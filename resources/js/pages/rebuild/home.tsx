import { Head, Link, usePage } from '@inertiajs/react';
import type { BreadcrumbItem } from '@/types';

type Counts = {
    kunjungan_hari_ini: number;
    pasien_baru_hari_ini: number;
    in_examination: number;
    ready_for_rm: number;
};

type Props = {
    counts: Counts;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Beranda',
        href: '/',
    },
];

export default function RebuildHome({ counts }: Props) {
    const { flash } = usePage().props;

    const cards = [
        {
            label: 'Kunjungan hari ini',
            value: counts.kunjungan_hari_ini,
            href: '/pendaftaran/rawat-jalan',
        },
        {
            label: 'Pasien baru hari ini',
            value: counts.pasien_baru_hari_ini,
            href: '/pendaftaran/rawat-jalan',
        },
        {
            label: 'Dalam pemeriksaan',
            value: counts.in_examination,
            href: '/pemeriksaan/rawat-jalan',
        },
        {
            label: 'Siap RM',
            value: counts.ready_for_rm,
            href: '/rm/rawat-jalan',
        },
    ];

    return (
        <>
            <Head title="Beranda" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-8 px-3 py-8 md:px-4 lg:px-5 lg:py-10">
                <header className="space-y-2">
                    <h1 className="text-3xl font-semibold tracking-tight text-[#0f172a] md:text-4xl">
                        Beranda
                    </h1>
                    <p className="max-w-2xl text-base leading-relaxed text-[#64748b]">
                        SIMRS Campus UEU — ringkasan operasional rawat jalan.
                    </p>
                </header>

                {flash?.error ? (
                    <div
                        role="alert"
                        className="rounded-xl border border-[#fecaca] bg-[#fef2f2] px-4 py-3.5 text-sm leading-relaxed text-[#991b1b]"
                    >
                        {flash.error}
                    </div>
                ) : null}

                {flash?.success ? (
                    <div
                        role="status"
                        className="rounded-xl border border-[#a7f3d0] bg-[#ecfdf5] px-4 py-3.5 text-sm leading-relaxed text-[#065f46]"
                    >
                        {flash.success}
                    </div>
                ) : null}

                <section
                    aria-labelledby="stats-heading"
                    className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
                >
                    <h2 id="stats-heading" className="sr-only">
                        Ringkasan
                    </h2>
                    {cards.map((card) => (
                        <Link
                            key={card.label}
                            href={card.href}
                            className="rounded-xl border border-[#e2e8f0] bg-white px-5 py-4 transition-colors hover:border-[#1b75bc]/40"
                        >
                            <p className="text-xs font-medium tracking-wide text-[#64748b] uppercase">
                                {card.label}
                            </p>
                            <p className="mt-2 text-3xl font-semibold text-[#0f172a]">
                                {card.value}
                            </p>
                        </Link>
                    ))}
                </section>

                <section className="rounded-xl border border-[#e2e8f0] bg-white p-5">
                    <h2 className="text-sm font-semibold text-[#123b63]">
                        Alur cepat (aktif)
                    </h2>
                    <ul className="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                        <li>
                            <Link
                                href="/pendaftaran/rawat-jalan"
                                className="font-medium text-[#1b75bc] hover:underline"
                            >
                                Pendaftaran rawat jalan
                            </Link>
                        </li>
                        <li>
                            <Link
                                href="/pemeriksaan/rawat-jalan"
                                className="font-medium text-[#1b75bc] hover:underline"
                            >
                                Pemeriksaan rawat jalan
                            </Link>
                        </li>
                        <li>
                            <Link
                                href="/rm/rawat-jalan"
                                className="font-medium text-[#1b75bc] hover:underline"
                            >
                                RM rawat jalan
                            </Link>
                        </li>
                    </ul>
                    <p className="mt-4 text-xs leading-relaxed text-[#64748b]">
                        Modul lain di bilah navigasi (Klaim, BPJS, Apotek, dll.)
                        masih penanda tempat untuk fase berikutnya.
                    </p>
                </section>
            </div>
        </>
    );
}

RebuildHome.layout = {
    breadcrumbs,
};
