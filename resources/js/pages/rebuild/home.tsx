import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Beranda',
        href: '/',
    },
];

export default function RebuildHome() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Beranda" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-8 px-4 py-10 md:px-6">
                <header className="space-y-2">
                    <h1 className="text-3xl font-semibold tracking-tight text-[#0f172a] md:text-4xl">
                        Beranda
                    </h1>
                    <p className="max-w-2xl text-base leading-relaxed text-[#64748b]">
                        SIMRS Campus UEU. Modul operasional akan ditambahkan
                        sesuai program parity.
                    </p>
                </header>

                <section
                    aria-labelledby="stats-heading"
                    className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
                >
                    <h2 id="stats-heading" className="sr-only">
                        Ringkasan
                    </h2>
                    {[
                        { label: 'Kunjungan', value: '—' },
                        { label: 'Pasien dirawat', value: '—' },
                        { label: 'Pasien baru', value: '—' },
                        { label: 'Pasien lama', value: '—' },
                    ].map((card) => (
                        <div
                            key={card.label}
                            className="rounded-xl border border-[#e2e8f0] bg-white px-5 py-4"
                        >
                            <p className="text-xs font-medium tracking-wide text-[#64748b] uppercase">
                                {card.label}
                            </p>
                            <p className="mt-2 text-3xl font-semibold text-[#0f172a]">
                                {card.value}
                            </p>
                        </div>
                    ))}
                </section>
            </div>
        </AppLayout>
    );
}
